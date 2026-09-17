<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Crypto;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Ids;
use Aicountly\Api\Env;
use Aicountly\Api\Http;
use Aicountly\Api\Payments\Providers\ProviderRegistry;
use Aicountly\Api\Permissions;

/**
 * Connecting, testing and re-keying payment providers.
 *
 * THE RULE THAT GOVERNS EVERY RESPONSE IN THIS FILE: a provider secret goes IN
 * and never comes back out. The screen is told "Configured" and a masked tail
 * from the connection row; there is no endpoint here that returns a credential,
 * and there must never be one. Somebody who can read a merchant's Razorpay
 * secret can collect that merchant's money outside Aicountly entirely.
 */
final class ProvidersController extends Controller
{
    /** Which credentials each provider needs, in the order a wizard asks for them. */
    private const REQUIRED_CREDENTIALS = [
        'RAZORPAY' => ['key_id' => 'Key ID', 'key_secret' => 'Key Secret', 'webhook_secret' => 'Webhook Secret'],
        'CASHFREE' => ['key_id' => 'App ID', 'key_secret' => 'Secret Key', 'webhook_secret' => 'Webhook Secret'],
        'STRIPE'   => ['key_id' => 'Publishable Key', 'key_secret' => 'Secret Key', 'webhook_secret' => 'Signing Secret'],
        'PAYU'     => ['key_id' => 'Merchant Key', 'key_secret' => 'Merchant Salt'],
        'MOCK'     => ['key_id' => 'Any value', 'key_secret' => 'Any value', 'webhook_secret' => 'Any value'],
    ];

    public static function index(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'providers.view');

        $out = [];
        foreach (ProviderRegistry::connectionsFor($ctx) as $entry) {
            $connection = $entry['connection'];
            $provider = $entry['provider'];
            $id = (int) $connection['connection_id'];

            $out[] = [
                'connection_id' => (string) $connection['connection_uuid'],
                'provider'      => (string) $connection['provider_code'],
                'name'          => (string) $connection['display_name'],
                'mode'          => (string) $connection['provider_mode'],
                'status'        => (string) $connection['status'],
                'status_reason' => $connection['status_reason'] === null ? null : (string) $connection['status_reason'],
                'environment'   => (string) $connection['environment'],
                'is_primary'    => $connection['is_primary'] === true || $connection['is_primary'] === 't',
                'priority'      => (int) $connection['priority'],
                'methods'       => Db::jsonColumn($connection['supported_methods'] ?? null),
                'currencies'    => Db::jsonColumn($connection['supported_currencies'] ?? null),
                'capabilities'  => $provider?->capabilities() ?? [],
                // Never a value. "Configured" and a tail.
                'credentials'   => ProviderRegistry::credentialStatus($id),
                'required_credentials' => self::REQUIRED_CREDENTIALS[(string) $connection['provider_code']] ?? [],
                'webhook_url'   => self::webhookUrl($connection),
                'webhook_verified' => $connection['webhook_verified_at'] !== null,
                'merchant_ref'  => $connection['merchant_ref'] === null ? null : (string) $connection['merchant_ref'],
                'connected_at'  => $connection['connected_at'] === null ? null : (string) $connection['connected_at'],
                'usable'        => $provider !== null && (string) $connection['status'] === 'ACTIVE',
            ];
        }

        Http::data([
            'connections' => $out,
            'available'   => ProviderRegistry::available(),
            'managed'     => ProviderRegistry::managedReadiness(),
            'credential_requirements' => self::REQUIRED_CREDENTIALS,
            'encryption_ready' => Crypto::isConfigured(),
        ]);
    }

    /**
     * Connect a provider, or re-key one.
     *
     * The credentials are verified against the provider BEFORE the connection
     * is marked active. A connection saved without testing is one that fails
     * silently at the first real payment, with a customer watching.
     */
    public static function create(): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'providers.manage');

        if (!Crypto::isConfigured()) {
            // Refused outright rather than stored in the clear.
            Http::error(
                503,
                'encryption_not_configured',
                'This deployment has no encryption key, so a gateway credential cannot be stored safely. Set PAY_ENCRYPTION_KEY in the API environment first.',
            );
        }

        $body = Http::body();
        $code = strtoupper(trim((string) ($body['provider'] ?? '')));

        $available = array_column(ProviderRegistry::available(), 'code');
        if (!in_array($code, $available, true)) {
            Http::validationFailed(
                'That payment provider is not available on this deployment.',
                ['field' => 'provider', 'options' => $available],
            );
        }

        $environment = strtoupper((string) ($body['environment'] ?? 'LIVE'));
        if (!in_array($environment, ['TEST', 'LIVE'], true)) {
            Http::validationFailed('A connection is either TEST or LIVE.', ['field' => 'environment']);
        }

        $credentials = is_array($body['credentials'] ?? null) ? $body['credentials'] : [];
        $required = self::REQUIRED_CREDENTIALS[$code] ?? [];

        foreach ($required as $kind => $label) {
            // The webhook secret can be added afterwards — a merchant often has
            // to create the webhook at the provider first, using the URL we give
            // them here.
            if ($kind === 'webhook_secret') {
                continue;
            }
            if (!isset($credentials[$kind]) || trim((string) $credentials[$kind]) === '') {
                Http::validationFailed('Enter the ' . $label . '.', ['field' => 'credentials.' . $kind]);
            }
        }

        $existing = Db::first(
            'SELECT * FROM ' . ProviderRegistry::CONNECTIONS . '
             WHERE cmp_id = :cmp AND provider_code = :code AND provider_mode = :mode AND environment = :env',
            ['cmp' => $ctx->cmpId, 'code' => $code, 'mode' => 'DIRECT', 'env' => $environment],
        );

        $connectionId = $existing === null
            ? (int) Db::insert(ProviderRegistry::CONNECTIONS, [
                'connection_uuid' => Ids::mint(Ids::CONNECTION),
                'cmp_id'          => $ctx->cmpId,
                'provider_code'   => $code,
                'provider_mode'   => 'DIRECT',
                'display_name'    => trim((string) ($body['display_name'] ?? ('My ' . ProviderRegistry::displayNameFor($code)))),
                'status'          => 'PENDING',
                'environment'     => $environment,
                'merchant_ref'    => isset($body['merchant_ref']) ? substr((string) $body['merchant_ref'], 0, 120) : null,
                'connected_by'    => $auth->uuid,
                'connected_at'    => gmdate('Y-m-d H:i:s'),
            ], 'connection_id')
            : (int) $existing['connection_id'];

        foreach ($credentials as $kind => $value) {
            if (!isset($required[$kind]) || trim((string) $value) === '') {
                continue;
            }
            ProviderRegistry::storeCredential($ctx, $connectionId, (string) $kind, trim((string) $value), $auth->uuid);
        }

        // Record the masked tail of the key id, so the merchant can tell two
        // keys apart without ever being shown one.
        if (isset($credentials['key_id'])) {
            Db::update(ProviderRegistry::CONNECTIONS, [
                'key_hint' => Crypto::mask(trim((string) $credentials['key_id'])),
            ], ['connection_id' => $connectionId]);
        }

        Audit::record(
            $ctx,
            $auth,
            $existing === null ? Audit::PROVIDER_CONNECTED : Audit::PROVIDER_CREDENTIALS_SET,
            'provider_connection',
            $connectionId,
            null,
            // Never the values. Which kinds changed, and nothing else.
            ['provider' => $code, 'environment' => $environment, 'credentials_set' => array_keys($credentials)],
        );

        ProviderRegistry::forget();

        // Test it before declaring it connected.
        $verification = self::verify($ctx, $connectionId);

        Http::data($verification, $existing === null ? 201 : 200);
    }

    /** Test a connection's credentials against the provider. */
    public static function test(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'providers.manage');

        $connection = self::requireConnection($ctx, $uuid);
        ProviderRegistry::forget();

        Http::data(self::verify($ctx, (int) $connection['connection_id']));
    }

    /** @param array<string, mixed> $body */
    public static function update(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'providers.manage');

        $connection = self::requireConnection($ctx, $uuid);
        $body = Http::body();
        $updates = ['updated_at' => gmdate('Y-m-d H:i:s')];

        if (isset($body['display_name'])) {
            $updates['display_name'] = substr(trim((string) $body['display_name']), 0, 120);
        }
        if (isset($body['priority'])) {
            $updates['priority'] = max(1, min(999, (int) $body['priority']));
        }
        if (isset($body['is_primary'])) {
            $updates['is_primary'] = (bool) $body['is_primary'];
            if ($updates['is_primary']) {
                // Only one primary per company. Two would leave the router with
                // no way to break the tie.
                Db::run(
                    'UPDATE ' . ProviderRegistry::CONNECTIONS . ' SET is_primary = FALSE WHERE cmp_id = :cmp',
                    ['cmp' => $ctx->cmpId],
                );
            }
        }
        if (isset($body['supported_methods']) && is_array($body['supported_methods'])) {
            $updates['supported_methods'] = array_values(array_filter(array_map(
                static fn ($m) => is_string($m) ? strtoupper($m) : null,
                $body['supported_methods'],
            )));
        }
        if (isset($body['status'])) {
            $status = strtoupper((string) $body['status']);
            if (!in_array($status, ['ACTIVE', 'DISABLED'], true)) {
                Http::validationFailed('A connection can be set to ACTIVE or DISABLED.', ['field' => 'status']);
            }
            $updates['status'] = $status;
            $updates['disabled_at'] = $status === 'DISABLED' ? gmdate('Y-m-d H:i:s') : null;
        }

        Db::update(ProviderRegistry::CONNECTIONS, $updates, ['connection_id' => (int) $connection['connection_id']]);

        Audit::record(
            $ctx,
            $auth,
            ($updates['status'] ?? null) === 'DISABLED' ? Audit::PROVIDER_DISABLED : Audit::PROVIDER_UPDATED,
            'provider_connection',
            (int) $connection['connection_id'],
            ['status' => (string) $connection['status'], 'display_name' => (string) $connection['display_name']],
            $updates,
        );

        ProviderRegistry::forget();
        Http::data(['updated' => true]);
    }

    /**
     * Disconnect.
     *
     * Disabled rather than deleted when payments exist against it. The
     * connection is what a settlement and a refund are traced through, and
     * removing it would orphan them.
     */
    public static function delete(string $uuid): never
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'providers.manage');

        $connection = self::requireConnection($ctx, $uuid);
        $connectionId = (int) $connection['connection_id'];

        $payments = (int) (Db::scalar(
            'SELECT COUNT(*) FROM pay_payment_attempts WHERE connection_id = :id',
            ['id' => $connectionId],
        ) ?? 0);

        if ($payments > 0) {
            Db::update(ProviderRegistry::CONNECTIONS, [
                'status'        => 'DISABLED',
                'status_reason' => 'Disconnected by ' . $auth->displayName() . '.',
                'disabled_at'   => gmdate('Y-m-d H:i:s'),
            ], ['connection_id' => $connectionId]);

            // The credentials go even though the row stays: a disabled
            // connection has no business still holding a live secret.
            Db::run('DELETE FROM ' . ProviderRegistry::CREDENTIALS . ' WHERE connection_id = :id', ['id' => $connectionId]);

            Audit::record($ctx, $auth, Audit::PROVIDER_DISABLED, 'provider_connection', $connectionId, null, [
                'kept_for_history' => true, 'payments' => $payments,
            ]);

            ProviderRegistry::forget();
            Http::data([
                'disabled' => true,
                'deleted'  => false,
                'note'     => $payments . ' payments were taken through this provider, so the connection is kept for their history. Its credentials have been removed.',
            ]);
        }

        Db::run('DELETE FROM ' . ProviderRegistry::CONNECTIONS . ' WHERE connection_id = :id', ['id' => $connectionId]);
        Audit::record($ctx, $auth, Audit::PROVIDER_DISABLED, 'provider_connection', $connectionId, null, ['deleted' => true]);

        ProviderRegistry::forget();
        Http::data(['deleted' => true]);
    }

    /**
     * Run the provider's own health check and record what it said.
     *
     * @return array<string, mixed>
     */
    private static function verify(\Aicountly\Api\Context $ctx, int $connectionId): array
    {
        $connection = Db::first(
            'SELECT * FROM ' . ProviderRegistry::CONNECTIONS . ' WHERE connection_id = :id',
            ['id' => $connectionId],
        );

        if ($connection === null) {
            Http::notFound('That connection could not be found.');
        }

        $provider = ProviderRegistry::forConnection($connection);

        if ($provider === null) {
            $fresh = Db::first('SELECT status, status_reason FROM ' . ProviderRegistry::CONNECTIONS . ' WHERE connection_id = :id', ['id' => $connectionId]);

            return [
                'ok'     => false,
                'status' => (string) ($fresh['status'] ?? 'CREDENTIALS_INVALID'),
                'detail' => (string) ($fresh['status_reason'] ?? 'This provider could not be prepared with the credentials given.'),
                'connection_id' => (string) $connection['connection_uuid'],
            ];
        }

        $health = $provider->healthCheck();

        Db::update(ProviderRegistry::CONNECTIONS, [
            'status'             => $health['ok'] ? 'ACTIVE' : (($health['error_code'] ?? '') === 'credentials_invalid' ? 'CREDENTIALS_INVALID' : 'PENDING'),
            'status_reason'      => $health['ok'] ? null : (string) $health['detail'],
            'health_state'       => $health['ok'] ? 'HEALTHY' : 'UNHEALTHY',
            'health_checked_at'  => gmdate('Y-m-d H:i:s'),
            'supported_methods'  => $provider->supportedMethods(),
            'supported_currencies' => $provider->supportedCurrencies(),
            'capabilities'       => $provider->capabilities(),
            'webhook_url'        => self::webhookUrl($connection),
            'updated_at'         => gmdate('Y-m-d H:i:s'),
        ], ['connection_id' => $connectionId]);

        return [
            'ok'     => (bool) $health['ok'],
            'status' => $health['ok'] ? 'ACTIVE' : 'PENDING',
            'detail' => (string) $health['detail'],
            'latency_ms' => (int) $health['latency_ms'],
            'connection_id' => (string) $connection['connection_uuid'],
            // Given to the merchant to paste into the provider's dashboard. It
            // carries the connection uuid so a callback is attributable even
            // when the payload names no merchant.
            'webhook_url' => self::webhookUrl($connection),
            'methods'     => $provider->supportedMethods(),
            'capabilities' => $provider->capabilities(),
        ];
    }

    /** @param array<string, mixed> $connection */
    private static function webhookUrl(array $connection): string
    {
        $base = Env::get('PAY_PUBLIC_BASE');
        if ($base === '') {
            $base = 'https://' . (string) ($_SERVER['HTTP_HOST'] ?? 'pay.aicountly.com');
        }

        return rtrim($base, '/') . '/api/v1/webhooks/' . strtolower((string) $connection['provider_code'])
            . '?c=' . rawurlencode((string) $connection['connection_uuid']);
    }

    /** @return array<string, mixed> */
    private static function requireConnection(\Aicountly\Api\Context $ctx, string $uuid): array
    {
        $row = Db::first(
            'SELECT * FROM ' . ProviderRegistry::CONNECTIONS . ' WHERE connection_uuid = :uuid AND cmp_id = :cmp',
            ['uuid' => $uuid, 'cmp' => $ctx->cmpId],
        );

        if ($row === null) {
            Http::notFound('That provider connection could not be found.');
        }

        return $row;
    }
}
