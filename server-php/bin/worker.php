<?php

declare(strict_types=1);

/**
 * The Pay worker — everything that must happen without a user waiting.
 *
 *   php server-php/bin/worker.php              one pass over every job
 *   php server-php/bin/worker.php --job=outbox just that job
 *   php server-php/bin/worker.php --company=55 just that company
 *
 * Run it from cron every minute. It is IDEMPOTENT AND RE-ENTRANT: each job
 * claims its work with a conditional UPDATE, so two overlapping runs cannot
 * both deliver the same callback or expire the same request twice. That matters
 * because a cron that overruns its minute is normal, not exceptional.
 *
 * WHAT IT DOES, and why none of it belongs in a request:
 *
 *   outbox        deliver the callbacks Pay owes source apps and merchant
 *                 endpoints. A payment must be recorded even when Books is
 *                 down, so delivery is separate from the payment.
 *   expire        mark payment requests that have run out of time.
 *   settlements   pull settlement batches from each provider.
 *   reconcile     find payments and refunds no settlement has claimed.
 *   pulse         recompute Pay Pulse insights.
 *   health        check each provider connection and record what it said.
 *   sweep         delete spent idempotency keys.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Domain\PaymentRequestService;
use Aicountly\Api\Domain\SettlementService;
use Aicountly\Api\Payments\Events\Outbox;
use Aicountly\Api\Payments\Providers\ProviderRegistry;
use Aicountly\Api\PayPulse\InsightEngine;

$args = array_slice($argv, 1);
$only = null;
$onlyCompany = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $only = substr($arg, 6);
    }
    if (str_starts_with($arg, '--company=')) {
        $onlyCompany = (int) substr($arg, 10);
    }
}

$started = microtime(true);
$report = [];

function job(string $name, ?string $only, callable $work): mixed
{
    if ($only !== null && $only !== $name) {
        return null;
    }

    try {
        return $work();
    } catch (\Throwable $e) {
        // One failing job must not stop the others. A stuck settlement import
        // should never mean callbacks stop being delivered.
        error_log('[worker] ' . $name . ' failed: ' . $e->getMessage());

        return ['error' => $e->getMessage()];
    }
}

/**
 * Companies with anything worth doing.
 *
 * Scoped rather than "every company that exists": a deployment with a thousand
 * companies, of which four took a payment this week, should do four companies'
 * worth of work.
 *
 * @return list<int>
 */
function activeCompanies(?int $onlyCompany): array
{
    if ($onlyCompany !== null && $onlyCompany > 0) {
        return [$onlyCompany];
    }

    $rows = Db::all(
        'SELECT DISTINCT cmp_id FROM pay_payment_requests WHERE updated_at > NOW() - INTERVAL \'30 days\'
         UNION
         SELECT DISTINCT cmp_id FROM pay_provider_connections WHERE status = \'ACTIVE\'',
    );

    return array_map(static fn (array $row) => (int) $row['cmp_id'], $rows);
}

// --- Outbound callbacks -----------------------------------------------------
// First, always. A source app waiting to hear about a payment is the most
// time-sensitive thing this worker does.
$report['outbox'] = job('outbox', $only, static fn () => Outbox::drain(100));

$companies = activeCompanies($onlyCompany);
$report['companies'] = count($companies);

foreach ($companies as $cmpId) {
    $ctx = Context::forBackground($cmpId);

    $report['expired'][$cmpId] = job('expire', $only, static fn () => PaymentRequestService::expireOverdue($ctx));

    $report['settlements'][$cmpId] = job('settlements', $only, static function () use ($ctx) {
        $imported = 0;
        foreach (ProviderRegistry::connectionsFor($ctx, activeOnly: true) as $entry) {
            $result = SettlementService::importFromProvider($ctx, $entry['connection'], [
                'from' => gmdate('Y-m-d', strtotime('-14 days')),
                'to'   => gmdate('Y-m-d'),
            ]);
            $imported += (int) ($result['imported'] ?? 0);
        }

        return $imported;
    });

    $report['reconciled'][$cmpId] = job('reconcile', $only, static fn () => [
        'payments' => SettlementService::findUnsettledPayments($ctx),
        'refunds'  => SettlementService::findUnsettledRefunds($ctx),
    ]);

    $report['pulse'][$cmpId] = job('pulse', $only, static fn () => count(InsightEngine::compute($ctx)));

    $report['health'][$cmpId] = job('health', $only, static function () use ($ctx) {
        $checked = 0;
        foreach (ProviderRegistry::connectionsFor($ctx, activeOnly: true) as $entry) {
            $provider = $entry['provider'];
            if ($provider === null) {
                continue;
            }

            $health = $provider->healthCheck();
            Db::update(ProviderRegistry::CONNECTIONS, [
                'health_state'      => $health['ok'] ? 'HEALTHY' : 'UNHEALTHY',
                'health_checked_at' => gmdate('Y-m-d H:i:s'),
                'avg_latency_ms'    => (int) $health['latency_ms'],
                'last_error_at'     => $health['ok'] ? null : gmdate('Y-m-d H:i:s'),
                'last_error_code'   => $health['ok'] ? null : (string) ($health['error_code'] ?? 'unknown'),
            ], ['connection_id' => (int) $entry['connection']['connection_id']]);
            $checked++;
        }

        return $checked;
    });
}

// --- Housekeeping -----------------------------------------------------------
$report['swept'] = job('sweep', $only, static function () {
    // Spent idempotency keys. Kept for seven days, which is comfortably longer
    // than any provider's retry window — deleting them sooner would let a very
    // late redelivery through as a new request.
    return Db::run(
        'DELETE FROM ' . Idempotency::TABLE . ' WHERE completed_at IS NOT NULL AND completed_at < NOW() - INTERVAL \'7 days\'',
    )->rowCount();
});

$elapsed = round((microtime(true) - $started) * 1000);

// One line per run, so a cron log stays readable. The detail is in error_log.
echo json_encode([
    'at'         => gmdate('c'),
    'ms'         => $elapsed,
    'companies'  => $report['companies'],
    'outbox'     => $report['outbox'],
    'expired'    => array_sum(array_map(static fn ($v) => is_int($v) ? $v : 0, $report['expired'] ?? [])),
    'settlements' => array_sum(array_map(static fn ($v) => is_int($v) ? $v : 0, $report['settlements'] ?? [])),
    'insights'   => array_sum(array_map(static fn ($v) => is_int($v) ? $v : 0, $report['pulse'] ?? [])),
    'swept'      => $report['swept'],
], JSON_UNESCAPED_SLASHES) . "\n";
