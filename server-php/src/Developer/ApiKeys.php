<?php

declare(strict_types=1);

namespace Aicountly\Api\Developer;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Crypto;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Ids;
use Aicountly\Api\Env;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * API keys — how software that is not an Aicountly product uses Pay.
 *
 * WHY THIS EXISTS AS A FIRST-CLASS SURFACE and not an afterthought: a merchant's
 * own website, a custom ERP, and a future Aicountly app that has not been built
 * yet are the same case from Pay's point of view. All three authenticate with a
 * key, POST the generic payment-request contract, and receive signed webhooks.
 * Building that properly now is what makes "wire the new app in later" a
 * configuration step rather than a release.
 *
 * THE KEY IS TWO HALVES:
 *
 *   pk_live_7rxJf8Kq   the prefix. Public, indexed, appears in logs and on
 *                      screen, and is what the lookup is done by.
 *   .AbCdEf…           the secret. HASHED, never stored, never shown again.
 *
 * HASHED AND NOT ENCRYPTED, deliberately. A provider credential has to be
 * presented back to the provider, so it must be reversible. A key we issued is
 * only ever COMPARED, so it is hashed — and a leak of this table buys nothing.
 *
 * SCOPES, NOT ROLES. An API key holds a short list from Permissions::API_SCOPES
 * and the two vocabularies deliberately do not overlap. Mapping a human's
 * `refunds.approve` onto a key is how a read-only integration ends up able to
 * send money.
 *
 * A KEY IS BOUND TO ONE COMPANY, read off the key and never from the request.
 */
final class ApiKeys
{
    public const CLIENTS = 'pay_api_clients';
    public const KEYS = 'pay_api_keys';

    private const LIVE_PREFIX = 'pk_live_';
    private const TEST_PREFIX = 'pk_test_';

    /** Cheap enough to run before a portal round trip, which is the point. */
    public static function looksLikeApiKey(string $token): bool
    {
        return str_starts_with($token, self::LIVE_PREFIX) || str_starts_with($token, self::TEST_PREFIX);
    }

    /**
     * Authenticate a presented key.
     *
     * Returns null rather than 401-ing so Auth can fall through consistently.
     */
    public static function authenticate(string $presented): ?Auth
    {
        // `<prefix>.<secret>`. Splitting on the last dot lets the prefix carry
        // its own underscores without ambiguity.
        $dot = strrpos($presented, '.');
        if ($dot === false) {
            return null;
        }

        $prefix = substr($presented, 0, $dot);
        $secret = substr($presented, $dot + 1);
        if ($prefix === '' || $secret === '') {
            return null;
        }

        try {
            $row = Db::first(
                'SELECT k.*, c.source_app, c.status AS client_status
                 FROM ' . self::KEYS . ' k
                 JOIN ' . self::CLIENTS . ' c ON c.client_id = k.client_id
                 WHERE k.key_prefix = :prefix',
                ['prefix' => $prefix],
            );
        } catch (\Throwable $e) {
            error_log('[api-keys] lookup failed: ' . $e->getMessage());

            return null;
        }

        if ($row === null) {
            // Still do a hash, so a missing key and a wrong secret take the
            // same time. Otherwise the endpoint tells an attacker which
            // prefixes exist.
            Crypto::hashSecret($secret);

            return null;
        }

        if ((string) $row['status'] !== 'ACTIVE' || (string) $row['client_status'] !== 'ACTIVE') {
            return null;
        }

        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        if (!Crypto::verifySecret($secret, (string) $row['secret_hash'])) {
            return null;
        }

        self::touch((int) $row['key_id']);

        $scopes = array_values(array_filter(
            Db::jsonColumn($row['scopes'] ?? null),
            static fn ($s) => is_string($s) && isset(Permissions::API_SCOPES[$s]),
        ));

        return Auth::forApiClient(
            (string) $row['key_uuid'],
            (string) $row['source_app'],
            (int) $row['cmp_id'],
            $scopes,
            strtoupper((string) $row['environment']) === 'TEST',
        );
    }

    /**
     * Mint a key.
     *
     * The secret is returned EXACTLY ONCE, here, and never again. That is the
     * contract a developer expects and the only way the hash is worth anything.
     *
     * @param array{client_id:string, environment?:string, scopes:list<string>, expires_at?:string} $input
     * @return array<string, mixed>
     */
    public static function create(Context $ctx, Auth $auth, array $input): array
    {
        Permissions::assert($ctx, $auth, 'developer.manage');

        $client = self::requireClient($ctx, (string) ($input['client_id'] ?? ''));
        $environment = strtoupper((string) ($input['environment'] ?? 'TEST'));

        if (!in_array($environment, ['TEST', 'LIVE'], true)) {
            Http::validationFailed('An API key is either TEST or LIVE.', ['field' => 'environment']);
        }

        // A live key on a sandbox deployment would let sandbox software take
        // real money.
        if ($environment === 'LIVE' && strtolower(Env::get('APP_ENV', 'production')) !== 'production') {
            Http::validationFailed(
                'Live API keys can only be created on the production deployment.',
                ['field' => 'environment'],
            );
        }

        $scopes = self::validateScopes($input['scopes'] ?? []);
        if ($scopes === []) {
            Http::validationFailed(
                'Choose at least one thing this key may do.',
                ['field' => 'scopes', 'options' => array_keys(Permissions::API_SCOPES)],
            );
        }

        $prefix = ($environment === 'LIVE' ? self::LIVE_PREFIX : self::TEST_PREFIX) . Crypto::token(6);
        $secret = Crypto::token(32);

        $keyId = (int) Db::insert(self::KEYS, [
            'key_uuid'    => Ids::mint('KEY'),
            'cmp_id'      => $ctx->cmpId,
            'client_id'   => (int) $client['client_id'],
            'key_prefix'  => $prefix,
            'secret_hash' => Crypto::hashSecret($secret),
            'environment' => $environment,
            'scopes'      => $scopes,
            'status'      => 'ACTIVE',
            'expires_at'  => isset($input['expires_at']) && $input['expires_at'] !== ''
                ? gmdate('Y-m-d H:i:s', strtotime((string) $input['expires_at']) ?: time() + 31536000) : null,
            'created_by'  => $auth->uuid,
        ], 'key_id');

        Audit::record($ctx, $auth, Audit::API_KEY_CREATED, 'api_key', $keyId, null, [
            'prefix'      => $prefix,
            'environment' => $environment,
            'scopes'      => $scopes,
            'client'      => (string) $client['client_name'],
        ]);

        $row = Db::first('SELECT * FROM ' . self::KEYS . ' WHERE key_id = :id', ['id' => $keyId]);

        return self::present($row ?? []) + [
            // Once. The caller is told, and the UI says so.
            'secret' => $prefix . '.' . $secret,
            'secret_notice' => 'Copy this now. It is hashed on our side and cannot be shown again.',
        ];
    }

    public static function revoke(Context $ctx, Auth $auth, string $keyUuid): array
    {
        Permissions::assert($ctx, $auth, 'developer.manage');

        $row = Db::first(
            'SELECT * FROM ' . self::KEYS . ' WHERE key_uuid = :uuid AND cmp_id = :cmp',
            ['uuid' => $keyUuid, 'cmp' => $ctx->cmpId],
        );

        if ($row === null) {
            Http::notFound('That API key could not be found.');
        }

        Db::update(self::KEYS, [
            'status'     => 'REVOKED',
            'revoked_by' => $auth->uuid,
            'revoked_at' => gmdate('Y-m-d H:i:s'),
        ], ['key_id' => (int) $row['key_id'], 'cmp_id' => $ctx->cmpId]);

        Audit::record($ctx, $auth, Audit::API_KEY_REVOKED, 'api_key', (int) $row['key_id'], null, [
            'prefix' => (string) $row['key_prefix'],
        ]);

        $fresh = Db::first('SELECT * FROM ' . self::KEYS . ' WHERE key_id = :id', ['id' => (int) $row['key_id']]);

        return self::present($fresh ?? []);
    }

    /**
     * Register an API client.
     *
     * `source_app` is what payment requests from this client are attributed to.
     * A future Aicountly app registers under its own name and appears correctly
     * in "Open requests by source" from its first payment — which is the whole
     * mechanism by which a new product wires into Pay without Pay changing.
     *
     * @param array{name:string, description?:string, source_app?:string} $input
     */
    public static function createClient(Context $ctx, Auth $auth, array $input): array
    {
        Permissions::assert($ctx, $auth, 'developer.manage');

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            Http::validationFailed('Give this integration a name.', ['field' => 'name']);
        }

        $sourceApp = strtoupper(trim((string) ($input['source_app'] ?? 'EXTERNAL')));
        if (preg_match('/^[A-Z][A-Z0-9_]{1,30}$/', $sourceApp) !== 1) {
            Http::validationFailed(
                'A source app name is letters, numbers and underscores, starting with a letter.',
                ['field' => 'source_app'],
            );
        }

        // The fleet's own names are reserved: a merchant's integration must not
        // be able to mint documents that look like they came from Books.
        if (\Aicountly\Api\Payments\Sources\SourceRegistry::isFirstParty($sourceApp)) {
            Http::validationFailed(
                $sourceApp . ' is an Aicountly product and cannot be used as an integration name.',
                ['field' => 'source_app'],
            );
        }

        $clientId = (int) Db::insert(self::CLIENTS, [
            'client_uuid' => Ids::mint(Ids::CLIENT),
            'cmp_id'      => $ctx->cmpId,
            'client_name' => substr($name, 0, 120),
            'description' => isset($input['description']) ? substr((string) $input['description'], 0, 500) : null,
            'source_app'  => $sourceApp,
            'status'      => 'ACTIVE',
            'created_by'  => $auth->uuid,
        ], 'client_id');

        $row = Db::first('SELECT * FROM ' . self::CLIENTS . ' WHERE client_id = :id', ['id' => $clientId]);

        return self::presentClient($row ?? []);
    }

    /**
     * Register where Pay should send this merchant's events.
     *
     * The signing secret is generated here and shown once. It is ENCRYPTED
     * rather than hashed — unlike an API key — because we need it in the clear
     * on every delivery to compute the signature.
     *
     * @param array{target_url:string, events?:list<string>, client_id?:string} $input
     */
    public static function createEndpoint(Context $ctx, Auth $auth, array $input): array
    {
        Permissions::assert($ctx, $auth, 'developer.manage');

        $url = trim((string) ($input['target_url'] ?? ''));
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            Http::validationFailed('Enter a full https:// address for the webhook.', ['field' => 'target_url']);
        }
        if (strtolower($parts['scheme']) !== 'https' && strtolower(Env::get('APP_ENV', 'production')) === 'production') {
            // Payment events name amounts and references. Over plain HTTP they
            // are readable by anybody on the path.
            Http::validationFailed('Webhook endpoints must use https.', ['field' => 'target_url']);
        }

        $secret = Crypto::token(32);
        $events = self::validateEvents($input['events'] ?? []);

        $client = null;
        if (isset($input['client_id']) && $input['client_id'] !== '') {
            $client = self::requireClient($ctx, (string) $input['client_id']);
        }

        $endpointId = (int) Db::insert('pay_webhook_endpoints', [
            'endpoint_uuid'     => Ids::mint(Ids::ENDPOINT),
            'cmp_id'            => $ctx->cmpId,
            'client_id'         => $client === null ? null : (int) $client['client_id'],
            'target_url'        => substr($url, 0, 1000),
            'subscribed_events' => $events,
            'signing_secret'    => Crypto::seal($secret),
            'secret_hint'       => Crypto::mask($secret),
            'status'            => 'ACTIVE',
            'environment'       => strtolower(Env::get('APP_ENV', 'production')) === 'production' ? 'LIVE' : 'TEST',
            'created_by'        => $auth->uuid,
        ], 'endpoint_id');

        Audit::record($ctx, $auth, Audit::WEBHOOK_SECRET_ROTATED, 'webhook_endpoint', $endpointId, null, [
            'target_url' => $url,
            'events'     => $events === [] ? 'all' : $events,
        ]);

        $row = Db::first('SELECT * FROM pay_webhook_endpoints WHERE endpoint_id = :id', ['id' => $endpointId]);

        return self::presentEndpoint($row ?? []) + [
            'signing_secret' => $secret,
            'secret_notice'  => 'Copy this now. Use it to verify the X-Pay-Signature header. It cannot be shown again.',
        ];
    }

    /** @param list<string> $scopes @return list<string> */
    private static function validateScopes(mixed $scopes): array
    {
        if (!is_array($scopes)) {
            return [];
        }

        $out = [];
        foreach ($scopes as $scope) {
            if (is_string($scope) && isset(Permissions::API_SCOPES[$scope])) {
                $out[$scope] = true;
            }
        }

        return array_keys($out);
    }

    /** @return list<string> */
    private static function validateEvents(mixed $events): array
    {
        if (!is_array($events)) {
            return [];
        }

        $allowed = \Aicountly\Api\Payments\Events\EventNames::deliverableToMerchants();
        $out = [];
        foreach ($events as $event) {
            if (is_string($event) && in_array($event, $allowed, true)) {
                $out[$event] = true;
            }
        }

        // Empty means all, which is what a developer setting up expects.
        return array_keys($out);
    }

    /** @return array<string, mixed> */
    private static function requireClient(Context $ctx, string $clientUuid): array
    {
        $row = Db::first(
            'SELECT * FROM ' . self::CLIENTS . ' WHERE client_uuid = :uuid AND cmp_id = :cmp',
            ['uuid' => $clientUuid, 'cmp' => $ctx->cmpId],
        );

        if ($row === null) {
            Http::notFound('That integration could not be found.');
        }

        return $row;
    }

    /**
     * Note that a key was used, cheaply.
     *
     * A counter and a timestamp, so a merchant can tell a key that is still in
     * use from one they can safely revoke — without keeping a request log keyed
     * to their customers.
     */
    private static function touch(int $keyId): void
    {
        try {
            Db::run(
                'UPDATE ' . self::KEYS . ' SET last_used_at = NOW(), use_count = use_count + 1 WHERE key_id = :id',
                ['id' => $keyId],
            );
        } catch (\Throwable) {
            // Never fail a request over a usage counter.
        }
    }

    /** @param array<string, mixed> $row */
    public static function present(array $row): array
    {
        if ($row === []) {
            return [];
        }

        return [
            'key_id'      => (string) $row['key_uuid'],
            // The public half only. The secret is a hash and stays one.
            'prefix'      => (string) $row['key_prefix'],
            'environment' => (string) $row['environment'],
            'scopes'      => Db::jsonColumn($row['scopes'] ?? null),
            'status'      => (string) $row['status'],
            'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
            'use_count'   => (int) $row['use_count'],
            'expires_at'  => $row['expires_at'] === null ? null : (string) $row['expires_at'],
            'created_at'  => (string) $row['created_at'],
            'revoked_at'  => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
        ];
    }

    /** @param array<string, mixed> $row */
    public static function presentClient(array $row): array
    {
        if ($row === []) {
            return [];
        }

        return [
            'client_id'   => (string) $row['client_uuid'],
            'name'        => (string) $row['client_name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'source_app'  => (string) $row['source_app'],
            'status'      => (string) $row['status'],
            'created_at'  => (string) $row['created_at'],
        ];
    }

    /** @param array<string, mixed> $row */
    public static function presentEndpoint(array $row): array
    {
        if ($row === []) {
            return [];
        }

        return [
            'endpoint_id' => (string) $row['endpoint_uuid'],
            'target_url'  => (string) $row['target_url'],
            'events'      => Db::jsonColumn($row['subscribed_events'] ?? null),
            'status'      => (string) $row['status'],
            // "Configured", and a tail. Never the value.
            'secret_hint' => $row['secret_hint'] === null ? null : (string) $row['secret_hint'],
            'consecutive_failures' => (int) $row['consecutive_failures'],
            'last_success_at' => $row['last_success_at'] === null ? null : (string) $row['last_success_at'],
            'last_failure_at' => $row['last_failure_at'] === null ? null : (string) $row['last_failure_at'],
            'last_failure_reason' => $row['last_failure_reason'] === null ? null : (string) $row['last_failure_reason'],
            'created_at'  => (string) $row['created_at'],
        ];
    }
}
