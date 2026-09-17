<?php

declare(strict_types=1);

/**
 * Shared fixtures for the integration suite and for tests/seed.php.
 *
 * They go through the real services rather than INSERT, so a test that says "a
 * payment in full marks the request paid" is exercising the code that would run
 * in production rather than a shape somebody wrote by hand to match.
 */

namespace Aicountly\Api;

use Aicountly\Api\Domain\Ids;
use Aicountly\Api\Domain\PaymentRequestService;
use Aicountly\Api\Domain\PaymentService;
use Aicountly\Api\Domain\States;
use Aicountly\Api\Payments\Providers\ProviderRegistry;

function freshContext(int $cmpId = 55, int $boId = 0, int $fyId = 4): Context
{
    $r = new \ReflectionClass(Context::class);
    $ctx = $r->newInstanceWithoutConstructor();
    foreach (['cmpId' => $cmpId, 'boId' => $boId, 'fyId' => $fyId] as $prop => $value) {
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($ctx, $value);
    }

    return $ctx;
}

function authFor(string $uuid = 'user-owner', ?int $acsType = 1, string $kind = 'user'): Auth
{
    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach ([
        'uuid'        => $uuid,
        'kind'        => $kind,
        'sourceApp'   => $kind === 'service' ? 'books' : 'pay',
        'sesKey'      => 'stub-ses-key',
        'session'     => ['acs_type' => $acsType, 'name' => $uuid],
        'boundCmpId'  => 0,
        'scopes'      => [],
        'testMode'    => false,
    ] as $prop => $value) {
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($auth, $value);
    }

    return $auth;
}

/**
 * A delegated user holding exactly the permissions of one shipped role.
 *
 * Use it with a company the stub reports as DELEGATED (57). Against a company
 * the caller owns, every check passes by definition — an owner holds
 * everything — and the test would prove nothing.
 */
function userWithRole(Context $ctx, string $uuid, string $templateKey): Auth
{
    Permissions::seed($ctx);
    $roleId = (int) Db::scalar(
        'SELECT role_id FROM pay_roles WHERE cmp_id = :cmp AND role_code = :code',
        ['cmp' => $ctx->cmpId, 'code' => $templateKey],
    );
    Db::run(
        'INSERT INTO pay_role_assignments (cmp_id, user_uuid, role_id) VALUES (:cmp, :uuid, :role) ON CONFLICT DO NOTHING',
        ['cmp' => $ctx->cmpId, 'uuid' => $uuid, 'role' => $roleId],
    );
    Permissions::forget();

    return authFor($uuid, 0);
}

/**
 * A working mock provider connection for a company.
 *
 * `$environment` exists so a test can create TWO connections for one company —
 * the unique index is (company, provider, mode, environment), and hybrid
 * routing cannot be tested with one provider. Both are mocks, so both work;
 * the environment is only what keeps them distinct.
 */
function connectMock(
    Context $ctx,
    string $name = 'Test provider',
    array $methods = ['UPI', 'CARD', 'NETBANKING', 'WALLET'],
    string $environment = 'TEST',
    bool $primary = true,
): array {
    $connectionId = (int) Db::insert('pay_provider_connections', [
        'connection_uuid' => Ids::mint(Ids::CONNECTION),
        'cmp_id'          => $ctx->cmpId,
        'provider_code'   => 'MOCK',
        'provider_mode'   => 'DIRECT',
        'display_name'    => $name,
        'status'          => 'ACTIVE',
        'environment'     => $environment,
        'supported_methods' => $methods,
        'supported_currencies' => ['INR'],
        'is_primary'      => $primary,
    ], 'connection_id');

    ProviderRegistry::storeCredential($ctx, $connectionId, 'key_id', 'mock_key_id', 'test');
    ProviderRegistry::storeCredential($ctx, $connectionId, 'key_secret', 'mock_key_secret', 'test');
    ProviderRegistry::storeCredential($ctx, $connectionId, 'webhook_secret', 'mock_webhook_secret', 'test');
    ProviderRegistry::forget();

    return Db::first('SELECT * FROM pay_provider_connections WHERE connection_id = :id', ['id' => $connectionId]);
}

/** A payment request, ready to be paid. */
function makeRequest(Context $ctx, Auth $auth, array $overrides = []): array
{
    $result = PaymentRequestService::create($ctx, $auth, array_merge([
        'source_app'  => 'PAY',
        'reference'   => 'TEST-' . bin2hex(random_bytes(4)),
        'description' => 'Test payment',
        'amount'      => 1000.00,
        'currency'    => 'INR',
    ], $overrides));

    $row = PaymentRequestService::findByUuid($ctx, (string) $result['payment_request_id']);
    if ($row === null) {
        throw new \RuntimeException('the request was not created');
    }

    return $row;
}

/** Drive a payment all the way to success through the mock provider. */
function payInFull(Context $ctx, Auth $auth, array $request, array $connection, ?float $amount = null): array
{
    $result = PaymentService::start($ctx, $auth, $request, [
        'amount'  => $amount,
        'method'  => 'UPI',
        'channel' => 'LINK',
        'idempotency_key' => 'test-' . bin2hex(random_bytes(6)),
    ]);

    if (!($result['ok'] ?? false)) {
        throw new \RuntimeException('payment did not start: ' . (string) ($result['error_message'] ?? '?'));
    }

    $attempt = PaymentService::findByUuid($ctx, (string) $result['attempt']['payment_id']);

    PaymentService::applyProviderState($ctx, [
        'provider_payment_id' => (string) $attempt['provider_payment_id'],
        'status'       => States::ATTEMPT_SUCCESS,
        'amount_minor' => (int) $attempt['amount_minor'],
        'currency'     => 'INR',
        'method'       => 'UPI',
        'occurred_at'  => gmdate('c'),
    ], $connection);

    return PaymentService::require($ctx, (int) $attempt['attempt_id']);
}

