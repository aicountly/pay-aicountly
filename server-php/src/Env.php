<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Minimal .env reader.
 *
 * Unlike the React build, this backend reads its configuration at runtime on
 * every request. The .env lives on the server, is never committed, and is
 * excluded from rsync so a --delete deploy cannot remove it.
 * See docs/DEPLOYMENT.md.
 */
final class Env
{
    /** @var array<string, string>|null */
    private static ?array $values = null;

    public static function load(string $path): void
    {
        $values = [];

        if (is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines === false ? [] : $lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));

                // Strip one matching pair of surrounding quotes.
                $len = strlen($value);
                if ($len >= 2) {
                    $first = $value[0];
                    if (($first === '"' || $first === "'") && $value[$len - 1] === $first) {
                        $value = substr($value, 1, -1);
                    }
                }

                if ($key !== '') {
                    $values[$key] = $value;
                }
            }
        }

        self::$values = $values;
    }

    /**
     * Real environment variables win over the file, so a host-level setting can
     * override a deployed .env without editing it.
     */
    public static function get(string $key, string $default = ''): string
    {
        $fromEnv = getenv($key);
        if ($fromEnv !== false && $fromEnv !== '') {
            return (string) $fromEnv;
        }

        $value = self::$values[$key] ?? '';

        return $value !== '' ? $value : $default;
    }
}
