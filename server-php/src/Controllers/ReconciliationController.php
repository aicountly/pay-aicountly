<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\PayerService;
use Aicountly\Api\Domain\PaymentService;
use Aicountly\Api\Domain\ReconciliationService;
use Aicountly\Api\Domain\SettlementService;
use Aicountly\Api\Http;
use Aicountly\Api\PayPulse\InsightEngine;
use Aicountly\Api\Permissions;

/**
 * Reconciliation cases, customers, links and Pay Pulse actions.
 *
 * The remaining read surfaces, kept together because each is a list with a
 * couple of actions rather than a domain of its own.
 */
final class ReconciliationController extends Controller
{
    public static function cases(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'reconciliation.view');

        $params = Http::listParams(['opened_at', 'severity', 'difference_minor'], 'opened_at');

        $where = ['c.cmp_id = :cmp'];
        $bindings = ['cmp' => $ctx->cmpId];

        $status = Http::param('status');
        if ($status !== null && $status !== '') {
            $where[] = 'c.status = :status';
            $bindings['status'] = strtoupper($status);
        } else {
            // The default view is work to do, not a history of everything ever
            // resolved.
            $where[] = 'c.status IN (:open, :investigating)';
            $bindings['open'] = ReconciliationService::OPEN;
            $bindings['investigating'] = ReconciliationService::INVESTIGATING;
        }

        $kind = Http::param('kind');
        if ($kind !== null && $kind !== '') {
            $where[] = 'c.case_kind = :kind';
            $bindings['kind'] = strtoupper($kind);
        }

        $sql = 'FROM ' . ReconciliationService::TABLE . ' c WHERE ' . implode(' AND ', $where);
        $total = (int) Db::scalar('SELECT COUNT(*) ' . $sql, $bindings);

        $rows = Db::all(
            'SELECT c.* ' . $sql . ' ORDER BY
                CASE c.severity WHEN \'HIGH\' THEN 0 WHEN \'MEDIUM\' THEN 1 ELSE 2 END,
                c.' . $params['sort'] . ' ' . $params['order'] . ' LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $params['limit'], 'offset' => $params['offset']],
        );

        Http::list(
            array_map(static fn (array $row) => ReconciliationService::present($row), $rows),
            $total,
            $params['limit'],
            $params['offset'],
            [
                'summary'     => ReconciliationService::summary($ctx),
                'can_resolve' => Permissions::allows($ctx, $auth, 'reconciliation.resolve'),
                'kinds'       => ReconciliationService::LABELS,
            ],
        );
    }

    public static function resolveCase(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'reconciliation.resolve');

        $case = Db::first(
            'SELECT * FROM ' . ReconciliationService::TABLE . ' WHERE case_uuid = :uuid AND cmp_id = :cmp',
            ['uuid' => $uuid, 'cmp' => $ctx->cmpId],
        );

        if ($case === null) {
            Http::notFound('That reconciliation case could not be found.');
        }

        Http::data(ReconciliationService::resolve(
            $ctx,
            $auth,
            (int) $case['case_id'],
            strtoupper((string) (Http::param('status') ?? ReconciliationService::RESOLVED)),
            (string) (Http::param('note') ?? ''),
        ));
    }

    /**
     * Run the reconciler on demand.
     *
     * Also runs on a schedule; this is the button for somebody who has just
     * fixed something and wants the number to move.
     */
    public static function run(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'reconciliation.manage');

        Http::data([
            'unsettled_payments' => SettlementService::findUnsettledPayments($ctx),
            'unsettled_refunds'  => SettlementService::findUnsettledRefunds($ctx),
            'summary'            => ReconciliationService::summary($ctx),
        ]);
    }

    // -------------------------------------------------------------- customers

    /**
     * Payers, as Pay knows them.
     *
     * NOT a customer master. The list is built from Pay's own payment history;
     * where a payer belongs to another app, the screen links there for the
     * authoritative record rather than duplicating it.
     */
    public static function customers(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'customers.view');

        $params = Http::listParams(['last_paid_at', 'total_paid_minor', 'payment_count', 'display_name'], 'last_paid_at');

        $where = ['p.cmp_id = :cmp'];
        $bindings = ['cmp' => $ctx->cmpId];

        if ($params['q'] !== '') {
            $where[] = '(p.display_name ILIKE :q OR p.email ILIKE :q OR p.mobile ILIKE :q)';
            $bindings['q'] = '%' . $params['q'] . '%';
        }

        $kind = Http::param('kind');
        if ($kind !== null && $kind !== '') {
            $where[] = 'p.payer_kind = :kind';
            $bindings['kind'] = strtoupper($kind);
        }

        if (Http::param('filter') === 'inactive') {
            $where[] = "p.payment_count >= 2 AND p.last_paid_at < NOW() - INTERVAL '30 days'";
        }

        $sql = 'FROM pay_payers p WHERE ' . implode(' AND ', $where);
        $total = (int) Db::scalar('SELECT COUNT(*) ' . $sql, $bindings);

        $rows = Db::all(
            'SELECT p.* ' . $sql . ' ORDER BY p.' . $params['sort'] . ' ' . $params['order']
            . ' NULLS LAST LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $params['limit'], 'offset' => $params['offset']],
        );

        Http::list(
            array_map(static fn (array $row) => PayerService::present($row), $rows),
            $total,
            $params['limit'],
            $params['offset'],
            ['note' => 'These are the people who have paid you through Pay. Their master record lives in the app that owns them.'],
        );
    }

    public static function customer(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'customers.view');

        $payer = PayerService::findByUuid($ctx, $uuid);
        if ($payer === null) {
            Http::notFound('That payer could not be found.');
        }

        $payments = Db::all(
            'SELECT a.*, r.source_reference FROM ' . PaymentService::TABLE . ' a
             LEFT JOIN pay_payment_requests r ON r.request_id = a.request_id
             WHERE a.payer_id = :id AND a.cmp_id = :cmp
             ORDER BY COALESCE(a.paid_at, a.created_at) DESC LIMIT 50',
            ['id' => (int) $payer['payer_id'], 'cmp' => $ctx->cmpId],
        );

        Http::data(PayerService::present($payer) + [
            'payments' => array_map(static fn (array $row) => PaymentService::present($ctx, $row) + [
                'reference' => $row['source_reference'] === null ? null : (string) $row['source_reference'],
            ], $payments),
            // Said explicitly, so nobody mistakes this screen for the customer
            // master and starts editing here.
            'source_note' => $payer['source_app'] === null
                ? 'This payer exists only in Pay.'
                : 'The master record for this customer belongs to ' . (string) $payer['source_app'] . '.',
        ]);
    }

    // ------------------------------------------------------------------ links

    public static function links(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'payment_requests.view');

        $params = Http::listParams(['created_at', 'expires_at', 'view_count'], 'created_at');

        $where = ['l.cmp_id = :cmp'];
        $bindings = ['cmp' => $ctx->cmpId];

        $kind = Http::param('kind');
        if ($kind !== null && $kind !== '') {
            $where[] = 'l.link_kind = :kind';
            $bindings['kind'] = strtoupper($kind);
        }

        if (Http::param('expiring') === '1') {
            $where[] = "l.status = 'ACTIVE' AND l.expires_at BETWEEN NOW() AND NOW() + INTERVAL '3 days'";
        }

        $sql = 'FROM pay_payment_links l JOIN pay_payment_requests r ON r.request_id = l.request_id
                WHERE ' . implode(' AND ', $where);

        $total = (int) Db::scalar('SELECT COUNT(*) ' . $sql, $bindings);

        $rows = Db::all(
            'SELECT l.*, r.request_uuid, r.source_reference, r.amount_minor, r.paid_minor,
                    r.refunded_minor, r.currency, r.payer_name, r.status AS request_status ' . $sql
            . ' ORDER BY l.' . $params['sort'] . ' ' . $params['order'] . ' NULLS LAST LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $params['limit'], 'offset' => $params['offset']],
        );

        $out = [];
        foreach ($rows as $row) {
            $currency = (string) $row['currency'];
            $outstanding = max(0, (int) $row['amount_minor'] - (int) $row['paid_minor'] + (int) $row['refunded_minor']);

            $out[] = \Aicountly\Api\Domain\LinkService::present($row) + [
                'payment_request_id' => (string) $row['request_uuid'],
                'reference'   => $row['source_reference'] === null ? null : (string) $row['source_reference'],
                'payer'       => $row['payer_name'] === null ? null : (string) $row['payer_name'],
                'amount'      => \Aicountly\Api\Domain\Money::minor((int) $row['amount_minor'], $currency)->toMajor(),
                'paid'        => \Aicountly\Api\Domain\Money::minor((int) $row['paid_minor'], $currency)->toMajor(),
                'outstanding' => \Aicountly\Api\Domain\Money::minor($outstanding, $currency)->toMajor(),
                'currency'    => $currency,
                'request_status' => (string) $row['request_status'],
            ];
        }

        Http::list($out, $total, $params['limit'], $params['offset']);
    }

    // -------------------------------------------------------------- pay pulse

    public static function insights(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'pay_pulse.view');

        InsightEngine::compute($ctx);

        Http::data([
            'insights' => InsightEngine::headlines($ctx, 20, Http::param('capability')),
            'enabled'  => InsightEngine::enabled($ctx),
            'can_act'  => Permissions::allows($ctx, $auth, 'pay_pulse.act'),
        ]);
    }

    /**
     * Mark an insight as acted on or dismissed.
     *
     * NOT "apply this recommendation". A routing change goes through the
     * routing endpoint with its own permission and its own audit entry, because
     * a one-click "do what the AI said" is how an automated decision loses its
     * paper trail.
     */
    public static function updateInsight(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'pay_pulse.view');

        $status = strtoupper((string) (Http::param('status') ?? ''));
        if (!in_array($status, ['SEEN', 'DISMISSED', 'ACTIONED'], true)) {
            Http::validationFailed('An insight can be marked SEEN, DISMISSED or ACTIONED.', ['field' => 'status']);
        }

        if ($status !== 'SEEN') {
            Permissions::assert($ctx, $auth, 'pay_pulse.act');
        }

        $affected = Db::update(InsightEngine::TABLE, array_filter([
            'status'        => $status,
            'dismissed_by'  => $status === 'DISMISSED' ? $auth->uuid : null,
            'dismissed_at'  => $status === 'DISMISSED' ? gmdate('Y-m-d H:i:s') : null,
            'applied_by'    => $status === 'ACTIONED' ? $auth->uuid : null,
            'applied_at'    => $status === 'ACTIONED' ? gmdate('Y-m-d H:i:s') : null,
        ], static fn ($v) => $v !== null), ['insight_uuid' => $uuid, 'cmp_id' => $ctx->cmpId]);

        if ($affected === 0) {
            Http::notFound('That insight could not be found.');
        }

        Http::data(['updated' => true]);
    }

    // ------------------------------------------------------------------ audit

    public static function audit(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'audit.view');

        $params = Http::listParams(['created_at'], 'created_at');

        $total = (int) Db::scalar('SELECT COUNT(*) FROM pay_audit_log WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);

        $rows = Db::all(
            'SELECT * FROM pay_audit_log WHERE cmp_id = :cmp
             ORDER BY created_at ' . $params['order'] . ' LIMIT :limit OFFSET :offset',
            ['cmp' => $ctx->cmpId, 'limit' => $params['limit'], 'offset' => $params['offset']],
        );

        Http::list(
            array_map(static fn (array $row) => [
                'audit_id'    => (int) $row['audit_id'],
                'action'      => (string) $row['action'],
                'entity_type' => (string) $row['entity_type'],
                'entity_id'   => $row['entity_id'] === null ? null : (string) $row['entity_id'],
                'actor'       => (string) $row['actor_uuid'],
                'actor_kind'  => (string) $row['actor_kind'],
                'source_app'  => $row['source_app'] === null ? null : (string) $row['source_app'],
                // Secrets were redacted on the way in; see src/Audit.php.
                'before'      => Db::jsonColumn($row['before_state'] ?? null),
                'after'       => Db::jsonColumn($row['after_state'] ?? null),
                'reason'      => $row['reason'] === null ? null : (string) $row['reason'],
                'ip'          => $row['ip_address'] === null ? null : (string) $row['ip_address'],
                'at'          => (string) $row['created_at'],
            ], $rows),
            $total,
            $params['limit'],
            $params['offset'],
        );
    }
}
