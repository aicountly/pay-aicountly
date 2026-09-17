<?php

declare(strict_types=1);

/**
 * Apply pending SQL migrations.
 *
 *   php server-php/bin/migrate.php            apply everything not yet applied
 *   php server-php/bin/migrate.php --status   list what would run, change nothing
 *   php server-php/bin/migrate.php --dry-run  parse and check, roll back
 *
 * Each file runs inside its own transaction and is recorded by name, so a
 * half-applied migration cannot exist: either the file is in and recorded, or
 * neither. Files are applied in filename order, which is why they are numbered.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Db.php';

Env::load(__DIR__ . '/../.env');

$args    = array_slice($argv, 1);
$status  = in_array('--status', $args, true);
$dryRun  = in_array('--dry-run', $args, true);

$dir = __DIR__ . '/../database/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, "No migrations found in {$dir}\n");
    exit(1);
}

try {
    $pdo = Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect: {$e->getMessage()}\n");
    exit(1);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS pay_sql_migrations (
        migration_id BIGSERIAL PRIMARY KEY,
        filename     TEXT        NOT NULL UNIQUE,
        checksum     TEXT        NOT NULL,
        applied_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
     )',
);

$applied = [];
foreach (Db::all('SELECT filename, checksum FROM pay_sql_migrations') as $row) {
    $applied[$row['filename']] = $row['checksum'];
}

$pending = 0;
$drift   = 0;

foreach ($files as $path) {
    $name = basename($path);
    $sql = (string) file_get_contents($path);
    $checksum = hash('sha256', $sql);

    if (isset($applied[$name])) {
        if ($applied[$name] !== $checksum) {
            // An applied migration that has since been edited. Reapplying it
            // would not undo what the old version did; say so and let a human
            // decide, rather than guessing.
            fwrite(STDERR, "CHANGED  {$name} — already applied, but the file has been edited since.\n");
            $drift++;
        } elseif ($status) {
            // "already", not "applied": in apply mode that same word means
            // "just applied it now". A deploy log is read by someone who
            // needs to know which of those two happened.
            echo "already  {$name}\n";
        }
        continue;
    }

    $pending++;
    if ($status) {
        echo "PENDING  {$name}\n";
        continue;
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        if ($dryRun) {
            $pdo->rollBack();
            echo "ok (rolled back)  {$name}\n";
            continue;
        }
        $stmt = $pdo->prepare('INSERT INTO pay_sql_migrations (filename, checksum) VALUES (:f, :c)');
        $stmt->execute(['f' => $name, 'c' => $checksum]);
        $pdo->commit();
        echo "applied  {$name}\n";
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "FAILED   {$name}\n         {$e->getMessage()}\n");
        exit(1);
    }
}

if ($drift > 0) {
    exit(2);
}

if ($status) {
    echo $pending === 0 ? "\nUp to date.\n" : "\n{$pending} migration(s) pending.\n";
} elseif ($pending === 0) {
    echo "Up to date.\n";
}
