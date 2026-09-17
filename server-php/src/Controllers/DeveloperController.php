<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Developer\ApiKeys;
use Aicountly\Api\Http;
use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Events\Outbox;
use Aicountly\Api\Permissions;

/**
 * The developer surface: API clients, keys, webhook endpoints and the logs.
 *
 * This is how software outside the fleet — a merchant's own site, a custom ERP,
 * or an Aicountly product that does not exist yet — uses Pay. The same contract
 * serves all three, which is what makes wiring a new app in later a
 * configuration step rather than a release.
 */
final class DeveloperController extends Controller
{
    public static function overview(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'developer.view');

        $clients = Db::all(
            'SELECT * FROM ' . ApiKeys::CLIENTS . ' WHERE cmp_id = :cmp ORDER BY created_at DESC',
            ['cmp' => $ctx->cmpId],
        );

        $keys = Db::all(
            'SELECT k.*, c.client_uuid, c.client_name FROM ' . ApiKeys::KEYS . ' k
             JOIN ' . ApiKeys::CLIENTS . ' c ON c.client_id = k.client_id
             WHERE k.cmp_id = :cmp ORDER BY k.created_at DESC',
            ['cmp' => $ctx->cmpId],
        );

        $endpoints = Db::all(
            'SELECT * FROM pay_webhook_endpoints WHERE cmp_id = :cmp ORDER BY created_at DESC',
            ['cmp' => $ctx->cmpId],
        );

        Http::data([
            'clients'   => array_map(static fn (array $row) => ApiKeys::presentClient($row), $clients),
            'keys'      => array_map(static fn (array $row) => ApiKeys::present($row) + [
                'client_id'   => (string) $row['client_uuid'],
                'client_name' => (string) $row['client_name'],
            ], $keys),
            'endpoints' => array_map(static fn (array $row) => ApiKeys::presentEndpoint($row), $endpoints),
            'scopes'    => Permissions::API_SCOPES,
            'events'    => EventNames::deliverableToMerchants(),
            'can_manage' => Permissions::allows($ctx, $auth, 'developer.manage'),
            // The whole public contract, described where a developer will look
            // for it rather than only in a document they have to find.
            'api'       => self::apiReference(),
        ]);
    }

    public static function createClient(): never
    {
        [$auth, $ctx] = self::enter();
        Http::data(ApiKeys::createClient($ctx, $auth, Http::body()), 201);
    }

    public static function createKey(): never
    {
        [$auth, $ctx] = self::enter();
        Http::data(ApiKeys::create($ctx, $auth, Http::body()), 201);
    }

    public static function revokeKey(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        Http::data(ApiKeys::revoke($ctx, $auth, $uuid));
    }

    public static function createEndpoint(): never
    {
        [$auth, $ctx] = self::enter();
        Http::data(ApiKeys::createEndpoint($ctx, $auth, Http::body()), 201);
    }

    /**
     * Inbound webhook log.
     *
     * The raw payload is NOT returned. It can carry a payer's contact details
     * and, on some providers, an address — and this screen is open to anybody
     * holding `developer.view`. What is returned is enough to debug: the event
     * type, whether the signature verified, and what we did with it.
     */
    public static function webhookLog(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'developer.view');

        $params = Http::listParams(['received_at'], 'received_at');

        $total = (int) Db::scalar(
            'SELECT COUNT(*) FROM pay_webhook_events WHERE cmp_id = :cmp',
            ['cmp' => $ctx->cmpId],
        );

        $rows = Db::all(
            'SELECT event_uuid, provider_code, provider_event_type, provider_event_id, normalized_event,
                    status, signature_valid, signature_reason, process_error, processing_ms, received_at
             FROM pay_webhook_events WHERE cmp_id = :cmp
             ORDER BY received_at ' . $params['order'] . ' LIMIT :limit OFFSET :offset',
            ['cmp' => $ctx->cmpId, 'limit' => $params['limit'], 'offset' => $params['offset']],
        );

        Http::list(
            array_map(static fn (array $row) => [
                'event_id'       => (string) $row['event_uuid'],
                'provider'       => (string) $row['provider_code'],
                'provider_event' => $row['provider_event_type'] === null ? null : (string) $row['provider_event_type'],
                'event'          => $row['normalized_event'] === null ? null : (string) $row['normalized_event'],
                'status'         => (string) $row['status'],
                'signature_valid' => $row['signature_valid'] === true || $row['signature_valid'] === 't',
                'signature_reason' => $row['signature_reason'] === null ? null : (string) $row['signature_reason'],
                'error'          => $row['process_error'] === null ? null : (string) $row['process_error'],
                'processing_ms'  => $row['processing_ms'] === null ? null : (int) $row['processing_ms'],
                'received_at'    => (string) $row['received_at'],
            ], $rows),
            $total,
            $params['limit'],
            $params['offset'],
        );
    }

    /** Outbound delivery log, and the button that retries one. */
    public static function outboundLog(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'developer.view');

        $params = Http::listParams(['created_at'], 'created_at');

        $where = ['cmp_id = :cmp'];
        $bindings = ['cmp' => $ctx->cmpId];

        $status = Http::param('status');
        if ($status !== null && $status !== '') {
            $where[] = 'status = :status';
            $bindings['status'] = strtoupper($status);
        }

        $sql = 'FROM pay_outbound_events WHERE ' . implode(' AND ', $where);
        $total = (int) Db::scalar('SELECT COUNT(*) ' . $sql, $bindings);

        $rows = Db::all(
            'SELECT outbound_id, event_uuid, event_name, target_app, target_url, status,
                    attempt_count, max_attempts, response_status, response_excerpt, last_error,
                    next_attempt_at, delivered_at, created_at ' . $sql
            . ' ORDER BY created_at ' . $params['order'] . ' LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $params['limit'], 'offset' => $params['offset']],
        );

        Http::list(
            array_map(static fn (array $row) => [
                'outbound_id' => (int) $row['outbound_id'],
                'event_id'    => (string) $row['event_uuid'],
                'event'       => (string) $row['event_name'],
                'event_label' => EventNames::label((string) $row['event_name']),
                'target'      => (string) $row['target_app'],
                'url'         => (string) $row['target_url'],
                'status'      => (string) $row['status'],
                'attempts'    => (int) $row['attempt_count'],
                'max_attempts' => (int) $row['max_attempts'],
                'response_status' => $row['response_status'] === null ? null : (int) $row['response_status'],
                'response'    => $row['response_excerpt'] === null ? null : (string) $row['response_excerpt'],
                'last_error'  => $row['last_error'] === null ? null : (string) $row['last_error'],
                'next_attempt_at' => $row['next_attempt_at'] === null ? null : (string) $row['next_attempt_at'],
                'delivered_at' => $row['delivered_at'] === null ? null : (string) $row['delivered_at'],
                'created_at'  => (string) $row['created_at'],
                'can_retry'   => in_array((string) $row['status'], [Outbox::EXHAUSTED, Outbox::FAILED], true),
            ], $rows),
            $total,
            $params['limit'],
            $params['offset'],
        );
    }

    /** Send an exhausted callback again, by hand. */
    public static function retryOutbound(int $outboundId): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'developer.manage');

        if (!Outbox::retry($ctx, $outboundId)) {
            Http::notFound('That event could not be found.');
        }

        \Aicountly\Api\Audit::record($ctx, $auth, \Aicountly\Api\Audit::CALLBACK_RETRIED, 'outbound_event', $outboundId);

        Http::data(['queued' => true]);
    }

    /**
     * The public API contract, served from the product itself.
     *
     * @return array<string, mixed>
     */
    private static function apiReference(): array
    {
        return [
            'base_url' => '/api/v1',
            'auth'     => [
                'scheme' => 'Authorization: Bearer pk_live_xxx.secretsecret',
                'note'   => 'A key is bound to one company. The company is read off the key, and a cmp_id in the request is ignored.',
            ],
            'idempotency' => [
                'header' => 'Idempotency-Key',
                'note'   => 'Required on refunds. Strongly advised on payment request creation: the same key replays the first answer rather than creating a second request.',
            ],
            'endpoints' => [
                ['method' => 'POST',  'path' => '/payment-requests',              'scope' => 'payment_requests.write', 'summary' => 'Raise a payment request'],
                ['method' => 'GET',   'path' => '/payment-requests',              'scope' => 'payment_requests.read',  'summary' => 'List payment requests'],
                ['method' => 'GET',   'path' => '/payment-requests/{id}',         'scope' => 'payment_requests.read',  'summary' => 'One request, with its payments'],
                ['method' => 'POST',  'path' => '/payment-requests/{id}/cancel',  'scope' => 'payment_requests.write', 'summary' => 'Cancel a request'],
                ['method' => 'POST',  'path' => '/payment-requests/{id}/link',    'scope' => 'links.write',            'summary' => 'Create a payment link'],
                ['method' => 'POST',  'path' => '/payment-requests/{id}/qr',      'scope' => 'links.write',            'summary' => 'Create a QR code'],
                ['method' => 'GET',   'path' => '/payments',                      'scope' => 'payments.read',          'summary' => 'List payments'],
                ['method' => 'GET',   'path' => '/payments/{id}',                 'scope' => 'payments.read',          'summary' => 'One payment'],
                ['method' => 'POST',  'path' => '/refunds',                       'scope' => 'refunds.write',          'summary' => 'Request a refund'],
                ['method' => 'GET',   'path' => '/settlements',                   'scope' => 'settlements.read',       'summary' => 'List settlements'],
            ],
            'webhooks' => [
                'signature_header' => 'X-Pay-Signature',
                'format' => 't=<unix seconds>,v1=<hmac sha256 of "<t>.<raw body>">',
                'note'   => 'Verify with the signing secret shown once when the endpoint was created. Compare in constant time. Reject a timestamp more than five minutes old.',
                'events' => EventNames::deliverableToMerchants(),
            ],
            // The minimum a future app has to send. Anything beyond this is
            // optional, which is what "integrate later without changing Pay"
            // means in practice.
            'minimum_request' => [
                'source_app'  => 'YOUR_APP',
                'source_type' => 'INVOICE',
                'source_id'   => '1042',
                'reference'   => 'INV-2026-1042',
                'amount'      => 1180.00,
                'currency'    => 'INR',
                'payer_name'  => 'Priya Mehta',
                'payer_mobile' => '9876543210',
            ],
        ];
    }
}
