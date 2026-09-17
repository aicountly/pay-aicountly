<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Domain\Money;
use Aicountly\Api\Http;

/**
 * Shared entry work for every scoped endpoint.
 *
 * Authenticate, resolve the company scope, and CHECK THAT THIS CALLER MAY OPEN
 * THAT COMPANY — in that order, before a controller touches a row. The tenant
 * check is not optional and is not something an individual endpoint remembers
 * to do: it happens here, once, for all of them.
 *
 * For an API client the company comes OFF THE KEY and the request's own cmp_id
 * is discarded. A merchant's integration must not be able to widen its reach by
 * editing a query parameter.
 */
abstract class Controller
{
    /** @return array{0: Auth, 1: Context} */
    protected static function enter(): array
    {
        $auth = Auth::require();

        $ctx = $auth->isApiClient()
            ? Context::forApiClient($auth)
            : Context::fromRequest();

        $ctx->assertAllowed($auth);

        return [$auth, $ctx];
    }

    /**
     * Entry for a public API endpoint, which needs a scope and a key SCOPE
     * rather than a human permission.
     *
     * @return array{0: Auth, 1: Context}
     */
    protected static function enterApi(string $scope): array
    {
        [$auth, $ctx] = self::enter();
        \Aicountly\Api\Permissions::assertScope($auth, $scope);

        return [$auth, $ctx];
    }

    /** A money amount from the request, with the caller's own words on a bad one. */
    protected static function money(mixed $value, string $currency, string $field = 'amount'): Money
    {
        try {
            return Money::fromMajor($value, $currency);
        } catch (\InvalidArgumentException $e) {
            Http::validationFailed($e->getMessage(), ['field' => $field]);
        }
    }

    /** A bounded page of rows, with the filters echoed back so a client can trust what it got. */
    protected static function page(array $rows, int $total, array $params, array $extra = []): never
    {
        Http::list($rows, $total, $params['limit'], $params['offset'], $extra);
    }
}
