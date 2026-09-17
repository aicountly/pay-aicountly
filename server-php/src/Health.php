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
     * What a PDOException actually means, for the caller and for the operator.
     *
     * "The database is not reachable" was once the answer to every one of
     * these, and it sent the one person who could fix it to look at the wrong
     * thing: an operator who had configured DB_HOST correctly and simply not
     * run the migrations was told the server could not be reached. The four
     * cases below need four different actions, so they get four different
     * sentences.
     *
     * The message is deliberately about THIS DEPLOYMENT and names no database,
     * role or host — the detail goes to the error log, which is where the
     * person fixing it is entitled to see it.
     *
     * @return array{code: string, message: string}
     */
    public static function explain(\PDOException $e): array
    {
        $m = strtolower($e->getMessage());

        // The pgsql driver puts the SQLSTATE in errorInfo[0] for BOTH connection
        // and query failures. getCode() only carries it for query failures — a
        // connection failure reports the libpq code 7 instead — so reading
        // getCode() alone misclassifies every connection error as unknown.
        $state = (string) ($e->errorInfo[0] ?? $e->getCode() ?? '');

        // 42P01 undefined_table, 3F000 invalid_schema_name. The connection is
        // FINE and the tables are not there. This is the common state on a host
        // somebody has just configured, and it is not about reachability at all.
        if ($state === '42P01' || $state === '3F000' || str_contains($m, 'undefined table')) {
            return [
                'code' => 'schema_not_migrated',
                'message' => 'The Pay database is reachable but its tables have not been created yet. Run php bin/migrate.php on the server.',
            ];
        }

        if (str_contains($m, 'could not find driver')) {
            return [
                'code' => 'driver_missing',
                'message' => 'This server has no PostgreSQL driver for PHP. Enable the pdo_pgsql extension.',
            ];
        }

        if (str_contains($m, 'not configured')) {
            return [
                'code' => 'database_not_configured',
                'message' => 'The Pay database is not configured on this server. Set DB_NAME and DB_USER in api/.env.',
            ];
        }

        // Checked before the database test below, because both arrive as
        // `FATAL: ... does not exist` and only the quoted noun tells them apart.
        if (str_contains($m, 'password authentication') || str_contains($m, 'pg_hba')
            || preg_match('/role "[^"]*" does not exist/', $m) === 1) {
            return [
                'code' => 'database_credentials_rejected',
                'message' => 'The Pay database rejected these credentials. Check DB_USER and DB_PASS in api/.env.',
            ];
        }

        if (preg_match('/database "[^"]*" does not exist/', $m) === 1) {
            return [
                'code' => 'no_such_database',
                'message' => 'That Pay database does not exist on this server. Check DB_NAME in api/.env, and create the database if it has not been created yet.',
            ];
        }

        if (str_contains($m, 'connection refused') || str_contains($m, 'could not connect')
            || str_contains($m, 'timeout') || str_contains($m, 'no such host')
            || str_contains($m, 'could not translate host name')) {
            return [
                'code' => 'database_unreachable',
                'message' => 'Nothing is answering at the configured Pay database address. Check DB_HOST and DB_PORT in api/.env, and that PostgreSQL is running.',
            ];
        }

        return [
            'code' => 'database_unavailable',
            'message' => 'The Pay database is not reachable right now. Please retry.',
        ];
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
