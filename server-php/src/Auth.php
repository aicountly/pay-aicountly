<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Who is calling.
 *
 * Three ways in, and Pay is the only product in the fleet that needs the third:
 *
 *  1. A human — `Authorization: Bearer <ses_key>`, validated at my.aicountly.com.
 *     The ses_key is kept so Pay can call Books, Billing, Sales and POS AS THAT
 *     USER, which is what makes their permissions apply over there instead of
 *     Pay re-implementing them.
 *
 *  2. A trusted product backend — `X-Service-Key`, plus `X-Actor-Uuid` naming
 *     the human it is acting for. This is how Books asks Pay for a payment
 *     request against one of its invoices.
 *
 *  3. A merchant's own software — `Authorization: Bearer pk_live_…` against a
 *     key the merchant minted in Developers. It is scoped to ONE company and
 *     to the public surface only, and it can never reach a provider credential
 *     or another company's payment. See Developer\ApiKeys.
 *
 * `sourceApp` is decided HERE and never read from a header: a service key
 * resolves to its product, a human session is Pay itself, and an API client
 * resolves to whatever source_app the merchant registered it under. A body that
 * claims to be another app is a caller trying to mint another product's payment
 * request, and it gets a 403.
 */
final class Auth
{
    public const KIND_USER    = 'user';
    public const KIND_SERVICE = 'service';
    public const KIND_API     = 'api_client';

    private function __construct(
        public readonly string $uuid,
        /** 'user' | 'service' | 'api_client' */
        public readonly string $kind,
        public readonly string $sourceApp,
        private readonly string $sesKey,
        private readonly ?array $session,
        /**
         * The one company an API key may act on, or 0 for a caller whose scope
         * comes from the request. A key is minted inside a company and can
         * never step outside it, which is what makes the public API safe to
         * hand to a merchant's own developer.
         */
        public readonly int $boundCmpId = 0,
        /** Scopes an API client holds, e.g. `payment_requests.write`. Empty for humans and services. */
        public readonly array $scopes = [],
        /** True when the caller presented a TEST key. Test callers never reach a live provider. */
        public readonly bool $testMode = false,
    ) {
    }

    /** Resolve the caller, or answer 401 and stop. */
    public static function require(): self
    {
        $resolved = self::resolve();
        if ($resolved === null) {
            Http::unauthorized();
        }

        return $resolved;
    }

    public static function resolve(): ?self
    {
        $serviceKey = Http::header('X-Service-Key');
        if ($serviceKey !== '') {
            $app = ServiceKeys::resolveApp($serviceKey);
            if ($app === null) {
                return null;
            }
            // Proven by the key, not claimed in a header. Recording it is what
            // stops us calling that product back inside its own request.
            CrossServiceCallContext::adoptAuthenticatedOrigin($app);
            $actor = Http::header('X-Actor-Uuid');

            return new self(
                $actor !== '' ? $actor : 'service:' . $app,
                self::KIND_SERVICE,
                $app,
                '',
                null,
            );
        }

        $bearer = self::bearer();
        if ($bearer === '') {
            return null;
        }

        // A merchant's API key and a portal session both arrive as a bearer
        // token. They are told apart by the key's own prefix rather than by a
        // second header, because a client library that has to remember which
        // header to use is a client library that sends the session key to the
        // public API.
        if (Developer\ApiKeys::looksLikeApiKey($bearer)) {
            return Developer\ApiKeys::authenticate($bearer);
        }

        $session = Portal::validateSesKey($bearer);
        if ($session === null) {
            return null;
        }

        return new self(
            (string) ($session['uuid_aictly'] ?? $session['uuid'] ?? ''),
            self::KIND_USER,
            Env::get('APP_PRODUCT_KEY', 'pay'),
            $bearer,
            $session,
        );
    }

    /**
     * Build an API-client caller. Only Developer\ApiKeys calls this, after it
     * has matched the presented secret against a stored hash.
     *
     * @param list<string> $scopes
     */
    public static function forApiClient(string $clientUuid, string $sourceApp, int $cmpId, array $scopes, bool $testMode): self
    {
        return new self(
            'api:' . $clientUuid,
            self::KIND_API,
            $sourceApp,
            '',
            null,
            $cmpId,
            $scopes,
            $testMode,
        );
    }

    public function isService(): bool
    {
        return $this->kind === self::KIND_SERVICE;
    }

    public function isApiClient(): bool
    {
        return $this->kind === self::KIND_API;
    }

    /** True for anything that is not a signed-in human: a service or an API key. */
    public function isMachine(): bool
    {
        return $this->kind !== self::KIND_USER;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * The session key, for calling Books / Billing / Sales as this user.
     *
     * Empty for a service or API caller, which is correct: a machine acts with
     * its own key over there, not with a borrowed human session.
     */
    public function sesKey(): string
    {
        return $this->sesKey;
    }

    /** Stable per-session identifier for memo keys. Never the key itself, which must not reach a log or a cache key. */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->kind . '|' . $this->uuid . '|' . $this->sesKey), 0, 32);
    }

    /** Portal access type for the company when the portal reported one: 1 = owner. */
    public function accessType(): ?int
    {
        return isset($this->session['acs_type']) ? (int) $this->session['acs_type'] : null;
    }

    /**
     * Candidate keys for a human's name, widest first.
     *
     * The portal has spelled this several ways over the years, and a key that
     * is merely absent should not cost the user their name — the fallback is a
     * uuid, which rendered in the header as an avatar reading "7".
     */
    private const NAME_FIELDS = [
        'name', 'full_name', 'fullname', 'display_name', 'user_name', 'username',
        'user_full_name', 'user_display_name', 'email', 'user_email', 'email_id', 'emailid',
    ];

    /** A name to show, or the uuid when the portal gave none. Pair with hasDisplayName(). */
    public function displayName(): string
    {
        foreach (self::NAME_FIELDS as $field) {
            $value = $this->session[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $parts = [];
        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            $value = $this->session[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }
        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return $this->uuid;
    }

    /**
     * Whether displayName() is a name or a fallback.
     *
     * The screen needs to tell them apart: initials cut from a uuid are not
     * initials, they are the first character of an identifier, and showing one
     * in an avatar makes the product look broken to the person it belongs to.
     */
    public function hasDisplayName(): bool
    {
        return $this->displayName() !== $this->uuid;
    }

    private static function bearer(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || $header === '') {
            if (function_exists('apache_request_headers')) {
                foreach ((array) apache_request_headers() as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0) {
                        $header = (string) $value;
                        break;
                    }
                }
            }
        }
        if (!is_string($header) || preg_match('/Bearer\s+(.+)/i', $header, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }
}
