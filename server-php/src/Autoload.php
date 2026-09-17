<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * PSR-4 style autoloader for Aicountly\Api\*, mapped onto server-php/src.
 *
 * There is no composer here on purpose: this API has no third-party
 * dependencies, and a vendor directory per product would be megabytes of
 * nothing for a deploy that is an rsync.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Aicountly\\Api\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
