<?php
/**
 * A stand-in for Manage, for the source apps and for the AICOUNTLY portal, for
 * the integration tests and for running the whole stack on a laptop.
 *
 * It answers the handful of endpoints Pay actually calls, in the envelope shape
 * the real contracts document, and records every request it received so a test
 * can assert on what was sent — which for this product means the IDEMPOTENCY
 * KEY and the SIGNATURE, the two things these tests exist to prove.
 */
declare(strict_types=1);

$log = getenv('STUB_LOG') ?: sys_get_temp_dir() . '/pay-stub-requests.jsonl';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}

file_put_contents($log, json_encode([
    'method'  => $method,
    'path'    => $path,
    'headers' => $headers,
    'body'    => $body,
    'raw'     => $raw,
    'query'   => $_GET,
]) . "\n", FILE_APPEND);

header('Content-Type: application/json');

/**
 * Forced failures, controlled through a file rather than the environment.
 *
 * The stub runs in its own process, started before the tests, so putenv() in
 * the test process cannot reach it. A file both processes can see is the only
 * control that works: {"path": "pay/events", "status": 503}.
 */
$controlFile = sys_get_temp_dir() . '/pay-stub-control.json';
$control = is_file($controlFile) ? (json_decode((string) file_get_contents($controlFile), true) ?: []) : [];
if (!empty($control['path']) && str_contains($path, (string) $control['path'])) {
    http_response_code((int) ($control['status'] ?? 500));
    echo json_encode(['error' => ['code' => 'stub_forced', 'message' => 'Forced failure for test']]);
    exit;
}

// --- Portal -----------------------------------------------------------------
/**
 * The two auth endpoints, so the real API and the real React app can be run
 * together without reaching my.aicountly.com.
 *
 * The session key encodes the user uuid it stands for — `stub.<uuid>` — because
 * every permission test in this suite turns on WHICH user is calling, and a
 * fixed uuid would make the stub able to play only one of them. It is a
 * development fixture and nothing more: PORTAL_AUTH_BASE points at the real
 * portal everywhere else, and a key minted here is worthless there.
 */
if (str_contains($path, '/seskey')) {
    $authToken = '';
    if (preg_match('/Bearer\s+(.+)/i', $headers['authorization'] ?? '', $m) === 1) {
        $authToken = trim($m[1]);
    }
    $uuid = $authToken !== '' ? $authToken : 'demo-owner';
    echo json_encode(['status' => 1, 'ses_key' => 'stub.' . $uuid, 'expires_in' => 900]);
    exit;
}

if (str_contains($path, '/validatesession')) {
    $sesKey = '';
    if (preg_match('/Bearer\s+(.+)/i', $headers['authorization'] ?? '', $m) === 1) {
        $sesKey = trim($m[1]);
    }
    if (!str_starts_with($sesKey, 'stub.')) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Not a stub session key.']);
        exit;
    }
    echo json_encode([
        'status' => 1,
        'data' => ['uuid_aictly' => substr($sesKey, 5), 'name' => 'Stub User'],
        'uuid_aictly' => substr($sesKey, 5),
    ]);
    exit;
}

// --- Manage -----------------------------------------------------------------
/**
 * Ownership is keyed off the company id so one stub can play every shape Manage
 * has used. Company 55 — the one almost every test opens — deliberately says
 * NOTHING about ownership on companyinfo, which is the case production is
 * actually in: the resolution has to fall through to the companies list.
 */
if (str_contains($path, '/companyinfo')) {
    $id = (int) ($_GET['comp_id'] ?? 0);
    echo json_encode(['data' => ['cmp_id' => $id, 'cmp_name' => 'Stub Trading Co', 'timezone' => 'Asia/Kolkata']]);
    exit;
}

if (str_contains($path, '/companies')) {
    echo json_encode(['data' => [
        ['comp_id' => 55, 'company_name' => 'Stub Trading Co', 'acs_type' => 1],
        ['comp_id' => 56, 'company_name' => 'Other Co', 'acs_type' => 1],
        ['comp_id' => 57, 'company_name' => 'Delegated Co', 'acs_type' => 0],
    ]]);
    exit;
}

// --- Source apps ------------------------------------------------------------
// Books' voucher, which Pay reads live when a request names one.
if (preg_match('#/vouchers/(\d+)$#', $path, $m) === 1) {
    echo json_encode(['data' => [
        'voucher_id'   => (int) $m[1],
        'voucher_no'   => 'INV-2026-' . $m[1],
        'voucher_date' => '2026-01-14',
        'grand_total'  => 118000.00,
        'balance'      => 118000.00,
        'account_id'   => 501,
        'account_name' => 'Northern Distributors',
        'status'       => 'POSTED',
    ]]);
    exit;
}

if (str_contains($path, '/masters/accounts/')) {
    echo json_encode(['data' => [
        'acc_id' => 501, 'acc_name' => 'Northern Distributors',
        'email' => 'accounts@northern.example', 'mobile' => '9876543210',
    ]]);
    exit;
}

/**
 * The event endpoint every source app exposes.
 *
 * Records what it received — including the signature and the event id — and
 * replays by event id, exactly as a real receiver must, so a test can prove a
 * redelivery produces one receipt and not two.
 */
if (str_contains($path, '/pay/events')) {
    $store = sys_get_temp_dir() . '/pay-stub-events.json';
    $seen = is_file($store) ? (json_decode((string) file_get_contents($store), true) ?: []) : [];
    $eventId = $headers['x-pay-event-id'] ?? ($body['event_id'] ?? '');

    if ($eventId !== '' && isset($seen[$eventId])) {
        echo json_encode(['data' => ['received' => true, 'duplicate' => true]]);
        exit;
    }

    if ($eventId !== '') {
        $seen[$eventId] = ['event' => $body['event'] ?? null, 'at' => gmdate('c')];
        file_put_contents($store, json_encode($seen));
    }

    echo json_encode(['data' => ['received' => true]]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => ['code' => 'not_found', 'message' => 'Stub has no route for ' . $path]]);
