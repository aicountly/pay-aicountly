<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Turning a request URI into something the router can match.
 *
 * This lives in its own file rather than in the front controller because the
 * front controller cannot be required by a test — including it runs the whole
 * app — and the bug this class exists to prevent is precisely the kind that a
 * test calling the router directly will never see.
 */
final class Path
{
    /**
     * Collapse a routed path to a single canonical form: no empty segments, no
     * backslashes, percent-escapes resolved.
     *
     * CASE IS PRESERVED, and that is the whole point of this comment. An
     * earlier version lowercased the result so it could be compared against
     * the portal-relay allowlist directly — which also lowercased every id in
     * it. `PAYREQ-…-527DD95B19FEA782` arrived at the handler as
     * `payreq-…-527dd95b19fea782`, matched no row, and every by-id route in the
     * product answered 404 on a deployment while passing every test that called
     * the router directly. Public payment link tokens are case-sensitive too,
     * so it broke real customers' links as well.
     *
     * The lowercase belongs at each comparison instead — see `key()` below, and
     * Router::dispatch, which fold case on their LITERAL segments only.
     *
     * Percent-escapes are decoded first so `%2e%2e` cannot smuggle a traversal
     * segment past the allowlist; exact matching does the rest.
     */
    public static function normalise(string $path): string
    {
        $decoded = str_replace('\\', '/', rawurldecode($path));
        $segments = array_values(array_filter(explode('/', $decoded), static fn ($s) => $s !== ''));

        return implode('/', $segments);
    }

    /**
     * The same path folded to lower case, for comparing against a fixed name.
     *
     * Only ever used on a path that is entirely literal — `health`, `session`,
     * `global/seskey`. Never on one that carries an id.
     */
    public static function key(string $path): string
    {
        return strtolower($path);
    }
}
