<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Providers;

use Aicountly\Api\Context;
use Aicountly\Api\Crypto;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Payments\Providers\Contracts\ManagedPaymentProviderInterface;
use Aicountly\Api\Payments\Providers\Contracts\PaymentProviderInterface;

/**
 * Turns a stored connection into a working provider, and refuses to when it
 * should not.
 *
 * This is the only class that opens a credential, the only one that names a
 * provider class, and the only one that decides whether a provider may be used
 * at all. Everything else asks it for an object and calls the interface.
 *
 * THE FOUR REFUSALS, each of which exists because of a specific way payment
 * systems go wrong:
 *
 *  1. A provider whose feature flag is off is not offered. A deployment turns
 *     Stripe off and no merchant can reach it, whatever their connection row
 *     says.
 *  2. A MOCK provider is never built in production. See MockProvider.
 *  3. A TEST credential in a LIVE connection is refused, and the reverse. The
 *     key prefix is checked against the connection's environment, because
 *     Razorpay and Stripe both serve test and live from one host and the only
 *     thing distinguishing them is the key — so a mis-pasted key takes real
 *     money on a screen labelled sandbox.
 *  4. A connection whose credentials will not decrypt is marked invalid rather
 *     than used with empty strings. Calling a gateway with a blank secret
 *     produces a 401 that looks like the merchant's account was suspended.
 */
final class ProviderRegistry
{
    public const CONNECTIONS = 'pay_provider_connections';
    public const CREDENTIALS = 'pay_provider_credentials';

    /** provider code => class, and whether it can onboard merchants. */
    private const CATALOG = [
        'RAZORPAY' => ['class' => Razorpay\RazorpayProvider::class, 'flag' => 'DIRECT_RAZORPAY_ENABLED', 'managed' => false],
        'CASHFREE' => ['class' => Cashfree\CashfreeProvider::class, 'flag' => 'DIRECT_CASHFREE_ENABLED', 'managed' => true],
        'STRIPE'   => ['class' => Stripe\StripeProvider::class,     'flag' => 'DIRECT_STRIPE_ENABLED',   'managed' => false],
        'PAYU'     => ['class' => PayU\PayUProvider::class,         'flag' => 'DIRECT_PAYU_ENABLED',     'managed' => false],
        'MOCK'     => ['class' => Mock\MockProvider::class,         'flag' => 'MOCK_PROVIDER_ENABLED',   'managed' => true],
    ];

    /** Which provider backs Aicountly Managed, per its own flags. */
    private const MANAGED_FLAGS = [
        'CASHFREE' => 'MANAGED_CASHFREE_ENABLED',
        'RAZORPAY' => 'MANAGED_RAZORPAY_ENABLED',
        'MOCK'     => 'MOCK_PROVIDER_ENABLED',
    ];

    /** @var array<int, PaymentProviderInterface|null> */
    private static array $built = [];

    /**
     * Provider codes this deployment will accept a connection for.
     *
     * Read by the Gateways screen so a merchant is never shown a provider they
     * cannot actually connect, and by the connection wizard so a request naming
     * a disabled provider is refused rather than half-saved.
     *
     * @return list<array{code:string, name:string, mode:string, managed_capable:bool}>
     */
    public static function available(): array
    {
        $out = [];
        foreach (self::CATALOG as $code => $entry) {
            if (!self::flagEnabled($entry['flag'], $code === 'MOCK' ? false : true)) {
                continue;
            }
            if ($code === 'MOCK' && self::isProduction()) {
                continue;
            }
            $out[] = [
                'code'            => $code,
                'name'            => self::displayNameFor($code),
                'mode'            => 'DIRECT',
                'managed_capable' => $entry['managed'] && self::managedEnabled($code),
            ];
        }

        return $out;
    }

    /** Which provider, if any, Aicountly Managed runs on in this deployment. */
    public static function managedProviderCode(): ?string
    {
        foreach (array_keys(self::MANAGED_FLAGS) as $code) {
            if (self::managedEnabled($code)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Whether Managed is merely architected here, or actually usable.
     *
     * The distinction the product must never blur: the flag being on means a
     * partner has been chosen; the partner credentials being present means a
     * merchant can really apply. The onboarding screen reads both and says
     * which.
     *
     * @return array{available:bool, provider:?string, reason:?string}
     */
    public static function managedReadiness(): array
    {
        $code = self::managedProviderCode();
        if ($code === null) {
            return [
                'available' => false,
                'provider'  => null,
                'reason'    => 'Aicountly Managed Payments is not enabled on this deployment.',
            ];
        }

        if (self::partnerCredentials($code) === []) {
            return [
                'available' => false,
                'provider'  => $code,
                'reason'    => 'Partner credentials not configured. Aicountly Managed cannot accept applications until they are.',
            ];
        }

        return ['available' => true, 'provider' => $code, 'reason' => null];
    }

    /**
     * Build the provider for a connection row.
     *
     * Returns null — never a half-working object — when the connection cannot
     * be used. The caller reports the connection's own `status_reason`, which
     * this method has by then written.
     *
     * @param array<string, mixed> $connection a pay_provider_connections row
     */
    public static function forConnection(array $connection): ?PaymentProviderInterface
    {
        $connectionId = (int) ($connection['connection_id'] ?? 0);
        if ($connectionId > 0 && array_key_exists($connectionId, self::$built)) {
            return self::$built[$connectionId];
        }

        $code = strtoupper((string) ($connection['provider_code'] ?? ''));
        $entry = self::CATALOG[$code] ?? null;

        if ($entry === null) {
            error_log('[provider-registry] unknown provider code "' . $code . '" on connection ' . $connectionId);

            return self::$built[$connectionId] = null;
        }

        if ($code === 'MOCK' && self::isProduction()) {
            error_log('[provider-registry] refusing to build the mock provider in production');

            return self::$built[$connectionId] = null;
        }

        $mode = strtoupper((string) ($connection['provider_mode'] ?? 'DIRECT'));

        if ($mode === 'MANAGED') {
            if (!self::managedEnabled($code)) {
                return self::$built[$connectionId] = null;
            }
        } elseif (!self::flagEnabled($entry['flag'], true)) {
            return self::$built[$connectionId] = null;
        }

        $credentials = $mode === 'MANAGED'
            // Managed runs on AICOUNTLY's partner credentials, not the
            // merchant's. That is the whole difference between the two modes,
            // and it is why a managed connection holds no credential rows.
            ? self::partnerCredentials($code)
            : self::openCredentials($connectionId);

        if ($credentials === []) {
            self::markUnusable(
                $connectionId,
                $mode === 'MANAGED' ? 'partner_not_configured' : 'credentials_invalid',
                $mode === 'MANAGED'
                    ? 'Aicountly Managed is not configured on this deployment.'
                    : 'The stored credentials for this provider could not be read. Re-enter them to reconnect.',
            );

            return self::$built[$connectionId] = null;
        }

        $environment = strtoupper((string) ($connection['environment'] ?? 'LIVE'));
        $mismatch = self::environmentMismatch($code, $credentials, $environment);
        if ($mismatch !== null) {
            self::markUnusable($connectionId, 'environment_mismatch', $mismatch);

            return self::$built[$connectionId] = null;
        }

        /** @var class-string<PaymentProviderInterface> $class */
        $class = $entry['class'];

        try {
            $provider = new $class($credentials, $connection);
        } catch (\Throwable $e) {
            error_log('[provider-registry] could not build ' . $code . ': ' . $e->getMessage());

            return self::$built[$connectionId] = null;
        }

        return self::$built[$connectionId] = $provider;
    }

    /** The managed provider for a company, or null when Managed is not usable. */
    public static function managedFor(Context $ctx): ?ManagedPaymentProviderInterface
    {
        $code = self::managedProviderCode();
        if ($code === null) {
            return null;
        }

        $connection = Db::first(
            'SELECT * FROM ' . self::CONNECTIONS . ' WHERE cmp_id = :cmp AND provider_mode = :mode AND provider_code = :code',
            ['cmp' => $ctx->cmpId, 'mode' => 'MANAGED', 'code' => $code],
        );

        // A company that has not applied yet still needs a provider object to
        // apply THROUGH, so a synthetic connection stands in until the real row
        // exists. It carries no credentials of its own — the partner's are used.
        $connection ??= [
            'connection_id' => 0,
            'cmp_id'        => $ctx->cmpId,
            'provider_code' => $code,
            'provider_mode' => 'MANAGED',
            'display_name'  => 'Aicountly Managed',
            'environment'   => self::isProduction() ? 'LIVE' : 'TEST',
        ];

        $provider = self::forConnection($connection);

        return $provider instanceof ManagedPaymentProviderInterface ? $provider : null;
    }

    /**
     * Every connection a company has, with its provider where one could be built.
     *
     * @return list<array{connection:array<string,mixed>, provider:?PaymentProviderInterface}>
     */
    public static function connectionsFor(Context $ctx, bool $activeOnly = false): array
    {
        // Deliberately company-wide rather than using Context::scopeClause():
        // a gateway is an account the business holds, and a branch does not get
        // its own Razorpay. Narrowing by bo_id here would hide the company's
        // only provider from every branch user.
        $sql = 'SELECT * FROM ' . self::CONNECTIONS . ' WHERE cmp_id = :cmp';
        $params = ['cmp' => $ctx->cmpId];

        if ($activeOnly) {
            $sql .= " AND status = 'ACTIVE'";
        }
        $sql .= ' ORDER BY is_primary DESC, priority ASC, connection_id ASC';

        $out = [];
        foreach (Db::all($sql, $params) as $row) {
            $out[] = ['connection' => $row, 'provider' => self::forConnection($row)];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function connection(Context $ctx, int $connectionId): ?array
    {
        return Db::first(
            'SELECT * FROM ' . self::CONNECTIONS . ' WHERE connection_id = :id AND cmp_id = :cmp',
            ['id' => $connectionId, 'cmp' => $ctx->cmpId],
        );
    }

    /**
     * Store a credential, encrypted.
     *
     * The plaintext is used exactly twice here: once to derive the masked hint,
     * once to seal it. It is never logged, never returned, and never written
     * anywhere else.
     */
    public static function storeCredential(Context $ctx, int $connectionId, string $kind, string $plaintext, string $actorUuid): void
    {
        $sealed = Crypto::seal($plaintext);
        $hint = Crypto::mask($plaintext);

        Db::run(
            'INSERT INTO ' . self::CREDENTIALS . ' (cmp_id, connection_id, credential_kind, secret_value, masked_hint, rotated_at, rotated_by)
             VALUES (:cmp, :conn, :kind, :value, :hint, NOW(), :actor)
             ON CONFLICT (connection_id, credential_kind) DO UPDATE
                SET secret_value = EXCLUDED.secret_value,
                    masked_hint  = EXCLUDED.masked_hint,
                    rotated_at   = NOW(),
                    rotated_by   = EXCLUDED.rotated_by',
            [
                'cmp'   => $ctx->cmpId,
                'conn'  => $connectionId,
                'kind'  => $kind,
                'value' => $sealed,
                'hint'  => $hint,
                'actor' => $actorUuid,
            ],
        );

        // The built object holds the old credentials.
        unset(self::$built[$connectionId]);
    }

    /**
     * Which credentials a connection holds, WITHOUT their values.
     *
     * This is what the Gateways screen reads. It answers "configured" and a
     * masked tail, and there is deliberately no method on this class that
     * returns a credential in a shape a controller could serialise.
     *
     * @return array<string, array{configured:bool, hint:?string, rotated_at:?string}>
     */
    public static function credentialStatus(int $connectionId): array
    {
        $rows = Db::all(
            'SELECT credential_kind, masked_hint, rotated_at FROM ' . self::CREDENTIALS . ' WHERE connection_id = :conn',
            ['conn' => $connectionId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['credential_kind']] = [
                'configured' => true,
                'hint'       => $row['masked_hint'] === null ? null : (string) $row['masked_hint'],
                'rotated_at' => $row['rotated_at'] === null ? null : (string) $row['rotated_at'],
            ];
        }

        return $out;
    }

    /** Test seam: built providers are per request in production. */
    public static function forget(): void
    {
        self::$built = [];
    }

    // -----------------------------------------------------------------------

    /** @return array<string, string> plaintext credentials, or [] when they cannot be read */
    private static function openCredentials(int $connectionId): array
    {
        if ($connectionId <= 0) {
            return [];
        }

        $rows = Db::all(
            'SELECT credential_kind, secret_value FROM ' . self::CREDENTIALS . ' WHERE connection_id = :conn',
            ['conn' => $connectionId],
        );

        $out = [];
        foreach ($rows as $row) {
            $opened = Crypto::open((string) $row['secret_value']);
            if ($opened === null) {
                // One unreadable credential poisons the whole connection. Using
                // the rest would call the gateway with a missing secret, which
                // fails in a way that reads as the merchant's account being
                // suspended rather than as our key being wrong.
                error_log('[provider-registry] credential "' . $row['credential_kind'] . '" on connection ' . $connectionId . ' could not be decrypted');

                return [];
            }
            $out[(string) $row['credential_kind']] = $opened;
        }

        return $out;
    }

    /**
     * Aicountly's own partner credentials for Managed mode, from api/.env.
     *
     * Not in the database: they belong to the deployment, not to a merchant,
     * and a company row should never be able to reach them.
     *
     * @return array<string, string>
     */
    private static function partnerCredentials(string $code): array
    {
        $prefix = 'PAY_MANAGED_' . $code . '_';

        $keyId = Env::get($prefix . 'KEY_ID');
        $keySecret = Env::get($prefix . 'KEY_SECRET');

        if ($keyId === '' || $keySecret === '' || str_starts_with($keyId, 'CHANGE_ME') || str_starts_with($keySecret, 'CHANGE_ME')) {
            return [];
        }

        return array_filter([
            'key_id'         => $keyId,
            'key_secret'     => $keySecret,
            'webhook_secret' => Env::get($prefix . 'WEBHOOK_SECRET'),
        ], static fn (string $v) => $v !== '');
    }

    /**
     * A test key in a live connection, or the reverse.
     *
     * Razorpay keys carry `rzp_test_` / `rzp_live_`; Stripe's carry `sk_test_`
     * / `sk_live_`. Cashfree and PayU have no prefix convention, so they are
     * not checked — a check that guesses would refuse valid credentials.
     *
     * @param array<string, string> $credentials
     */
    private static function environmentMismatch(string $code, array $credentials, string $environment): ?string
    {
        $key = $credentials['key_id'] ?? ($credentials['key_secret'] ?? '');
        if ($key === '') {
            return null;
        }

        $looksTest = match ($code) {
            'RAZORPAY' => str_starts_with($key, 'rzp_test_'),
            'STRIPE'   => str_contains($key, '_test_'),
            default    => null,
        };

        if ($looksTest === null) {
            return null;
        }

        if ($looksTest && $environment === 'LIVE') {
            return 'This connection is marked Live but the key is a test key. No real payment would be taken.';
        }
        if (!$looksTest && $environment === 'TEST') {
            return 'This connection is marked Test but the key is a live key. A real customer would really be charged.';
        }

        return null;
    }

    private static function markUnusable(int $connectionId, string $code, string $reason): void
    {
        if ($connectionId <= 0) {
            return;
        }

        try {
            Db::update(self::CONNECTIONS, [
                'status'        => $code === 'environment_mismatch' ? 'DISABLED' : 'CREDENTIALS_INVALID',
                'status_reason' => $reason,
                'updated_at'    => gmdate('Y-m-d H:i:s'),
            ], ['connection_id' => $connectionId]);
        } catch (\Throwable $e) {
            error_log('[provider-registry] could not mark connection ' . $connectionId . ' unusable: ' . $e->getMessage());
        }
    }

    /**
     * A feature flag, defaulting to on for the real providers.
     *
     * The default matters: a host that has not thought about flags should be
     * able to connect Razorpay, and should NOT be able to reach the mock. So
     * real providers default on and the mock defaults off.
     */
    private static function flagEnabled(string $flag, bool $default): bool
    {
        $value = strtolower(Env::get($flag));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function managedEnabled(string $code): bool
    {
        $flag = self::MANAGED_FLAGS[$code] ?? null;
        if ($flag === null) {
            return false;
        }
        if ($code === 'MOCK') {
            return !self::isProduction() && self::flagEnabled($flag, false);
        }

        // Managed defaults OFF everywhere. A deployment turns it on when a
        // partner agreement exists, and not before.
        return self::flagEnabled($flag, false);
    }

    private static function isProduction(): bool
    {
        return strtolower(Env::get('APP_ENV', 'production')) === 'production';
    }

    public static function displayNameFor(string $code): string
    {
        return match (strtoupper($code)) {
            'RAZORPAY' => 'Razorpay',
            'CASHFREE' => 'Cashfree',
            'STRIPE'   => 'Stripe',
            'PAYU'     => 'PayU',
            'MOCK'     => 'Test provider',
            'EXTERNAL' => 'Recorded outside Pay',
            default    => ucfirst(strtolower($code)),
        };
    }
}
