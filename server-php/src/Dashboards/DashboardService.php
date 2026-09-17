<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Money;
use Aicountly\Api\Domain\ReconciliationService;
use Aicountly\Api\Domain\SettlementService;
use Aicountly\Api\Domain\States;
use Aicountly\Api\Payments\Providers\ProviderMetrics;
use Aicountly\Api\Payments\Providers\ProviderRegistry;
use Aicountly\Api\PayPulse\InsightEngine;
use Aicountly\Api\Permissions;

/**
 * The five dashboards.
 *
 * FIVE ENDPOINTS, NOT ONE. Each is a separate method with its own permission
 * check and its own queries, because they answer five different questions and
 * are read by different people:
 *
 *   Overview     What happened with my payments?
 *   Collections  How well are requests turning into money?
 *   Gateways     How are my providers performing?
 *   Settlements  Did the money arrive and reconcile?
 *   Pay Pulse    What should I do next?
 *
 * A single endpoint returning all five would make every screen pay for the
 * other four — the settlements queries alone are the slowest in the product —
 * and would hand a collections clerk the gateway health data their role
 * deliberately withholds.
 *
 * THE PERMISSION CHECK COMES FIRST, before any read. A dashboard the user may
 * not open is a 403, not an empty screen: the tab bar and the URL have to agree
 * about who may see what, or the URL is a way around the tab bar.
 */
final class DashboardService
{
    /**
     * Dashboard 1 — Overview / Collection Command Centre.
     *
     * @return array<string, mixed>
     */
    public static function overview(Context $ctx, Auth $auth, Period $period): array
    {
        Permissions::assert($ctx, $auth, 'dashboard.overview');

        $now = PaymentReadings::collections($ctx, $period->from, $period->to);
        $before = PaymentReadings::collections($ctx, $period->previousFrom, $period->previousTo);

        $openRequests = Db::first(
            'SELECT COUNT(*) AS count, COALESCE(SUM(amount_minor - paid_minor + refunded_minor), 0) AS outstanding
             FROM pay_payment_requests
             WHERE cmp_id = :cmp AND status = ANY(:payable)',
            ['cmp' => $ctx->cmpId, 'payable' => PaymentReadings::pgArray(States::REQUEST_PAYABLE)],
        ) ?? [];

        $settlement = PaymentReadings::settlementTotals($ctx, gmdate('Y-m-d', strtotime('-60 days')), gmdate('Y-m-d', strtotime('+30 days')));
        $exposure = PaymentReadings::exposure($ctx);

        return [
            'period'  => $period->describe(),
            'metrics' => [
                Metric::money(
                    'collected', 'Collected ' . strtolower($period->label),
                    $now['collected_minor'],
                    'Money that actually reached a provider, grouped by when it was paid rather than when the request was raised.',
                    Metric::BASIS_PERIOD,
                    $before['collected_minor'],
                ),
                Metric::percent(
                    'success_rate', 'Success rate',
                    $now['success_rate'],
                    'Of the payments that finished one way or the other, the share that succeeded. Journeys still in progress are not counted either way.',
                    $before['success_rate'],
                ),
                Metric::count(
                    'open_requests', 'Open payment requests',
                    (int) ($openRequests['count'] ?? 0),
                    'Requests that can still take money, across every source app.',
                    null,
                    'neutral',
                    Money::minor(max(0, (int) ($openRequests['outstanding'] ?? 0)))->format() . ' outstanding',
                ),
                Metric::money(
                    'settlement_awaited', 'Settlement awaited',
                    $settlement['awaiting_minor'],
                    'What providers say they will send and the bank has not confirmed yet.',
                    Metric::BASIS_AS_OF,
                    null,
                    'INR',
                    'neutral',
                    $settlement['awaiting_batches'] . ' ' . ($settlement['awaiting_batches'] === 1 ? 'batch' : 'batches'),
                ),
                Metric::count(
                    'failed', 'Failed payments',
                    $now['failed_count'],
                    'Payments that were attempted and did not go through in this period.',
                    $before['failed_count'],
                    // A rise here is bad news, and the card must not be green.
                    'bad',
                ),
                Metric::count(
                    'refund_exceptions', 'Refund exceptions',
                    $exposure['refunds_open'] + $exposure['refunds_failed'],
                    'Refunds waiting for approval, in progress, or that the provider refused.',
                    null,
                    'bad',
                    $exposure['disputes_open'] > 0 ? $exposure['disputes_open'] . ' open disputes' : null,
                ),
            ],
            'panels' => [
                'trend'           => PaymentReadings::trend($ctx, $period->from, $period->to, $period->bucket()),
                'method_mix'      => PaymentReadings::methodMix($ctx, $period->from, $period->to),
                'providers'       => PaymentReadings::providerPerformance($ctx, $period->from, $period->to),
                'open_by_source'  => PaymentReadings::openRequestsBySource($ctx),
                'recent_activity' => PaymentReadings::recentActivity($ctx, 8),
                'action_centre'   => self::actionCentre($ctx),
            ],
            // The compact strip. Full Pay Pulse lives on its own dashboard; this
            // is three lines, and it is read-only.
            'pulse'   => InsightEngine::headlines($ctx, 4),
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * Dashboard 2 — Collections.
     *
     * @return array<string, mixed>
     */
    public static function collections(Context $ctx, Auth $auth, Period $period): array
    {
        Permissions::assert($ctx, $auth, 'dashboard.collections');

        $funnel = PaymentReadings::funnel($ctx, $period->from, $period->to);
        $before = PaymentReadings::funnel($ctx, $period->previousFrom, $period->previousTo);

        $stage = static fn (array $f, string $key): int => (int) (
            current(array_filter($f['stages'], static fn (array $s) => $s['key'] === $key))['count'] ?? 0
        );

        return [
            'period'  => $period->describe(),
            'metrics' => [
                Metric::count('requests_created', 'Requests created', $funnel['created'],
                    'Payment requests raised in this period, from every source.', $before['created']),
                Metric::count('delivered', 'Delivered', $stage($funnel, 'delivered'),
                    'Requests whose link was sent from Pay. A merchant who copies the URL elsewhere cannot be counted here.',
                    $stage($before, 'delivered')),
                Metric::count('viewed', 'Viewed', $stage($funnel, 'viewed'),
                    'Requests whose payment page was opened at least once.', $stage($before, 'viewed')),
                Metric::count('checkout_started', 'Checkout started', $stage($funnel, 'checkout'),
                    'Requests where a payer got as far as a provider checkout.', $stage($before, 'checkout')),
                Metric::count('paid', 'Successfully paid', $funnel['fully_paid'],
                    'Requests paid in full.', $before['fully_paid']),
                Metric::count('partial_or_failed', 'Part paid or failed', $funnel['partially_paid'],
                    'Requests that took some money but are not settled, plus those whose last attempt failed.',
                    $before['partially_paid'], 'bad'),
            ],
            'panels' => [
                'funnel'      => $funnel,
                'by_source'   => PaymentReadings::sourcePerformance($ctx, $period->from, $period->to),
                'by_channel'  => PaymentReadings::channelEffectiveness($ctx, $period->from, $period->to),
                'recovery'    => PaymentReadings::recoveryQueue($ctx, 10),
                // What the create/record forms need to render. Sent with the
                // dashboard so the forms are usable on first paint rather than
                // after three more round trips.
                'sources'     => \Aicountly\Api\Payments\Sources\SourceRegistry::catalog(),
                'can_create'  => Permissions::allows($ctx, $auth, 'payment_requests.create'),
                'can_record_external' => Permissions::allows($ctx, $auth, 'external_payment.record'),
                'external_methods' => \Aicountly\Api\Domain\ExternalPaymentService::METHODS,
            ],
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * Dashboard 3 — Gateways & Routing.
     *
     * SCOPED TO THIS COMPANY, ALWAYS. This screen shows the merchant's own
     * providers, their own KYC and their own traffic. It must never show
     * platform-wide statistics, other merchants' activation queues or anybody
     * else's KYC — that is Aicountly's internal administration and has no place
     * in a customer's dashboard.
     *
     * @return array<string, mixed>
     */
    public static function gateways(Context $ctx, Auth $auth, Period $period): array
    {
        Permissions::assert($ctx, $auth, 'dashboard.gateways');

        $connections = ProviderRegistry::connectionsFor($ctx);
        $health = ProviderMetrics::window($ctx->cmpId, 60 * 24);
        $collections = PaymentReadings::collections($ctx, $period->from, $period->to);
        $before = PaymentReadings::collections($ctx, $period->previousFrom, $period->previousTo);

        $providers = [];
        $activeCount = 0;
        foreach ($connections as $entry) {
            $connection = $entry['connection'];
            $id = (int) $connection['connection_id'];
            $provider = $entry['provider'];
            $isActive = (string) $connection['status'] === 'ACTIVE';
            $activeCount += $isActive ? 1 : 0;

            $providers[] = [
                'connection_id'   => (string) $connection['connection_uuid'],
                'provider'        => (string) $connection['provider_code'],
                'name'            => (string) $connection['display_name'],
                'mode'            => (string) $connection['provider_mode'],
                'status'          => (string) $connection['status'],
                'status_reason'   => $connection['status_reason'] === null ? null : (string) $connection['status_reason'],
                'environment'     => (string) $connection['environment'],
                'is_primary'      => $connection['is_primary'] === true || $connection['is_primary'] === 't',
                'methods'         => Db::jsonColumn($connection['supported_methods'] ?? null)
                    ?: ($provider?->supportedMethods() ?? []),
                'currencies'      => Db::jsonColumn($connection['supported_currencies'] ?? null)
                    ?: ($provider?->supportedCurrencies() ?? []),
                'capabilities'    => $provider?->capabilities() ?? [],
                'success_rate'    => $health[$id]['success_rate'] ?? null,
                'avg_latency_ms'  => $health[$id]['avg_latency_ms'] ?? null,
                'last_error_code' => $health[$id]['last_error_code'] ?? null,
                'settlement_cycle' => $connection['settlement_cycle'] === null ? null : (string) $connection['settlement_cycle'],
                // "Configured" and a masked tail. Never a value.
                'credentials'     => ProviderRegistry::credentialStatus($id),
                'webhook_verified' => $connection['webhook_verified_at'] !== null,
                'usable'          => $provider !== null && $isActive,
            ];
        }

        $managed = self::managedStatus($ctx);
        $technical = self::technicalHealth($ctx);

        return [
            'period'  => $period->describe(),
            'metrics' => [
                Metric::count('connected_providers', 'Connected providers', $activeCount,
                    'Payment providers this company can currently collect through.', null, 'neutral',
                    count($connections) > $activeCount ? (count($connections) - $activeCount) . ' not active' : null),
                [
                    // Not a number, so not a Metric. The card renders the state.
                    'id'    => 'managed_status',
                    'label' => 'Aicountly Managed',
                    'value' => null,
                    'format' => 'state',
                    'status' => 'ready',
                    'state'  => $managed['state'],
                    'state_label' => $managed['label'],
                    'detail' => $managed['detail'],
                    'definition' => 'Whether payments through Aicountly Managed are available to this company.',
                    'basis' => Metric::BASIS_AS_OF,
                    'comparison' => null,
                ],
                [
                    'id'    => 'managed_kyc',
                    'label' => 'Managed KYC status',
                    'value' => null,
                    'format' => 'state',
                    'status' => 'ready',
                    'state'  => $managed['kyc_status'],
                    'state_label' => $managed['kyc_label'],
                    'detail' => $managed['kyc_detail'],
                    'definition' => 'What the payment partner says about this company\'s verification. Aicountly does not decide it.',
                    'basis' => Metric::BASIS_AS_OF,
                    'comparison' => null,
                ],
                Metric::percent('success_rate', 'Payment success rate', $collections['success_rate'],
                    'Of the payments that finished, the share that succeeded, across every provider.',
                    $before['success_rate']),
                Metric::count('avg_processing_ms', 'Average processing time',
                    (int) ($technical['avg_latency_ms'] ?? 0),
                    'How long a provider call has taken on average over the last day, in milliseconds.',
                    null, 'bad', 'milliseconds'),
                Metric::percent('webhook_health', 'Webhook health', $technical['webhook_success_rate'],
                    'Of the provider callbacks received in the last day, the share whose signature verified and which processed cleanly.'),
            ],
            'panels' => [
                'providers'      => $providers,
                'routing_rules'  => self::routingRules($ctx),
                'health_matrix'  => self::healthMatrix($ctx),
                'managed'        => $managed,
                'technical'      => $technical,
                'traffic'        => PaymentReadings::providerPerformance($ctx, $period->from, $period->to),
                'available_providers' => ProviderRegistry::available(),
                'can_manage'     => Permissions::allows($ctx, $auth, 'providers.manage'),
                'can_route'      => Permissions::allows($ctx, $auth, 'routing.manage'),
            ],
            'pulse'   => InsightEngine::headlines($ctx, 3, 'ROUTE'),
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * Dashboard 4 — Settlements & Reconciliation.
     *
     * @return array<string, mixed>
     */
    public static function settlements(Context $ctx, Auth $auth, Period $period): array
    {
        Permissions::assert($ctx, $auth, 'dashboard.settlements');

        $totals = PaymentReadings::settlementTotals($ctx, $period->from, $period->to);
        $collections = PaymentReadings::collections($ctx, $period->from, $period->to);
        $before = PaymentReadings::collections($ctx, $period->previousFrom, $period->previousTo);

        $unreconciled = (int) (Db::scalar(
            'SELECT COALESCE(SUM(ABS(COALESCE(difference_minor, actual_minor, 0))), 0)
             FROM ' . ReconciliationService::TABLE . '
             WHERE cmp_id = :cmp AND status IN (:open, :investigating)',
            ['cmp' => $ctx->cmpId, 'open' => ReconciliationService::OPEN, 'investigating' => ReconciliationService::INVESTIGATING],
        ) ?? 0);

        return [
            'period'  => $period->describe(),
            'metrics' => [
                Metric::money('gross', 'Gross collections', $collections['collected_minor'],
                    'Everything collected in this period, before the provider deducted anything.',
                    Metric::BASIS_PERIOD, $before['collected_minor']),
                Metric::money('refunds', 'Refunds', $collections['refunded_minor'],
                    'Money sent back to payers in this period.', Metric::BASIS_PERIOD,
                    $before['refunded_minor'], 'INR', 'bad'),
                Metric::money('provider_fees', 'Provider fees', $totals['fees_minor'],
                    'Fees and tax as the providers themselves reported them on their settlement records. Pay never calculates a rate of its own.',
                    Metric::BASIS_PERIOD, null, 'INR', 'bad'),
                Metric::money('expected', 'Expected settlement', $totals['expected_minor'],
                    'What the providers say they will send, after their own deductions.',
                    Metric::BASIS_PERIOD, null, 'INR', 'neutral'),
                Metric::money('settled', 'Settled (credited)', $totals['settled_minor'],
                    'What has been confirmed as credited to the bank account.',
                    Metric::BASIS_PERIOD),
                Metric::money('unreconciled', 'Unreconciled', $unreconciled,
                    'The total of every open reconciliation exception — differences, missing credits and payments no settlement has claimed.',
                    Metric::BASIS_AS_OF, null, 'INR', 'bad'),
            ],
            'panels' => [
                'timeline'       => self::settlementTimeline($ctx),
                'by_provider'    => self::settlementsByProvider($ctx, $period),
                'flow'           => self::reconciliationFlow($ctx, $period),
                'exceptions'     => ReconciliationService::summary($ctx),
                'recent'         => self::recentSettlements($ctx, 8),
                'can_resolve'    => Permissions::allows($ctx, $auth, 'reconciliation.resolve'),
            ],
            'pulse'   => InsightEngine::headlines($ctx, 3, 'DETECT'),
            'generated_at' => gmdate('c'),
        ];
    }

    // -----------------------------------------------------------------------
    // Panels
    // -----------------------------------------------------------------------

    /**
     * The Action Centre — things somebody has to do, most urgent first.
     *
     * Every row is a real count off a real query, and every row links
     * somewhere. A count with nowhere to go is a number that gets ignored.
     *
     * @return list<array<string, mixed>>
     */
    private static function actionCentre(Context $ctx): array
    {
        $items = [];

        $exposure = PaymentReadings::exposure($ctx);
        $expiring = PaymentReadings::expiringSoon($ctx, 3);

        $failedToday = (int) (Db::scalar(
            'SELECT COUNT(*) FROM pay_payment_attempts
             WHERE cmp_id = :cmp AND status = :failed AND created_at >= NOW() - INTERVAL \'1 day\'',
            ['cmp' => $ctx->cmpId, 'failed' => States::ATTEMPT_FAILED],
        ) ?? 0);

        if ($failedToday > 0) {
            $items[] = ['key' => 'failed_payments', 'tone' => 'danger', 'label' => 'Failed payments need attention',
                        'count' => $failedToday, 'path' => '/payments?status=FAILED'];
        }

        foreach (ReconciliationService::summary($ctx) as $exception) {
            $items[] = [
                'key'   => 'exception_' . strtolower($exception['kind']),
                'tone'  => $exception['severity'] === 'HIGH' ? 'danger' : 'warning',
                'label' => $exception['label'],
                'count' => $exception['count'],
                'path'  => '/reconciliation?kind=' . $exception['kind'],
            ];
        }

        if ($exposure['refunds_open'] > 0) {
            $items[] = ['key' => 'refunds_open', 'tone' => 'warning', 'label' => 'Refunds waiting',
                        'count' => $exposure['refunds_open'], 'path' => '/refunds?status=REQUESTED'];
        }

        if ($expiring['count'] > 0) {
            $items[] = ['key' => 'expiring', 'tone' => 'info', 'label' => 'Payment links expiring in 3 days',
                        'count' => $expiring['count'], 'path' => '/links?expiring=1',
                        'detail' => Money::minor($expiring['amount_minor'])->format() . ' outstanding'];
        }

        $degraded = Db::all(
            'SELECT display_name FROM pay_provider_connections
             WHERE cmp_id = :cmp AND status IN (:invalid, :suspended)',
            ['cmp' => $ctx->cmpId, 'invalid' => 'CREDENTIALS_INVALID', 'suspended' => 'SUSPENDED'],
        );
        if ($degraded !== []) {
            $items[] = ['key' => 'provider_degraded', 'tone' => 'danger', 'label' => 'Gateway needs re-connecting',
                        'count' => count($degraded), 'path' => '/gateways',
                        'detail' => implode(', ', array_map(static fn ($r) => (string) $r['display_name'], $degraded))];
        }

        // Most urgent first, then biggest.
        usort($items, static function (array $a, array $b): int {
            $weight = ['danger' => 0, 'warning' => 1, 'info' => 2];
            $tone = ($weight[$a['tone']] ?? 3) <=> ($weight[$b['tone']] ?? 3);

            return $tone !== 0 ? $tone : ($b['count'] <=> $a['count']);
        });

        return array_slice($items, 0, 8);
    }

    /** @return array<string, mixed> */
    private static function managedStatus(Context $ctx): array
    {
        $readiness = ProviderRegistry::managedReadiness();

        $onboarding = Db::first(
            'SELECT * FROM pay_managed_onboarding WHERE cmp_id = :cmp ORDER BY onboarding_id DESC LIMIT 1',
            ['cmp' => $ctx->cmpId],
        );

        $kycStatus = $onboarding === null ? 'NOT_STARTED' : (string) $onboarding['kyc_status'];

        if (!$readiness['available']) {
            return [
                'state'      => 'UNAVAILABLE',
                'label'      => 'Not configured',
                // The honest sentence, not a pretend "coming soon".
                'detail'     => $readiness['reason'],
                'kyc_status' => $kycStatus,
                'kyc_label'  => self::kycLabel($kycStatus),
                'kyc_detail' => null,
                'provider'   => $readiness['provider'],
                'can_apply'  => false,
                'required_actions' => [],
            ];
        }

        $state = match ($kycStatus) {
            'VERIFIED' => 'ACTIVE',
            'REJECTED', 'SUSPENDED' => 'BLOCKED',
            'NOT_STARTED' => 'AVAILABLE',
            default    => 'IN_PROGRESS',
        };

        return [
            'state'      => $state,
            'label'      => match ($state) {
                'ACTIVE'      => 'Active',
                'BLOCKED'     => 'Action needed',
                'AVAILABLE'   => 'Available',
                default       => 'In progress',
            },
            'detail'     => match ($state) {
                'ACTIVE'    => 'All systems operational',
                'AVAILABLE' => 'Activate to collect through Aicountly Managed Payments.',
                default     => $onboarding === null ? null : ($onboarding['kyc_status_detail'] === null ? null : (string) $onboarding['kyc_status_detail']),
            },
            'kyc_status' => $kycStatus,
            'kyc_label'  => self::kycLabel($kycStatus),
            'kyc_detail' => $onboarding === null ? null : ($onboarding['kyc_status_detail'] === null ? null : (string) $onboarding['kyc_status_detail']),
            'provider'   => $readiness['provider'],
            'can_apply'  => true,
            'required_actions' => $onboarding === null ? [] : Db::jsonColumn($onboarding['required_actions'] ?? null),
            'bank_last4' => $onboarding === null ? null : ($onboarding['bank_account_last4'] === null ? null : (string) $onboarding['bank_account_last4']),
            'merchant_id' => $onboarding === null ? null : ($onboarding['provider_merchant_id'] === null ? null : (string) $onboarding['provider_merchant_id']),
        ];
    }

    private static function kycLabel(string $status): string
    {
        return match ($status) {
            'VERIFIED'        => 'Verified',
            'REJECTED'        => 'Rejected',
            'SUSPENDED'       => 'Suspended',
            'UNDER_REVIEW'    => 'Under review',
            'ACTION_REQUIRED' => 'Action required',
            'IN_PROGRESS'     => 'In progress',
            default           => 'Not started',
        };
    }

    /** @return list<array<string, mixed>> */
    private static function routingRules(Context $ctx): array
    {
        $rows = Db::all(
            'SELECT r.*, p.display_name AS primary_name, f.display_name AS fallback_name
             FROM pay_routing_rules r
             LEFT JOIN pay_provider_connections p ON p.connection_id = r.primary_connection_id
             LEFT JOIN pay_provider_connections f ON f.connection_id = r.fallback_connection_id
             WHERE r.cmp_id = :cmp
             ORDER BY r.priority ASC, r.rule_id ASC',
            ['cmp' => $ctx->cmpId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'rule_id'   => (int) $row['rule_id'],
                'name'      => (string) $row['rule_name'],
                'priority'  => (int) $row['priority'],
                'active'    => $row['is_active'] === true || $row['is_active'] === 't',
                'method'    => $row['match_method'] === null ? null : (string) $row['match_method'],
                'currency'  => $row['match_currency'] === null ? null : (string) $row['match_currency'],
                'scope'     => $row['match_scope'] === null ? null : (string) $row['match_scope'],
                'source_app' => $row['match_source_app'] === null ? null : (string) $row['match_source_app'],
                'primary'   => $row['primary_name'] === null ? null : (string) $row['primary_name'],
                'fallback'  => $row['fallback_name'] === null ? null : (string) $row['fallback_name'],
                'allow_failover' => $row['allow_failover'] === true || $row['allow_failover'] === 't',
                // Whether an AI proposed it, and who accepted. A recommendation
                // a human took is still the human's decision.
                'suggested_by_ai' => $row['suggested_by_ai'] === true || $row['suggested_by_ai'] === 't',
                'accepted_by' => $row['accepted_by'] === null ? null : (string) $row['accepted_by'],
            ];
        }

        return $out;
    }

    /**
     * Success rate and latency per (method, provider).
     *
     * @return array<string, mixed>
     */
    private static function healthMatrix(Context $ctx): array
    {
        $rows = ProviderMetrics::byMethod($ctx->cmpId, 60 * 24);

        $connections = [];
        foreach (ProviderRegistry::connectionsFor($ctx) as $entry) {
            $connections[(int) $entry['connection']['connection_id']] = (string) $entry['connection']['display_name'];
        }

        $matrix = [];
        foreach ($rows as $row) {
            $method = (string) ($row['payment_method'] ?? 'OTHER');
            $connectionId = (int) $row['connection_id'];
            $calls = (int) $row['calls'];

            $matrix[$method][] = [
                'connection_id' => $connectionId,
                'name'          => $connections[$connectionId] ?? ProviderRegistry::displayNameFor((string) $row['provider_code']),
                'success_rate'  => $calls > 0 ? round(((int) $row['successes'] / $calls) * 100, 1) : null,
                'avg_latency_ms' => $calls > 0 ? (int) round((int) $row['latency'] / $calls) : null,
                'calls'         => $calls,
            ];
        }

        $out = [];
        foreach ($matrix as $method => $cells) {
            $out[] = ['method' => $method, 'label' => PaymentReadings::methodLabel($method), 'providers' => $cells];
        }

        return ['rows' => $out, 'window_hours' => 24];
    }

    /** @return array<string, mixed> */
    private static function technicalHealth(Context $ctx): array
    {
        $webhooks = Db::first(
            'SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = :processed) AS processed,
                    COUNT(*) FILTER (WHERE signature_valid = FALSE) AS bad_signature,
                    COUNT(*) FILTER (WHERE status = :duplicate) AS duplicates,
                    COUNT(*) FILTER (WHERE status = :failed) AS failed,
                    AVG(processing_ms) AS avg_ms
             FROM pay_webhook_events
             WHERE cmp_id = :cmp AND received_at >= NOW() - INTERVAL \'1 day\'',
            [
                'cmp' => $ctx->cmpId,
                'processed' => \Aicountly\Api\Payments\Webhooks\WebhookIngest::PROCESSED,
                'duplicate' => \Aicountly\Api\Payments\Webhooks\WebhookIngest::DUPLICATE,
                'failed' => \Aicountly\Api\Payments\Webhooks\WebhookIngest::FAILED,
            ],
        ) ?? [];

        $callbacks = Db::first(
            'SELECT COUNT(*) FILTER (WHERE status IN (:pending, :failed)) AS pending,
                    COUNT(*) FILTER (WHERE status = :exhausted) AS exhausted
             FROM pay_outbound_events WHERE cmp_id = :cmp',
            [
                'cmp' => $ctx->cmpId,
                'pending' => \Aicountly\Api\Payments\Events\Outbox::PENDING,
                'failed' => \Aicountly\Api\Payments\Events\Outbox::FAILED,
                'exhausted' => \Aicountly\Api\Payments\Events\Outbox::EXHAUSTED,
            ],
        ) ?? [];

        $health = ProviderMetrics::window($ctx->cmpId, 60 * 24);
        $totalCalls = 0;
        $totalFailures = 0;
        $latencySum = 0;
        $latencyCount = 0;
        foreach ($health as $entry) {
            $totalCalls += $entry['calls'];
            $totalFailures += $entry['failures'];
            if ($entry['avg_latency_ms'] !== null) {
                $latencySum += $entry['avg_latency_ms'] * $entry['calls'];
                $latencyCount += $entry['calls'];
            }
        }

        $webhookTotal = (int) ($webhooks['total'] ?? 0);

        return [
            'webhook_success_rate' => $webhookTotal > 0
                ? round(((int) ($webhooks['processed'] ?? 0) / $webhookTotal) * 100, 1) : null,
            'webhooks_received'  => $webhookTotal,
            'signature_failures' => (int) ($webhooks['bad_signature'] ?? 0),
            'duplicate_callbacks' => (int) ($webhooks['duplicates'] ?? 0),
            'webhook_failures'   => (int) ($webhooks['failed'] ?? 0),
            'avg_webhook_ms'     => $webhooks['avg_ms'] === null ? null : (int) round((float) $webhooks['avg_ms']),
            'callbacks_pending'  => (int) ($callbacks['pending'] ?? 0),
            'callbacks_exhausted' => (int) ($callbacks['exhausted'] ?? 0),
            'provider_calls'     => $totalCalls,
            'provider_error_rate' => $totalCalls > 0 ? round(($totalFailures / $totalCalls) * 100, 1) : null,
            'avg_latency_ms'     => $latencyCount > 0 ? (int) round($latencySum / $latencyCount) : null,
        ];
    }

    /**
     * The five-step settlement journey, for the most recent batch.
     *
     * @return array<string, mixed>
     */
    private static function settlementTimeline(Context $ctx): array
    {
        $latest = Db::first(
            'SELECT * FROM ' . SettlementService::TABLE . '
             WHERE cmp_id = :cmp ORDER BY COALESCE(settlement_date, imported_at) DESC LIMIT 1',
            ['cmp' => $ctx->cmpId],
        );

        if ($latest === null) {
            return [
                'available' => false,
                'reason'    => 'Settlements will appear here after your first provider settlement.',
                'steps'     => [],
            ];
        }

        $syncPending = (int) (Db::scalar(
            'SELECT COUNT(*) FROM pay_payment_attempts
             WHERE settlement_id = :id AND source_sync_status = :pending',
            ['id' => (int) $latest['settlement_id'], 'pending' => 'PENDING'],
        ) ?? 0);

        return [
            'available' => true,
            'settlement_id' => (string) $latest['settlement_uuid'],
            'steps' => [
                ['key' => 'request',  'label' => 'Payment request',     'detail' => 'Customer is asked to pay',
                 'at' => null, 'status' => 'COMPLETED'],
                ['key' => 'payment',  'label' => 'Payment transaction', 'detail' => 'Payment succeeds at the provider',
                 'at' => null, 'status' => 'COMPLETED'],
                ['key' => 'provider', 'label' => 'Provider settlement', 'detail' => 'Included in a provider batch',
                 'at' => $latest['settlement_date'] === null ? null : (string) $latest['settlement_date'],
                 'status' => 'COMPLETED'],
                ['key' => 'bank',     'label' => 'Bank settlement',     'detail' => 'Credited to the bank account',
                 'at' => $latest['bank_credited_at'] === null ? null : (string) $latest['bank_credited_at'],
                 'status' => $latest['bank_credited_at'] === null ? 'PENDING' : 'COMPLETED'],
                ['key' => 'sync',     'label' => 'Source sync',         'detail' => 'Reported back to the originating app',
                 'at' => null,
                 'status' => $syncPending > 0 ? 'PENDING' : 'COMPLETED'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function settlementsByProvider(Context $ctx, Period $period): array
    {
        $rows = Db::all(
            'SELECT provider_code, provider_mode,
                    COALESCE(SUM(gross_minor), 0) AS gross,
                    COALESCE(SUM(refund_minor), 0) AS refunds,
                    COALESCE(SUM(provider_fee_minor + provider_tax_minor), 0) AS fees,
                    COALESCE(SUM(expected_net_minor), 0) AS expected,
                    COALESCE(SUM(settled_net_minor), 0) AS settled,
                    COUNT(*) FILTER (WHERE settled_net_minor IS NULL) AS awaiting
             FROM ' . SettlementService::TABLE . '
             WHERE cmp_id = :cmp AND settlement_date >= :from::date AND settlement_date <= :to::date
             GROUP BY provider_code, provider_mode
             ORDER BY gross DESC',
            ['cmp' => $ctx->cmpId, 'from' => $period->from, 'to' => $period->to],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'provider'   => (string) $row['provider_code'],
                'name'       => (string) $row['provider_mode'] === 'MANAGED'
                    ? 'Aicountly Managed'
                    : ProviderRegistry::displayNameFor((string) $row['provider_code']),
                'mode'       => (string) $row['provider_mode'],
                'gross'      => ((int) $row['gross']) / 100,
                'refunds'    => ((int) $row['refunds']) / 100,
                'fees'       => ((int) $row['fees']) / 100,
                'expected'   => ((int) $row['expected']) / 100,
                'settled'    => ((int) $row['settled']) / 100,
                'status'     => (int) $row['awaiting'] > 0 ? 'Pending' : 'Settled',
            ];
        }

        return $out;
    }

    /**
     * The reconciliation ladder, with the count that survives each step.
     *
     * @return array<string, mixed>
     */
    private static function reconciliationFlow(Context $ctx, Period $period): array
    {
        $collected = PaymentReadings::collections($ctx, $period->from, $period->to);
        $settlement = PaymentReadings::settlementTotals($ctx, $period->from, $period->to);

        $matched = (int) (Db::scalar(
            'SELECT COUNT(*) FROM pay_settlement_items
             WHERE cmp_id = :cmp AND match_status = :matched',
            ['cmp' => $ctx->cmpId, 'matched' => 'MATCHED'],
        ) ?? 0);

        $unmatched = (int) (Db::scalar(
            'SELECT COUNT(*) FROM pay_settlement_items
             WHERE cmp_id = :cmp AND match_status <> :matched',
            ['cmp' => $ctx->cmpId, 'matched' => 'MATCHED'],
        ) ?? 0);

        $total = $matched + $unmatched;

        return [
            'steps' => [
                ['key' => 'provider_file', 'label' => 'Provider settlement file',
                 'detail' => 'Received from payment providers',
                 'amount' => $settlement['expected_minor'] / 100, 'count' => $settlement['awaiting_batches']],
                ['key' => 'our_records', 'label' => 'Our records (Aicountly Pay)',
                 'detail' => 'Matched with Pay transactions',
                 'amount' => $collected['collected_minor'] / 100, 'count' => $collected['payment_count']],
                ['key' => 'bank', 'label' => 'Bank statement',
                 'detail' => 'Matched with bank credits',
                 'amount' => $settlement['settled_minor'] / 100, 'count' => null],
            ],
            'match_rate' => $total > 0 ? round(($matched / $total) * 100, 1) : null,
            'matched'    => $matched,
            'exceptions' => $unmatched,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function recentSettlements(Context $ctx, int $limit): array
    {
        $rows = Db::all(
            'SELECT * FROM ' . SettlementService::TABLE . '
             WHERE cmp_id = :cmp ORDER BY COALESCE(settlement_date, imported_at) DESC LIMIT :limit',
            ['cmp' => $ctx->cmpId, 'limit' => $limit],
        );

        return array_map(static fn (array $row) => SettlementService::present($row), $rows);
    }
}
