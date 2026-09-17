<?php

declare(strict_types=1);

namespace Aicountly\Api;

use PDOException;

/**
 * What /api/health can honestly say about this deployment.
 *
 * WHY THIS EXISTS. Health used to answer 200 as soon as PHP was serving, which
 * is a liveness check and nothing more. That let a deploy go green, and a
 * monitor stay quiet, on an app where every real endpoint answered 503 because
 * its database had never been created. "The process is up" and "the app works"
 * are different claims and only one of them is useful.
 *
 * It now also reports whether the database answers, whether the schema has been
 * applied, and — unique to Pay — whether an encryption key is configured. The
 * last one matters because without it this product cannot store a gateway
 * credential at all, and the first symptom is a merchant failing to connect
 * their provider rather than anything the health check would otherwise notice.
 *
 * WHAT IT MUST NOT SAY. This endpoint is UNAUTHENTICATED and public. The driver's
 * own message names the database, the role and the host — PostgreSQL's
 * "no pg_hba.conf entry for host X, user Y, database Z" hands all three to
 * anyone who asks. So the reason is reduced to a category here and the detail
 * goes to the error log, where the person fixing it can read it and a passer-by
 * cannot.
 */
final class Health
{
    /** Migration bookkeeping table for this product. */
    private const MIGRATIONS_TABLE = 'pay_sql_migrations';

    /**
     * @return array<string, mixed>
     */
    public static function database(): array
    {
        try {
            $pdo = Db::connect();
        } catch (PDOException $e) {
            return [
                'reachable' => false,
                'reason'    => self::categorise($e->getMessage()),
                'schema'    => null,
            ];
        } catch (\Throwable $e) {
            error_log('[health] database check failed: ' . $e->getMessage());

            return ['reachable' => false, 'reason' => 'error', 'schema' => null];
        }

        $onDisk = count(glob(__DIR__ . '/../database/migrations/*.sql') ?: []);

        try {
            $applied = (int) $pdo->query('SELECT COUNT(*) FROM ' . self::MIGRATIONS_TABLE)->fetchColumn();
        } catch (\Throwable) {
            // No bookkeeping table means migrate.php has never run here. That is
            // a normal state on a host somebody has only just created, not an
            // error worth logging.
            $applied = 0;
        }

        return [
            'reachable' => true,
            'reason'    => null,
            'schema'    => [
                'applied' => $applied,
                'pending' => max(0, $onDisk - $applied),
                // The one field worth reading at a glance: can this app be used?
                'ready'   => $onDisk > 0 && $applied >= $onDisk,
            ],
        ];
    }

    /**
     * Whether this host can store a provider credential.
     *
     * A boolean and nothing more: naming the key, its id or its length would
     * turn a public endpoint into reconnaissance.
     *
     * @return array<string, bool>
     */
    public static function encryption(): array
    {
        return ['configured' => Crypto::isConfigured()];
    }

    /**
     * A category a stranger may see, from a message they may not.
     *
     * The full driver text is logged, because the person who has to fix this
     * needs the database and role names that the category deliberately omits.
     */
    private static function categorise(string $message): string
    {
        error_log('[health] database unreachable: ' . $message);

        $m = strtolower($message);

        return match (true) {
            str_contains($m, 'not configured')       => 'not_configured',
            str_contains($m, 'pg_hba'),
            str_contains($m, 'password authentication'),
            str_contains($m, 'role ') && str_contains($m, 'does not exist') => 'refused',
            str_contains($m, 'does not exist')       => 'no_such_database',
            str_contains($m, 'connection refused'),
            str_contains($m, 'could not connect'),
            str_contains($m, 'timeout')              => 'unreachable',
            str_contains($m, 'could not find driver') => 'driver_missing',
            default                                   => 'error',
        };
    }
}
