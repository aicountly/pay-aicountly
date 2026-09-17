<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Http;
use Aicountly\Api\Payments\Providers\ProviderRegistry;
use Aicountly\Api\Payments\Routing\RoutingEngine;
use Aicountly\Api\Permissions;

/**
 * Payment links and QR codes — ways to REACH a payment request.
 *
 * A link is not a second kind of request. One ₹1,00,000 request can carry a
 * WhatsApp link, an emailed link and a printed QR, all collecting against the
 * same balance and all sharing its paid total. Modelling them as separate
 * requests is how a customer pays twice.
 *
 * THE TOKEN IS RANDOM AND NOT DERIVED FROM THE REQUEST. Two links against the
 * same request must not be guessable from one another, or a customer who was
 * forwarded one could reach the other — which matters when the merchant sent
 * them to different people.
 *
 * REUSABLE QR CODES ARE ONLY OFFERED WHERE THE PROVIDER REALLY HAS THEM. A
 * static QR printed on a counter card that silently stops working after one
 * customer is worse than no QR at all, so `is_reusable` records what the
 * provider actually gave us rather than what we asked for.
 */
final class LinkService
{
    public const TABLE = 'pay_payment_links';

    public const ACTIVE    = 'ACTIVE';
    public const EXPIRED   = 'EXPIRED';
    public const CANCELLED = 'CANCELLED';
    public const CONSUMED  = 'CONSUMED';

    /** Channels a link can be sent through. Used for the effectiveness table. */
    public const CHANNELS = ['LINK', 'WHATSAPP', 'EMAIL', 'SMS', 'QR', 'IN_APP', 'EMBEDDED'];

    /**
     * Create a link for a request.
     *
     * @param array{channel?:string, expires_at?:string, sent_to?:string, use_provider_link?:bool} $input
     * @return array<string, mixed>
     */
    public static function create(Context $ctx, Auth $auth, array $request, array $input): array
    {
        Permissions::assert($ctx, $auth, 'links.manage');

        $payable = PaymentRequestService::assertPayable($request);
        if (!$payable['ok']) {
            Http::conflict($payable['error_message'], ['code' => $payable['error_code']]);
        }

        $channel = strtoupper((string) ($input['channel'] ?? 'LINK'));
        if (!in_array($channel, self::CHANNELS, true)) {
            Http::validationFailed('That is not a channel a link can be sent through.', ['field' => 'channel', 'options' => self::CHANNELS]);
        }

        // A link never outlives its request. A link that works after the
        // request expired is the expiry not having happened.
        $expiresAt = self::resolveExpiry($input['expires_at'] ?? null, $request['expires_at'] ?? null);

        $linkId = (int) Db::insert(self::TABLE, [
            'link_uuid'    => Ids::mint(Ids::LINK),
            'cmp_id'       => $ctx->cmpId,
            'request_id'   => (int) $request['request_id'],
            'link_kind'    => 'LINK',
            'public_token' => Ids::token(),
            'channel'      => $channel,
            'status'       => self::ACTIVE,
            'expires_at'   => $expiresAt,
            'sent_to'      => isset($input['sent_to']) ? substr((string) $input['sent_to'], 0, 200) : null,
            'sent_at'      => isset($input['sent_to']) ? gmdate('Y-m-d H:i:s') : null,
            'created_by'   => $auth->uuid,
        ], 'link_id');

        // A provider-hosted link is optional. Pay's own checkout works for every
        // provider; a hosted one is offered where the provider does it better.
        if (($input['use_provider_link'] ?? false) === true) {
            self::attachProviderLink($ctx, $linkId, $request, $expiresAt);
        }

        return self::present(self::require($ctx, $linkId));
    }

    /**
     * Create a QR code for a request.
     *
     * @param array{reusable?:bool, expires_at?:string} $input
     * @return array<string, mixed>
     */
    public static function createQr(Context $ctx, Auth $auth, array $request, array $input): array
    {
        Permissions::assert($ctx, $auth, 'links.manage');

        $payable = PaymentRequestService::assertPayable($request);
        if (!$payable['ok']) {
            Http::conflict($payable['error_message'], ['code' => $payable['error_code']]);
        }

        $wantsReusable = (bool) ($input['reusable'] ?? false);
        $expiresAt = self::resolveExpiry($input['expires_at'] ?? null, $request['expires_at'] ?? null);

        // QR means UPI in practice, so the routing engine is asked for a
        // provider that actually takes UPI — rather than creating the QR first
        // and discovering at scan time that nobody can serve it.
        $routed = RoutingEngine::choose($ctx, [
            'method'       => 'UPI',
            'currency'     => (string) $request['currency'],
            'amount_minor' => (int) $request['amount_minor'],
            'source_app'   => (string) $request['source_app'],
        ]);

        if (!$routed['ok']) {
            Http::conflict($routed['error_message'], ['code' => $routed['error_code']]);
        }

        $provider = $routed['provider'];
        $capabilities = $provider->capabilities();

        if ($wantsReusable && !($capabilities['static_qr'] ?? false)) {
            // Refused rather than quietly downgraded. A merchant asking for a
            // reusable QR is about to print it, and they need to know now.
            Http::validationFailed(
                ProviderRegistry::displayNameFor($provider->code()) . ' does not issue reusable QR codes. A single-use QR can be created instead.',
                ['field' => 'reusable', 'provider' => $provider->code()],
            );
        }

        if (!($capabilities['dynamic_qr'] ?? false)) {
            Http::conflict(
                ProviderRegistry::displayNameFor($provider->code()) . ' does not issue QR codes. Send a payment link instead.',
                ['code' => 'qr_unsupported'],
            );
        }

        $result = $provider->createQrCode([
            'amount_minor' => (int) $request['amount_minor'],
            'currency'     => (string) $request['currency'],
            'reference'    => (string) ($request['source_reference'] ?? $request['request_uuid']),
            'description'  => (string) ($request['description'] ?? 'Payment'),
            'reusable'     => $wantsReusable,
            'expires_at'   => $expiresAt,
            'request_uuid' => (string) $request['request_uuid'],
        ]);

        if (!($result['ok'] ?? false)) {
            Http::error(
                502,
                (string) ($result['error_code'] ?? 'qr_failed'),
                (string) ($result['error_message'] ?? 'The QR code could not be created.'),
            );
        }

        $linkId = (int) Db::insert(self::TABLE, [
            'link_uuid'        => Ids::mint(Ids::LINK),
            'cmp_id'           => $ctx->cmpId,
            'request_id'       => (int) $request['request_id'],
            'link_kind'        => 'QR',
            'public_token'     => Ids::token(),
            'channel'          => 'QR',
            'provider_code'    => $provider->code(),
            'provider_link_id' => $result['provider_qr_id'] ?? null,
            'qr_payload'       => $result['payload'] ?? null,
            'provider_link_url' => $result['image_url'] ?? null,
            // What the provider ACTUALLY gave us, not what was asked for.
            'is_reusable'      => (bool) ($result['reusable'] ?? false),
            'status'           => self::ACTIVE,
            'expires_at'       => $expiresAt,
            'created_by'       => $auth->uuid,
        ], 'link_id');

        return self::present(self::require($ctx, $linkId));
    }

    /**
     * Attach a provider-hosted link to one we already made.
     *
     * Best-effort: if the provider cannot host one, Pay's own checkout still
     * works and the link is simply ours. That is why a failure here logs rather
     * than throws.
     */
    private static function attachProviderLink(Context $ctx, int $linkId, array $request, ?string $expiresAt): void
    {
        $routed = RoutingEngine::choose($ctx, [
            'currency'     => (string) $request['currency'],
            'amount_minor' => (int) $request['amount_minor'],
            'source_app'   => (string) $request['source_app'],
        ]);

        if (!$routed['ok']) {
            return;
        }

        $provider = $routed['provider'];
        if (!($provider->capabilities()['hosted_link'] ?? false)) {
            return;
        }

        $result = $provider->createPaymentLink([
            'amount_minor'      => (int) $request['amount_minor'],
            'currency'          => (string) $request['currency'],
            'reference'         => (string) ($request['source_reference'] ?? $request['request_uuid']),
            'description'       => (string) ($request['description'] ?? 'Payment'),
            'payer'             => [
                'name'   => $request['payer_name'] ?? null,
                'email'  => $request['payer_email'] ?? null,
                'mobile' => $request['payer_mobile'] ?? null,
            ],
            'allow_partial'     => $request['allow_partial'] ?? false,
            'min_partial_minor' => $request['min_partial_minor'] ?? null,
            'expires_at'        => $expiresAt,
            'return_url'        => $request['return_url'] ?? null,
            'link_uuid'         => (string) Db::scalar('SELECT link_uuid FROM ' . self::TABLE . ' WHERE link_id = :id', ['id' => $linkId]),
        ]);

        if (!($result['ok'] ?? false)) {
            error_log('[links] provider-hosted link unavailable, using the Aicountly checkout: ' . (string) ($result['error_code'] ?? '?'));

            return;
        }

        Db::update(self::TABLE, [
            'provider_code'     => $provider->code(),
            'provider_link_id'  => $result['provider_link_id'] ?? null,
            'provider_link_url' => $result['url'] ?? null,
            'updated_at'        => gmdate('Y-m-d H:i:s'),
        ], ['link_id' => $linkId]);
    }

    /**
     * Find a link by its public token.
     *
     * NOT company-scoped, by necessity — the caller is a stranger on the
     * internet with no company context. Safety comes from the token itself
     * being 192 bits of randomness, and from the company being read OFF the
     * link rather than accepted from the request.
     *
     * @return array<string, mixed>|null
     */
    public static function findByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 20) {
            // Short-circuit obvious probing before it reaches the database.
            return null;
        }

        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE public_token = :token', ['token' => $token]);
    }

    public static function cancel(Context $ctx, Auth $auth, int $linkId): array
    {
        Permissions::assert($ctx, $auth, 'links.manage');

        Db::update(self::TABLE, [
            'status'     => self::CANCELLED,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['link_id' => $linkId, 'cmp_id' => $ctx->cmpId]);

        return self::present(self::require($ctx, $linkId));
    }

    /** The URL a payer opens. */
    public static function publicUrl(string $token): string
    {
        $base = Env::get('PAY_PUBLIC_BASE');
        if ($base === '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'pay.aicountly.com');
            $scheme = (($_SERVER['HTTPS'] ?? '') !== '' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'https';
            $base = $scheme . '://' . $host;
        }

        return rtrim($base, '/') . '/pay/p/' . $token;
    }

    private static function resolveExpiry(mixed $given, mixed $requestExpiry): ?string
    {
        $requestTs = $requestExpiry === null ? null : strtotime((string) $requestExpiry);

        if (is_string($given) && trim($given) !== '') {
            $ts = strtotime($given);
            if ($ts === false || $ts <= time()) {
                Http::validationFailed('The expiry has to be a date in the future.', ['field' => 'expires_at']);
            }
            // Capped at the request's own expiry.
            if ($requestTs !== null && $ts > $requestTs) {
                return gmdate('Y-m-d H:i:s', $requestTs);
            }

            return gmdate('Y-m-d H:i:s', $ts);
        }

        return $requestTs === null ? null : gmdate('Y-m-d H:i:s', $requestTs);
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $linkId): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE link_id = :id AND cmp_id = :cmp', ['id' => $linkId, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed>|null */
    public static function findByUuid(Context $ctx, string $uuid): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE link_uuid = :uuid AND cmp_id = :cmp', ['uuid' => $uuid, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed> */
    public static function require(Context $ctx, int $linkId): array
    {
        $row = self::find($ctx, $linkId);
        if ($row === null) {
            Http::notFound('That link could not be found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    public static function present(array $row): array
    {
        return [
            'link_id'    => (string) $row['link_uuid'],
            'kind'       => (string) $row['link_kind'],
            'channel'    => (string) $row['channel'],
            'status'     => (string) $row['status'],
            // The provider's hosted URL where there is one, ours otherwise.
            'url'        => $row['provider_link_url'] !== null && (string) $row['link_kind'] === 'LINK'
                ? (string) $row['provider_link_url']
                : self::publicUrl((string) $row['public_token']),
            'qr_payload' => $row['qr_payload'] === null ? null : (string) $row['qr_payload'],
            'reusable'   => $row['is_reusable'] === true || $row['is_reusable'] === 't',
            'expires_at' => $row['expires_at'] === null ? null : (string) $row['expires_at'],
            'views'      => (int) $row['view_count'],
            'checkout_starts' => (int) $row['checkout_count'],
            'sent_to'    => $row['sent_to'] === null ? null : (string) $row['sent_to'],
            'sent_at'    => $row['sent_at'] === null ? null : (string) $row['sent_at'],
            'created_at' => (string) $row['created_at'],
            'provider'   => $row['provider_code'] === null ? null : (string) $row['provider_code'],
        ];
    }
}
