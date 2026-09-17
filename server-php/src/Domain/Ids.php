<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Crypto;

/**
 * The identifiers that leave this system.
 *
 * TWO RULES, both learned the hard way by other payment products.
 *
 *  1. A PUBLIC IDENTIFIER IS NEVER THE PRIMARY KEY. `pay_payment_requests.
 *     request_id` is a BIGSERIAL and stays inside the database. What appears in
 *     a URL, a webhook body or a customer's email is a random uuid. Sequential
 *     ids in public places let anybody count your business and walk your
 *     records; both matter, and the second is a breach.
 *
 *  2. THE PREFIX IS PART OF THE ID. `PAYREQ-`, `PAY-`, `RFND-`. Support reads
 *     these out loud over the phone, and a bare random string tells nobody what
 *     it refers to. It also means a caller that passes a refund id where a
 *     payment id was wanted gets a clear error instead of a silent miss.
 *
 * The public LINK token is separate and deliberately longer — see token().
 */
final class Ids
{
    public const REQUEST    = 'PAYREQ';
    public const ATTEMPT    = 'PAY';
    public const REFUND     = 'RFND';
    public const LINK       = 'LNK';
    public const PAYER      = 'PYR';
    public const SETTLEMENT = 'STL';
    public const DISPUTE    = 'DSP';
    public const MANDATE    = 'MND';
    public const EVENT      = 'EVT';
    public const CASE_      = 'RCN';
    public const CONNECTION = 'CON';
    public const CLIENT     = 'CLI';
    public const ENDPOINT   = 'WHE';
    public const EXTERNAL   = 'EXT';
    public const INSIGHT    = 'INS';

    /**
     * A prefixed, sortable-by-time, unguessable id.
     *
     * The leading base-36 timestamp means ids sort roughly in creation order,
     * which makes a support conversation about "the one from Tuesday" tractable.
     * The 16 random characters after it are what make it unguessable — 80 bits,
     * which is not brute-forceable at any rate a rate limiter would permit.
     */
    public static function mint(string $prefix): string
    {
        $time = strtoupper(base_convert((string) time(), 10, 36));
        $random = strtoupper(bin2hex(random_bytes(8)));

        return $prefix . '-' . $time . '-' . $random;
    }

    /**
     * The token in a public payment URL.
     *
     * Longer than an id and with no structure at all, because this one is
     * handed to strangers and is the only thing standing between a payment page
     * and whoever guesses it. 32 URL-safe characters over 24 random bytes.
     *
     * It is also NOT derived from the request it belongs to: two links against
     * the same request must not be guessable from one another, or a customer
     * forwarded one link could reach the other.
     */
    public static function token(): string
    {
        return Crypto::token(24);
    }

    /** Whether a caller handed us the right kind of id. */
    public static function hasPrefix(string $id, string $prefix): bool
    {
        return str_starts_with(strtoupper(trim($id)), $prefix . '-');
    }

    /**
     * A short, human-quotable reference for a request that has none of its own.
     *
     * Used when a merchant raises a request in Pay itself with no source
     * document behind it. Deliberately short and typo-resistant: no O/0, no
     * I/1, because somebody reads this to a customer over the phone.
     */
    public static function humanReference(string $prefix = 'PR'): string
    {
        $alphabet = 'ACDEFGHJKLMNPQRTUVWXY3456789';
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $prefix . '-' . substr($out, 0, 4) . '-' . substr($out, 4);
    }
}
