<?php

declare(strict_types=1);

/**
 * Integration tests for Aicountly Pay.
 *
 * Against a REAL PostgreSQL database and a stub standing in for Manage and the
 * source apps. No live gateway is ever called: the mock provider serves every
 * payment path and refuses to be constructed in production.
 *
 *   server-php/tests/run.sh
 *
 * WHAT THESE TESTS ARE FOR. A payment system's failures are not the ones a unit
 * test finds. They are: the same webhook arriving twice, a payment recorded
 * against the wrong company, a refund sent twice because somebody pressed the
 * button twice, and an amount the browser was allowed to choose. Every case
 * below is one of those.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Dashboards\DashboardService;
use Aicountly\Api\Dashboards\Period;
use Aicountly\Api\Domain\ExternalPaymentService;
use Aicountly\Api\Domain\Ids;
use Aicountly\Api\Domain\LinkService;
use Aicountly\Api\Domain\Money;
use Aicountly\Api\Domain\PaymentRequestService;
use Aicountly\Api\Domain\PaymentService;
use Aicountly\Api\Domain\ReconciliationService;
use Aicountly\Api\Domain\RefundService;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Domain\States;
use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Events\Outbox;
use Aicountly\Api\Payments\Providers\Mock\MockProvider;
use Aicountly\Api\Payments\Providers\ProviderRegistry;
use Aicountly\Api\Payments\Routing\RoutingEngine;
use Aicountly\Api\Payments\Webhooks\WebhookIngest;
use Aicountly\Api\PayPulse\InsightEngine;

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
        if (getenv('VERBOSE')) {
            echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
        $failed++;
    }
}

function assertSame(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf('%s: expected %s, got %s', $what, var_export($expected, true), var_export($actual, true)));
    }
}

function assertTrue(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

function assertThrows(callable $fn, string $expectFragment, string $what): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($expectFragment !== '' && !str_contains($e->getMessage(), $expectFragment)) {
            throw new \RuntimeException($what . ': wrong error — ' . $e->getMessage());
        }

        return;
    }
    throw new \RuntimeException($what . ': expected a failure, none was thrown');
}

// ---------------------------------------------------------------------------
// Fixtures
//
// In their own file because tests/seed.php builds the local stack's demo data
// with the same helpers. Two copies would drift, and the copy that drifted
// would be the one nobody runs.
// ---------------------------------------------------------------------------

require __DIR__ . '/fixtures.php';

function resetDatabase(): void
{
    // Every memo is per-request in production. Across a suite in one process
    // they would carry one case's answer into the next.
    Context::forgetAccess();
    Permissions::forget();
    Settings::forget();
    ProviderRegistry::forget();
    Crypto::forgetKeys();

    Db::connect()->exec('TRUNCATE
        pay_settlement_items, pay_settlements, pay_reconciliation_cases,
        pay_external_payments, pay_refunds, pay_disputes, pay_mandates,
        pay_routing_decisions, pay_routing_rules,
        pay_outbound_events, pay_webhook_events, pay_webhook_endpoints,
        pay_api_keys, pay_api_clients, pay_pulse_insights, pay_provider_metrics,
        pay_payment_links, pay_payment_attempts, pay_payment_requests, pay_payers,
        pay_provider_credentials, pay_provider_connections, pay_managed_onboarding,
        pay_role_assignments, pay_roles, pay_settings, pay_idempotency_keys, pay_audit_log
        RESTART IDENTITY CASCADE');

    @unlink(sys_get_temp_dir() . '/pay-stub-requests.jsonl');
    @unlink(sys_get_temp_dir() . '/pay-stub-events.json');
    stubRecover();
}

function stubFail(string $pathFragment, int $status): void
{
    file_put_contents(sys_get_temp_dir() . '/pay-stub-control.json', json_encode(['path' => $pathFragment, 'status' => $status]));
}

function stubRecover(): void
{
    @unlink(sys_get_temp_dir() . '/pay-stub-control.json');
}

/** @return list<array<string, mixed>> what the stub was asked */
function stubRequests(): array
{
    $log = sys_get_temp_dir() . '/pay-stub-requests.jsonl';
    if (!is_file($log)) {
        return [];
    }

    $out = [];
    foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $out[] = $decoded;
        }
    }

    return $out;
}

echo "\nAicountly Pay — integration tests\n";
echo str_repeat('=', 70) . "\n";

// ===========================================================================
echo "\nMoney\n";
// ===========================================================================

check('rupees become paise exactly', static function (): void {
    assertSame(118000, Money::fromMajor(1180.00)->minor, '1180.00');
    assertSame(118000, Money::fromMajor('1180')->minor, 'the string "1180"');
    assertSame(1, Money::fromMajor(0.01)->minor, 'one paisa');
    // The case that a float-based system gets wrong: 0.1 + 0.2 is not 0.3.
    assertSame(30, Money::fromMajor(0.10)->plus(Money::fromMajor(0.20))->minor, '0.10 + 0.20');
});

check('an amount with more places than the currency has is refused, not rounded', static function (): void {
    assertThrows(
        static fn () => Money::fromMajor('100.005'),
        'decimal place',
        'a half-paisa amount',
    );
    // Trailing zeros are fine — they do not lose anything.
    assertSame(10000, Money::fromMajor('100.000')->minor, '100.000');
});

check('a currency with no minor unit is not assumed to have two', static function (): void {
    // ¥1000 read as two-decimal would settle as ¥10.
    assertSame(1000, Money::fromMajor(1000, 'JPY')->minor, 'JPY 1000');
    assertSame(1000000, Money::fromMajor(1000, 'KWD')->minor, 'KWD 1000 has three places');
});

check('adding two currencies is refused', static function (): void {
    assertThrows(
        static fn () => Money::minor(100, 'INR')->plus(Money::minor(100, 'USD')),
        'Cannot combine',
        'INR + USD',
    );
});

check('Indian digit grouping', static function (): void {
    assertSame('₹8,42,320.00', Money::minor(84232000)->format(), 'lakhs, not thousands');
    assertSame('₹1,00,00,000.00', Money::minor(1000000000)->format(), 'a crore');
});

// ===========================================================================
echo "\nEncryption\n";
// ===========================================================================

check('a sealed credential round-trips and is not readable as stored', static function (): void {
    $secret = 'rzp_live_secret_value_9281';
    $sealed = Crypto::seal($secret);

    assertTrue(!str_contains($sealed, $secret), 'the ciphertext must not contain the plaintext');
    assertTrue(str_starts_with($sealed, 'v1.'), 'the envelope is self-describing');
    assertSame($secret, Crypto::open($sealed), 'it opens back to the same value');
});

check('a tampered credential fails authentication rather than decrypting to something else', static function (): void {
    $sealed = Crypto::seal('rzp_live_secret');
    $parts = explode('.', $sealed);
    // Flip a byte of the ciphertext.
    $cipher = base64_decode($parts[4], true);
    $cipher[0] = chr(ord($cipher[0]) ^ 0xFF);
    $parts[4] = base64_encode($cipher);

    assertSame(null, Crypto::open(implode('.', $parts)), 'a tampered envelope must not open');
});

check('a masked secret shows only the last four', static function (): void {
    assertSame('••••••••8921', Crypto::mask('rzp_live_abcdefgh8921'), 'the mask');
    assertTrue(!str_contains(Crypto::mask('rzp_live_abcdefgh8921'), 'abcdefgh'), 'no prefix leaks');
});

// ===========================================================================
echo "\nPayment requests\n";
// ===========================================================================

check('a request is created and starts collectable', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();

    $result = PaymentRequestService::create($ctx, $auth, [
        'source_app'  => 'BOOKS',
        'source_type' => 'SALES_INVOICE',
        'source_id'   => '92841',
        'reference'   => 'INV-2026-1042',
        'amount'      => 1180.00,
        'currency'    => 'INR',
    ]);

    assertSame('ACTIVE', $result['status'], 'a new request is live');
    assertSame(1180.0, $result['amount'], 'the amount');
    assertSame(1180.0, $result['outstanding'], 'nothing paid yet');
    assertTrue(str_starts_with((string) $result['payment_request_id'], 'PAYREQ-'), 'the id is prefixed and public-safe');
});

check('the same source document cannot raise two live requests', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();

    $payload = [
        'source_app' => 'BOOKS', 'source_type' => 'SALES_INVOICE', 'source_id' => '92841',
        'reference' => 'INV-2026-1042', 'amount' => 1180.00,
    ];

    $first = PaymentRequestService::create($ctx, $auth, $payload);
    $second = PaymentRequestService::create($ctx, $auth, $payload);

    assertSame(
        $first['payment_request_id'],
        $second['payment_request_id'],
        'a second call for the same invoice returns the SAME request, not a second one',
    );

    assertSame(1, (int) Db::scalar(
        'SELECT COUNT(*) FROM pay_payment_requests WHERE cmp_id = :cmp AND source_id = :id',
        ['cmp' => $ctx->cmpId, 'id' => '92841'],
    ), 'exactly one row exists');
});

check('a zero or negative amount is refused', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();

    assertThrows(
        static fn () => PaymentRequestService::create($ctx, $auth, ['amount' => 0, 'reference' => 'ZERO']),
        'greater than zero',
        'a zero-amount request',
    );
});

check('a return URL that is not http(s) is refused', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();

    // An unchecked return_url is an open redirect on a page the payer trusts.
    assertThrows(
        static fn () => PaymentRequestService::create($ctx, $auth, [
            'amount' => 100, 'reference' => 'R1', 'return_url' => 'javascript:alert(1)',
        ]),
        'full https',
        'a javascript: return URL',
    );
});

// ===========================================================================
echo "\nPayments and partial payments\n";
// ===========================================================================

check('a payment in full marks the request paid', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);
    $request = makeRequest($ctx, $auth, ['amount' => 1000.00]);

    payInFull($ctx, $auth, $request, $connection);

    $fresh = PaymentRequestService::require($ctx, (int) $request['request_id']);
    assertSame(States::REQUEST_PAID, (string) $fresh['status'], 'the request is paid');
    assertSame(100000, (int) $fresh['paid_minor'], 'the full amount was recorded');
    assertSame(0.0, PaymentRequestService::outstanding($fresh)->toMajor(), 'nothing outstanding');
});

check('two part payments settle a request, and both are kept', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);
    $request = makeRequest($ctx, $auth, ['amount' => 1000.00, 'allow_partial_payment' => true, 'min_partial_amount' => 100]);

    payInFull($ctx, $auth, $request, $connection, 400.00);

    $afterFirst = PaymentRequestService::require($ctx, (int) $request['request_id']);
    assertSame(States::REQUEST_PARTIALLY_PAID, (string) $afterFirst['status'], 'part paid after the first');
    assertSame(600.0, PaymentRequestService::outstanding($afterFirst)->toMajor(), '600 still owed');

    payInFull($ctx, $auth, $afterFirst, $connection, 600.00);

    $afterSecond = PaymentRequestService::require($ctx, (int) $request['request_id']);
    assertSame(States::REQUEST_PAID, (string) $afterSecond['status'], 'paid after the second');
    assertSame(100000, (int) $afterSecond['paid_minor'], 'both payments counted');

    // The whole reason attempts are a separate table: the history survives.
    assertSame(2, (int) Db::scalar(
        'SELECT COUNT(*) FROM pay_payment_attempts WHERE request_id = :id',
        ['id' => (int) $request['request_id']],
    ), 'both payments are still on the record');
});

check('a part payment is refused when the request does not allow one', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    connectMock($ctx);
    $request = makeRequest($ctx, $auth, ['amount' => 1000.00, 'allow_partial_payment' => false]);

    $result = PaymentService::start($ctx, $auth, $request, ['amount' => 400.00, 'method' => 'UPI']);

    assertSame(false, $result['ok'], 'a part payment must not go through');
    assertSame('partial_not_allowed', $result['error_code'], 'and the reason says why');
});

check('a browser cannot pay less than the invoice by posting a smaller amount', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    connectMock($ctx);
    $request = makeRequest($ctx, $auth, ['amount' => 100000.00]);

    // The attack: a payment page posting amount=1 against a ₹1,00,000 invoice.
    $result = PaymentService::start($ctx, $auth, $request, ['amount' => 1.00, 'method' => 'UPI']);

    assertSame(false, $result['ok'], 'the server must not accept the browser\'s amount');
    assertSame('partial_not_allowed', $result['error_code'], 'it is refused as a part payment');
});

check('paying more than is outstanding is refused', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    connectMock($ctx);
    $request = makeRequest($ctx, $auth, ['amount' => 1000.00, 'allow_partial_payment' => true]);

    $result = PaymentService::start($ctx, $auth, $request, ['amount' => 5000.00, 'method' => 'UPI']);

    assertSame(false, $result['ok'], 'overpayment is refused');
    assertSame('amount_exceeds_outstanding', $result['error_code'], 'with the right reason');
});

check('an expired request refuses payment even before the sweep has run', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    connectMock($ctx);
    $request = makeRequest($ctx, $auth, ['amount' => 1000.00]);

    // Expired two minutes ago, status still ACTIVE because the sweep runs on a
    // schedule. The check is against the clock, not the stored status.
    Db::update('pay_payment_requests', [
        'expires_at' => gmdate('Y-m-d H:i:s', time() - 120),
    ], ['request_id' => (int) $request['request_id']]);

    $fresh = PaymentRequestService::require($ctx, (int) $request['request_id']);
    assertSame(States::REQUEST_ACTIVE, (string) $fresh['status'], 'the stored status has not caught up yet');

    $payable = PaymentRequestService::assertPayable($fresh);
    assertSame(false, $payable['ok'], 'but it must still refuse');
    assertSame('request_expired', $payable['error_code'], 'because it has expired');
});

check('a cancelled request stops every link that reached it', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    connectMock($ctx);
    $request = makeRequest($ctx, $auth);
    LinkService::create($ctx, $auth, $request, ['channel' => 'WHATSAPP']);

    PaymentRequestService::cancel($ctx, $auth, (int) $request['request_id'], 'Customer cancelled the order');

    assertSame(0, (int) Db::scalar(
        'SELECT COUNT(*) FROM pay_payment_links WHERE request_id = :id AND status = :active',
        ['id' => (int) $request['request_id'], 'active' => LinkService::ACTIVE],
    ), 'a cancelled request must not leave a working link');
});

// ===========================================================================
echo "\nIdempotency\n";
// ===========================================================================

check('the same idempotency key replays the first answer', static function (): void {
    resetDatabase();
    $ctx = freshContext();

    $calls = 0;
    $work = static function () use (&$calls): array {
        $calls++;

        return ['value' => 'first', 'call' => $calls];
    };

    [$first] = Idempotency::once($ctx, 'test.op', 'key-1', $work);
    [$second] = Idempotency::once($ctx, 'test.op', 'key-1', $work);

    assertSame(1, $calls, 'the work ran exactly once');
    assertSame('first', $second['value'], 'the second caller got the first answer');
    assertSame(true, $second['idempotent_replay'] ?? false, 'and is told it was a replay');
});

check('one company\'s idempotency key cannot replay another\'s answer', static function (): void {
    resetDatabase();

    $a = freshContext(55);
    $b = freshContext(56);

    [$first] = Idempotency::once($a, 'test.op', 'shared-key', static fn () => ['who' => 'company-55']);
    [$second] = Idempotency::once($b, 'test.op', 'shared-key', static fn () => ['who' => 'company-56']);

    assertSame('company-55', $first['who'], 'the first company');
    assertSame('company-56', $second['who'], 'the second company got its OWN answer, not the first\'s');
});

check('a failed operation can be retried on the same key', static function (): void {
    resetDatabase();
    $ctx = freshContext();

    try {
        Idempotency::once($ctx, 'test.op', 'retry-key', static function (): array {
            throw new \RuntimeException('provider timed out');
        });
    } catch (\RuntimeException) {
        // Expected.
    }

    [$result] = Idempotency::once($ctx, 'test.op', 'retry-key', static fn () => ['value' => 'second attempt worked']);

    assertSame('second attempt worked', $result['value'], 'a failure does not poison the key forever');
});

// ===========================================================================
echo "\nWebhooks\n";
// ===========================================================================

/** Sign a mock webhook the way the mock provider verifies it. */
function mockWebhook(array $payload, string $secret = 'mock_webhook_secret'): array
{
    $raw = (string) json_encode($payload);

    return [$raw, ['x-mock-signature' => hash_hmac('sha256', $raw, $secret)]];
}

check('a webhook with a bad signature is rejected and recorded', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    connectMock($ctx);

    [$raw] = mockWebhook(['event' => EventNames::PAYMENT_SUCCESS, 'payment_id' => 'x']);

    $result = WebhookIngest::handle('MOCK', $raw, ['x-mock-signature' => 'not-the-right-signature']);

    assertSame(401, $result['status'], 'a forged callback is rejected');

    // Stored anyway — a rejected webhook is the most interesting row in the
    // table: either our secret is wrong, or somebody is forging.
    $stored = Db::first('SELECT * FROM pay_webhook_events ORDER BY event_id DESC LIMIT 1');
    assertTrue($stored !== null, 'the rejected callback was still stored');
    assertSame(false, $stored['signature_valid'] === true || $stored['signature_valid'] === 't', 'marked as failing verification');
    assertSame(WebhookIngest::REJECTED, (string) $stored['status'], 'and recorded as rejected');
});

check('a redelivered webhook does not capture the payment twice', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);
    $request = makeRequest($ctx, $auth, ['amount' => 1000.00]);

    $start = PaymentService::start($ctx, $auth, $request, ['method' => 'UPI', 'idempotency_key' => 'k1']);
    $attempt = PaymentService::findByUuid($ctx, (string) $start['attempt']['payment_id']);

    $payload = [
        'event'        => EventNames::PAYMENT_SUCCESS,
        'id'           => 'evt_redelivered_001',
        'payment_id'   => (string) $attempt['provider_payment_id'],
        'status'       => States::ATTEMPT_SUCCESS,
        'amount_minor' => 100000,
        'currency'     => 'INR',
        'method'       => 'UPI',
    ];
    [$raw, $headers] = mockWebhook($payload);

    $first = WebhookIngest::handle('MOCK', $raw, $headers);
    $second = WebhookIngest::handle('MOCK', $raw, $headers);
    $third = WebhookIngest::handle('MOCK', $raw, $headers);

    assertSame(200, $first['status'], 'the first delivery is processed');
    assertSame(true, $second['body']['duplicate'] ?? false, 'the second is recognised as a duplicate');
    assertSame(true, $third['body']['duplicate'] ?? false, 'and so is the third');

    $fresh = PaymentRequestService::require($ctx, (int) $request['request_id']);
    assertSame(100000, (int) $fresh['paid_minor'], 'the money was counted ONCE');
    assertSame(1, (int) Db::scalar(
        'SELECT COUNT(*) FROM pay_payment_attempts WHERE request_id = :id',
        ['id' => (int) $request['request_id']],
    ), 'and there is still one payment');
});

check('a provider contradicting a settled payment opens a case rather than reversing it', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);
    $request = makeRequest($ctx, $auth, ['amount' => 1000.00]);

    $attempt = payInFull($ctx, $auth, $request, $connection);
    assertSame(States::ATTEMPT_SUCCESS, (string) $attempt['status'], 'the payment succeeded');

    // Now the provider says it failed. That is a disagreement about whether
    // money moved, and it needs a person — not a silent reversal.
    $applied = PaymentService::applyProviderState($ctx, [
        'provider_payment_id' => (string) $attempt['provider_payment_id'],
        'status'       => States::ATTEMPT_FAILED,
        'amount_minor' => 100000,
        'currency'     => 'INR',
    ], $connection);

    assertSame(false, $applied['applied'], 'the contradiction is not applied');
    assertSame('terminal_conflict', $applied['reason'], 'it is recognised as a conflict');

    $unchanged = PaymentService::require($ctx, (int) $attempt['attempt_id']);
    assertSame(States::ATTEMPT_SUCCESS, (string) $unchanged['status'], 'the payment still stands');

    assertSame(1, (int) Db::scalar(
        'SELECT COUNT(*) FROM pay_reconciliation_cases WHERE cmp_id = :cmp AND case_kind = :kind',
        ['cmp' => $ctx->cmpId, 'kind' => ReconciliationService::PROVIDER_STATE_CONFLICT],
    ), 'and a reconciliation case was opened for a human');
});

check('a failed payment is not left waiting for a settlement that cannot come', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);
    $request = makeRequest($ctx, $auth, ['amount' => 500.00]);

    $started = PaymentService::start($ctx, $auth, $request, [
        'method'  => 'CARD',
        'channel' => 'CHECKOUT',
        'idempotency_key' => 'test-' . bin2hex(random_bytes(6)),
    ]);
    assertTrue($started['ok'] ?? false, 'the payment started');

    $attempt = PaymentService::findByUuid($ctx, (string) $started['attempt']['payment_id']);

    // The column defaults to PENDING, which is right while the attempt is in
    // flight and wrong the moment it is not.
    assertSame('PENDING', (string) $attempt['settlement_status'], 'pending while in flight');

    PaymentService::applyProviderState($ctx, [
        'provider_payment_id' => (string) $attempt['provider_payment_id'],
        'status'        => States::ATTEMPT_FAILED,
        'error_code'    => 'payment_declined',
        'error_message' => 'The bank declined this card.',
        'currency'      => 'INR',
    ], $connection);

    $failed = PaymentService::require($ctx, (int) $attempt['attempt_id']);
    assertSame(States::ATTEMPT_FAILED, (string) $failed['status'], 'the payment failed');
    assertSame(
        'NOT_APPLICABLE',
        (string) $failed['settlement_status'],
        'money that never moved is not awaiting settlement',
    );
});

check('an out-of-order webhook does not take a captured payment backwards', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);
    $request = makeRequest($ctx, $auth);
    $attempt = payInFull($ctx, $auth, $request, $connection);

    // "Pending" arriving after "success" is stale news, not a reversal.
    $applied = PaymentService::applyProviderState($ctx, [
        'provider_payment_id' => (string) $attempt['provider_payment_id'],
        'status' => States::ATTEMPT_PENDING,
    ], $connection);

    assertSame(false, $applied['applied'], 'stale news is ignored');
    assertSame(
        States::ATTEMPT_SUCCESS,
        (string) PaymentService::require($ctx, (int) $attempt['attempt_id'])['status'],
        'the payment is still successful',
    );
});

check('a payment the provider reports that Pay never started is recorded, not dropped', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $connection = connectMock($ctx);

    [$raw, $headers] = mockWebhook([
        'event'        => EventNames::PAYMENT_SUCCESS,
        'id'           => 'evt_orphan_001',
        'payment_id'   => 'mock_pay_orphan',
        'status'       => States::ATTEMPT_SUCCESS,
        'amount_minor' => 250000,
        'currency'     => 'INR',
        'method'       => 'UPI',
    ]);

    WebhookIngest::handle('MOCK', $raw, $headers);

    // Money that arrived with nowhere to put it must not be lost.
    $orphan = Db::first(
        'SELECT * FROM pay_payment_attempts WHERE provider_payment_id = :pid',
        ['pid' => 'mock_pay_orphan'],
    );
    assertTrue($orphan !== null, 'the payment was recorded');
    assertSame(250000, (int) $orphan['amount_minor'], 'with the amount the provider reported');

    assertSame(1, (int) Db::scalar(
        'SELECT COUNT(*) FROM pay_reconciliation_cases WHERE case_kind = :kind',
        ['kind' => ReconciliationService::UNRECONCILED_TRANSACTION],
    ), 'and flagged for reconciliation');
});

// ===========================================================================
echo "\nRouting\n";
// ===========================================================================

check('a provider that cannot take the method is skipped with a reason', static function (): void {
    resetDatabase();
    $ctx = freshContext();

    // Cards only.
    connectMock($ctx, 'Cards only', ['CARD']);

    $result = RoutingEngine::choose($ctx, ['method' => 'UPI', 'currency' => 'INR', 'amount_minor' => 100000]);

    assertSame(false, $result['ok'], 'no provider can take it');
    assertSame('method_unsupported', $result['error_code'], 'and the code says exactly why');
    assertTrue(str_contains($result['error_message'], 'UPI'), 'the message names the method: ' . $result['error_message']);
});

check('a disabled provider is never routed to, whatever a rule says', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();

    $good = connectMock($ctx, 'Working provider');
    $bad = connectMock($ctx, 'Broken provider', ['UPI', 'CARD'], 'LIVE', false);

    // The second is disabled after the fact, as happens when credentials fail.
    Db::update('pay_provider_connections', [
        'status' => 'CREDENTIALS_INVALID', 'status_reason' => 'The stored key was rejected.',
    ], ['connection_id' => (int) $bad['connection_id']]);
    ProviderRegistry::forget();

    // And a rule points at it anyway.
    Db::insert('pay_routing_rules', [
        'cmp_id'   => $ctx->cmpId,
        'rule_name' => 'UPI to the broken one',
        'priority' => 1,
        'match_method' => 'UPI',
        'primary_connection_id' => (int) $bad['connection_id'],
        'allow_failover' => true,
    ], 'rule_id');

    $result = RoutingEngine::choose($ctx, ['method' => 'UPI', 'currency' => 'INR', 'amount_minor' => 100000]);

    assertSame(true, $result['ok'], 'routing still finds a provider');
    assertSame(
        (int) $good['connection_id'],
        (int) $result['connection']['connection_id'],
        'and it is the working one, not the one the rule named',
    );
});

check('a rule sends payments to the provider it names', static function (): void {
    resetDatabase();
    $ctx = freshContext();

    $first = connectMock($ctx, 'First');
    $second = connectMock($ctx, 'Second', ['UPI', 'CARD'], 'LIVE', false);

    Db::insert('pay_routing_rules', [
        'cmp_id' => $ctx->cmpId,
        'rule_name' => 'UPI to Second',
        'priority' => 1,
        'match_method' => 'UPI',
        'primary_connection_id' => (int) $second['connection_id'],
    ], 'rule_id');
    ProviderRegistry::forget();

    $result = RoutingEngine::choose($ctx, ['method' => 'UPI', 'currency' => 'INR', 'amount_minor' => 50000]);

    assertSame(true, $result['ok'], 'routed');
    assertSame((int) $second['connection_id'], (int) $result['connection']['connection_id'], 'to the one the rule names');
    assertSame('RULE', $result['decided_by'], 'and says a rule decided it');
});

check('a declined payment is not retryable, so it does not fail over to a second provider', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);
    // The mock declines this exact amount.
    $request = makeRequest($ctx, $auth, ['amount' => MockProvider::AMOUNT_DECLINE / 100]);

    $result = PaymentService::start($ctx, $auth, $request, ['method' => 'CARD', 'idempotency_key' => 'd1']);

    assertSame(false, $result['ok'], 'the payment failed');
    assertSame('payment_declined', $result['error_code'], 'because the bank declined it');
    // Asking a different gateway will not change the bank's mind; it will just
    // put a second decline on the customer's statement.
    assertSame(false, $result['retryable'], 'a decline must NOT fail over');
});

check('a gateway timeout IS retryable', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    connectMock($ctx);
    $request = makeRequest($ctx, $auth, ['amount' => MockProvider::AMOUNT_TIMEOUT / 100]);

    $result = PaymentService::start($ctx, $auth, $request, ['method' => 'UPI', 'idempotency_key' => 't1']);

    assertSame(false, $result['ok'], 'the payment failed');
    assertSame(true, $result['retryable'], 'but it is worth trying elsewhere');
});

check('every routing decision is recorded with the candidates that lost', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    connectMock($ctx);
    $request = makeRequest($ctx, $auth);

    $start = PaymentService::start($ctx, $auth, $request, ['method' => 'UPI', 'idempotency_key' => 'r1']);
    $attempt = PaymentService::findByUuid($ctx, (string) $start['attempt']['payment_id']);

    $decision = Db::first(
        'SELECT * FROM pay_routing_decisions WHERE attempt_id = :id',
        ['id' => (int) $attempt['attempt_id']],
    );

    assertTrue($decision !== null, '"why did this go through X" is a lookup, not an argument');
    assertTrue(Db::jsonColumn($decision['considered']) !== [], 'and the candidates were recorded');
});

// ===========================================================================
echo "\nRefunds\n";
// ===========================================================================

check('a refund needs a stated reason', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);
    $attempt = payInFull($ctx, $auth, makeRequest($ctx, $auth), $connection);

    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'refund-key-1';

    assertThrows(
        static fn () => RefundService::request($ctx, $auth, $attempt, ['reason' => '']),
        'why this refund',
        'a refund with no reason',
    );
});

check('a refund cannot exceed what the payment took', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);
    $attempt = payInFull($ctx, $auth, makeRequest($ctx, $auth, ['amount' => 1000.00]), $connection);

    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'refund-key-2';

    assertThrows(
        static fn () => RefundService::request($ctx, $auth, $attempt, ['amount' => 5000.00, 'reason' => 'Too much']),
        'can still be refunded',
        'an over-refund',
    );
});

check('a requested refund reserves the money, so two clerks cannot each refund it all', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $owner = authFor();
    $connection = connectMock($ctx);
    $attempt = payInFull($ctx, $owner, makeRequest($ctx, $owner, ['amount' => 1000.00]), $connection);

    // Approval on, so the first refund sits in REQUESTED rather than going out.
    Settings::ensure($ctx);
    Settings::update($ctx, ['refund_requires_approval' => true]);

    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'refund-a';
    RefundService::request($ctx, $owner, $attempt, ['amount' => 1000.00, 'reason' => 'Clerk one refunds it all']);

    $fresh = PaymentService::require($ctx, (int) $attempt['attempt_id']);
    assertSame(0.0, PaymentService::refundable($ctx, $fresh)->toMajor(), 'the balance is reserved by the first request');

    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'refund-b';
    assertThrows(
        static fn () => RefundService::request($ctx, $owner, $fresh, ['amount' => 1000.00, 'reason' => 'Clerk two refunds it too']),
        'fully refunded',
        'a second full refund of the same payment',
    );
});

check('a refund cannot be approved by the person who asked for it', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $owner = authFor('owner-uuid');
    $connection = connectMock($ctx);
    $attempt = payInFull($ctx, $owner, makeRequest($ctx, $owner, ['amount' => 1000.00]), $connection);

    Settings::ensure($ctx);
    Settings::update($ctx, ['refund_requires_approval' => true]);

    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'refund-four-eyes';
    $refund = RefundService::request($ctx, $owner, $attempt, ['amount' => 500.00, 'reason' => 'Goods returned']);

    $row = RefundService::findByUuid($ctx, (string) $refund['refund_id']);

    assertThrows(
        static fn () => RefundService::approve($ctx, $owner, $row),
        'other than the person who requested',
        'self-approval',
    );
});

check('the four-eyes rule is also enforced by the database', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $owner = authFor();
    $connection = connectMock($ctx);
    $attempt = payInFull($ctx, $owner, makeRequest($ctx, $owner), $connection);

    $refundId = (int) Db::insert('pay_refunds', [
        'refund_uuid'  => Ids::mint(Ids::REFUND),
        'cmp_id'       => $ctx->cmpId,
        'attempt_id'   => (int) $attempt['attempt_id'],
        'amount_minor' => 10000,
        'currency'     => 'INR',
        'reason'       => 'Test',
        'requested_by' => 'same-person',
    ], 'refund_id');

    // A check that lives only in one code path is a check that one day gets a
    // second code path. This proves the constraint is there too.
    assertThrows(
        static fn () => Db::update('pay_refunds', ['approved_by' => 'same-person'], ['refund_id' => $refundId]),
        '',
        'the database refusing self-approval',
    );
});

check('a completed refund makes the request collectable again', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor('owner');
    $approver = authFor('approver');
    $connection = connectMock($ctx);
    $request = makeRequest($ctx, $auth, ['amount' => 1000.00]);
    $attempt = payInFull($ctx, $auth, $request, $connection);

    assertSame(States::REQUEST_PAID, (string) PaymentRequestService::require($ctx, (int) $request['request_id'])['status'], 'paid first');

    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'refund-full';
    $refund = RefundService::request($ctx, $auth, $attempt, ['amount' => 1000.00, 'reason' => 'Order cancelled']);
    $refundRow = RefundService::findByUuid($ctx, (string) $refund['refund_id']);
    RefundService::markRefunded($ctx, (int) $refundRow['refund_id']);

    $fresh = PaymentRequestService::require($ctx, (int) $request['request_id']);
    // PAID with nothing in it would be a lie.
    assertSame(States::REQUEST_ACTIVE, (string) $fresh['status'], 'a fully refunded request is collectable again');
    assertSame(1000.0, PaymentRequestService::outstanding($fresh)->toMajor(), 'and the whole amount is owed again');
});

// ===========================================================================
echo "\nExternal payments\n";
// ===========================================================================

check('an external payment needs a method, a reference and a date', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();

    assertThrows(
        static fn () => ExternalPaymentService::record($ctx, $auth, [
            'amount' => 1000, 'method' => 'BANK_TRANSFER', 'external_reference' => '', 'received_at' => '2026-01-14',
        ]),
        'UTR',
        'a bank transfer with no UTR',
    );

    assertThrows(
        static fn () => ExternalPaymentService::record($ctx, $auth, [
            'amount' => 1000, 'method' => 'CHEQUE', 'external_reference' => '', 'received_at' => '2026-01-14',
        ]),
        'cheque number',
        'a cheque with no number — and the prompt is method-specific',
    );
});

check('an external payment settles the request and is never "pending settlement"', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $request = makeRequest($ctx, $auth, ['amount' => 1000.00]);

    $result = ExternalPaymentService::record($ctx, $auth, [
        'request_id'         => (string) $request['request_uuid'],
        'amount'             => 1000.00,
        'method'             => 'BANK_TRANSFER',
        'external_reference' => 'UTR123456789',
        'received_at'        => gmdate('Y-m-d'),
        'note'               => 'NEFT received directly',
    ]);

    assertSame('ACTIVE', $result['status'], 'the record is live');

    $fresh = PaymentRequestService::require($ctx, (int) $request['request_id']);
    assertSame(States::REQUEST_PAID, (string) $fresh['status'], 'the request is paid');

    $attempt = Db::first(
        'SELECT * FROM pay_payment_attempts WHERE request_id = :id',
        ['id' => (int) $request['request_id']],
    );
    // No provider took it, so no provider will ever settle it. PENDING would
    // leave it in the unsettled sweep forever.
    assertSame('NOT_APPLICABLE', (string) $attempt['settlement_status'], 'it can never settle, and says so');
    assertSame('EXTERNAL', (string) $attempt['provider_mode'], 'and it is marked external');
});

check('the same UTR cannot be recorded twice', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();

    $payload = [
        'amount' => 1000.00, 'method' => 'BANK_TRANSFER',
        'external_reference' => 'UTR-DUPLICATE', 'received_at' => gmdate('Y-m-d'),
    ];

    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'ext-1';
    ExternalPaymentService::record($ctx, $auth, $payload);

    // A different idempotency key, same UTR — which is somebody entering it
    // twice rather than a retry.
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'ext-2';
    assertThrows(
        static fn () => ExternalPaymentService::record($ctx, $auth, $payload),
        '',
        'a duplicate UTR',
    );
    unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
});

check('a reversed external payment is kept, and the request falls back', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $request = makeRequest($ctx, $auth, ['amount' => 1000.00]);

    $recorded = ExternalPaymentService::record($ctx, $auth, [
        'request_id' => (string) $request['request_uuid'],
        'amount' => 1000.00, 'method' => 'CHEQUE',
        'external_reference' => 'CHQ-00123', 'received_at' => gmdate('Y-m-d'),
    ]);

    $row = ExternalPaymentService::findByUuid($ctx, (string) $recorded['external_payment_id']);
    ExternalPaymentService::reverse($ctx, $auth, (int) $row['external_id'], 'Cheque bounced');

    $fresh = PaymentRequestService::require($ctx, (int) $request['request_id']);
    assertSame(States::REQUEST_ACTIVE, (string) $fresh['status'], 'the request is owed again');
    assertSame(0, (int) $fresh['paid_minor'], 'and nothing is counted as paid');

    // The evidence that somebody recorded a payment that did not exist is
    // exactly the evidence worth keeping.
    assertSame(1, (int) Db::scalar('SELECT COUNT(*) FROM pay_external_payments WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]),
        'the original record still stands');
});

// ===========================================================================
echo "\nTenant isolation\n";
// ===========================================================================

check('one company cannot read another company\'s payment', static function (): void {
    resetDatabase();

    $a = freshContext(55);
    $b = freshContext(56);
    $auth = authFor();
    $connection = connectMock($a);

    $request = makeRequest($a, $auth, ['amount' => 1000.00]);
    $attempt = payInFull($a, $auth, $request, $connection);

    assertTrue(PaymentService::find($a, (int) $attempt['attempt_id']) !== null, 'the owner can read it');
    assertSame(null, PaymentService::find($b, (int) $attempt['attempt_id']), 'another company cannot');
    assertSame(null, PaymentRequestService::findByUuid($b, (string) $request['request_uuid']), 'nor the request');
});

check('one company cannot use another company\'s provider', static function (): void {
    resetDatabase();

    $a = freshContext(55);
    $b = freshContext(56);
    connectMock($a);

    $result = RoutingEngine::choose($b, ['method' => 'UPI', 'currency' => 'INR', 'amount_minor' => 100000]);

    assertSame(false, $result['ok'], 'company B has no provider of its own');
    assertSame('no_provider_connected', $result['error_code'], 'and is told to connect one');
});

check('an API key is locked to the company it was minted in', static function (): void {
    resetDatabase();
    $ctx = freshContext(55);

    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach ([
        'uuid' => 'api:test', 'kind' => 'api_client', 'sourceApp' => 'EXTERNAL',
        'sesKey' => '', 'session' => null, 'boundCmpId' => 55,
        'scopes' => ['payment_requests.write'], 'testMode' => true,
    ] as $prop => $value) {
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($auth, $value);
    }

    // The key says 55. A request claiming 56 must be refused, not honoured.
    $other = freshContext(56);
    assertThrows(
        static fn () => $other->assertAllowed($auth),
        'cannot act on that company',
        'an API key reaching outside its company',
    );
});

// ===========================================================================
echo "\nPermissions\n";
// ===========================================================================

check('a collections clerk can raise a request but cannot refund or touch a gateway', static function (): void {
    resetDatabase();
    // Company 57, which the stub reports as delegated. Against a company the
    // user owns there is nothing to test: an owner holds everything.
    $ctx = freshContext(57);
    $clerk = userWithRole($ctx, 'clerk-uuid', 'collections_clerk');

    assertTrue(Permissions::allows($ctx, $clerk, 'payment_requests.create'), 'a clerk can ask for money');
    assertTrue(Permissions::allows($ctx, $clerk, 'external_payment.record'), 'and record what comes in');

    // The three the whole role system exists for.
    assertSame(false, Permissions::allows($ctx, $clerk, 'refunds.request'), 'but cannot send money back');
    assertSame(false, Permissions::allows($ctx, $clerk, 'providers.manage'), 'nor re-key a gateway');
    assertSame(false, Permissions::allows($ctx, $clerk, 'routing.manage'), 'nor change where the money goes');
});

check('requesting and approving a refund are separate rights', static function (): void {
    resetDatabase();
    $ctx = freshContext(57);
    $finance = userWithRole($ctx, 'finance-uuid', 'finance');
    $manager = userWithRole($ctx, 'manager-uuid', 'payments_manager');

    assertTrue(Permissions::allows($ctx, $finance, 'refunds.request'), 'finance may ask');
    assertSame(false, Permissions::allows($ctx, $finance, 'refunds.approve'), 'but not send');
    assertTrue(Permissions::allows($ctx, $manager, 'refunds.approve'), 'a manager may send');
});

check('an API client holds scopes and never a human permission', static function (): void {
    resetDatabase();
    $ctx = freshContext();

    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach ([
        'uuid' => 'api:x', 'kind' => 'api_client', 'sourceApp' => 'EXTERNAL',
        'sesKey' => '', 'session' => null, 'boundCmpId' => 55,
        'scopes' => ['payment_requests.write', 'refunds.write'], 'testMode' => true,
    ] as $prop => $value) {
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($auth, $value);
    }

    assertTrue($auth->hasScope('refunds.write'), 'the key holds its scope');
    // Mapping a human right onto a key is how a read-only integration ends up
    // able to approve a refund.
    assertSame(false, Permissions::allows($ctx, $auth, 'refunds.approve'), 'but never a human permission');
    assertSame(false, Permissions::allows($ctx, $auth, 'providers.manage'), 'and never gateway management');
});

// ===========================================================================
echo "\nOutbound callbacks\n";
// ===========================================================================

check('a successful payment queues a callback in the same breath', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);

    $request = makeRequest($ctx, $auth, [
        'source_app' => 'BOOKS', 'source_type' => 'SALES_INVOICE',
        'source_id' => '92841', 'amount' => 1000.00,
    ]);

    payInFull($ctx, $auth, $request, $connection);

    $queued = Db::first(
        'SELECT * FROM pay_outbound_events WHERE cmp_id = :cmp AND event_name = :event',
        ['cmp' => $ctx->cmpId, 'event' => EventNames::PAYMENT_SUCCESS],
    );

    assertTrue($queued !== null, 'money can never be taken without the event existing');
    assertSame('BOOKS', (string) $queued['target_app'], 'aimed at the app that raised it');

    $payload = Db::jsonColumn($queued['payload']);
    assertSame('92841', (string) $payload['source_id'], 'carrying the source document');
    assertSame(1000.0, (float) $payload['amount'], 'and the amount');
    // A receiver that only reads `amount` cannot tell a part payment from a
    // full one; both figures travel.
    assertSame(0.0, (float) $payload['request_outstanding'], 'and where the request now stands');
});

check('a part payment sends its own event name', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);

    $request = makeRequest($ctx, $auth, [
        'source_app' => 'BOOKS', 'source_id' => '92842',
        'amount' => 1000.00, 'allow_partial_payment' => true, 'min_partial_amount' => 100,
    ]);

    payInFull($ctx, $auth, $request, $connection, 400.00);

    // Books files a part receipt and leaves the bill open. Making it do
    // arithmetic on two numbers to work that out is how integrations get it
    // wrong.
    assertSame(1, (int) Db::scalar(
        'SELECT COUNT(*) FROM pay_outbound_events WHERE event_name = :event',
        ['event' => EventNames::PARTIAL_PAYMENT_RECEIVED],
    ), 'a part payment is announced as one');

    assertSame(0, (int) Db::scalar(
        'SELECT COUNT(*) FROM pay_outbound_events WHERE event_name = :event',
        ['event' => EventNames::PAYMENT_SUCCESS],
    ), 'and not as a full payment');
});

check('a callback is delivered, signed, with a stable event id', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);

    $request = makeRequest($ctx, $auth, ['source_app' => 'BOOKS', 'source_id' => '92843', 'amount' => 500.00]);
    payInFull($ctx, $auth, $request, $connection);

    $stats = Outbox::drain(10);
    assertTrue($stats['delivered'] >= 1, 'the callback was delivered: ' . json_encode($stats));

    $delivered = null;
    foreach (stubRequests() as $call) {
        if (str_contains((string) $call['path'], 'pay/events')) {
            $delivered = $call;
            break;
        }
    }

    assertTrue($delivered !== null, 'the source app was actually called');
    assertTrue(($delivered['headers']['x-pay-signature'] ?? '') !== '', 'the callback was signed');
    assertTrue(str_starts_with((string) $delivered['headers']['x-pay-signature'], 't='), 'with a timestamped signature');
    assertTrue(($delivered['headers']['x-pay-event-id'] ?? '') !== '', 'and carries an event id the receiver deduplicates on');

    // The signature is over the exact bytes sent.
    [$t, $v1] = explode(',', (string) $delivered['headers']['x-pay-signature'], 2);
    $timestamp = substr($t, 2);
    $signature = substr($v1, 3);
    $expected = hash_hmac('sha256', $timestamp . '.' . (string) $delivered['raw'], 'test-callback-secret');
    assertSame($expected, $signature, 'and the signature verifies against the raw body');
});

check('a callback that fails is retried with backoff, not lost', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);

    $request = makeRequest($ctx, $auth, ['source_app' => 'BOOKS', 'source_id' => '92844', 'amount' => 500.00]);
    payInFull($ctx, $auth, $request, $connection);

    // Books is having a bad minute.
    stubFail('pay/events', 503);
    $stats = Outbox::drain(10);
    stubRecover();

    assertSame(1, $stats['failed'], 'the delivery failed: ' . json_encode($stats));

    $event = Db::first('SELECT * FROM pay_outbound_events WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
    assertSame(Outbox::FAILED, (string) $event['status'], 'it is marked failed, not gone');
    assertSame(1, (int) $event['attempt_count'], 'one attempt so far');
    assertTrue(strtotime((string) $event['next_attempt_at']) > time(), 'and it is scheduled to try again');

    // The payment itself was never in doubt.
    assertSame(States::REQUEST_PAID, (string) PaymentRequestService::require($ctx, (int) $request['request_id'])['status'],
        'a source app being down must never roll back a payment');
});

check('a permanent refusal stops retrying and opens a case', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);

    $request = makeRequest($ctx, $auth, ['source_app' => 'BOOKS', 'source_id' => '92845', 'amount' => 500.00]);
    payInFull($ctx, $auth, $request, $connection);

    // 422: "that invoice does not exist". The sixth attempt will say the same.
    stubFail('pay/events', 422);
    $stats = Outbox::drain(10);
    stubRecover();

    assertSame(1, $stats['exhausted'], 'a refusal is not retried: ' . json_encode($stats));

    assertSame(1, (int) Db::scalar(
        'SELECT COUNT(*) FROM pay_reconciliation_cases WHERE case_kind = :kind',
        ['kind' => ReconciliationService::SOURCE_SYNC_FAILED],
    ), 'and a human is told the books are now missing a receipt');
});

check('a receiver deduplicates a redelivered callback on the event id', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);

    $request = makeRequest($ctx, $auth, ['source_app' => 'BOOKS', 'source_id' => '92846', 'amount' => 500.00]);
    payInFull($ctx, $auth, $request, $connection);

    Outbox::drain(10);

    $event = Db::first('SELECT * FROM pay_outbound_events WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
    Outbox::retry($ctx, (int) $event['outbound_id']);
    Outbox::drain(10);

    $store = sys_get_temp_dir() . '/pay-stub-events.json';
    $seen = is_file($store) ? (json_decode((string) file_get_contents($store), true) ?: []) : [];

    // The event id is stable across every retry — a fresh one per attempt would
    // defeat the receiver's own idempotency.
    assertSame(1, count($seen), 'the receiver saw one distinct event, however many times it was sent');
});

// ===========================================================================
echo "\nDashboards\n";
// ===========================================================================

check('all five dashboards answer', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);
    payInFull($ctx, $auth, makeRequest($ctx, $auth, ['amount' => 1000.00]), $connection);

    $period = Period::resolve(Period::TODAY);

    foreach ([
        'overview'    => static fn () => DashboardService::overview($ctx, $auth, $period),
        'collections' => static fn () => DashboardService::collections($ctx, $auth, $period),
        'gateways'    => static fn () => DashboardService::gateways($ctx, $auth, $period),
        'settlements' => static fn () => DashboardService::settlements($ctx, $auth, $period),
        'pay_pulse'   => static fn () => \Aicountly\Api\PayPulse\PulseDashboard::build($ctx, $auth, $period),
    ] as $name => $build) {
        $result = $build();
        assertTrue(is_array($result), $name . ' returned something');
        assertTrue(isset($result['period']), $name . ' says which window it is showing');
    }
});

check('the overview counts today\'s collection', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);
    payInFull($ctx, $auth, makeRequest($ctx, $auth, ['amount' => 1000.00]), $connection);

    $overview = DashboardService::overview($ctx, $auth, Period::resolve(Period::TODAY));

    $collected = null;
    foreach ($overview['metrics'] as $metric) {
        if ($metric['id'] === 'collected') {
            $collected = $metric;
        }
    }

    assertTrue($collected !== null, 'there is a collected card');
    assertSame(1000.0, (float) $collected['value'], 'showing what was collected');
});

check('a success rate with no payments is unavailable, not 0%', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    connectMock($ctx);

    $overview = DashboardService::overview($ctx, $auth, Period::resolve(Period::TODAY));

    foreach ($overview['metrics'] as $metric) {
        if ($metric['id'] === 'success_rate') {
            // "0% success" and "nobody tried" are different facts, and only one
            // of them is alarming.
            assertSame('unavailable', $metric['status'], 'a rate with no denominator says so');
            assertSame(null, $metric['value'], 'rather than showing zero');

            return;
        }
    }

    throw new \RuntimeException('no success rate card was found');
});

check('a rise in failures is not painted as good news', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    connectMock($ctx);

    // Two failures today, none before.
    for ($i = 0; $i < 2; $i++) {
        $request = makeRequest($ctx, $auth, ['amount' => MockProvider::AMOUNT_DECLINE / 100]);
        PaymentService::start($ctx, $auth, $request, ['method' => 'CARD', 'idempotency_key' => 'f' . $i]);
    }

    $overview = DashboardService::overview($ctx, $auth, Period::resolve(Period::TODAY));

    foreach ($overview['metrics'] as $metric) {
        if ($metric['id'] === 'failed') {
            assertSame(2, (int) $metric['value'], 'both failures counted');

            $comparison = $metric['comparison'];
            if ($comparison !== null && ($comparison['available'] ?? false)) {
                assertTrue(
                    ($comparison['tone'] ?? '') !== 'positive',
                    'more failures must never be green',
                );
            }

            return;
        }
    }

    throw new \RuntimeException('no failed-payments card was found');
});

check('the collections funnel never goes up in the middle', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);

    $request = makeRequest($ctx, $auth, ['amount' => 1000.00]);
    PaymentRequestService::recordView((int) $request['request_id']);
    payInFull($ctx, $auth, $request, $connection);
    makeRequest($ctx, $auth, ['amount' => 500.00]);

    $collections = DashboardService::collections($ctx, $auth, Period::resolve(Period::TODAY));
    $stages = $collections['panels']['funnel']['stages'];

    $previous = PHP_INT_MAX;
    foreach ($stages as $stage) {
        assertTrue(
            (int) $stage['count'] <= $previous,
            'the funnel must be monotonic — ' . $stage['key'] . ' had ' . $stage['count'] . ' after ' . $previous,
        );
        $previous = (int) $stage['count'];
    }
});

// ===========================================================================
echo "\nPay Pulse\n";
// ===========================================================================

check('Pay Pulse says it is warming up rather than inventing a forecast', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    connectMock($ctx);

    $pulse = \Aicountly\Api\PayPulse\PulseDashboard::build($ctx, $auth, Period::resolve(Period::TODAY));

    assertSame(true, $pulse['warming_up'], 'with almost no data it says so');
    assertTrue(str_contains((string) $pulse['reason'], 'more payment activity'), 'and says what it needs');
});

check('an insight carries the evidence it was computed from', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    $connection = connectMock($ctx);

    // Something to find: an open request expiring tomorrow.
    $request = makeRequest($ctx, $auth, [
        'amount' => 5000.00,
        'expires_at' => gmdate('c', time() + 3600),
    ]);

    InsightEngine::compute($ctx);
    $insights = InsightEngine::headlines($ctx, 10);

    assertTrue($insights !== [], 'something was found');

    foreach ($insights as $insight) {
        // An insight a merchant cannot check is one they will not act on.
        assertTrue(isset($insight['evidence']), $insight['key'] . ' carries its evidence');
        assertTrue(isset($insight['confidence']), $insight['key'] . ' says how confident it is');
        assertTrue($insight['headline'] !== '', $insight['key'] . ' has a headline');
    }
});

check('Pay Pulse recommends a routing change and does not make one', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();
    connectMock($ctx);

    $before = (int) Db::scalar('SELECT COUNT(*) FROM pay_routing_rules WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
    InsightEngine::compute($ctx);
    $after = (int) Db::scalar('SELECT COUNT(*) FROM pay_routing_rules WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);

    // Routing decides where a merchant's money goes. An AI does not get to
    // change that because a number moved.
    assertSame($before, $after, 'computing insights must never alter routing');
});

check('auto-optimisation is off by default', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    Settings::ensure($ctx);

    $settings = Settings::for($ctx);
    $enabled = ($settings['auto_routing_enabled'] ?? false) === true || ($settings['auto_routing_enabled'] ?? false) === 't';

    assertSame(false, $enabled, 'a merchant must opt in before software moves their money');
});

// ===========================================================================
echo "\nThe no-database-synchronisation rule\n";
// ===========================================================================

check('no table in this product holds another product\'s master data', static function (): void {
    // THE RELEASE-BLOCKING TEST. Reads information_schema and fails if an
    // authoritative table from another product has appeared here. Its whole
    // job is to fail the day somebody adds a convenient copy of the customer
    // master.
    $tables = Db::all(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = 'public' AND table_type = 'BASE TABLE'",
    );

    // Shapes that would mean Pay had started keeping somebody else's records.
    // Matched against the WHOLE suffix after `pay_`, so `pay_settlement_items`
    // — which is Pay's own settlement lines — is not mistaken for an item
    // master, while `pay_items` would be caught.
    $forbidden = [
        'companies', 'company', 'branches', 'branch', 'financial_years', 'fiscal_years',
        'invoices', 'vouchers', 'ledgers', 'ledger_entries', 'accounts', 'account_masters',
        'chart_of_accounts', 'customers', 'customer_masters', 'parties', 'items', 'stock',
        'stock_ledger', 'inventory', 'gst_returns', 'tax_masters', 'hsn_codes',
        'subscriptions', 'plans', 'users', 'employees',
    ];

    $offenders = [];
    foreach ($tables as $table) {
        $name = (string) $table['table_name'];
        if (!str_starts_with($name, 'pay_')) {
            continue;
        }
        if (in_array(substr($name, 4), $forbidden, true)) {
            $offenders[] = $name;
        }
    }

    assertSame([], $offenders, 'Pay must not hold another product\'s master data: ' . implode(', ', $offenders));
});

check('a payer row keeps a reference, not a copy of the customer', static function (): void {
    $columns = Db::all(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'pay_payers'",
    );

    $names = array_map(static fn (array $c) => (string) $c['column_name'], $columns);

    assertTrue(in_array('source_app', $names, true), 'it records which app owns them');
    assertTrue(in_array('source_customer_ref', $names, true), 'and their id over there');

    // What a copied master would have brought with it.
    foreach (['gstin', 'pan', 'credit_limit', 'credit_days', 'billing_address', 'shipping_address', 'opening_balance'] as $forbidden) {
        assertTrue(
            !in_array($forbidden, $names, true),
            'pay_payers must not carry "' . $forbidden . '" — that belongs to the app that owns the customer',
        );
    }
});

check('nothing in the codebase opens a second database connection', static function (): void {
    $root = dirname(__DIR__);
    $offenders = [];

    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        // A second PDO, an FDW, or a dblink would all be ways around the rule.
        foreach (['new PDO(', 'dblink', 'postgres_fdw', 'CREATE SERVER', 'IMPORT FOREIGN SCHEMA'] as $needle) {
            if (str_contains($contents, $needle) && !str_contains($file->getPathname(), '/Db.php')) {
                $offenders[] = basename($file->getPathname()) . ' contains "' . $needle . '"';
            }
        }
    }

    assertSame([], $offenders, 'cross-database access: ' . implode('; ', $offenders));
});

// ===========================================================================
echo "\nProduction safety\n";
// ===========================================================================

check('the mock provider refuses to be constructed in production', static function (): void {
    $original = getenv('APP_ENV');
    putenv('APP_ENV=production');

    try {
        assertThrows(
            static fn () => new MockProvider([], []),
            'cannot be used in production',
            'a mock provider on a live host',
        );
    } finally {
        putenv($original === false ? 'APP_ENV' : 'APP_ENV=' . $original);
    }
});

check('the registry will not build the mock in production either', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $connection = connectMock($ctx);

    $original = getenv('APP_ENV');
    putenv('APP_ENV=production');
    ProviderRegistry::forget();

    try {
        assertSame(null, ProviderRegistry::forConnection($connection), 'no mock on a production host');
    } finally {
        putenv($original === false ? 'APP_ENV' : 'APP_ENV=' . $original);
        ProviderRegistry::forget();
    }
});

check('Aicountly Managed is honest about not being configured', static function (): void {
    $readiness = ProviderRegistry::managedReadiness();

    // Nothing in this product may claim Managed is live without partner
    // credentials behind it.
    assertSame(false, $readiness['available'], 'Managed is not available without a partner');
    assertTrue($readiness['reason'] !== null, 'and says why');
});

check('a public link token is long and unguessable', static function (): void {
    $tokens = [];
    for ($i = 0; $i < 50; $i++) {
        $token = Ids::token();
        assertTrue(strlen($token) >= 32, 'a token is at least 32 characters');
        assertTrue(!isset($tokens[$token]), 'and never repeats');
        $tokens[$token] = true;
    }

    // An incrementing id in a public URL is an invitation to walk the range.
    assertTrue(
        preg_match('/^\d+$/', array_key_first($tokens)) !== 1,
        'a token is not a number',
    );
});

check('a provider credential never reaches a response shape', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $connection = connectMock($ctx);

    $status = ProviderRegistry::credentialStatus((int) $connection['connection_id']);
    $serialised = json_encode($status);

    assertTrue(str_contains($serialised, 'configured'), 'the screen is told it is configured');
    assertTrue(!str_contains($serialised, 'mock_key_secret'), 'but never the secret itself');
    assertTrue(!str_contains($serialised, 'mock_webhook_secret'), 'nor the webhook secret');
});

check('the audit trail redacts anything secret-shaped', static function (): void {
    resetDatabase();
    $ctx = freshContext();
    $auth = authFor();

    Audit::record($ctx, $auth, Audit::PROVIDER_CREDENTIALS_SET, 'provider_connection', 1, null, [
        'provider'   => 'RAZORPAY',
        'key_secret' => 'rzp_live_should_never_be_stored',
        'key_id'     => 'rzp_live_visible_id',
    ]);

    $row = Db::first('SELECT after_state FROM pay_audit_log ORDER BY audit_id DESC LIMIT 1');
    $after = Db::jsonColumn($row['after_state']);

    assertSame('[redacted]', $after['key_secret'] ?? null, 'a secret is redacted');
    // key_id is an identifier, not a secret, and redacting it would make the
    // trail unreadable.
    assertSame('rzp_live_visible_id', $after['key_id'] ?? null, 'but an identifier is kept');
});

// ===========================================================================
echo "\nThe frontend/backend contract\n";
// ===========================================================================

/**
 * Every path the React app calls must exist in the router.
 *
 * These two halves live in different languages and are never compiled
 * together, so a renamed route is a 404 nobody sees until a user clicks the
 * thing. This walks web/src for `api.list('v1/…')` and friends and matches
 * each one against Routes.php, which is the only place that mapping is
 * written down.
 */
check('every API path the React app calls is a registered route', static function (): void {
    $root = dirname(__DIR__, 2);
    $webSrc = $root . '/web/src';
    if (!is_dir($webSrc)) {
        // The API is deployable without the web folder beside it — a checkout
        // of just server-php should not fail this suite.
        echo "      (web/src not present — skipped)\n";
        return;
    }

    // --- what the router serves ---
    $routesFile = (string) file_get_contents(dirname(__DIR__) . '/src/Routes.php');
    preg_match_all(
        "/->(get|post|put|delete)\(\s*'([^']+)'/",
        $routesFile,
        $matches,
        PREG_SET_ORDER,
    );

    $registered = [];
    foreach ($matches as $match) {
        // {id}, {token}, {paymentId} — the name does not matter for matching,
        // only that a segment is dynamic.
        $registered[] = preg_replace('/\{[^}]+\}/', '{}', $match[2]);
    }
    assertTrue(count($registered) > 40, 'Routes.php was actually parsed');

    // --- what the app calls ---
    $called = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($webSrc));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        preg_match_all(
            '/api\.(?:one|list|post|put|delete|unscoped)(?:<[^(]*>)?\(\s*(?:`([^`]+)`|\'([^\']+)\')/',
            $contents,
            $found,
            PREG_SET_ORDER,
        );

        foreach ($found as $call) {
            $path = $call[1] !== '' ? $call[1] : ($call[2] ?? '');
            if ($path === '' || !str_starts_with($path, 'v1/')) {
                continue;
            }

            // `${id}` in a template literal is the same dynamic segment the
            // router writes as `{id}`.
            $normalised = preg_replace('/\$\{[^}]*\}/', '{}', $path);
            $called[$normalised] = basename($file->getPathname());
        }
    }
    assertTrue(count($called) > 20, 'the web sources were actually scanned');

    $missing = [];
    foreach ($called as $path => $where) {
        if (!in_array($path, $registered, true)) {
            $missing[] = $path . ' (' . $where . ')';
        }
    }

    sort($missing);
    assertSame([], $missing, 'the app calls routes that do not exist: ' . implode(', ', $missing));
});

/**
 * A 503 must say which of six different things went wrong.
 *
 * Regression test for a live incident: an operator who had configured the
 * database correctly and simply not run the migrations was told "the Pay
 * database is not reachable", and went to debug a network that was fine. Every
 * PDOException answered with that one sentence.
 *
 * The exceptions here come from the REAL driver, not from hand-written strings,
 * because the whole bug was an assumption about what the driver returns:
 * getCode() carries the SQLSTATE for a query failure but the libpq code 7 for a
 * connection failure, so matching on it alone misclassifies every connection
 * error.
 */
check('a database failure says which failure it is', static function (): void {
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', Env::get('DB_HOST'), Env::get('DB_PORT'), Env::get('DB_NAME'));
    $user = Env::get('DB_USER');
    $pass = Env::get('DB_PASS');
    $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];

    /** @return array{code:string, message:string} */
    $explain = static function (callable $fn) use (&$explain): array {
        try {
            $fn();
        } catch (\PDOException $e) {
            return Health::explain($e);
        }

        throw new \RuntimeException('expected the driver to fail, it did not');
    };

    // A table that is not there. The connection is fine.
    assertSame(
        'schema_not_migrated',
        $explain(static fn () => (new \PDO($dsn, $user, $pass, $options))
            ->query('SELECT 1 FROM pay_table_that_does_not_exist'))['code'],
        'a missing table is not an unreachable database',
    );

    // A role that is not there, and a database that is not there, both arrive as
    // `FATAL: ... does not exist` and are told apart only by the quoted noun.
    assertSame(
        'database_credentials_rejected',
        $explain(static fn () => new \PDO($dsn, 'pay_role_that_does_not_exist', 'x', $options))['code'],
        'a bad role is a credentials problem',
    );

    assertSame(
        'no_such_database',
        $explain(static fn () => new \PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', Env::get('DB_HOST'), Env::get('DB_PORT'), 'pay_db_that_does_not_exist'),
            $user,
            $pass,
            $options,
        ))['code'],
        'a missing database is named as one',
    );

    // Nothing listening at all.
    assertSame(
        'database_unreachable',
        $explain(static fn () => new \PDO('pgsql:host=127.0.0.1;port=59999;dbname=x', 'u', 'p', $options))['code'],
        'a refused connection points at DB_HOST and DB_PORT',
    );

    // The two Db::connect() raises itself, before the driver is involved.
    assertSame(
        'database_not_configured',
        Health::explain(new \PDOException('Database is not configured (DB_NAME / DB_USER missing from api/.env).'))['code'],
        'an empty configuration says so',
    );

    assertSame(
        'driver_missing',
        Health::explain(new \PDOException('could not find driver'))['code'],
        'a host without pdo_pgsql says so',
    );

    // The fallback still exists for anything unrecognised.
    assertSame(
        'database_unavailable',
        Health::explain(new \PDOException('something nobody has seen before'))['code'],
        'and anything else falls back',
    );
});

/**
 * A route argument must reach its handler exactly as it arrived.
 *
 * This is a regression test for a real bug: the front controller lowercased the
 * whole path so it could compare it against the portal-relay allowlist, which
 * also lowercased every id and public link token inside it. `PAYREQ-…-A7F3` hit
 * the handler as `payreq-…-a7f3`, matched no row, and every by-id route in the
 * product answered 404 on a deployment — while passing a suite that called the
 * router directly with paths it had built itself.
 */
check('a path keeps the case of its ids and its link tokens', static function (): void {
    assertSame(
        'v1/payment-requests/PAYREQ-TLH1-527DD95B19FEA782',
        Path::normalise('/v1/payment-requests/PAYREQ-TLH1-527DD95B19FEA782'),
        'an id survives normalisation',
    );
    assertSame(
        'v1/public/pay/05KGcfMaXYKvR-o4CYyYDSuH6g59Fzsz',
        Path::normalise('/v1/public/pay/05KGcfMaXYKvR-o4CYyYDSuH6g59Fzsz'),
        'so does a public link token',
    );
    assertSame(
        'v1/payments',
        Path::normalise('//v1//payments/'),
        'empty segments still collapse',
    );
    assertSame(
        'v1/payments/../secret',
        Path::normalise('/v1/payments/%2e%2e/secret'),
        'and escapes are still decoded before anything compares them',
    );
    assertSame('global/seskey', Path::key('Global/SesKey'), 'a fixed name still folds');

    // The literal half of the path is still matched without regard to case.
    $router = new Router();
    $seen = null;
    $router->get('v1/payment-requests/{id}', static function (string $id) use (&$seen): void {
        $seen = $id;
    });

    assertTrue(
        $router->dispatch('GET', 'V1/Payment-Requests/PAYREQ-MiXeD-Case'),
        'a differently-cased literal segment still routes',
    );
    assertSame('PAYREQ-MiXeD-Case', $seen, 'and the argument arrives untouched');
});

/**
 * The front controller has to be able to find its own classes.
 *
 * index.php is the one file the autoloader cannot load, so it loads the
 * autoloader — and every test in this suite bootstraps that itself, which is
 * exactly why a missing require here would go unnoticed until deployment,
 * where it 500s every route below /session.
 */
check('the front controller bootstraps the autoloader', static function (): void {
    $front = (string) file_get_contents(dirname(__DIR__) . '/index.php');

    assertTrue(
        str_contains($front, "require __DIR__ . '/src/Autoload.php'"),
        'index.php requires the autoloader',
    );
    // The lowercase-everything bug came back the moment someone needed a
    // case-insensitive comparison and reached for the nearest variable.
    assertTrue(
        !str_contains($front, 'strtolower($path)'),
        'index.php does not fold the case of the whole path',
    );
    assertTrue(
        str_contains($front, 'Routes::register'),
        'index.php dispatches the product routes',
    );
});

// ===========================================================================

echo "\n" . str_repeat('=', 70) . "\n";
echo sprintf("%d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
