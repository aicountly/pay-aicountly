<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\PayerService;
use Aicountly\Api\Domain\PaymentRequestService;
use Aicountly\Api\Domain\PaymentService;
use Aicountly\Api\Domain\RefundService;
use Aicountly\Api\Domain\SettlementService;
use Aicountly\Api\Domain\States;
use Aicountly\Api\Http;
use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Permissions;

/**
 * Payments — the list, the detail, and the timeline behind one.
 *
 * TECHNICAL DETAIL IS PERMISSION-GATED. A provider payment id is enough to look
 * a customer up in the provider's own dashboard, and a failure code is a
 * developer's tool. Both are withheld from a role that does not hold
 * `developer.view`, which is why present() takes a `$technical` flag rather
 * than always returning everything and letting the browser hide it.
 */
final class PaymentsController extends Controller
{
    public static function index(): never
    {
        [$auth, $ctx] = self::enter();
        $auth->isApiClient()
            ? Permissions::assertScope($auth, 'payments.read')
            : Permissions::assert($ctx, $auth, 'payments.view');

        $params = Http::listParams(['paid_at', 'created_at', 'amount_minor', 'status'], 'paid_at');
        [$scope, $bindings] = $ctx->scopeClause('a');

        $where = [$scope];
        $filters = [];

        foreach ([
            'status'            => 'a.status = :status',
            'payment_method'    => 'a.payment_method = :payment_method',
            'provider'          => 'a.provider_code = :provider',
            'provider_mode'     => 'a.provider_mode = :provider_mode',
            'settlement_status' => 'a.settlement_status = :settlement_status',
        ] as $param => $clause) {
            $value = Http::param($param);
            if ($value !== null && $value !== '') {
                $where[] = $clause;
                $bindings[$param] = strtoupper($value);
                $filters[$param] = strtoupper($value);
            }
        }

        $sourceApp = Http::param('source_app');
        if ($sourceApp !== null && $sourceApp !== '') {
            $where[] = 'r.source_app = :source_app';
            $bindings['source_app'] = strtoupper($sourceApp);
            $filters['source_app'] = strtoupper($sourceApp);
        }

        if (($from = Http::param('from')) !== null && $from !== '') {
            $where[] = 'COALESCE(a.paid_at, a.created_at) >= :from';
            $bindings['from'] = $from;
        }
        if (($to = Http::param('to')) !== null && $to !== '') {
            $where[] = 'COALESCE(a.paid_at, a.created_at) < :to';
            $bindings['to'] = $to;
        }

        // Amounts arrive as major units and are compared in minor, because that
        // is what the column holds.
        if (($min = Http::param('min_amount')) !== null && $min !== '') {
            $where[] = 'a.amount_minor >= :min_amount';
            $bindings['min_amount'] = (int) round(((float) $min) * 100);
        }
        if (($max = Http::param('max_amount')) !== null && $max !== '') {
            $where[] = 'a.amount_minor <= :max_amount';
            $bindings['max_amount'] = (int) round(((float) $max) * 100);
        }

        if ($params['q'] !== '') {
            $where[] = '(p.display_name ILIKE :q OR r.source_reference ILIKE :q
                         OR a.attempt_uuid = :exact OR a.provider_payment_id = :exact)';
            $bindings['q'] = '%' . $params['q'] . '%';
            $bindings['exact'] = $params['q'];
        }

        $sql = 'FROM ' . PaymentService::TABLE . ' a
                LEFT JOIN pay_payers p ON p.payer_id = a.payer_id
                LEFT JOIN ' . PaymentRequestService::TABLE . ' r ON r.request_id = a.request_id
                WHERE ' . implode(' AND ', $where);

        $total = (int) Db::scalar('SELECT COUNT(*) ' . $sql, $bindings);

        $rows = Db::all(
            'SELECT a.*, p.display_name AS payer_name, r.source_app, r.source_reference, r.request_uuid ' . $sql
            . ' ORDER BY a.' . $params['sort'] . ' ' . $params['order'] . ' NULLS LAST LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $params['limit'], 'offset' => $params['offset']],
        );

        $technical = !$auth->isApiClient() && Permissions::allows($ctx, $auth, 'developer.view');

        $out = [];
        foreach ($rows as $row) {
            $out[] = PaymentService::present($ctx, $row, $technical) + [
                'payer'      => $row['payer_name'] === null ? null : (string) $row['payer_name'],
                'source'     => $row['source_app'] === null ? 'PAY' : (string) $row['source_app'],
                'reference'  => $row['source_reference'] === null ? null : (string) $row['source_reference'],
                'payment_request_id' => $row['request_uuid'] === null ? null : (string) $row['request_uuid'],
            ];
        }

        Http::list($out, $total, $params['limit'], $params['offset'], ['filters' => $filters]);
    }

    /**
     * One payment, with everything that happened to it.
     *
     * This is the screen somebody opens when a customer rings up, so it carries
     * the whole story: the provider's own timeline, what we told the source app,
     * the settlement it landed in, and every refund against it.
     */
    public static function show(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        $auth->isApiClient()
            ? Permissions::assertScope($auth, 'payments.read')
            : Permissions::assert($ctx, $auth, 'payments.view');

        $attempt = PaymentService::findByUuid($ctx, $uuid);
        if ($attempt === null) {
            Http::notFound('That payment could not be found.');
        }

        $technical = !$auth->isApiClient() && Permissions::allows($ctx, $auth, 'developer.view');
        $attemptId = (int) $attempt['attempt_id'];

        $request = $attempt['request_id'] === null ? null : PaymentRequestService::find($ctx, (int) $attempt['request_id']);
        $payer = $attempt['payer_id'] === null ? null : PayerService::find($ctx, (int) $attempt['payer_id']);

        $refunds = Db::all(
            'SELECT * FROM ' . RefundService::TABLE . ' WHERE attempt_id = :id AND cmp_id = :cmp ORDER BY requested_at DESC',
            ['id' => $attemptId, 'cmp' => $ctx->cmpId],
        );

        $settlement = $attempt['settlement_id'] === null
            ? null : SettlementService::find($ctx, (int) $attempt['settlement_id']);

        Http::data(PaymentService::present($ctx, $attempt, $technical) + [
            'payer'   => PayerService::present($payer),
            'request' => $request === null ? null : PaymentRequestService::present($ctx, $request),
            'refunds' => array_map(static fn (array $row) => RefundService::present($row), $refunds),
            'refundable' => PaymentService::refundable($ctx, $attempt)->toMajor(),
            'settlement' => $settlement === null ? null : SettlementService::present($settlement),
            'timeline'   => self::timeline($ctx, $attempt),
            // The webhook and callback record, for whoever is debugging.
            'webhooks'   => $technical ? self::webhooks($ctx, $attemptId) : null,
            'source_sync' => self::sourceSync($ctx, $attemptId),
            'can_refund' => !$auth->isApiClient() && Permissions::allows($ctx, $auth, 'refunds.request'),
        ]);
    }

    /**
     * What happened, in order.
     *
     * Built from the attempt's own timestamps rather than a separate event log,
     * so it cannot drift from the record it describes.
     *
     * @param array<string, mixed> $attempt
     * @return list<array<string, mixed>>
     */
    private static function timeline(\Aicountly\Api\Context $ctx, array $attempt): array
    {
        $steps = [];

        $add = static function (?string $at, string $label, string $detail, string $tone = 'neutral') use (&$steps): void {
            if ($at !== null) {
                $steps[] = ['at' => $at, 'label' => $label, 'detail' => $detail, 'tone' => $tone];
            }
        };

        $add((string) $attempt['created_at'], 'Payment started', 'A payment was started in Pay.');
        $add($attempt['initiated_at'] === null ? null : (string) $attempt['initiated_at'],
            'Sent to provider', 'The provider accepted the payment and returned a reference.');
        $add($attempt['authorized_at'] === null ? null : (string) $attempt['authorized_at'],
            'Authorised', 'The money was held but not yet taken.', 'warning');
        $add($attempt['captured_at'] === null ? null : (string) $attempt['captured_at'],
            'Captured', 'The money was taken.', 'success');
        $add($attempt['failed_at'] === null ? null : (string) $attempt['failed_at'],
            'Failed', (string) ($attempt['failure_reason'] ?? 'The payment did not go through.'), 'danger');
        $add($attempt['source_synced_at'] === null ? null : (string) $attempt['source_synced_at'],
            'Source app told', 'The app that raised the request was notified.', 'success');

        usort($steps, static fn (array $a, array $b): int => strcmp((string) $a['at'], (string) $b['at']));

        return $steps;
    }

    /** @return list<array<string, mixed>> */
    private static function webhooks(\Aicountly\Api\Context $ctx, int $attemptId): array
    {
        $rows = Db::all(
            'SELECT event_uuid, provider_code, provider_event_type, normalized_event, status,
                    signature_valid, processing_ms, received_at
             FROM pay_webhook_events
             WHERE attempt_id = :id AND cmp_id = :cmp ORDER BY received_at ASC LIMIT 50',
            ['id' => $attemptId, 'cmp' => $ctx->cmpId],
        );

        return array_map(static fn (array $row) => [
            'event_id'   => (string) $row['event_uuid'],
            'provider'   => (string) $row['provider_code'],
            'provider_event' => $row['provider_event_type'] === null ? null : (string) $row['provider_event_type'],
            'event'      => $row['normalized_event'] === null ? null : (string) $row['normalized_event'],
            'status'     => (string) $row['status'],
            'signature_valid' => $row['signature_valid'] === true || $row['signature_valid'] === 't',
            'processing_ms' => $row['processing_ms'] === null ? null : (int) $row['processing_ms'],
            'received_at' => (string) $row['received_at'],
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    private static function sourceSync(\Aicountly\Api\Context $ctx, int $attemptId): array
    {
        $rows = Db::all(
            'SELECT event_uuid, event_name, target_app, status, attempt_count, max_attempts,
                    response_status, last_error, next_attempt_at, delivered_at, created_at
             FROM pay_outbound_events
             WHERE attempt_id = :id AND cmp_id = :cmp ORDER BY created_at ASC',
            ['id' => $attemptId, 'cmp' => $ctx->cmpId],
        );

        return array_map(static fn (array $row) => [
            'event_id'   => (string) $row['event_uuid'],
            'event'      => (string) $row['event_name'],
            'event_label' => EventNames::label((string) $row['event_name']),
            'target'     => (string) $row['target_app'],
            'status'     => (string) $row['status'],
            'attempts'   => (int) $row['attempt_count'] . ' of ' . (int) $row['max_attempts'],
            'response_status' => $row['response_status'] === null ? null : (int) $row['response_status'],
            'last_error' => $row['last_error'] === null ? null : (string) $row['last_error'],
            'next_attempt_at' => $row['next_attempt_at'] === null ? null : (string) $row['next_attempt_at'],
            'delivered_at' => $row['delivered_at'] === null ? null : (string) $row['delivered_at'],
            'can_retry'  => in_array((string) $row['status'], ['EXHAUSTED', 'FAILED'], true),
        ], $rows);
    }
}
