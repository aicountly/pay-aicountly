<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Providers;

use Aicountly\Api\Db;

/**
 * Counts and latencies for every outbound provider call.
 *
 * WHAT IS RECORDED: which connection, which operation, whether it worked, how
 * long it took. WHAT IS NOT: the payment, the payer, the amount, the payload,
 * the identifier. This table is read on the routing path, it is read by an
 * operator debugging a bad afternoon, and it may reasonably be graphed on a
 * wall — none of which should be a place a customer's details can turn up.
 *
 * Aggregated to the minute so it can be queried on every routing decision
 * without reading a row per call. A busy company writing four calls a second
 * produces 1,440 rows a day per operation, not 345,600.
 *
 * A metric write NEVER fails a payment. If this table is missing or the write
 * errors, the payment carries on and the failure goes to the log: observability
 * that can take down the thing it observes is worse than none.
 */
final class ProviderMetrics
{
    public const TABLE = 'pay_provider_metrics';

    public static function record(
        int $cmpId,
        int $connectionId,
        string $providerCode,
        string $operation,
        ?string $paymentMethod,
        bool $ok,
        int $latencyMs,
        ?string $errorCode = null,
    ): void {
        if ($cmpId <= 0 || $connectionId <= 0) {
            // A health check run before the connection is saved, or a mock in a
            // unit test. Nothing to attribute it to.
            return;
        }

        try {
            Db::run(
                'INSERT INTO ' . self::TABLE . '
                    (cmp_id, connection_id, provider_code, operation, payment_method, bucket_at,
                     call_count, success_count, failure_count, total_latency_ms, max_latency_ms, last_error_code)
                 VALUES (:cmp, :conn, :code, :op, :method, date_trunc(\'minute\', NOW()),
                     1, :success, :failure, :latency, :latency, :error)
                 ON CONFLICT (cmp_id, connection_id, operation, payment_method, bucket_at) DO UPDATE SET
                     call_count       = ' . self::TABLE . '.call_count + 1,
                     success_count    = ' . self::TABLE . '.success_count + EXCLUDED.success_count,
                     failure_count    = ' . self::TABLE . '.failure_count + EXCLUDED.failure_count,
                     total_latency_ms = ' . self::TABLE . '.total_latency_ms + EXCLUDED.total_latency_ms,
                     max_latency_ms   = GREATEST(' . self::TABLE . '.max_latency_ms, EXCLUDED.max_latency_ms),
                     last_error_code  = COALESCE(EXCLUDED.last_error_code, ' . self::TABLE . '.last_error_code)',
                [
                    'cmp'     => $cmpId,
                    'conn'    => $connectionId,
                    'code'    => $providerCode,
                    'op'      => $operation,
                    // The unique index treats NULL as distinct, which would give
                    // one row per call for method-less operations. An empty
                    // string collapses them as intended.
                    'method'  => $paymentMethod ?? '',
                    'success' => $ok ? 1 : 0,
                    'failure' => $ok ? 0 : 1,
                    'latency' => $latencyMs,
                    'error'   => $errorCode,
                ],
            );
        } catch (\Throwable $e) {
            error_log('[provider-metrics] could not record ' . $providerCode . '/' . $operation . ': ' . $e->getMessage());
        }
    }

    /**
     * Health over a recent window, per connection.
     *
     * Used by the router to skip a provider that is currently failing, and by
     * the Gateways dashboard. `success_rate` is null rather than 100 when there
     * were no calls: a provider nobody used is not a provider that worked.
     *
     * @return array<int, array{calls:int, successes:int, failures:int, success_rate:?float, avg_latency_ms:?int, last_error_code:?string}>
     */
    public static function window(int $cmpId, int $minutes = 60): array
    {
        $rows = Db::all(
            'SELECT connection_id,
                    SUM(call_count)       AS calls,
                    SUM(success_count)    AS successes,
                    SUM(failure_count)    AS failures,
                    SUM(total_latency_ms) AS latency,
                    MAX(max_latency_ms)   AS max_latency,
                    (ARRAY_AGG(last_error_code ORDER BY bucket_at DESC) FILTER (WHERE last_error_code IS NOT NULL))[1] AS last_error
             FROM ' . self::TABLE . '
             WHERE cmp_id = :cmp AND bucket_at >= NOW() - (:minutes || \' minutes\')::interval
             GROUP BY connection_id',
            ['cmp' => $cmpId, 'minutes' => $minutes],
        );

        $out = [];
        foreach ($rows as $row) {
            $calls = (int) $row['calls'];
            $out[(int) $row['connection_id']] = [
                'calls'           => $calls,
                'successes'       => (int) $row['successes'],
                'failures'        => (int) $row['failures'],
                'success_rate'    => $calls > 0 ? round(((int) $row['successes'] / $calls) * 100, 1) : null,
                'avg_latency_ms'  => $calls > 0 ? (int) round((int) $row['latency'] / $calls) : null,
                'last_error_code' => $row['last_error'] === null ? null : (string) $row['last_error'],
            ];
        }

        return $out;
    }

    /**
     * Success rate per (connection, method) — what a routing recommendation is
     * built on.
     *
     * @return list<array<string, mixed>>
     */
    public static function byMethod(int $cmpId, int $minutes = 1440): array
    {
        return Db::all(
            'SELECT connection_id, provider_code,
                    NULLIF(payment_method, \'\') AS payment_method,
                    SUM(call_count) AS calls, SUM(success_count) AS successes,
                    SUM(total_latency_ms) AS latency
             FROM ' . self::TABLE . '
             WHERE cmp_id = :cmp
               AND bucket_at >= NOW() - (:minutes || \' minutes\')::interval
               AND payment_method <> \'\'
             GROUP BY connection_id, provider_code, payment_method
             HAVING SUM(call_count) > 0
             ORDER BY calls DESC',
            ['cmp' => $cmpId, 'minutes' => $minutes],
        );
    }
}
