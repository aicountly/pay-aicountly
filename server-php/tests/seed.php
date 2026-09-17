<?php

declare(strict_types=1);

/**
 * Demo data for the local stack — see tests/devstack.sh.
 *
 * It goes through PaymentRequestService and PaymentService rather than INSERT,
 * so what ends up on the screens is what the product would actually produce:
 * derived request states, real idempotency keys, a settlement batch that does
 * not quite match, and the reconciliation cases that follow from it.
 *
 * NOT for a deployed host. It writes to whatever database .env points at.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';
Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Domain\{Ids, PaymentRequestService, PaymentService, States, Settings, SettlementService, Money, RefundService};
use Aicountly\Api\Payments\Providers\ProviderRegistry;

// The same fixtures the tests use, so this exercises real code paths.
require __DIR__ . '/fixtures.php';

if (Env::get('APP_ENV') === 'production') {
    fwrite(STDERR, "seed.php refuses to run against a production environment.\n");
    exit(1);
}

$ctx = freshContext(55);
$auth = authFor('demo-owner');
Settings::ensure($ctx);
Permissions::seed($ctx);
$connection = connectMock($ctx, 'Aicountly Managed');

$sources = [['BOOKS','SALES_INVOICE'],['BILLING','BILL'],['SALES','SALES_ORDER'],['POS','BILL'],['PAY',null]];
$amounts = [118000, 28000, 4990, 7560, 18000, 52000, 12480, 96400, 15200, 31000, 8900, 44500];

foreach ($amounts as $i => $paise) {
    $src = $sources[$i % count($sources)];
    $request = PaymentRequestService::findByUuid($ctx, (string) PaymentRequestService::create($ctx, $auth, [
        'source_app'  => $src[0],
        'source_type' => $src[1],
        'source_id'   => $src[1] === null ? null : (string) (90000 + $i),
        'reference'   => 'INV-2026-' . (1000 + $i),
        'description' => 'Payment for ' . $src[0],
        'amount'      => $paise / 100,
        'payer_name'  => ['Priya Mehta','UrbanNest Pvt Ltd','Karan Singh','FreshKart','Ananya Retail'][$i % 5],
        'payer_mobile' => '98765432' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
        'allow_partial_payment' => $i % 4 === 0,
        'min_partial_amount' => $i % 4 === 0 ? 100 : null,
    ])['payment_request_id']);

    // Two in three get paid; one in six part-paid; the rest fail.
    if ($i % 6 === 5) {
        $result = PaymentService::start($ctx, $auth, $request, ['method' => 'CARD', 'idempotency_key' => "seed-f$i"]);
        if ($result['ok'] ?? false) {
            PaymentService::markFailed($ctx, (int) PaymentService::findByUuid($ctx, (string) $result['attempt']['payment_id'])['attempt_id'],
                'payment_declined', 'The bank declined this card.');
        }
        continue;
    }

    $part = ($i % 4 === 0) ? round(($paise / 100) * 0.4, 2) : null;
    $method = ['UPI','UPI','CARD','NETBANKING','WALLET','UPI'][$i % 6];

    $result = PaymentService::start($ctx, $auth, $request, ['method' => $method, 'amount' => $part, 'idempotency_key' => "seed-$i"]);
    if (!($result['ok'] ?? false)) {
        // Expected on some rows: a 40% part payment of a small amount is below
        // the minimum this request allows, and the engine is right to refuse.
        echo "  refused (as designed): {$result['error_message']}\n";
        continue;
    }

    $attempt = PaymentService::findByUuid($ctx, (string) $result['attempt']['payment_id']);
    PaymentService::applyProviderState($ctx, [
        'provider_payment_id' => (string) $attempt['provider_payment_id'],
        'status' => States::ATTEMPT_SUCCESS,
        'amount_minor' => (int) $attempt['amount_minor'],
        'currency' => 'INR',
        'method' => $method,
        'occurred_at' => gmdate('c', time() - ($i * 3600)),
    ], $connection);
}

// A settlement batch, part credited, so the reconciler has something to find.
SettlementService::record($ctx, $connection, [
    'provider_settlement_id' => 'mock_stl_001',
    'settlement_date' => gmdate('c', strtotime('-1 day')),
    'status' => States::SETTLEMENT_PROCESSING,
    'currency' => 'INR',
    'amount_minor' => 312450, 'fee_minor' => 4680, 'tax_minor' => 842, 'refund_minor' => 8420,
    'utr' => 'UTR20260117001',
]);

SettlementService::findUnsettledPayments($ctx, 0);
\Aicountly\Api\PayPulse\InsightEngine::compute($ctx);

echo "seeded: ";
echo (int) Db::scalar('SELECT COUNT(*) FROM pay_payment_requests WHERE cmp_id=55') . " requests, ";
echo (int) Db::scalar('SELECT COUNT(*) FROM pay_payment_attempts WHERE cmp_id=55') . " payments, ";
echo (int) Db::scalar('SELECT COUNT(*) FROM pay_pulse_insights WHERE cmp_id=55') . " insights\n";
