<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Who issued the request this process is serving, so we never call them back
 * inside it.
 *
 * NOT a recursion guard — nothing recurses. Each AICOUNTLY product runs in its
 * own PHP-FPM pool with a small pm.max_children, and a synchronous HTTP call
 * parks the calling worker for the whole round trip. When A calls B and B calls
 * A back, three workers across two pools serve one user action and two of them
 * are sitting in read(). At a handful of concurrent users every child in both
 * pools is blocked on the other and the queue answers 504. A depth counter
 * inside one process cannot see this, and raising pm.max_children only makes
 * the deadlock more expensive to reach.
 *
 * See books-react-app/docs/CROSS_SERVICE_CALL_RULES.md — this is the fleet-wide
 * invariant that document records.
 *
 * The header GRANTS NOTHING. It can only ever suppress one of our outbound
 * calls, so forging it costs the forger enrichment they wanted; it cannot
 * create access, a row or a permission. Unknown values are ignored.
 */
final class CrossServiceCallContext
{
    public const HEADER = 'X-Saas-Origin';

    /** Products whose name we honour in the header. */
    private const KNOWN = ['books', 'inventory', 'manage', 'contacts', 'pos', 'billing', 'sales', 'purchases', 'pay', 'crm'];

    private static ?string $inbound = null;
    private static bool $resolved = false;

    /** The calling product, or null when a browser (or an unknown name) called us. */
    public static function inbound(): ?string
    {
        if (self::$resolved) {
            return self::$inbound;
        }
        self::$resolved = true;

        $claimed = strtolower(Http::header(self::HEADER));
        self::$inbound = in_array($claimed, self::KNOWN, true) ? $claimed : null;

        return self::$inbound;
    }

    public static function isInboundFrom(string $app): bool
    {
        return self::inbound() === strtolower($app);
    }

    /**
     * Record the caller proven by its service key.
     *
     * Stronger than the header, and the reason a forged header buys nothing:
     * where a service key is presented, the key decides.
     */
    public static function adoptAuthenticatedOrigin(string $app): void
    {
        $app = strtolower(trim($app));
        if (in_array($app, self::KNOWN, true)) {
            self::$inbound = $app;
            self::$resolved = true;
        }
    }
}
