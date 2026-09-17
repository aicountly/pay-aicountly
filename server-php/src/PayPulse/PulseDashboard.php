<?php

declare(strict_types=1);

namespace Aicountly\Api\PayPulse;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Dashboards\Metric;
use Aicountly\Api\Dashboards\PaymentReadings;
use Aicountly\Api\Dashboards\Period;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Money;
use Aicountly\Api\Domain\PaymentRequestService;
use Aicountly\Api\Domain\PaymentService;
use Aicountly\Api\Domain\States;
use Aicountly\Api\Permissions;

/**
 * Dashboard 5 — Pay Pulse.
 *
 * "What should I do next?", answered from this company's own payment data.
 *
 * THE FORECAST IS EXPLAINABLE, NOT LEARNED. Every point on the chart is either
 * something that happened (actual) or a weighting of open requests by how far
 * each has got and how that payer has behaved with this merchant before. The
 * weights are on the screen. A merchant who can see why a number is what it is
 * will act on it; a black box gets ignored, and an ignored forecast is worse
 * than none because it occupies the space where a useful one would go.
 *
 * THE CONFIDENCE RANGE IS WIDER WHEN THERE IS LESS DATA, and the screen says
 * so. A company with forty payments behind it gets a forecast with a wide band
 * and a stated confidence, not a precise-looking line.
 */
final class PulseDashboard
{
    /** @return array<string, mixed> */
    public static function build(Context $ctx, Auth $auth, Period $period): array
    {
        Permissions::assert($ctx, $auth, 'dashboard.pay_pulse');

        if (!InsightEngine::enabled($ctx)) {
            return [
                'enabled' => false,
                'reason'  => 'Pay Pulse is switched off for this company. Turn it on in Settings.',
                'period'  => $period->describe(),
                'metrics' => [],
                'panels'  => [],
            ];
        }

        // Recompute on load. The analysis is a handful of aggregate queries
        // over this company's own rows — cheap enough to be current, which
        // matters more here than anywhere else in the product: a stale
        // recommendation is a wrong one.
        InsightEngine::compute($ctx);

        $sample = self::sampleSize($ctx);

        // Below this, behavioural segments are noise. Said plainly rather than
        // drawn as though it meant something.
        if ($sample['payments'] < 10) {
            return [
                'enabled' => true,
                'warming_up' => true,
                'reason'  => 'Pay Pulse needs more payment activity before it can describe how your payers behave. '
                    . $sample['payments'] . ' ' . ($sample['payments'] === 1 ? 'payment has' : 'payments have') . ' been recorded so far.',
                'period'  => $period->describe(),
                'metrics' => [],
                'panels'  => ['actions' => InsightEngine::headlines($ctx, 6)],
                'generated_at' => gmdate('c'),
            ];
        }

        $forecast = self::forecast($ctx);
        $behaviour = self::behaviour($ctx);
        $reminders = self::reminderEffectiveness($ctx);
        $repeat = self::repeatPayers($ctx);

        return [
            'enabled' => true,
            'warming_up' => false,
            'period'  => $period->describe(),
            'metrics' => [
                Metric::money('expected_7d', 'Expected next 7 days', $forecast['expected_minor'],
                    'Open requests weighted by how far each has got and how that payer has behaved with you before. Not a learned model.',
                    Metric::BASIS_WINDOW, null, 'INR', 'good'),
                Metric::money('high_confidence', 'High-confidence collections', $forecast['high_confidence_minor'],
                    'The part of that forecast from requests where a payer already reached a checkout, or has paid you several times.',
                    Metric::BASIS_WINDOW),
                Metric::money('at_risk', 'At-risk payment requests', $forecast['at_risk_minor'],
                    'Open requests expiring within two days that nobody has opened.',
                    Metric::BASIS_WINDOW, null, 'INR', 'bad'),
                Metric::percent('conversion_lift', 'Conversion after a reminder', $reminders['converted_rate'],
                    'Of the requests that were reminded, the share that were then paid. Measured, not modelled.',
                    null, 'good'),
                Metric::percent('reminder_effectiveness', 'Reminder effectiveness', $reminders['engagement_rate'],
                    'Of the requests whose link was sent from Pay, the share that were then opened.'),
                Metric::percent('repeat_payers', 'Repeat payers', $repeat['rate'],
                    'The share of your payers who have paid you more than once.',
                    null, 'good', $repeat['count'] . ' of ' . $repeat['total'] . ' payers'),
            ],
            'panels' => [
                'forecast'  => $forecast,
                'behaviour' => $behaviour,
                'actions'   => InsightEngine::headlines($ctx, 8),
                'insights'  => InsightEngine::headlines($ctx, 6),
                'sample'    => $sample,
                'can_act'   => Permissions::allows($ctx, $auth, 'pay_pulse.act'),
                'auto_routing' => self::autoRoutingState($ctx),
            ],
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * Actuals behind, weighted expectation ahead.
     *
     * @return array<string, mixed>
     */
    private static function forecast(Context $ctx): array
    {
        // Fourteen days of what actually happened.
        $actual = PaymentReadings::trend(
            $ctx,
            gmdate('Y-m-d H:i:s', strtotime('-13 days midnight')),
            gmdate('Y-m-d H:i:s'),
            'DAY',
        );

        $rows = Db::all(
            'SELECT r.amount_minor, r.paid_minor, r.refunded_minor, r.expires_at, r.created_at,
                    r.first_viewed_at, r.checkout_started_at, p.payment_count
             FROM ' . PaymentRequestService::TABLE . ' r
             LEFT JOIN pay_payers p ON p.payer_id = r.payer_id
             WHERE r.cmp_id = :cmp AND r.status = ANY(:payable)',
            ['cmp' => $ctx->cmpId, 'payable' => '{' . implode(',', States::REQUEST_PAYABLE) . '}'],
        );

        // How long payers typically take, from this company's own history. Used
        // to spread the expected amount over the days ahead rather than putting
        // it all on day one.
        $medianDays = self::medianDaysToPay($ctx);

        $expectedByDay = array_fill(0, 14, 0);
        $expectedTotal = 0;
        $highConfidence = 0;
        $atRisk = 0;

        foreach ($rows as $row) {
            $outstanding = max(0, (int) $row['amount_minor'] - (int) $row['paid_minor'] + (int) $row['refunded_minor']);
            if ($outstanding <= 0) {
                continue;
            }

            $likelihood = match (true) {
                $row['checkout_started_at'] !== null => 0.75,
                $row['first_viewed_at'] !== null     => 0.45,
                default                              => 0.20,
            };
            if ((int) ($row['payment_count'] ?? 0) >= 3) {
                $likelihood = min(0.92, $likelihood + 0.15);
            }

            $expiresAt = $row['expires_at'] === null ? null : strtotime((string) $row['expires_at']);
            if ($expiresAt !== null && $expiresAt < strtotime('+2 days') && $row['first_viewed_at'] === null) {
                $likelihood *= 0.4;
                $atRisk += $outstanding;
            }

            $amount = (int) round($outstanding * $likelihood);
            $expectedTotal += $amount;
            if ($likelihood >= 0.7) {
                $highConfidence += $outstanding;
            }

            // Age the request: one raised a week ago and unpaid is likelier to
            // be paid soon than one raised today, because its own clock is
            // already running.
            $ageDays = (int) floor((time() - strtotime((string) $row['created_at'])) / 86400);
            $landingDay = max(0, min(13, $medianDays - $ageDays));
            $expectedByDay[$landingDay] += $amount;
        }

        $points = [];
        foreach ($actual as $index => $day) {
            $points[] = ['date' => $day['date'], 'actual' => $day['successful'], 'forecast' => null, 'low' => null, 'high' => null];
        }

        // How wide the band is depends on how much history there is.
        $confidence = self::confidenceFor($ctx);
        $spread = (100 - $confidence) / 100;

        $running = 0;
        for ($day = 0; $day < 14; $day++) {
            $running += $expectedByDay[$day];
            $value = $running / 100;
            $points[] = [
                'date'     => gmdate('Y-m-d', strtotime('+' . ($day + 1) . ' days')),
                'actual'   => null,
                'forecast' => round($value, 2),
                'low'      => round($value * (1 - $spread), 2),
                'high'     => round($value * (1 + $spread), 2),
            ];
        }

        $sevenDay = 0;
        for ($day = 0; $day < 7; $day++) {
            $sevenDay += $expectedByDay[$day];
        }

        return [
            'points'     => $points,
            'expected_minor' => $sevenDay,
            'expected_14d_minor' => $expectedTotal,
            'high_confidence_minor' => $highConfidence,
            'at_risk_minor' => $atRisk,
            'confidence' => $confidence,
            // On the screen, so nobody mistakes this for a model.
            'basis'      => 'Open requests weighted by engagement and this payer\'s own history with you, spread over the days your payers typically take.',
            'median_days_to_pay' => $medianDays,
        ];
    }

    /**
     * How this company's payers behave.
     *
     * Five segments, all computed from this company's own attempts.
     *
     * @return list<array<string, mixed>>
     */
    private static function behaviour(Context $ctx): array
    {
        $row = Db::first(
            'WITH first_success AS (
                SELECT a.request_id,
                       MIN(a.paid_at) AS paid_at,
                       COUNT(*) FILTER (WHERE a.status = :failed) AS failures
                FROM ' . PaymentService::TABLE . ' a
                WHERE a.cmp_id = :cmp AND a.request_id IS NOT NULL
                  AND a.created_at >= NOW() - INTERVAL \'90 days\'
                GROUP BY a.request_id
             )
             SELECT
                COUNT(*) FILTER (WHERE f.paid_at IS NOT NULL
                    AND f.paid_at <= r.created_at + INTERVAL \'2 hours\')          AS fast,
                COUNT(*) FILTER (WHERE f.paid_at IS NOT NULL
                    AND f.paid_at >  r.created_at + INTERVAL \'3 days\')           AS delayed,
                COUNT(*) FILTER (WHERE r.status = :partially_paid)                 AS partial,
                COUNT(*) FILTER (WHERE f.failures > 0 AND f.paid_at IS NOT NULL)   AS retry_recovered,
                COUNT(*) FILTER (WHERE f.failures > 0 AND f.paid_at IS NULL)       AS retry_pending,
                COUNT(*)                                                           AS total
             FROM ' . PaymentRequestService::TABLE . ' r
             LEFT JOIN first_success f ON f.request_id = r.request_id
             WHERE r.cmp_id = :cmp AND r.created_at >= NOW() - INTERVAL \'90 days\'',
            ['cmp' => $ctx->cmpId, 'failed' => States::ATTEMPT_FAILED, 'partially_paid' => States::REQUEST_PARTIALLY_PAID],
        ) ?? [];

        $total = max(1, (int) ($row['total'] ?? 0));
        $share = static fn (int $n): float => round(($n / $total) * 100, 1);

        // How many payers stick to one method, which is what "method sensitive"
        // means in practice.
        $methodSensitive = (int) (Db::scalar(
            'SELECT COUNT(*) FROM (
                SELECT payer_id FROM ' . PaymentService::TABLE . '
                WHERE cmp_id = :cmp AND payer_id IS NOT NULL AND status = ANY(:settled)
                  AND paid_at >= NOW() - INTERVAL \'90 days\'
                GROUP BY payer_id
                HAVING COUNT(DISTINCT payment_method) = 1 AND COUNT(*) >= 2
             ) sub',
            ['cmp' => $ctx->cmpId, 'settled' => '{' . implode(',', States::ATTEMPT_SETTLED_MONEY) . '}'],
        ) ?? 0);

        $payers = max(1, (int) (Db::scalar(
            'SELECT COUNT(*) FROM pay_payers WHERE cmp_id = :cmp AND payment_count > 0',
            ['cmp' => $ctx->cmpId],
        ) ?? 0));

        return [
            ['key' => 'fast', 'label' => 'Fast completers', 'share' => $share((int) ($row['fast'] ?? 0)),
             'detail' => 'Pay within 2 hours', 'count' => (int) ($row['fast'] ?? 0)],
            ['key' => 'delayed', 'label' => 'Delayed completers', 'share' => $share((int) ($row['delayed'] ?? 0)),
             'detail' => 'Pay after 3 days or more', 'count' => (int) ($row['delayed'] ?? 0)],
            ['key' => 'partial', 'label' => 'Partial payers', 'share' => $share((int) ($row['partial'] ?? 0)),
             'detail' => 'Pay in instalments', 'count' => (int) ($row['partial'] ?? 0)],
            ['key' => 'method_sensitive', 'label' => 'Method sensitive',
             'share' => round(($methodSensitive / $payers) * 100, 1),
             'detail' => 'Always use the same payment method', 'count' => $methodSensitive],
            ['key' => 'retry_recoverable', 'label' => 'Retry recoverable',
             'share' => $share((int) ($row['retry_recovered'] ?? 0)),
             'detail' => 'Paid after an earlier attempt failed', 'count' => (int) ($row['retry_recovered'] ?? 0)],
        ];
    }

    /** @return array{engagement_rate:?float, converted_rate:?float, sent:int} */
    private static function reminderEffectiveness(Context $ctx): array
    {
        $row = Db::first(
            'SELECT COUNT(DISTINCT l.request_id) AS sent,
                    COUNT(DISTINCT l.request_id) FILTER (WHERE r.first_viewed_at IS NOT NULL) AS viewed,
                    COUNT(DISTINCT l.request_id) FILTER (WHERE r.paid_minor > 0) AS paid
             FROM pay_payment_links l
             JOIN ' . PaymentRequestService::TABLE . ' r ON r.request_id = l.request_id
             WHERE l.cmp_id = :cmp AND l.sent_at IS NOT NULL AND l.sent_at >= NOW() - INTERVAL \'30 days\'',
            ['cmp' => $ctx->cmpId],
        ) ?? [];

        $sent = (int) ($row['sent'] ?? 0);

        return [
            'sent' => $sent,
            // Null rather than zero when nothing was sent. "0% effective" and
            // "we never sent one" are different facts.
            'engagement_rate' => $sent > 0 ? round(((int) ($row['viewed'] ?? 0) / $sent) * 100, 1) : null,
            'converted_rate'  => $sent > 0 ? round(((int) ($row['paid'] ?? 0) / $sent) * 100, 1) : null,
        ];
    }

    /** @return array{rate:?float, count:int, total:int} */
    private static function repeatPayers(Context $ctx): array
    {
        $row = Db::first(
            'SELECT COUNT(*) FILTER (WHERE payment_count > 1) AS repeat,
                    COUNT(*) FILTER (WHERE payment_count > 0) AS total
             FROM pay_payers WHERE cmp_id = :cmp',
            ['cmp' => $ctx->cmpId],
        ) ?? [];

        $total = (int) ($row['total'] ?? 0);

        return [
            'rate'  => $total > 0 ? round(((int) ($row['repeat'] ?? 0) / $total) * 100, 1) : null,
            'count' => (int) ($row['repeat'] ?? 0),
            'total' => $total,
        ];
    }

    /** How long payers typically take, in days. Median rather than mean: one slow payer must not move it. */
    private static function medianDaysToPay(Context $ctx): int
    {
        $median = Db::scalar(
            'SELECT PERCENTILE_CONT(0.5) WITHIN GROUP (
                ORDER BY EXTRACT(EPOCH FROM (a.paid_at - r.created_at)) / 86400
             )
             FROM ' . PaymentService::TABLE . ' a
             JOIN ' . PaymentRequestService::TABLE . ' r ON r.request_id = a.request_id
             WHERE a.cmp_id = :cmp AND a.status = ANY(:settled)
               AND a.paid_at >= NOW() - INTERVAL \'90 days\'',
            ['cmp' => $ctx->cmpId, 'settled' => '{' . implode(',', States::ATTEMPT_SETTLED_MONEY) . '}'],
        );

        if ($median === null) {
            return 3;
        }

        return max(0, min(13, (int) round((float) $median)));
    }

    /**
     * How much to trust the forecast, from how much history there is.
     *
     * Capped at 85. A payment forecast is a forecast, and a number over 90 on a
     * screen invites a merchant to plan against it.
     */
    private static function confidenceFor(Context $ctx): int
    {
        $sample = self::sampleSize($ctx);

        return max(35, min(85, 30 + (int) floor($sample['payments'] / 4) + (int) floor($sample['days'] / 3)));
    }

    /** @return array{payments:int, days:int, payers:int} */
    private static function sampleSize(Context $ctx): array
    {
        $row = Db::first(
            'SELECT COUNT(*) AS payments,
                    COUNT(DISTINCT payer_id) AS payers,
                    COALESCE(EXTRACT(DAY FROM NOW() - MIN(paid_at)), 0) AS days
             FROM ' . PaymentService::TABLE . '
             WHERE cmp_id = :cmp AND status = ANY(:settled)',
            ['cmp' => $ctx->cmpId, 'settled' => '{' . implode(',', States::ATTEMPT_SETTLED_MONEY) . '}'],
        ) ?? [];

        return [
            'payments' => (int) ($row['payments'] ?? 0),
            'payers'   => (int) ($row['payers'] ?? 0),
            'days'     => (int) ($row['days'] ?? 0),
        ];
    }

    /**
     * Whether Pay Pulse may change routing on its own.
     *
     * OFF by default and off for nearly every company. The screen says so
     * explicitly rather than leaving a merchant to wonder whether an AI is
     * moving their money.
     *
     * @return array<string, mixed>
     */
    private static function autoRoutingState(Context $ctx): array
    {
        $settings = \Aicountly\Api\Domain\Settings::for($ctx);
        $enabled = ($settings['auto_routing_enabled'] ?? false) === true
            || ($settings['auto_routing_enabled'] ?? false) === 't';

        return [
            'enabled' => $enabled,
            'detail'  => $enabled
                ? 'Pay Pulse may change routing within your fallback rules. Every change is recorded in the audit trail.'
                : 'Pay Pulse recommends routing changes but does not make them. Turn on auto-optimisation in Settings to let it act.',
        ];
    }
}
