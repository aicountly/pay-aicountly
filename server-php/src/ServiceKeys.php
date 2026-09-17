<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Service keys other AICOUNTLY products present when they call this one.
 *
 * Configured as `SERVICE_KEYS=books:<key>,pos:<key>,billing:<key>` in api/.env.
 * The comparison is constant-time: a plain === on a secret leaks its length and,
 * over enough requests, its content.
 */
final class ServiceKeys
{
    /** The calling product's name, or null when the key is unknown. */
    public static function resolveApp(string $presented): ?string
    {
        $presented = trim($presented);
        if ($presented === '') {
            return null;
        }

        foreach (self::configured() as $app => $key) {
            if (hash_equals($key, $presented)) {
                return $app;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private static function configured(): array
    {
        $raw = Env::get('SERVICE_KEYS');
        if ($raw === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, ':')) {
                continue;
            }
            [$app, $key] = explode(':', $pair, 2);
            $app = strtolower(trim($app));
            $key = trim($key);
            // A placeholder left in a deployed .env must not authenticate anything.
            if ($app === '' || $key === '' || str_starts_with($key, 'CHANGE_ME')) {
                continue;
            }
            $out[$app] = $key;
        }

        return $out;
    }
}
