<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\LinkService;
use Aicountly\Api\Domain\PaymentRequestService;
use Aicountly\Api\Domain\PaymentService;
use Aicountly\Api\Domain\RefundService;
use Aicountly\Api\Domain\SettlementService;
use Aicountly\Api\Domain\States;

/**
 * The queries every dashboard is built from.
 *
 * ALL OF THEM READ PAY'S OWN TABLES AND NOTHING ELSE. A dashboard must not
 * fan out to Books, Billing and Sales on every render: five products
 * round-tripped per page load is a page that takes four seconds on a good day
 * and fails whenever any one of them is having a bad minute.
 *
 * That is affordable precisely because of the boundary this product keeps.
 * Every figure on these screens is a payment fact — collected, failed,
 * refunded, settled — and payment facts are Pay's own. The moment a dashboard
 * needs an invoice's line items or a customer's credit limit, it asks the app
 * that owns them, on that one screen, and not in a metric card.
 *
 * `paid_at` IS THE TIME AXIS, not `created_at`. A payment started at 11:58 pm
 * and captured at 12:02 am belongs to the day the money moved, which is the day
 * the merchant will look for it.
 */
final class PaymentReadings
{
    /**
     * Collections in a window, by outcome.
     *
     * One query for the whole card row rather than six: these run on every
     * dashboard load and a query per metric is five extra round trips for
     * numbers that all come off the same table.
     *
     * @return array{collected_minor:int, payment_count:int, failed_count:int,
     *     refunded_minor:int, success_rate:?float, attempts:int, payers:int}
     */
    public static function collections(Context $ctx, string $from, string $to): array
    {
        [$scope, $params] = $ctx->scopeClause('a');

        $row = Db::first(
            'SELECT
                COALESCE(SUM(a.amount_minor)   FILTER (WHERE a.status = ANY(:settled)), 0) AS collected,
                COUNT(*)                       FILTER (WHERE a.status = ANY(:settled))     AS payments,
                COUNT(*)                       FILTER (WHERE a.status = :failed)           AS failed,
                COALESCE(SUM(a.refunded_minor) FILTER (WHERE a.status = ANY(:settled)), 0) AS refunded,
                COUNT(*)                       FILTER (WHERE a.status = ANY(:terminal))    AS decided,
                COUNT(DISTINCT a.payer_id)     FILTER (WHERE a.status = ANY(:settled))     AS payers
             FROM ' . PaymentService::TABLE . ' a
             WHERE ' . $scope . '
               AND COALESCE(a.paid_at, a.created_at) >= :from
               AND COALESCE(a.paid_at, a.created_at) <  :to',
            $params + [
                'settled'  => self::pgArray(States::ATTEMPT_SETTLED_MONEY),
                'terminal' => self::pgArray(States::ATTEMPT_TERMINAL),
                'failed'   => States::ATTEMPT_FAILED,
                'from'     => $from,
                'to'       => $to,
            ],
        ) ?? [];

        $decided = (int) ($row['decided'] ?? 0);
        $payments = (int) ($row['payments'] ?? 0);

        return [
            'collected_minor' => (int) ($row['collected'] ?? 0),
            'payment_count'   => $payments,
            'failed_count'    => (int) ($row['failed'] ?? 0),
            'refunded_minor'  => (int) ($row['refunded'] ?? 0),
            // Null rather than 100 when nobody tried. "No payments" and "every
            // payment worked" are different facts.
            'success_rate'    => $decided > 0 ? round(($payments / $decided) * 100, 1) : null,
            'attempts'        => $decided,
            'payers'          => (int) ($row['payers'] ?? 0),
        ];
    }

    /**
     * The collections trend, bucketed.
     *
     * generate_series fills the gaps, so a day with no payments is a zero bar
     * rather than a missing one — a line chart that silently skips empty days
     * compresses a quiet week into a busy-looking one.
     *
     * @return list<array{date:string, successful:float, pending:float, failed:float, refunded:float}>
     */
    public static function trend(Context $ctx, string $from, string $to, string $bucket = 'DAY'): array
    {
        [$scope, $params] = $ctx->scopeClause('a');
        $unit = match ($bucket) { 'MONTH' => 'month', 'WEEK' => 'week', default => 'day' };

        $rows = Db::all(
            "WITH buckets AS (
                SELECT generate_series(
                    date_trunc('$unit', :from::timestamptz),
                    date_trunc('$unit', :to::timestamptz),
                    ('1 $unit')::interval
                ) AS bucket_at
             )
             SELECT b.bucket_at,
                COALESCE(SUM(a.amount_minor)   FILTER (WHERE a.status = ANY(:settled)), 0) AS successful,
                COALESCE(SUM(a.amount_minor)   FILTER (WHERE a.status = ANY(:open)), 0)    AS pending,
                COALESCE(SUM(a.amount_minor)   FILTER (WHERE a.status = :failed), 0)       AS failed,
                COALESCE(SUM(a.refunded_minor) FILTER (WHERE a.status = ANY(:settled)), 0) AS refunded
             FROM buckets b
             LEFT JOIN " . PaymentService::TABLE . " a
                    ON date_trunc('$unit', COALESCE(a.paid_at, a.created_at)) = b.bucket_at
                   AND " . $scope . "
                   AND COALESCE(a.paid_at, a.created_at) >= :from
                   AND COALESCE(a.paid_at, a.created_at) <  :to
             GROUP BY b.bucket_at
             ORDER BY b.bucket_at",
            $params + [
                'settled' => self::pgArray(States::ATTEMPT_SETTLED_MONEY),
                'open'    => self::pgArray(States::ATTEMPT_OPEN),
                'failed'  => States::ATTEMPT_FAILED,
                'from'    => $from,
                'to'      => $to,
            ],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'date'       => gmdate('Y-m-d', strtotime((string) $row['bucket_at'])),
                'successful' => self::toMajor((int) $row['successful']),
                'pending'    => self::toMajor((int) $row['pending']),
                'failed'     => self::toMajor((int) $row['failed']),
                'refunded'   => self::toMajor((int) $row['refunded']),
            ];
        }

        return $out;
    }

    /**
     * Which methods the money came in through.
     *
     * @return list<array{method:string, amount:float, count:int, share:float}>
     */
    public static function methodMix(Context $ctx, string $from, string $to): array
    {
        [$scope, $params] = $ctx->scopeClause('a');

        $rows = Db::all(
            'SELECT COALESCE(a.payment_method, \'OTHER\') AS method,
                    SUM(a.amount_minor) AS amount, COUNT(*) AS count
             FROM ' . PaymentService::TABLE . ' a
             WHERE ' . $scope . ' AND a.status = ANY(:settled)
               AND a.paid_at >= :from AND a.paid_at < :to
             GROUP BY 1 ORDER BY amount DESC',
            $params + ['settled' => self::pgArray(States::ATTEMPT_SETTLED_MONEY), 'from' => $from, 'to' => $to],
        );

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['amount'];
        }

        $out = [];
        foreach ($rows as $row) {
            $amount = (int) $row['amount'];
            $out[] = [
                'method' => (string) $row['method'],
                'label'  => self::methodLabel((string) $row['method']),
                'amount' => self::toMajor($amount),
                'count'  => (int) $row['count'],
                'share'  => $total > 0 ? round(($amount / $total) * 100, 1) : 0.0,
            ];
        }

        return $out;
    }

    /**
     * How each provider performed.
     *
     * Deliberately distinguishes Aicountly Managed from a merchant's own
     * gateways, because that is the comparison a merchant in hybrid mode
     * actually wants to make.
     *
     * @return list<array<string, mixed>>
     */
    public static function providerPerformance(Context $ctx, string $from, string $to): array
    {
        [$scope, $params] = $ctx->scopeClause('a');

        $rows = Db::all(
            'SELECT a.connection_id, a.provider_code, a.provider_mode,
                    c.display_name, c.status AS connection_status,
                    COUNT(*) FILTER (WHERE a.status = ANY(:settled))  AS successes,
                    COUNT(*) FILTER (WHERE a.status = ANY(:terminal)) AS decided,
                    COALESCE(SUM(a.amount_minor) FILTER (WHERE a.status = ANY(:settled)), 0) AS collected
             FROM ' . PaymentService::TABLE . ' a
             LEFT JOIN pay_provider_connections c ON c.connection_id = a.connection_id
             WHERE ' . $scope . '
               AND COALESCE(a.paid_at, a.created_at) >= :from
               AND COALESCE(a.paid_at, a.created_at) <  :to
               AND a.provider_code IS NOT NULL
             GROUP BY a.connection_id, a.provider_code, a.provider_mode, c.display_name, c.status
             ORDER BY collected DESC',
            $params + [
                'settled'  => self::pgArray(States::ATTEMPT_SETTLED_MONEY),
                'terminal' => self::pgArray(States::ATTEMPT_TERMINAL),
                'from' => $from, 'to' => $to,
            ],
        );

        $latency = \Aicountly\Api\Payments\Providers\ProviderMetrics::window($ctx->cmpId, 60 * 24 * 7);

        $out = [];
        foreach ($rows as $row) {
            $decided = (int) $row['decided'];
            $connectionId = $row['connection_id'] === null ? 0 : (int) $row['connection_id'];

            $out[] = [
                'connection_id' => $connectionId,
                'provider'      => (string) $row['provider_code'],
                'mode'          => (string) $row['provider_mode'],
                'name'          => $row['display_name'] !== null
                    ? (string) $row['display_name']
                    : ((string) $row['provider_mode'] === 'MANAGED'
                        ? 'Aicountly Managed'
                        : \Aicountly\Api\Payments\Providers\ProviderRegistry::displayNameFor((string) $row['provider_code'])),
                'collected'     => self::toMajor((int) $row['collected']),
                'payments'      => (int) $row['successes'],
                'success_rate'  => $decided > 0 ? round(((int) $row['successes'] / $decided) * 100, 1) : null,
                'avg_latency_ms' => $latency[$connectionId]['avg_latency_ms'] ?? null,
                'connection_status' => $row['connection_status'] === null ? null : (string) $row['connection_status'],
            ];
        }

        return $out;
    }

    /**
     * Open payment requests, grouped by the app that raised them.
     *
     * The count and amount come from Pay's own request rows. What is NOT done
     * here is asking Books for each invoice's current balance — that would be
     * one HTTP call per row on a dashboard.
     *
     * @return list<array{source:string, label:string, count:int, amount:float}>
     */
    public static function openRequestsBySource(Context $ctx): array
    {
        [$scope, $params] = $ctx->scopeClause('r');

        $rows = Db::all(
            'SELECT r.source_app,
                    COUNT(*) AS count,
                    COALESCE(SUM(r.amount_minor - r.paid_minor + r.refunded_minor), 0) AS outstanding
             FROM ' . PaymentRequestService::TABLE . ' r
             WHERE ' . $scope . ' AND r.status = ANY(:payable)
             GROUP BY r.source_app
             ORDER BY outstanding DESC',
            $params + ['payable' => self::pgArray(States::REQUEST_PAYABLE)],
        );

        $out = [];
        foreach ($rows as $row) {
            $app = (string) $row['source_app'];
            $out[] = [
                'source' => $app,
                'label'  => \Aicountly\Api\Payments\Sources\SourceRegistry::for($app)->displayName(),
                'count'  => (int) $row['count'],
                'amount' => self::toMajor(max(0, (int) $row['outstanding'])),
            ];
        }

        return $out;
    }

    /**
     * The conversion funnel.
     *
     * Each stage counts requests that reached AT LEAST that far, so the funnel
     * is monotonic by construction. Counting "currently at this stage" instead
     * produces a funnel that goes up in the middle, which is how a funnel loses
     * a reader's trust.
     *
     * @return array<string, mixed>
     */
    public static function funnel(Context $ctx, string $from, string $to): array
    {
        [$scope, $params] = $ctx->scopeClause('r');

        $row = Db::first(
            'SELECT
                COUNT(*)                                                       AS created,
                -- Sent from Pay, OR opened by somebody. A merchant who copies
                -- the URL into their own WhatsApp has delivered it and we
                -- cannot see that — but if the page was viewed, it reached
                -- somebody. Counting only what Pay sent would put a drop-off in
                -- the funnel that never happened, and a funnel that rises in
                -- the middle is one nobody trusts again.
                COUNT(*) FILTER (WHERE r.first_viewed_at IS NOT NULL OR EXISTS (
                    SELECT 1 FROM ' . LinkService::TABLE . ' l WHERE l.request_id = r.request_id AND l.sent_at IS NOT NULL
                ))                                                             AS delivered,
                COUNT(*) FILTER (WHERE r.first_viewed_at IS NOT NULL)           AS viewed,
                COUNT(*) FILTER (WHERE r.checkout_started_at IS NOT NULL)       AS checkout_started,
                COUNT(*) FILTER (WHERE EXISTS (
                    SELECT 1 FROM ' . PaymentService::TABLE . ' a WHERE a.request_id = r.request_id
                ))                                                             AS attempted,
                COUNT(*) FILTER (WHERE r.paid_minor > 0)                        AS paid,
                COUNT(*) FILTER (WHERE r.status = :fully_paid)                  AS fully_paid,
                COUNT(*) FILTER (WHERE r.status = :partially_paid)              AS partially_paid,
                COALESCE(SUM(r.amount_minor), 0)                                AS asked,
                COALESCE(SUM(r.paid_minor), 0)                                  AS collected
             FROM ' . PaymentRequestService::TABLE . ' r
             WHERE ' . $scope . ' AND r.created_at >= :from AND r.created_at < :to',
            $params + [
                'fully_paid'     => States::REQUEST_PAID,
                'partially_paid' => States::REQUEST_PARTIALLY_PAID,
                'from' => $from, 'to' => $to,
            ],
        ) ?? [];

        $created = (int) ($row['created'] ?? 0);
        $share = static fn (int $n): float => $created > 0 ? round(($n / $created) * 100, 1) : 0.0;

        // Delivered can only be counted where a link was actually sent through
        // Pay. A merchant who copies the URL into their own WhatsApp has
        // delivered it and we cannot know, so the stage is marked as such
        // rather than reported as a drop-off that did not happen.
        $delivered = (int) ($row['delivered'] ?? 0);

        return [
            'stages' => [
                ['key' => 'created',   'label' => 'Requests created',  'count' => $created, 'share' => $created > 0 ? 100.0 : 0.0],
                ['key' => 'delivered', 'label' => 'Delivered',         'count' => $delivered, 'share' => $share($delivered),
                 'note' => 'Counted where Pay sent the link, or where the page was opened.'],
                ['key' => 'viewed',    'label' => 'Viewed',            'count' => (int) ($row['viewed'] ?? 0), 'share' => $share((int) ($row['viewed'] ?? 0))],
                ['key' => 'checkout',  'label' => 'Checkout started',  'count' => (int) ($row['checkout_started'] ?? 0), 'share' => $share((int) ($row['checkout_started'] ?? 0))],
                ['key' => 'attempted', 'label' => 'Payment attempted', 'count' => (int) ($row['attempted'] ?? 0), 'share' => $share((int) ($row['attempted'] ?? 0))],
                ['key' => 'paid',      'label' => 'Successfully paid', 'count' => (int) ($row['fully_paid'] ?? 0), 'share' => $share((int) ($row['fully_paid'] ?? 0))],
            ],
            'created'         => $created,
            'fully_paid'      => (int) ($row['fully_paid'] ?? 0),
            'partially_paid'  => (int) ($row['partially_paid'] ?? 0),
            'conversion_rate' => $created > 0 ? round(((int) ($row['fully_paid'] ?? 0) / $created) * 100, 1) : null,
            'asked'           => self::toMajor((int) ($row['asked'] ?? 0)),
            'collected'       => self::toMajor((int) ($row['collected'] ?? 0)),
        ];
    }

    /**
     * How each source app's requests converted.
     *
     * @return list<array<string, mixed>>
     */
    public static function sourcePerformance(Context $ctx, string $from, string $to): array
    {
        [$scope, $params] = $ctx->scopeClause('r');

        $rows = Db::all(
            'SELECT r.source_app,
                    COUNT(*) AS requests,
                    COALESCE(SUM(r.amount_minor), 0) AS asked,
                    COALESCE(SUM(r.paid_minor), 0) AS collected,
                    COUNT(*) FILTER (WHERE r.status = :paid) AS paid
             FROM ' . PaymentRequestService::TABLE . ' r
             WHERE ' . $scope . ' AND r.created_at >= :from AND r.created_at < :to
             GROUP BY r.source_app ORDER BY asked DESC',
            $params + ['paid' => States::REQUEST_PAID, 'from' => $from, 'to' => $to],
        );

        $out = [];
        foreach ($rows as $row) {
            $requests = (int) $row['requests'];
            $app = (string) $row['source_app'];
            $out[] = [
                'source'       => $app,
                'label'        => \Aicountly\Api\Payments\Sources\SourceRegistry::for($app)->displayName(),
                'requests'     => $requests,
                'asked'        => self::toMajor((int) $row['asked']),
                'collected'    => self::toMajor((int) $row['collected']),
                'success_rate' => $requests > 0 ? round(((int) $row['paid'] / $requests) * 100, 1) : null,
            ];
        }

        return $out;
    }

    /**
     * How well each delivery channel converted.
     *
     * @return list<array<string, mixed>>
     */
    public static function channelEffectiveness(Context $ctx, string $from, string $to): array
    {
        $rows = Db::all(
            'SELECT l.link_kind, l.channel,
                    COUNT(DISTINCT l.link_id) AS links,
                    COALESCE(SUM(l.view_count), 0) AS views,
                    COUNT(DISTINCT r.request_id) FILTER (WHERE r.paid_minor > 0) AS paid,
                    COALESCE(SUM(DISTINCT r.paid_minor), 0) AS collected
             FROM ' . LinkService::TABLE . ' l
             JOIN ' . PaymentRequestService::TABLE . ' r ON r.request_id = l.request_id
             WHERE l.cmp_id = :cmp AND l.created_at >= :from AND l.created_at < :to
             GROUP BY l.link_kind, l.channel
             ORDER BY collected DESC',
            ['cmp' => $ctx->cmpId, 'from' => $from, 'to' => $to],
        );

        $out = [];
        foreach ($rows as $row) {
            $links = (int) $row['links'];
            $out[] = [
                'kind'         => (string) $row['link_kind'],
                'channel'      => (string) $row['channel'],
                'label'        => self::channelLabel((string) $row['channel']),
                'sent'         => $links,
                'views'        => (int) $row['views'],
                'paid'         => (int) $row['paid'],
                'collected'    => self::toMajor((int) $row['collected']),
                'success_rate' => $links > 0 ? round(((int) $row['paid'] / $links) * 100, 1) : null,
            ];
        }

        return $out;
    }

    /**
     * Settlement totals for the window.
     *
     * @return array<string, mixed>
     */
    public static function settlementTotals(Context $ctx, string $from, string $to): array
    {
        $row = Db::first(
            'SELECT
                COALESCE(SUM(gross_minor), 0)        AS gross,
                COALESCE(SUM(refund_minor), 0)       AS refunds,
                COALESCE(SUM(provider_fee_minor + provider_tax_minor), 0) AS fees,
                COALESCE(SUM(expected_net_minor), 0) AS expected,
                COALESCE(SUM(settled_net_minor), 0)  AS settled,
                COALESCE(SUM(ABS(difference_minor)), 0) AS difference,
                COUNT(*) FILTER (WHERE settled_net_minor IS NULL) AS awaiting,
                COALESCE(SUM(expected_net_minor) FILTER (WHERE settled_net_minor IS NULL), 0) AS awaiting_amount
             FROM ' . SettlementService::TABLE . '
             WHERE cmp_id = :cmp AND settlement_date >= :from::date AND settlement_date <= :to::date',
            ['cmp' => $ctx->cmpId, 'from' => $from, 'to' => $to],
        ) ?? [];

        return [
            'gross_minor'     => (int) ($row['gross'] ?? 0),
            'refunds_minor'   => (int) ($row['refunds'] ?? 0),
            'fees_minor'      => (int) ($row['fees'] ?? 0),
            'expected_minor'  => (int) ($row['expected'] ?? 0),
            'settled_minor'   => (int) ($row['settled'] ?? 0),
            'difference_minor' => (int) ($row['difference'] ?? 0),
            'awaiting_batches' => (int) ($row['awaiting'] ?? 0),
            'awaiting_minor'  => (int) ($row['awaiting_amount'] ?? 0),
        ];
    }

    /** Payments recently received, for the activity panel. @return list<array<string, mixed>> */
    public static function recentActivity(Context $ctx, int $limit = 10): array
    {
        [$scope, $params] = $ctx->scopeClause('a');

        $rows = Db::all(
            'SELECT a.*, p.display_name AS payer_name, r.source_app, r.source_reference
             FROM ' . PaymentService::TABLE . ' a
             LEFT JOIN pay_payers p ON p.payer_id = a.payer_id
             LEFT JOIN ' . PaymentRequestService::TABLE . ' r ON r.request_id = a.request_id
             WHERE ' . $scope . '
             ORDER BY COALESCE(a.paid_at, a.created_at) DESC
             LIMIT :limit',
            $params + ['limit' => $limit],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'payment_id' => (string) $row['attempt_uuid'],
                'at'         => (string) ($row['paid_at'] ?? $row['created_at']),
                'payer'      => $row['payer_name'] === null ? 'Payer' : (string) $row['payer_name'],
                'amount'     => self::toMajor((int) $row['amount_minor']),
                'currency'   => (string) $row['currency'],
                'status'     => (string) $row['status'],
                'status_label' => States::label((string) $row['status']),
                'source'     => $row['source_app'] === null ? 'PAY' : (string) $row['source_app'],
                'reference'  => $row['source_reference'] === null ? null : (string) $row['source_reference'],
                'method'     => $row['payment_method'] === null ? null : (string) $row['payment_method'],
            ];
        }

        return $out;
    }

    /**
     * Failed payments that could reasonably be recovered.
     *
     * The rule is deliberately narrow: the LATEST attempt on the request
     * failed, the request is still collectable, and it has not expired. A
     * request whose later attempt succeeded is not in recovery, and offering to
     * chase somebody who has already paid is the fastest way to lose a customer.
     *
     * @return list<array<string, mixed>>
     */
    public static function recoveryQueue(Context $ctx, int $limit = 20): array
    {
        [$scope, $params] = $ctx->scopeClause('r');

        $rows = Db::all(
            'SELECT r.request_uuid, r.source_reference, r.amount_minor, r.paid_minor, r.refunded_minor,
                    r.currency, r.expires_at, p.display_name AS payer_name,
                    last.failure_code, last.failure_reason, last.payment_method, last.created_at AS failed_at,
                    (SELECT COUNT(*) FROM ' . PaymentService::TABLE . ' x WHERE x.request_id = r.request_id) AS attempts
             FROM ' . PaymentRequestService::TABLE . ' r
             LEFT JOIN pay_payers p ON p.payer_id = r.payer_id
             JOIN LATERAL (
                SELECT a.status, a.failure_code, a.failure_reason, a.payment_method, a.created_at
                FROM ' . PaymentService::TABLE . ' a
                WHERE a.request_id = r.request_id
                ORDER BY a.created_at DESC LIMIT 1
             ) last ON TRUE
             WHERE ' . $scope . '
               AND r.status = ANY(:payable)
               AND last.status = :failed
               AND (r.expires_at IS NULL OR r.expires_at > NOW())
             ORDER BY (r.amount_minor - r.paid_minor) DESC
             LIMIT :limit',
            $params + [
                'payable' => self::pgArray(States::REQUEST_PAYABLE),
                'failed'  => States::ATTEMPT_FAILED,
                'limit'   => $limit,
            ],
        );

        $out = [];
        foreach ($rows as $row) {
            $outstanding = max(0, (int) $row['amount_minor'] - (int) $row['paid_minor'] + (int) $row['refunded_minor']);
            $out[] = [
                'payment_request_id' => (string) $row['request_uuid'],
                'reference'   => $row['source_reference'] === null ? null : (string) $row['source_reference'],
                'payer'       => $row['payer_name'] === null ? 'Payer' : (string) $row['payer_name'],
                'outstanding' => self::toMajor($outstanding),
                'currency'    => (string) $row['currency'],
                'attempts'    => (int) $row['attempts'],
                'last_method' => $row['payment_method'] === null ? null : (string) $row['payment_method'],
                'last_failure' => $row['failure_reason'] === null ? null : (string) $row['failure_reason'],
                'failed_at'   => (string) $row['failed_at'],
                'expires_at'  => $row['expires_at'] === null ? null : (string) $row['expires_at'],
            ];
        }

        return $out;
    }

    /** Refunds and disputes needing attention. @return array<string, int> */
    public static function exposure(Context $ctx): array
    {
        $refunds = Db::first(
            'SELECT COUNT(*) FILTER (WHERE status = ANY(:open)) AS open,
                    COUNT(*) FILTER (WHERE status = :failed)    AS failed
             FROM ' . RefundService::TABLE . ' WHERE cmp_id = :cmp',
            ['cmp' => $ctx->cmpId, 'open' => self::pgArray(States::REFUND_OPEN), 'failed' => States::REFUND_FAILED],
        ) ?? [];

        $disputes = (int) (Db::scalar(
            'SELECT COUNT(*) FROM pay_disputes WHERE cmp_id = :cmp AND status IN (:open, :review)',
            ['cmp' => $ctx->cmpId, 'open' => States::DISPUTE_OPEN, 'review' => States::DISPUTE_UNDER_REVIEW],
        ) ?? 0);

        return [
            'refunds_open'   => (int) ($refunds['open'] ?? 0),
            'refunds_failed' => (int) ($refunds['failed'] ?? 0),
            'disputes_open'  => $disputes,
        ];
    }

    /** Links expiring soon that still have money on them. */
    public static function expiringSoon(Context $ctx, int $days = 3): array
    {
        [$scope, $params] = $ctx->scopeClause('r');

        $row = Db::first(
            'SELECT COUNT(*) AS count, COALESCE(SUM(r.amount_minor - r.paid_minor + r.refunded_minor), 0) AS amount
             FROM ' . PaymentRequestService::TABLE . ' r
             WHERE ' . $scope . ' AND r.status = ANY(:payable)
               AND r.expires_at IS NOT NULL
               AND r.expires_at BETWEEN NOW() AND NOW() + (:days || \' days\')::interval',
            $params + ['payable' => self::pgArray(States::REQUEST_PAYABLE), 'days' => $days],
        ) ?? [];

        return ['count' => (int) ($row['count'] ?? 0), 'amount_minor' => max(0, (int) ($row['amount'] ?? 0))];
    }

    // -----------------------------------------------------------------------

    /** PostgreSQL array literal for `= ANY(:param)`. */
    public static function pgArray(array $values): string
    {
        return '{' . implode(',', $values) . '}';
    }

    private static function toMajor(int $minor): float
    {
        return $minor / 100;
    }

    public static function methodLabel(string $method): string
    {
        return match ($method) {
            'UPI'        => 'UPI',
            'CARD'       => 'Cards',
            'NETBANKING' => 'Net Banking',
            'WALLET'     => 'Wallets',
            'EMI'        => 'EMI',
            'PAYLATER'   => 'Pay Later',
            default      => 'Others',
        };
    }

    public static function channelLabel(string $channel): string
    {
        return match ($channel) {
            'LINK'     => 'Payment Link',
            'WHATSAPP' => 'WhatsApp',
            'EMAIL'    => 'Email',
            'SMS'      => 'SMS',
            'QR'       => 'QR Code',
            'IN_APP'   => 'In-App',
            'EMBEDDED' => 'Embedded Checkout',
            default    => ucfirst(strtolower($channel)),
        };
    }
}
