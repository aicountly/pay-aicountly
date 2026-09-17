<?php

declare(strict_types=1);

namespace Aicountly\Api\PayPulse;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Ids;
use Aicountly\Api\Domain\Money;
use Aicountly\Api\Domain\PaymentRequestService;
use Aicountly\Api\Domain\PaymentService;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Domain\States;
use Aicountly\Api\Payments\Providers\ProviderMetrics;
use Aicountly\Api\Payments\Providers\ProviderRegistry;

/**
 * Pay Pulse — payment intelligence for THIS company's payment data.
 *
 * WHAT IT IS NOT, and these are architectural constraints rather than
 * preferences:
 *
 *   NOT A CENTRAL AICOUNTLY BRAIN. Each product in the fleet owns its own
 *   intelligence. Pay Pulse reads pay_payment_attempts, pay_settlements and
 *   pay_provider_metrics for one cmp_id. It does not read Books' ledgers, does
 *   not learn across companies, and there is no shared decision engine for it
 *   to call.
 *
 *   NOT AN AUTOPILOT. It may recommend that UPI traffic move to a different
 *   provider. It may not make that change unless the merchant has explicitly
 *   turned auto-optimisation on, and even then every change is audited and
 *   attributed to 'pay_pulse:auto' rather than to a person.
 *
 *   NOT A BUSINESS ADVISER. It has no opinion on discounts, credit limits,
 *   write-offs, legal recovery, invoice terms or accounting treatment. Those
 *   need context Pay does not have and authority Pay does not hold. Every
 *   insight below is about the mechanics of getting paid.
 *
 * FIVE CAPABILITIES: PREDICT, RECOVER, ROUTE, DETECT, OPTIMISE.
 *
 * EVERY INSIGHT CARRIES ITS EVIDENCE AND A CONFIDENCE. A recommendation a
 * merchant cannot check is a recommendation they will not act on, so `evidence`
 * holds the counts it was computed from and `confidence` reflects how much data
 * was behind it. An insight computed from six payments says so.
 */
final class InsightEngine
{
    public const TABLE = 'pay_pulse_insights';

    public const PREDICT  = 'PREDICT';
    public const RECOVER  = 'RECOVER';
    public const ROUTE    = 'ROUTE';
    public const DETECT   = 'DETECT';
    public const OPTIMISE = 'OPTIMISE';

    /**
     * Below this many observations, a finding is reported with low confidence
     * and never drives an automatic change.
     */
    private const MIN_SAMPLE = 20;

    /**
     * Recompute this company's insights.
     *
     * Each check is independent and upserts on its own `insight_key`, so a
     * finding that is still true is updated rather than duplicated, and one
     * that has gone away is expired.
     *
     * @return list<array<string, mixed>>
     */
    public static function compute(Context $ctx): array
    {
        if (!self::enabled($ctx)) {
            return [];
        }

        $found = [];

        foreach ([
            self::expectedCollections($ctx),
            self::atRiskRequests($ctx),
            self::expiringLinks($ctx),
            self::recoverableFailures($ctx),
            self::methodFailureSpike($ctx),
            self::providerComparison($ctx),
            self::settlementAnomalies($ctx),
            self::duplicateCallbacks($ctx),
            self::inactivePayers($ctx),
        ] as $insight) {
            if ($insight !== null) {
                $found[] = self::store($ctx, $insight);
            }
        }

        // Anything that was true yesterday and is not in this run has stopped
        // being true. Expiring it is what stops the screen accumulating stale
        // alarms nobody can dismiss.
        self::expireMissing($ctx, array_column($found, 'insight_key'));

        return $found;
    }

    /**
     * PREDICT — what is likely to be collected in the next seven days.
     *
     * Deliberately NOT a machine-learned forecast. It is an explainable
     * calculation over open requests, weighted by how this company's payers
     * have actually behaved, and the weights are on screen. A merchant will act
     * on a number they can check and ignore one they cannot.
     */
    private static function expectedCollections(Context $ctx): ?array
    {
        $rows = Db::all(
            'SELECT r.request_id, r.amount_minor, r.paid_minor, r.refunded_minor, r.expires_at,
                    r.first_viewed_at, r.checkout_started_at, r.created_at,
                    p.behaviour_segment, p.payment_count
             FROM ' . PaymentRequestService::TABLE . ' r
             LEFT JOIN pay_payers p ON p.payer_id = r.payer_id
             WHERE r.cmp_id = :cmp AND r.status = ANY(:payable)
               AND (r.expires_at IS NULL OR r.expires_at <= NOW() + INTERVAL \'30 days\')',
            ['cmp' => $ctx->cmpId, 'payable' => '{' . implode(',', States::REQUEST_PAYABLE) . '}'],
        );

        if ($rows === []) {
            return null;
        }

        $expected = 0;
        $highConfidence = 0;
        $atRisk = 0;
        $considered = 0;

        foreach ($rows as $row) {
            $outstanding = max(0, (int) $row['amount_minor'] - (int) $row['paid_minor'] + (int) $row['refunded_minor']);
            if ($outstanding <= 0) {
                continue;
            }
            $considered++;

            // Engagement is the strongest signal available without a model:
            // somebody who reached a checkout is far more likely to finish than
            // somebody who never opened the link.
            $likelihood = match (true) {
                $row['checkout_started_at'] !== null => 0.75,
                $row['first_viewed_at'] !== null     => 0.45,
                default                              => 0.20,
            };

            // A payer who has paid this merchant before is likelier than one who
            // never has. Their own history, not a cross-tenant model.
            if ((int) ($row['payment_count'] ?? 0) >= 3) {
                $likelihood = min(0.92, $likelihood + 0.15);
            }

            // Running out of time cuts both ways: a deadline concentrates the
            // mind, but a link expiring tomorrow that has never been opened is
            // going to expire.
            $expiresAt = $row['expires_at'] === null ? null : strtotime((string) $row['expires_at']);
            if ($expiresAt !== null && $expiresAt < strtotime('+2 days') && $row['first_viewed_at'] === null) {
                $likelihood *= 0.4;
                $atRisk += $outstanding;
            }

            $expected += (int) round($outstanding * $likelihood);
            if ($likelihood >= 0.7) {
                $highConfidence += $outstanding;
            }
        }

        if ($considered === 0) {
            return null;
        }

        return [
            'capability'  => self::PREDICT,
            'insight_key' => 'expected_collections_7d',
            'severity'    => 'INFO',
            'headline'    => Money::minor($expected)->format() . ' is likely to be collected from open requests.',
            'detail'      => sprintf(
                'Across %d open requests, weighted by whether each has been viewed, reached a checkout, and whether that payer has paid you before.',
                $considered,
            ),
            'evidence'    => [
                'open_requests'        => $considered,
                'expected_minor'       => $expected,
                'high_confidence_minor' => $highConfidence,
                'at_risk_minor'        => $atRisk,
                'method'               => 'engagement-weighted, explainable — not a learned model',
            ],
            // Confidence is about how much data is behind it, not how sure the
            // number is.
            'confidence'  => min(95, 40 + $considered * 2),
            'estimated_value_minor' => $expected,
        ];
    }

    /** PREDICT — high-value requests about to expire. */
    private static function atRiskRequests(Context $ctx): ?array
    {
        $row = Db::first(
            'SELECT COUNT(*) AS count,
                    COALESCE(SUM(amount_minor - paid_minor + refunded_minor), 0) AS amount
             FROM ' . PaymentRequestService::TABLE . '
             WHERE cmp_id = :cmp AND status = ANY(:payable)
               AND expires_at IS NOT NULL AND expires_at BETWEEN NOW() AND NOW() + INTERVAL \'2 days\'',
            ['cmp' => $ctx->cmpId, 'payable' => '{' . implode(',', States::REQUEST_PAYABLE) . '}'],
        );

        $count = (int) ($row['count'] ?? 0);
        if ($count === 0) {
            return null;
        }

        return [
            'capability'  => self::PREDICT,
            'insight_key' => 'requests_at_risk',
            'severity'    => 'WARNING',
            'headline'    => $count . ' payment ' . ($count === 1 ? 'request is' : 'requests are') . ' about to expire, worth '
                . Money::minor((int) $row['amount'])->format() . '.',
            'detail'      => 'Extending the expiry or sending a reminder keeps them collectable.',
            'evidence'    => ['count' => $count, 'amount_minor' => (int) $row['amount'], 'window' => '48 hours'],
            'confidence'  => 100,
            'estimated_value_minor' => (int) $row['amount'],
            'suggested_action' => ['kind' => 'send_reminders', 'label' => 'Send reminders', 'target' => 'expiring'],
        ];
    }

    /** OPTIMISE — links expiring soon that people have actually engaged with. */
    private static function expiringLinks(Context $ctx): ?array
    {
        $row = Db::first(
            'SELECT COUNT(*) AS count, COALESCE(SUM(amount_minor - paid_minor), 0) AS amount
             FROM ' . PaymentRequestService::TABLE . '
             WHERE cmp_id = :cmp AND status = ANY(:payable)
               AND first_viewed_at IS NOT NULL
               AND expires_at IS NOT NULL AND expires_at BETWEEN NOW() AND NOW() + INTERVAL \'3 days\'',
            ['cmp' => $ctx->cmpId, 'payable' => '{' . implode(',', States::REQUEST_PAYABLE) . '}'],
        );

        $count = (int) ($row['count'] ?? 0);
        if ($count === 0) {
            return null;
        }

        return [
            'capability'  => self::OPTIMISE,
            'insight_key' => 'links_expiring_engaged',
            'severity'    => 'OPPORTUNITY',
            'headline'    => $count . ' payment ' . ($count === 1 ? 'link expires' : 'links expire')
                . ' within 3 days and ' . ($count === 1 ? 'has' : 'have') . ' already been opened.',
            'detail'      => 'Somebody looked and did not finish. These convert better than a cold reminder.',
            'evidence'    => ['count' => $count, 'amount_minor' => (int) $row['amount']],
            'confidence'  => 90,
            'estimated_value_minor' => (int) $row['amount'],
            'suggested_action' => ['kind' => 'send_reminders', 'label' => 'Remind these payers', 'target' => 'viewed_unpaid'],
        ];
    }

    /**
     * RECOVER — failed payments worth retrying, and through what.
     *
     * The recommendation is method-specific because the reason matters: a card
     * declined by the issuer will be declined again on the same card, but the
     * same customer paying by UPI often goes through.
     */
    private static function recoverableFailures(Context $ctx): ?array
    {
        $rows = Db::all(
            'SELECT a.payment_method, a.failure_code, COUNT(*) AS count,
                    COALESCE(SUM(a.amount_minor), 0) AS amount
             FROM ' . PaymentService::TABLE . ' a
             JOIN ' . PaymentRequestService::TABLE . ' r ON r.request_id = a.request_id
             WHERE a.cmp_id = :cmp AND a.status = :failed
               AND a.created_at >= NOW() - INTERVAL \'7 days\'
               AND r.status = ANY(:payable)
               AND (r.expires_at IS NULL OR r.expires_at > NOW())
             GROUP BY a.payment_method, a.failure_code
             ORDER BY amount DESC',
            [
                'cmp' => $ctx->cmpId,
                'failed' => States::ATTEMPT_FAILED,
                'payable' => '{' . implode(',', States::REQUEST_PAYABLE) . '}',
            ],
        );

        if ($rows === []) {
            return null;
        }

        $total = 0;
        $count = 0;
        $byMethod = [];
        foreach ($rows as $row) {
            $total += (int) $row['amount'];
            $count += (int) $row['count'];
            $method = (string) ($row['payment_method'] ?? 'OTHER');
            $byMethod[$method] = ($byMethod[$method] ?? 0) + (int) $row['count'];
        }

        if ($count === 0) {
            return null;
        }

        arsort($byMethod);
        $worstMethod = (string) array_key_first($byMethod);

        // Which method actually works best for this company right now — their
        // own data, not a rule of thumb.
        $best = Db::first(
            'SELECT payment_method,
                    COUNT(*) FILTER (WHERE status = ANY(:settled)) AS successes,
                    COUNT(*) FILTER (WHERE status = ANY(:terminal)) AS decided
             FROM ' . PaymentService::TABLE . '
             WHERE cmp_id = :cmp AND created_at >= NOW() - INTERVAL \'30 days\'
               AND payment_method IS NOT NULL AND payment_method <> :worst
             GROUP BY payment_method
             HAVING COUNT(*) FILTER (WHERE status = ANY(:terminal)) >= :min_sample
             ORDER BY (COUNT(*) FILTER (WHERE status = ANY(:settled))::float
                     / NULLIF(COUNT(*) FILTER (WHERE status = ANY(:terminal)), 0)) DESC
             LIMIT 1',
            [
                'cmp' => $ctx->cmpId,
                'settled' => '{' . implode(',', States::ATTEMPT_SETTLED_MONEY) . '}',
                'terminal' => '{' . implode(',', States::ATTEMPT_TERMINAL) . '}',
                'worst' => $worstMethod,
                'min_sample' => self::MIN_SAMPLE,
            ],
        );

        $suggestion = null;
        $detail = 'These requests are still open and can be paid.';
        if ($best !== null && (int) $best['decided'] > 0) {
            $bestRate = round(((int) $best['successes'] / (int) $best['decided']) * 100, 1);
            $bestMethod = (string) $best['payment_method'];
            $detail = sprintf(
                '%s is succeeding %.1f%% of the time for you, against %s which is where most of these failed.',
                \Aicountly\Api\Dashboards\PaymentReadings::methodLabel($bestMethod),
                $bestRate,
                \Aicountly\Api\Dashboards\PaymentReadings::methodLabel($worstMethod),
            );
            $suggestion = ['kind' => 'retry_alternative_method', 'label' => 'Retry through ' .
                \Aicountly\Api\Dashboards\PaymentReadings::methodLabel($bestMethod),
                'target' => 'failed_recent', 'params' => ['method' => $bestMethod]];
        }

        return [
            'capability'  => self::RECOVER,
            'insight_key' => 'recoverable_failures',
            'severity'    => 'OPPORTUNITY',
            'headline'    => $count . ' failed ' . ($count === 1 ? 'payment is' : 'payments are') . ' still recoverable, worth '
                . Money::minor($total)->format() . '.',
            'detail'      => $detail,
            'evidence'    => ['failed_count' => $count, 'amount_minor' => $total, 'by_method' => $byMethod, 'window' => '7 days'],
            'confidence'  => $count >= self::MIN_SAMPLE ? 85 : 55,
            'estimated_value_minor' => $total,
            'suggested_action' => $suggestion,
        ];
    }

    /**
     * DETECT — a method that is failing much more than it usually does.
     *
     * Compares the last 6 hours against the previous 7 days for the same
     * method. Both windows need enough volume, or ordinary quiet-hour noise
     * reads as an outage.
     */
    private static function methodFailureSpike(Context $ctx): ?array
    {
        $rows = Db::all(
            'SELECT payment_method,
                COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL \'6 hours\' AND status = :failed) AS recent_failed,
                COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL \'6 hours\' AND status = ANY(:terminal)) AS recent_total,
                COUNT(*) FILTER (WHERE created_at <  NOW() - INTERVAL \'6 hours\' AND status = :failed) AS base_failed,
                COUNT(*) FILTER (WHERE created_at <  NOW() - INTERVAL \'6 hours\' AND status = ANY(:terminal)) AS base_total
             FROM ' . PaymentService::TABLE . '
             WHERE cmp_id = :cmp AND created_at >= NOW() - INTERVAL \'7 days\' AND payment_method IS NOT NULL
             GROUP BY payment_method',
            [
                'cmp' => $ctx->cmpId,
                'failed' => States::ATTEMPT_FAILED,
                'terminal' => '{' . implode(',', States::ATTEMPT_TERMINAL) . '}',
            ],
        );

        foreach ($rows as $row) {
            $recentTotal = (int) $row['recent_total'];
            $baseTotal = (int) $row['base_total'];

            // Both windows need enough volume. Two failures out of two is 100%
            // and is also a coincidence.
            if ($recentTotal < 10 || $baseTotal < self::MIN_SAMPLE) {
                continue;
            }

            $recentRate = ((int) $row['recent_failed'] / $recentTotal) * 100;
            $baseRate = ((int) $row['base_failed'] / $baseTotal) * 100;
            $delta = $recentRate - $baseRate;

            // Four points worse AND meaningfully elevated. A rise from 1% to 5%
            // is worth saying; from 40% to 44% on a method that always
            // struggles is not news.
            if ($delta < 4.0 || $recentRate < 10.0) {
                continue;
            }

            $method = (string) $row['payment_method'];

            return [
                'capability'  => self::DETECT,
                'insight_key' => 'failure_spike_' . strtolower($method),
                'severity'    => $delta > 15 ? 'RISK' : 'WARNING',
                'headline'    => sprintf(
                    '%s failures are up %.1f points in the last 6 hours.',
                    \Aicountly\Api\Dashboards\PaymentReadings::methodLabel($method),
                    $delta,
                ),
                'detail'      => sprintf(
                    'Failing %.1f%% of the time now, against %.1f%% over the last week. It may be the provider, or the bank behind that method.',
                    $recentRate,
                    $baseRate,
                ),
                'evidence'    => [
                    'method' => $method,
                    'recent_rate' => round($recentRate, 1), 'recent_sample' => $recentTotal,
                    'baseline_rate' => round($baseRate, 1), 'baseline_sample' => $baseTotal,
                ],
                'confidence'  => $recentTotal >= self::MIN_SAMPLE ? 85 : 60,
                'suggested_action' => ['kind' => 'review_routing', 'label' => 'Review routing for ' . $method,
                                       'target' => 'routing', 'params' => ['method' => $method]],
            ];
        }

        return null;
    }

    /**
     * ROUTE — one provider is doing better than another at the same method.
     *
     * A RECOMMENDATION AND NOT A CHANGE. Where auto-optimisation is off — which
     * is the default and will be true for nearly every company — this produces
     * a suggestion with a button. Routing decides where a merchant's money
     * travels and at what commercial rate, and that is not a decision to make
     * on their behalf because a number moved.
     */
    private static function providerComparison(Context $ctx): ?array
    {
        $rows = ProviderMetrics::byMethod($ctx->cmpId, 60 * 24 * 3);
        if (count($rows) < 2) {
            return null;
        }

        $byMethod = [];
        foreach ($rows as $row) {
            $method = (string) ($row['payment_method'] ?? '');
            if ($method === '' || (int) $row['calls'] < self::MIN_SAMPLE) {
                continue;
            }
            $byMethod[$method][] = [
                'connection_id' => (int) $row['connection_id'],
                'provider'      => (string) $row['provider_code'],
                'rate'          => round(((int) $row['successes'] / (int) $row['calls']) * 100, 1),
                'calls'         => (int) $row['calls'],
            ];
        }

        foreach ($byMethod as $method => $candidates) {
            if (count($candidates) < 2) {
                continue;
            }

            usort($candidates, static fn (array $a, array $b): int => $b['rate'] <=> $a['rate']);
            $best = $candidates[0];
            $worst = $candidates[count($candidates) - 1];
            $gap = $best['rate'] - $worst['rate'];

            // Two and a half points, over enough calls. Below that it is noise,
            // and recommending a routing change on noise is how a merchant's
            // traffic ends up oscillating between gateways.
            if ($gap < 2.5) {
                continue;
            }

            $bestName = ProviderRegistry::displayNameFor($best['provider']);
            $worstName = ProviderRegistry::displayNameFor($worst['provider']);

            return [
                'capability'  => self::ROUTE,
                'insight_key' => 'provider_gap_' . strtolower($method),
                'severity'    => 'OPPORTUNITY',
                'headline'    => sprintf(
                    '%s is succeeding %.1f points more often than %s on %s.',
                    $bestName,
                    $gap,
                    $worstName,
                    \Aicountly\Api\Dashboards\PaymentReadings::methodLabel($method),
                ),
                'detail'      => sprintf(
                    '%s: %.1f%% over %d calls. %s: %.1f%% over %d. Making %s the primary for %s would be worth trying.',
                    $bestName, $best['rate'], $best['calls'],
                    $worstName, $worst['rate'], $worst['calls'],
                    $bestName,
                    \Aicountly\Api\Dashboards\PaymentReadings::methodLabel($method),
                ),
                'evidence'    => ['method' => $method, 'candidates' => $candidates, 'window_hours' => 72],
                'confidence'  => min(90, 50 + (int) (($best['calls'] + $worst['calls']) / 10)),
                'suggested_action' => [
                    'kind'   => 'change_routing_primary',
                    'label'  => 'Make ' . $bestName . ' primary for ' . $method,
                    'target' => 'routing',
                    'params' => ['method' => $method, 'connection_id' => $best['connection_id']],
                ],
            ];
        }

        return null;
    }

    /** DETECT — settlement differences worth a look. */
    private static function settlementAnomalies(Context $ctx): ?array
    {
        $row = Db::first(
            'SELECT COUNT(*) AS count, COALESCE(SUM(ABS(COALESCE(difference_minor, actual_minor, 0))), 0) AS amount
             FROM pay_reconciliation_cases
             WHERE cmp_id = :cmp AND status IN (:open, :investigating)
               AND case_kind IN (:mismatch, :missing, :fee, :delayed)',
            [
                'cmp' => $ctx->cmpId, 'open' => 'OPEN', 'investigating' => 'INVESTIGATING',
                'mismatch' => 'SETTLEMENT_AMOUNT_MISMATCH', 'missing' => 'MISSING_BANK_SETTLEMENT',
                'fee' => 'UNEXPECTED_PROVIDER_FEE', 'delayed' => 'DELAYED_SETTLEMENT',
            ],
        );

        $count = (int) ($row['count'] ?? 0);
        if ($count === 0) {
            return null;
        }

        return [
            'capability'  => self::DETECT,
            'insight_key' => 'settlement_anomalies',
            'severity'    => $count > 5 ? 'RISK' : 'WARNING',
            'headline'    => $count . ' settlement ' . ($count === 1 ? 'difference needs' : 'differences need') . ' review.',
            'detail'      => Money::minor((int) $row['amount'])->format() . ' is unaccounted for across mismatches, missing credits, unexpected fees and late batches.',
            'evidence'    => ['open_cases' => $count, 'amount_minor' => (int) $row['amount']],
            'confidence'  => 100,
            'estimated_value_minor' => (int) $row['amount'],
            'suggested_action' => ['kind' => 'review_exceptions', 'label' => 'Open the Exception Centre', 'target' => 'reconciliation'],
        ];
    }

    /** DETECT — provider callbacks arriving more than once. */
    private static function duplicateCallbacks(Context $ctx): ?array
    {
        $count = (int) (Db::scalar(
            'SELECT COUNT(*) FROM pay_webhook_events
             WHERE cmp_id = :cmp AND status = :duplicate AND received_at >= NOW() - INTERVAL \'7 days\'',
            ['cmp' => $ctx->cmpId, 'duplicate' => \Aicountly\Api\Payments\Webhooks\WebhookIngest::DUPLICATE],
        ) ?? 0);

        // Under ten a week is normal provider behaviour, correctly absorbed.
        // Worth naming only when it is unusual enough to suggest a
        // misconfiguration — two webhook URLs pointed at us, for instance.
        if ($count < 10) {
            return null;
        }

        return [
            'capability'  => self::DETECT,
            'insight_key' => 'duplicate_callbacks',
            'severity'    => 'INFO',
            'headline'    => $count . ' duplicate provider callbacks were received this week.',
            'detail'      => 'They were all recognised and ignored, so no payment was recorded twice. A high number can mean the same webhook URL is registered more than once at the provider.',
            'evidence'    => ['duplicates' => $count, 'window' => '7 days', 'impact' => 'none — all deduplicated'],
            'confidence'  => 100,
        ];
    }

    /** OPTIMISE — payers who used to pay and have stopped. */
    private static function inactivePayers(Context $ctx): ?array
    {
        $row = Db::first(
            'SELECT COUNT(*) AS count FROM pay_payers
             WHERE cmp_id = :cmp AND payment_count >= 2
               AND last_paid_at < NOW() - INTERVAL \'30 days\'',
            ['cmp' => $ctx->cmpId],
        );

        $count = (int) ($row['count'] ?? 0);
        if ($count < 3) {
            return null;
        }

        return [
            'capability'  => self::OPTIMISE,
            'insight_key' => 'inactive_payers',
            'severity'    => 'INFO',
            'headline'    => $count . ' regular payers have not paid in over 30 days.',
            'detail'      => 'They had paid you at least twice before. Worth a look at whether anything is outstanding with them.',
            'evidence'    => ['payers' => $count, 'threshold_days' => 30],
            'confidence'  => 100,
            'suggested_action' => ['kind' => 'view_customers', 'label' => 'See these payers', 'target' => 'customers',
                                   'params' => ['filter' => 'inactive']],
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * Store or refresh one insight.
     *
     * Upserts on (company, insight_key), so a finding that is still true is
     * updated in place. A row per run would make the screen a log.
     *
     * @param array<string, mixed> $insight
     * @return array<string, mixed>
     */
    private static function store(Context $ctx, array $insight): array
    {
        try {
            Db::run(
                'INSERT INTO ' . self::TABLE . ' (insight_uuid, cmp_id, capability, insight_key, severity,
                     headline, detail, evidence, confidence, suggested_action, estimated_value_minor,
                     status, computed_at, expires_at)
                 VALUES (:uuid, :cmp, :capability, :key, :severity, :headline, :detail, :evidence,
                     :confidence, :action, :value, :status, NOW(), NOW() + INTERVAL \'2 days\')
                 ON CONFLICT (cmp_id, insight_key) DO UPDATE SET
                     severity = EXCLUDED.severity,
                     headline = EXCLUDED.headline,
                     detail   = EXCLUDED.detail,
                     evidence = EXCLUDED.evidence,
                     confidence = EXCLUDED.confidence,
                     suggested_action = EXCLUDED.suggested_action,
                     estimated_value_minor = EXCLUDED.estimated_value_minor,
                     computed_at = NOW(),
                     expires_at = EXCLUDED.expires_at,
                     -- A merchant who dismissed this keeps it dismissed; one who
                     -- acted on it has it reopen, because the finding is true
                     -- again.
                     status = CASE WHEN ' . self::TABLE . '.status = \'DISMISSED\'
                                   THEN \'DISMISSED\' ELSE \'NEW\' END',
                [
                    'uuid'       => Ids::mint(Ids::INSIGHT),
                    'cmp'        => $ctx->cmpId,
                    'capability' => (string) $insight['capability'],
                    'key'        => (string) $insight['insight_key'],
                    'severity'   => (string) $insight['severity'],
                    'headline'   => substr((string) $insight['headline'], 0, 500),
                    'detail'     => isset($insight['detail']) ? substr((string) $insight['detail'], 0, 1000) : null,
                    'evidence'   => json_encode($insight['evidence'] ?? []),
                    'confidence' => (int) ($insight['confidence'] ?? 0),
                    'action'     => isset($insight['suggested_action']) && $insight['suggested_action'] !== null
                        ? json_encode($insight['suggested_action']) : null,
                    'value'      => $insight['estimated_value_minor'] ?? null,
                    'status'     => 'NEW',
                ],
            );
        } catch (\Throwable $e) {
            error_log('[pay-pulse] could not store insight ' . ($insight['insight_key'] ?? '?') . ': ' . $e->getMessage());
        }

        return $insight;
    }

    /** @param list<string> $stillTrue */
    private static function expireMissing(Context $ctx, array $stillTrue): void
    {
        try {
            if ($stillTrue === []) {
                Db::run(
                    'UPDATE ' . self::TABLE . ' SET status = :expired WHERE cmp_id = :cmp AND status IN (:new, :seen)',
                    ['expired' => 'EXPIRED', 'cmp' => $ctx->cmpId, 'new' => 'NEW', 'seen' => 'SEEN'],
                );

                return;
            }

            Db::run(
                'UPDATE ' . self::TABLE . ' SET status = :expired
                 WHERE cmp_id = :cmp AND status IN (:new, :seen) AND NOT (insight_key = ANY(:keys))',
                [
                    'expired' => 'EXPIRED', 'cmp' => $ctx->cmpId,
                    'new' => 'NEW', 'seen' => 'SEEN',
                    'keys' => '{' . implode(',', array_map(static fn (string $k) => '"' . $k . '"', $stillTrue)) . '}',
                ],
            );
        } catch (\Throwable $e) {
            error_log('[pay-pulse] could not expire stale insights: ' . $e->getMessage());
        }
    }

    /**
     * The short list for a dashboard strip.
     *
     * Read-only and cheap: it reads stored insights rather than recomputing, so
     * putting three lines on the Overview does not cost every dashboard the
     * whole analysis.
     *
     * @return list<array<string, mixed>>
     */
    public static function headlines(Context $ctx, int $limit = 4, ?string $capability = null): array
    {
        if (!self::enabled($ctx)) {
            return [];
        }

        try {
            $rows = Db::all(
                'SELECT * FROM ' . self::TABLE . '
                 WHERE cmp_id = :cmp AND status IN (:new, :seen)
                   AND (:capability = \'\' OR capability = :capability)
                 ORDER BY CASE severity WHEN \'RISK\' THEN 0 WHEN \'WARNING\' THEN 1
                                        WHEN \'OPPORTUNITY\' THEN 2 ELSE 3 END,
                          COALESCE(estimated_value_minor, 0) DESC
                 LIMIT :limit',
                ['cmp' => $ctx->cmpId, 'new' => 'NEW', 'seen' => 'SEEN', 'capability' => $capability ?? '', 'limit' => $limit],
            );
        } catch (\Throwable $e) {
            error_log('[pay-pulse] could not read insights: ' . $e->getMessage());

            return [];
        }

        return array_map(static fn (array $row) => self::present($row), $rows);
    }

    public static function enabled(Context $ctx): bool
    {
        $flag = strtolower(\Aicountly\Api\Env::get('PAY_PULSE_ENABLED'));
        if (in_array($flag, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        $setting = Settings::for($ctx)['pay_pulse_enabled'] ?? true;

        return $setting === true || $setting === 't' || $setting === '1' || $setting === 1;
    }

    /** @param array<string, mixed> $row */
    public static function present(array $row): array
    {
        return [
            'insight_id' => (string) $row['insight_uuid'],
            'capability' => (string) $row['capability'],
            'key'        => (string) $row['insight_key'],
            'severity'   => (string) $row['severity'],
            'headline'   => (string) $row['headline'],
            'detail'     => $row['detail'] === null ? null : (string) $row['detail'],
            // Shown on the card. An insight a merchant cannot check is one they
            // will not act on.
            'evidence'   => Db::jsonColumn($row['evidence'] ?? null),
            'confidence' => (int) $row['confidence'],
            'action'     => $row['suggested_action'] === null ? null : Db::jsonColumn($row['suggested_action']),
            'value'      => $row['estimated_value_minor'] === null
                ? null : Money::minor((int) $row['estimated_value_minor'])->toMajor(),
            'status'     => (string) $row['status'],
            'computed_at' => (string) $row['computed_at'],
        ];
    }
}
