<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\DisputeService;
use Aicountly\Api\Domain\ExternalPaymentService;
use Aicountly\Api\Domain\MandateService;
use Aicountly\Api\Domain\Money;
use Aicountly\Api\Domain\PaymentService;
use Aicountly\Api\Domain\RefundService;
use Aicountly\Api\Domain\SettlementService;
use Aicountly\Api\Domain\States;
use Aicountly\Api\Http;
use Aicountly\Api\Payments\Providers\ProviderRegistry;
use Aicountly\Api\Permissions;

/**
 * Refunds, disputes, mandates, settlements and payments recorded outside Pay.
 *
 * Grouped because they are the money-movement endpoints and share their entry
 * rules: each one names the permission it needs before it reads anything, and
 * the two that send money out — approving a refund, recording an external
 * payment — are the two the RBAC catalog keeps apart from everything else.
 */
final class MoneyController extends Controller
{
    // ---------------------------------------------------------------- refunds

    public static function refunds(): never
    {
        [$auth, $ctx] = self::enter();
        $auth->isApiClient()
            ? Permissions::assertScope($auth, 'payments.read')
            : Permissions::assert($ctx, $auth, 'payments.view');

        $params = Http::listParams(['requested_at', 'amount_minor', 'status'], 'requested_at');
        $where = ['r.cmp_id = :cmp'];
        $bindings = ['cmp' => $ctx->cmpId];

        $status = Http::param('status');
        if ($status !== null && $status !== '' && in_array($status, States::all('refund'), true)) {
            $where[] = 'r.status = :status';
            $bindings['status'] = $status;
        }

        $sql = 'FROM ' . RefundService::TABLE . ' r
                LEFT JOIN ' . PaymentService::TABLE . ' a ON a.attempt_id = r.attempt_id
                LEFT JOIN pay_payers p ON p.payer_id = a.payer_id
                WHERE ' . implode(' AND ', $where);

        $total = (int) Db::scalar('SELECT COUNT(*) ' . $sql, $bindings);

        $rows = Db::all(
            'SELECT r.*, a.attempt_uuid, p.display_name AS payer_name ' . $sql
            . ' ORDER BY r.' . $params['sort'] . ' ' . $params['order'] . ' LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $params['limit'], 'offset' => $params['offset']],
        );

        $out = array_map(static fn (array $row) => RefundService::present($row) + [
            'payment_id' => $row['attempt_uuid'] === null ? null : (string) $row['attempt_uuid'],
            'payer'      => $row['payer_name'] === null ? null : (string) $row['payer_name'],
        ], $rows);

        Http::list($out, $total, $params['limit'], $params['offset'], [
            'can_approve' => !$auth->isApiClient() && Permissions::allows($ctx, $auth, 'refunds.approve'),
        ]);
    }

    public static function requestRefund(): never
    {
        [$auth, $ctx] = self::enter();
        if ($auth->isApiClient()) {
            Permissions::assertScope($auth, 'refunds.write');
        }

        $body = Http::body();
        $paymentId = (string) ($body['payment_id'] ?? '');

        $attempt = PaymentService::findByUuid($ctx, $paymentId);
        if ($attempt === null) {
            Http::notFound('That payment could not be found.');
        }

        Http::data(RefundService::request($ctx, $auth, $attempt, $body), 201);
    }

    public static function approveRefund(string $uuid): never
    {
        [$auth, $ctx] = self::enter();

        $refund = RefundService::findByUuid($ctx, $uuid);
        if ($refund === null) {
            Http::notFound('That refund could not be found.');
        }

        Http::data(RefundService::approve($ctx, $auth, $refund));
    }

    public static function rejectRefund(string $uuid): never
    {
        [$auth, $ctx] = self::enter();

        $refund = RefundService::findByUuid($ctx, $uuid);
        if ($refund === null) {
            Http::notFound('That refund could not be found.');
        }

        $reason = trim((string) (Http::param('reason') ?? ''));
        if ($reason === '') {
            Http::validationFailed('Say why this refund is being rejected.', ['field' => 'reason']);
        }

        Http::data(RefundService::reject($ctx, $auth, $refund, $reason));
    }

    // --------------------------------------------------------------- disputes

    public static function disputes(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'payments.view');

        $params = Http::listParams(['opened_at', 'evidence_due_at', 'amount_minor'], 'opened_at');

        $total = (int) Db::scalar('SELECT COUNT(*) FROM pay_disputes WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);

        $rows = Db::all(
            'SELECT d.*, a.attempt_uuid FROM pay_disputes d
             LEFT JOIN ' . PaymentService::TABLE . ' a ON a.attempt_id = d.attempt_id
             WHERE d.cmp_id = :cmp
             ORDER BY d.' . $params['sort'] . ' ' . $params['order'] . ' NULLS LAST LIMIT :limit OFFSET :offset',
            ['cmp' => $ctx->cmpId, 'limit' => $params['limit'], 'offset' => $params['offset']],
        );

        Http::list(
            array_map(static fn (array $row) => DisputeService::present($row) + [
                'payment_id' => $row['attempt_uuid'] === null ? null : (string) $row['attempt_uuid'],
            ], $rows),
            $total,
            $params['limit'],
            $params['offset'],
            ['can_manage' => Permissions::allows($ctx, $auth, 'disputes.manage')],
        );
    }

    public static function createDispute(): never
    {
        [$auth, $ctx] = self::enter();
        Http::data(DisputeService::recordManually($ctx, $auth, Http::body()), 201);
    }

    public static function updateDispute(string $uuid): never
    {
        [$auth, $ctx] = self::enter();

        $dispute = DisputeService::findByUuid($ctx, $uuid);
        if ($dispute === null) {
            Http::notFound('That dispute could not be found.');
        }

        Http::data(DisputeService::update($ctx, $auth, (int) $dispute['dispute_id'], Http::body()));
    }

    // --------------------------------------------------------------- mandates

    public static function mandates(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'mandates.view');

        $params = Http::listParams(['created_at', 'last_debit_at', 'status'], 'created_at');

        $total = (int) Db::scalar('SELECT COUNT(*) FROM ' . MandateService::TABLE . ' WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);

        $rows = Db::all(
            'SELECT m.*, p.display_name AS payer_name FROM ' . MandateService::TABLE . ' m
             LEFT JOIN pay_payers p ON p.payer_id = m.payer_id
             WHERE m.cmp_id = :cmp
             ORDER BY m.' . $params['sort'] . ' ' . $params['order'] . ' NULLS LAST LIMIT :limit OFFSET :offset',
            ['cmp' => $ctx->cmpId, 'limit' => $params['limit'], 'offset' => $params['offset']],
        );

        Http::list(
            array_map(static fn (array $row) => MandateService::present($row) + [
                'payer' => $row['payer_name'] === null ? null : (string) $row['payer_name'],
            ], $rows),
            $total,
            $params['limit'],
            $params['offset'],
            [
                'can_manage' => Permissions::allows($ctx, $auth, 'mandates.manage'),
                // Said plainly on the screen, so nobody goes looking for plan
                // management here.
                'note' => 'Pay holds the payer\'s authority to be debited. The plan, its price and its renewal belong to the app that raised the subscription.',
            ],
        );
    }

    public static function setMandateStatus(string $uuid): never
    {
        [$auth, $ctx] = self::enter();

        $mandate = MandateService::findByUuid($ctx, $uuid);
        if ($mandate === null) {
            Http::notFound('That mandate could not be found.');
        }

        Http::data(MandateService::setStatus(
            $ctx,
            $auth,
            (int) $mandate['mandate_id'],
            (string) (Http::param('status') ?? ''),
            (string) (Http::param('reason') ?? ''),
        ));
    }

    // ------------------------------------------------------------ settlements

    public static function settlements(): never
    {
        [$auth, $ctx] = self::enter();
        $auth->isApiClient()
            ? Permissions::assertScope($auth, 'settlements.read')
            : Permissions::assert($ctx, $auth, 'settlements.view');

        $params = Http::listParams(['settlement_date', 'expected_net_minor', 'status'], 'settlement_date');

        $where = ['cmp_id = :cmp'];
        $bindings = ['cmp' => $ctx->cmpId];

        $status = Http::param('status');
        if ($status !== null && $status !== '') {
            $where[] = 'status = :status';
            $bindings['status'] = strtoupper($status);
        }

        $sql = 'FROM ' . SettlementService::TABLE . ' WHERE ' . implode(' AND ', $where);
        $total = (int) Db::scalar('SELECT COUNT(*) ' . $sql, $bindings);

        $rows = Db::all(
            'SELECT * ' . $sql . ' ORDER BY ' . $params['sort'] . ' ' . $params['order']
            . ' NULLS LAST LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $params['limit'], 'offset' => $params['offset']],
        );

        Http::list(
            array_map(static fn (array $row) => SettlementService::present($row), $rows),
            $total,
            $params['limit'],
            $params['offset'],
        );
    }

    public static function showSettlement(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'settlements.view');

        $settlement = SettlementService::findByUuid($ctx, $uuid);
        if ($settlement === null) {
            Http::notFound('That settlement could not be found.');
        }

        $items = Db::all(
            'SELECT i.*, a.attempt_uuid, r.refund_uuid FROM ' . SettlementService::ITEMS . ' i
             LEFT JOIN ' . PaymentService::TABLE . ' a ON a.attempt_id = i.attempt_id
             LEFT JOIN ' . RefundService::TABLE . ' r ON r.refund_id = i.refund_id
             WHERE i.settlement_id = :id ORDER BY i.item_id ASC LIMIT 500',
            ['id' => (int) $settlement['settlement_id']],
        );

        Http::data(SettlementService::present($settlement) + [
            'items' => array_map(static fn (array $row) => [
                'kind'        => (string) $row['item_kind'],
                'payment_id'  => $row['attempt_uuid'] === null ? null : (string) $row['attempt_uuid'],
                'refund_id'   => $row['refund_uuid'] === null ? null : (string) $row['refund_uuid'],
                'gross'       => Money::minor((int) $row['gross_minor'])->toMajor(),
                'fee'         => Money::minor((int) $row['fee_minor'])->toMajor(),
                'tax'         => Money::minor((int) $row['tax_minor'])->toMajor(),
                'net'         => Money::minor((int) $row['net_minor'])->toMajor(),
                'match_status' => (string) $row['match_status'],
            ], $items),
        ]);
    }

    /** Pull settlements from a provider on demand. */
    public static function importSettlements(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'reconciliation.manage');

        $results = [];
        foreach (ProviderRegistry::connectionsFor($ctx, activeOnly: true) as $entry) {
            $connection = $entry['connection'];
            $result = SettlementService::importFromProvider($ctx, $connection, [
                'from' => (string) (Http::param('from') ?? gmdate('Y-m-d', strtotime('-30 days'))),
                'to'   => (string) (Http::param('to') ?? gmdate('Y-m-d')),
            ]);

            $results[] = [
                'provider' => (string) $connection['provider_code'],
                'name'     => (string) $connection['display_name'],
            ] + $result;
        }

        SettlementService::findUnsettledPayments($ctx);
        SettlementService::findUnsettledRefunds($ctx);

        Http::data(['providers' => $results]);
    }

    /** Confirm a bank credit against a settlement. */
    public static function confirmSettlement(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'reconciliation.resolve');

        $settlement = SettlementService::findByUuid($ctx, $uuid);
        if ($settlement === null) {
            Http::notFound('That settlement could not be found.');
        }

        $body = Http::body();
        $reference = trim((string) ($body['bank_reference'] ?? ''));
        if ($reference === '') {
            Http::validationFailed('Enter the bank reference or UTR for this credit.', ['field' => 'bank_reference']);
        }

        $credited = self::money($body['credited'] ?? null, (string) $settlement['currency'], 'credited');

        Http::data(SettlementService::confirmBankCredit(
            $ctx,
            $auth,
            (int) $settlement['settlement_id'],
            $credited,
            $reference,
            isset($body['credited_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $body['credited_at']) ?: time()) : null,
        ));
    }

    // -------------------------------------------------------- external money

    public static function recordExternal(): never
    {
        [$auth, $ctx] = self::enter();
        Http::data(ExternalPaymentService::record($ctx, $auth, Http::body()), 201);
    }

    public static function reverseExternal(string $uuid): never
    {
        [$auth, $ctx] = self::enter();

        $external = ExternalPaymentService::findByUuid($ctx, $uuid);
        if ($external === null) {
            Http::notFound('That external payment could not be found.');
        }

        Http::data(ExternalPaymentService::reverse(
            $ctx,
            $auth,
            (int) $external['external_id'],
            (string) (Http::param('reason') ?? ''),
        ));
    }

    public static function externalPayments(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'payments.view');

        $params = Http::listParams(['received_at', 'amount_minor'], 'received_at');
        [$scope, $bindings] = $ctx->scopeClause('e');

        $total = (int) Db::scalar(
            'SELECT COUNT(*) FROM ' . ExternalPaymentService::TABLE . ' e WHERE ' . $scope,
            $bindings,
        );

        $rows = Db::all(
            'SELECT e.* FROM ' . ExternalPaymentService::TABLE . ' e WHERE ' . $scope
            . ' ORDER BY e.' . $params['sort'] . ' ' . $params['order'] . ' LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $params['limit'], 'offset' => $params['offset']],
        );

        Http::list(
            array_map(static fn (array $row) => ExternalPaymentService::present($row), $rows),
            $total,
            $params['limit'],
            $params['offset'],
            ['methods' => ExternalPaymentService::METHODS],
        );
    }
}
