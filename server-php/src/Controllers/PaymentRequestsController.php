<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Domain\LinkService;
use Aicountly\Api\Domain\Money;
use Aicountly\Api\Domain\PaymentRequestService;
use Aicountly\Api\Domain\PaymentService;
use Aicountly\Api\Domain\States;
use Aicountly\Api\Http;
use Aicountly\Api\Payments\Sources\SourceRegistry;
use Aicountly\Api\Permissions;

/**
 * Payment requests.
 *
 * This controller serves both the Pay UI and the public API, which is why the
 * permission check differs per caller: a human needs
 * `payment_requests.create`, a merchant's integration needs the
 * `payment_requests.write` SCOPE, and the two vocabularies never map onto each
 * other. See Permissions.
 */
final class PaymentRequestsController extends Controller
{
    public static function index(): never
    {
        [$auth, $ctx] = self::enter();
        $auth->isApiClient()
            ? Permissions::assertScope($auth, 'payment_requests.read')
            : Permissions::assert($ctx, $auth, 'payment_requests.view');

        $params = Http::listParams(['created_at', 'amount_minor', 'expires_at', 'status'], 'created_at');
        [$scope, $bindings] = $ctx->scopeClause('r');

        $where = [$scope];
        $filters = [];

        $status = Http::param('status');
        if ($status !== null && $status !== '' && in_array($status, States::all('request'), true)) {
            $where[] = 'r.status = :status';
            $bindings['status'] = $status;
            $filters['status'] = $status;
        }

        $sourceApp = Http::param('source_app');
        if ($sourceApp !== null && $sourceApp !== '') {
            $where[] = 'r.source_app = :source_app';
            $bindings['source_app'] = strtoupper($sourceApp);
            $filters['source_app'] = strtoupper($sourceApp);
        }

        if (($from = Http::param('from')) !== null && $from !== '') {
            $where[] = 'r.created_at >= :from';
            $bindings['from'] = $from;
        }
        if (($to = Http::param('to')) !== null && $to !== '') {
            $where[] = 'r.created_at < :to';
            $bindings['to'] = $to;
        }

        if ($params['q'] !== '') {
            // Reference, description or payer. Never a raw LIKE on the whole
            // row — an unindexed scan on a payments table is a slow query
            // anybody can trigger from a search box.
            $where[] = '(r.source_reference ILIKE :q OR r.description ILIKE :q OR r.payer_name ILIKE :q OR r.request_uuid = :exact)';
            $bindings['q'] = '%' . $params['q'] . '%';
            $bindings['exact'] = $params['q'];
        }

        $sql = 'FROM ' . PaymentRequestService::TABLE . ' r WHERE ' . implode(' AND ', $where);

        $total = (int) Db::scalar('SELECT COUNT(*) ' . $sql, $bindings);

        $rows = Db::all(
            'SELECT r.* ' . $sql . ' ORDER BY r.' . $params['sort'] . ' ' . $params['order']
            . ' LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $params['limit'], 'offset' => $params['offset']],
        );

        Http::list(
            array_map(static fn (array $row) => PaymentRequestService::present($ctx, $row), $rows),
            $total,
            $params['limit'],
            $params['offset'],
            ['filters' => $filters],
        );
    }

    public static function create(): never
    {
        [$auth, $ctx] = self::enter();
        $auth->isApiClient()
            ? Permissions::assertScope($auth, 'payment_requests.write')
            : Permissions::assert($ctx, $auth, 'payment_requests.create');

        $body = Http::body();

        // An API client's requests are attributed to whatever source_app the
        // merchant registered it under, never to what the body claims.
        if ($auth->isApiClient()) {
            $body['source_app'] = $auth->sourceApp;
        }

        Http::data(PaymentRequestService::create($ctx, $auth, $body), 201);
    }

    public static function show(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        $auth->isApiClient()
            ? Permissions::assertScope($auth, 'payment_requests.read')
            : Permissions::assert($ctx, $auth, 'payment_requests.view');

        $request = PaymentRequestService::findByUuid($ctx, $uuid);
        if ($request === null) {
            Http::notFound('That payment request could not be found.');
        }

        $technical = !$auth->isApiClient() && Permissions::allows($ctx, $auth, 'developer.view');

        $attempts = Db::all(
            'SELECT * FROM ' . PaymentService::TABLE . ' WHERE request_id = :id AND cmp_id = :cmp ORDER BY created_at ASC',
            ['id' => (int) $request['request_id'], 'cmp' => $ctx->cmpId],
        );

        $links = Db::all(
            'SELECT * FROM ' . LinkService::TABLE . ' WHERE request_id = :id ORDER BY created_at DESC',
            ['id' => (int) $request['request_id']],
        );

        Http::data(PaymentRequestService::present($ctx, $request) + [
            'payments' => array_map(static fn (array $row) => PaymentService::present($ctx, $row, $technical), $attempts),
            'links'    => array_map(static fn (array $row) => LinkService::present($row), $links),
            // Where it came from, so the screen can link back to the invoice.
            // Read live and only for this one screen — never on a list.
            'source'   => self::sourceDocument($ctx, $auth, $request),
        ]);
    }

    public static function cancel(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        $auth->isApiClient()
            ? Permissions::assertScope($auth, 'payment_requests.write')
            : Permissions::assert($ctx, $auth, 'payment_requests.cancel');

        $request = PaymentRequestService::findByUuid($ctx, $uuid);
        if ($request === null) {
            Http::notFound('That payment request could not be found.');
        }

        $reason = trim((string) (Http::param('reason') ?? ''));
        if ($reason === '') {
            Http::validationFailed('Say why this request is being cancelled.', ['field' => 'reason']);
        }

        Http::data(PaymentRequestService::cancel($ctx, $auth, (int) $request['request_id'], $reason));
    }

    /** Extend the expiry of a request that has run out of time. */
    public static function extend(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'payment_requests.create');

        $request = PaymentRequestService::findByUuid($ctx, $uuid);
        if ($request === null) {
            Http::notFound('That payment request could not be found.');
        }

        if ((string) $request['status'] === States::REQUEST_CANCELLED) {
            Http::conflict('A cancelled request cannot be extended. Raise a new one.');
        }

        $expiresAt = (string) (Http::param('expires_at') ?? '');
        $parsed = $expiresAt === '' ? false : strtotime($expiresAt);
        if ($parsed === false || $parsed <= time()) {
            Http::validationFailed('Choose a new expiry in the future.', ['field' => 'expires_at']);
        }

        Db::update(PaymentRequestService::TABLE, [
            'expires_at' => gmdate('Y-m-d H:i:s', $parsed),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['request_id' => (int) $request['request_id'], 'cmp_id' => $ctx->cmpId]);

        // Any link that expired with it comes back too, capped at the new date.
        Db::run(
            'UPDATE ' . LinkService::TABLE . '
             SET status = :active, expires_at = :expires, updated_at = NOW()
             WHERE request_id = :id AND status = :expired',
            ['active' => LinkService::ACTIVE, 'expires' => gmdate('Y-m-d H:i:s', $parsed),
             'id' => (int) $request['request_id'], 'expired' => LinkService::EXPIRED],
        );

        // Recalculate, so an EXPIRED request becomes ACTIVE or PARTIALLY_PAID
        // according to what is actually on it.
        Http::data(PaymentRequestService::present($ctx, PaymentRequestService::recalculate($ctx, (int) $request['request_id'])));
    }

    public static function createLink(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        $auth->isApiClient()
            ? Permissions::assertScope($auth, 'links.write')
            : Permissions::assert($ctx, $auth, 'links.manage');

        $request = PaymentRequestService::findByUuid($ctx, $uuid);
        if ($request === null) {
            Http::notFound('That payment request could not be found.');
        }

        Http::data(LinkService::create($ctx, $auth, $request, Http::body()), 201);
    }

    public static function createQr(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        $auth->isApiClient()
            ? Permissions::assertScope($auth, 'links.write')
            : Permissions::assert($ctx, $auth, 'links.manage');

        $request = PaymentRequestService::findByUuid($ctx, $uuid);
        if ($request === null) {
            Http::notFound('That payment request could not be found.');
        }

        Http::data(LinkService::createQr($ctx, $auth, $request, Http::body()), 201);
    }

    /**
     * The source document, read live.
     *
     * Only on the detail screen, never on a list: one HTTP call per row would
     * make a page of fifty requests fifty round trips to Books. A failure here
     * degrades the panel rather than the page.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private static function sourceDocument(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth, array $request): ?array
    {
        $sourceApp = (string) $request['source_app'];
        if ($sourceApp === 'PAY' || $request['source_id'] === null) {
            return null;
        }

        $adapter = SourceRegistry::for($sourceApp);
        if (!$adapter->isAvailable()) {
            return ['available' => false, 'reason' => $adapter->displayName() . ' is not available in this deployment.'];
        }

        $result = $adapter->fetchSourceDocument($ctx, (string) $request['source_id'], [
            'ses_key'     => $auth->sesKey(),
            'actor_uuid'  => $auth->uuid,
            'source_type' => (string) ($request['source_type'] ?? ''),
        ]);

        if (!($result['ok'] ?? false)) {
            // Says which product and why, so a merchant knows whether to wait
            // or to go and look.
            return [
                'available' => false,
                'app'       => $adapter->displayName(),
                'reason'    => (string) ($result['error_message'] ?? 'The source document could not be read.'),
            ];
        }

        return ['available' => true, 'app' => $adapter->displayName(), 'document' => $result['document']];
    }
}
