<?php

declare(strict_types=1);

namespace Aicountly\Api;

use RuntimeException;

/**
 * Encryption for the one class of secret this product has to keep: a merchant's
 * own payment-gateway credentials.
 *
 * WHAT IS AT STAKE. A Razorpay key secret is a bearer credential for the
 * merchant's real gateway account. Whoever holds it can create payments, issue
 * refunds and read every transaction that account has ever taken — including
 * the ones that had nothing to do with Aicountly. A leaked provider secret is
 * not "a database row was exposed"; it is somebody else able to move the
 * merchant's money.
 *
 * So: AES-256-GCM, a fresh random nonce per encryption, and the authentication
 * tag stored with the ciphertext. GCM and not CBC because the tag is what makes
 * a tampered ciphertext fail to decrypt rather than decrypt to something else,
 * and a webhook secret that silently decrypts to the wrong bytes is a signature
 * check that rejects every legitimate callback.
 *
 * THE KEY ITSELF lives in PAY_ENCRYPTION_KEY in api/.env — on the server, never
 * in the repository, never in a VITE_ variable, never in the database beside
 * what it protects. Rotation is supported by keeping the old keys in
 * PAY_ENCRYPTION_KEY_OLD: every ciphertext records which key id sealed it, so
 * a rotation re-seals on next write instead of requiring a migration that
 * decrypts every row at once.
 *
 * The envelope is `v1.<key id>.<base64 nonce>.<base64 tag>.<base64 ciphertext>`,
 * which is self-describing: a row can be read years later without a lookup
 * table saying how it was encrypted.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = 'v1';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    /** @var array<string, string>|null key id => raw 32-byte key */
    private static ?array $keys = null;
    private static string $activeKeyId = '';

    /**
     * Seal a secret for storage.
     *
     * @throws RuntimeException when no key is configured. This is deliberately
     *         fatal rather than a silent plaintext fallback: a product that
     *         quietly stores gateway secrets in the clear when misconfigured is
     *         worse than one that refuses to store them at all.
     */
    public static function seal(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        [$keyId, $key] = self::activeKey();

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_BYTES);

        if ($ciphertext === false) {
            throw new RuntimeException('Could not encrypt the provider credential.');
        }

        return implode('.', [
            self::VERSION,
            $keyId,
            self::b64($nonce),
            self::b64($tag),
            self::b64($ciphertext),
        ]);
    }

    /**
     * Open a sealed secret.
     *
     * Returns null rather than throwing when the envelope cannot be opened, so
     * a single un-decryptable row — a key that was rotated away, a value copied
     * between environments — degrades to "this provider needs re-keying" on one
     * screen instead of 500-ing every request that lists providers.
     */
    public static function open(string $sealed): ?string
    {
        if ($sealed === '') {
            return null;
        }

        $parts = explode('.', $sealed);
        if (count($parts) !== 5 || $parts[0] !== self::VERSION) {
            error_log('[crypto] refusing to open a credential with an unrecognised envelope');

            return null;
        }

        [, $keyId, $nonce64, $tag64, $cipher64] = $parts;

        $keys = self::keys();
        if (!isset($keys[$keyId])) {
            error_log('[crypto] credential sealed with key id "' . $keyId . '", which is not configured on this host');

            return null;
        }

        $plaintext = openssl_decrypt(
            self::unb64($cipher64),
            self::CIPHER,
            $keys[$keyId],
            OPENSSL_RAW_DATA,
            self::unb64($nonce64),
            self::unb64($tag64),
        );

        if ($plaintext === false) {
            // The tag did not verify. Either the row was tampered with or the
            // key is wrong; neither is something to guess past.
            error_log('[crypto] credential failed authentication — refusing to use it');

            return null;
        }

        return $plaintext;
    }

    /** Whether this ciphertext was sealed with a key that is no longer the active one. */
    public static function needsReseal(string $sealed): bool
    {
        $parts = explode('.', $sealed);
        if (count($parts) !== 5) {
            return true;
        }
        [$keyId] = [$parts[1]];

        return $keyId !== self::activeKeyId();
    }

    public static function isConfigured(): bool
    {
        try {
            self::activeKey();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * What a screen may show in place of a secret.
     *
     * Never the value, never a prefix of the value long enough to be guessed
     * forward from — just enough for a human to tell two keys apart when the
     * provider's own dashboard shows the same tail.
     */
    public static function mask(string $plaintext): string
    {
        $length = strlen($plaintext);
        if ($length === 0) {
            return '';
        }
        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', 8) . substr($plaintext, -4);
    }

    /**
     * A one-way hash for something that is COMPARED but never shown again:
     * an API key secret, a webhook signing secret we issued.
     *
     * Different from seal() on purpose. Sealing is reversible because we have to
     * present the merchant's Razorpay secret back to Razorpay. An API key we
     * issued is never needed in the clear again, so it is hashed and a leak of
     * the table buys nothing.
     */
    public static function hashSecret(string $secret): string
    {
        return hash_hmac('sha256', $secret, self::pepper());
    }

    public static function verifySecret(string $presented, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hashSecret($presented));
    }

    /** A URL-safe token with the requested number of random bytes behind it. */
    public static function token(int $bytes = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    // -----------------------------------------------------------------------

    /** @return array{0:string, 1:string} */
    private static function activeKey(): array
    {
        $keys = self::keys();
        $id = self::activeKeyId();

        if ($id === '' || !isset($keys[$id])) {
            throw new RuntimeException(
                'PAY_ENCRYPTION_KEY is not set. Pay refuses to store a gateway credential without it.',
            );
        }

        return [$id, $keys[$id]];
    }

    private static function activeKeyId(): string
    {
        self::keys();

        return self::$activeKeyId;
    }

    /**
     * Configured keys, by id.
     *
     * PAY_ENCRYPTION_KEY is the active one and may be written either as
     * `<id>:<base64 key>` or as a bare base64 key, which then takes the id "1".
     * PAY_ENCRYPTION_KEY_OLD is a comma-separated list in the same form, kept
     * only so already-sealed rows can still be opened during a rotation.
     *
     * @return array<string, string>
     */
    private static function keys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }

        $keys = [];

        $active = self::parseKey(Env::get('PAY_ENCRYPTION_KEY'));
        if ($active !== null) {
            $keys[$active[0]] = $active[1];
            self::$activeKeyId = $active[0];
        }

        foreach (explode(',', Env::get('PAY_ENCRYPTION_KEY_OLD')) as $raw) {
            $parsed = self::parseKey(trim($raw));
            if ($parsed !== null && !isset($keys[$parsed[0]])) {
                $keys[$parsed[0]] = $parsed[1];
            }
        }

        return self::$keys = $keys;
    }

    /** @return array{0:string, 1:string}|null */
    private static function parseKey(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, 'CHANGE_ME')) {
            return null;
        }

        $id = '1';
        if (preg_match('/^([A-Za-z0-9_-]{1,16}):(.+)$/', $raw, $m) === 1) {
            $id = $m[1];
            $raw = $m[2];
        }

        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            error_log('[crypto] an encryption key is not 32 bytes of base64 and was ignored');

            return null;
        }

        return [$id, $decoded];
    }

    /**
     * The pepper for hashed secrets.
     *
     * Falls back to the active encryption key so a host that configured one
     * secret has configured both. A separate PAY_HASH_PEPPER is honoured for
     * deployments that want the two rotated independently.
     */
    private static function pepper(): string
    {
        $configured = Env::get('PAY_HASH_PEPPER');
        if ($configured !== '') {
            return $configured;
        }

        try {
            return self::activeKey()[1];
        } catch (RuntimeException) {
            // Hashing with a constant is still a hash; it just loses the
            // pepper's value. Better than refusing to authenticate an API key
            // on a host where only the key material is missing.
            return 'aicountly-pay-unpeppered';
        }
    }

    /** Test seam: keys are read once per process in production. */
    public static function forgetKeys(): void
    {
        self::$keys = null;
        self::$activeKeyId = '';
    }

    private static function b64(string $raw): string
    {
        return base64_encode($raw);
    }

    private static function unb64(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);

        return $decoded === false ? '' : $decoded;
    }
}
