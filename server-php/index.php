<?php

declare(strict_types=1);

/**
 * Pay API — front controller.
 *
 * Deployed to <document root>/api, so it is same-origin with the React app on
 * both pay.aicountly.com and pay.gh.aicountly.com.
 *
 * This file handles the three routes that exist before the product does:
 *
 *   GET  /api/health          liveness + readiness
 *   POST /api/global/{path}   allow-listed relay to the portal auth API
 *   GET  /api/session         who the caller is, per the portal
 *
 * Everything else — the Pay API proper — is dispatched by Routes/Router at the
 * bottom, so authentication, company scope and the tenant check happen in one
 * place instead of being remembered per endpoint.
 */

namespace Aicountly\Api;

// Env is required by hand because the autoloader has not been registered yet
// and this file needs configuration before anything else runs. Everything after
// it — Portal, Router, Routes, the controllers and the services beneath them —
// is resolved by the autoloader, which is why it is loaded here and not only in
// the test bootstrap: without it this file parses fine and then fatals on the
// first class it names, which is every route below /session.
require __DIR__ . '/src/Env.php';
require __DIR__ . '/src/Autoload.php';

Env::load(__DIR__ . '/.env');

/**
 * Portal paths this API relays for the browser.
 *
 * The relay exists so the SPA never makes a cross-origin call to the portal:
 * a new product domain is not in the portal's CORS allowlist on day one.
 *
 * It is an allowlist and must stay one. Forwarding arbitrary paths would turn
 * this host into an open proxy for the portal's whole auth surface — login,
 * signup, OTP, user lookups — with the portal seeing this server's IP instead
 * of the caller's, so anything it rate-limits per IP could be driven through
 * here instead.
 */
const RELAYED_PATHS = [
    'seskey',
    'seskey/refresh',
    'refresh_authtoken',
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed> $payload
 */
function send_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * The Authorization header, wherever this server happens to expose it.
 *
 * Under CGI/FastCGI Apache does not pass it to PHP unless it is copied
 * explicitly, and after an internal rewrite it arrives only under the
 * REDIRECT_ prefix. Reading just one of these is why an otherwise correct
 * deployment answers 401 to every sign-in.
 */
function authorization_header(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];

    if (function_exists('apache_request_headers')) {
        foreach ((array) apache_request_headers() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $candidates[] = (string) $value;
                break;
            }
        }
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function bearer_token(): string
{
    $header = authorization_header();
    if ($header === '' || preg_match('/Bearer\s+(.+)/i', $header, $matches) !== 1) {
        return '';
    }

    return trim($matches[1]);
}

/**
 * CORS for local development only.
 *
 * In both deployed environments the app and this API share an origin, so no
 * CORS headers are needed or sent. CORS_ALLOWED_ORIGINS in the server .env is
 * what lets `npm run dev` on localhost talk to a deployed API.
 */
function apply_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    $allowed = array_filter(array_map('trim', explode(',', Env::get('CORS_ALLOWED_ORIGINS'))));
    if (!in_array($origin, $allowed, true)) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-Service-Key, X-Actor-Uuid, X-Saas-Origin, X-Source-App');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

apply_cors();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

// Strip the directory this front controller is mounted under, so the same file
// works at <docroot>/api and at the root of a dedicated API vhost.
$mountPoint = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
if ($mountPoint !== '' && $mountPoint !== '/' && strpos($uri, $mountPoint) === 0) {
    $uri = substr($uri, strlen($mountPoint));
}

$path = Path::normalise($uri);
// Only the fixed names below are matched case-insensitively. $path itself keeps
// the case it arrived with, because everything after this point may contain an
// id or a link token.
$fixed = Path::key($path);

if ($fixed === '' || $fixed === 'health') {
    // Liveness AND readiness. 'status' stays ok whenever PHP is serving, so an
    // uptime monitor pointed here keeps behaving as it always has; the database
    // and encryption blocks are what say whether the app can actually be used.
    // Reporting only the former is how a deploy goes green on an app whose every
    // real endpoint answers 503.
    $database = Health::database();
    $encryption = Health::encryption();

    send_json(200, [
        'status' => 'ok',
        'app' => 'Pay',
        'env' => Env::get('APP_ENV', 'unknown'),
        'time' => gmdate('c'),
        'database' => $database,
        // A boolean and nothing more: naming the key or its length would turn a
        // public endpoint into reconnaissance.
        'encryption' => $encryption,
        // One field to read when something is wrong. False means the site is up
        // and the product is not usable.
        'usable' => $database['reachable']
            && ($database['schema']['ready'] ?? false)
            && $encryption['configured'],
    ]);
}

if (strpos($fixed, 'global/') === 0) {
    $portalPath = substr($fixed, strlen('global/'));

    if (!in_array($portalPath, RELAYED_PATHS, true)) {
        send_json(404, ['message' => 'This path is not relayed. Call the portal API directly.']);
    }

    $headers = [];
    $authorization = authorization_header();
    if ($authorization !== '') {
        $headers[] = 'Authorization: ' . $authorization;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (is_string($contentType) && $contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }

    $body = (string) file_get_contents('php://input');
    $result = Portal::forward($method, $portalPath, $headers, $body);

    if ($result['status'] === 504) {
        send_json(504, ['message' => 'Auth service unavailable — please retry.']);
    }

    http_response_code($result['status']);
    header('Content-Type: ' . $result['contentType']);
    header('Cache-Control: no-store');
    echo $result['body'];
    exit;
}

if ($fixed === 'session') {
    $sesKey = bearer_token();
    if ($sesKey === '') {
        send_json(401, ['message' => 'Missing bearer session key.']);
    }

    $session = Portal::validateSesKey($sesKey);
    if ($session === null) {
        send_json(401, ['message' => 'Invalid or expired session.']);
    }

    send_json(200, [
        'authenticated' => true,
        'uuid' => $session['uuid_aictly'] ?? ($session['uuid'] ?? ''),
    ]);
}

// ---------------------------------------------------------------------------
// The Pay API
//
// Everything above this line is the auth bootstrap and predates the product.
// Everything below is the product, and it all goes through one router so that
// authentication, company scope and the tenant check happen in one place rather
// than being remembered per endpoint.
// ---------------------------------------------------------------------------

$router = new Router();
Routes::register($router);

try {
    if ($router->dispatch($method, $path)) {
        exit;
    }
} catch (\PDOException $e) {
    // A database problem is ours, not the caller's. The full driver text — which
    // names the database, the role and the host — goes to the log; the caller
    // gets the one sentence that says what to DO about it. Answering "not
    // reachable" to every PDOException sent operators who had configured the
    // connection correctly, and simply not migrated, to debug their network.
    error_log('[pay] database error on ' . $path . ': ' . $e->getMessage());
    $explained = Health::explain($e);
    Http::error(503, $explained['code'], $explained['message']);
} catch (\Throwable $e) {
    error_log('[pay] unhandled error on ' . $path . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Http::error(500, 'server_error', 'Something went wrong handling that request.');
}

send_json(404, ['message' => 'Not found.']);
