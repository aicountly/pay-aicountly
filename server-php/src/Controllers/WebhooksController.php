<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Http;
use Aicountly\Api\Payments\Webhooks\WebhookIngest;

/**
 * Provider webhook endpoints.
 *
 * DELIBERATELY NOT A SUBCLASS OF Controller. These endpoints have no portal
 * session, no company in the query string and no CSRF token — the caller is
 * Razorpay's server, not a browser. Authentication IS the signature check,
 * which happens inside WebhookIngest over the raw body.
 *
 * Extending Controller here would call Auth::require() and 401 every callback
 * the fleet ever received.
 *
 * THE RAW BODY IS READ ONCE, before anything parses it. Every provider signs
 * the exact bytes they sent.
 */
final class WebhooksController
{
    public static function razorpay(): never
    {
        self::handle('RAZORPAY');
    }

    public static function cashfree(): never
    {
        self::handle('CASHFREE');
    }

    public static function stripe(): never
    {
        self::handle('STRIPE');
    }

    public static function payu(): never
    {
        self::handle('PAYU');
    }

    /** Development and test only; ProviderRegistry refuses to build the mock in production. */
    public static function mock(): never
    {
        self::handle('MOCK');
    }

    private static function handle(string $providerCode): never
    {
        $raw = (string) file_get_contents('php://input');

        // A body cap. A provider sending megabytes is either broken or hostile,
        // and either way it must not become a memory problem.
        if (strlen($raw) > 1048576) {
            Http::error(413, 'payload_too_large', 'That callback is too large to process.');
        }

        $result = WebhookIngest::handle($providerCode, $raw, self::headers());

        Http::json($result['status'], $result['body']);
    }

    /**
     * Every header, lower-cased.
     *
     * Read from both $_SERVER and apache_request_headers(): under CGI/FastCGI
     * some signature headers only arrive through one of them, and reading just
     * one is why an otherwise correct integration rejects every callback.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif (str_starts_with($key, 'REDIRECT_HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 14)));
                $headers[$name] ??= $value;
            }
        }

        if (function_exists('apache_request_headers')) {
            foreach ((array) apache_request_headers() as $name => $value) {
                if (is_string($value)) {
                    $headers[strtolower((string) $name)] ??= $value;
                }
            }
        }

        // Content-Type does not carry the HTTP_ prefix in CGI.
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] ??= $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }
}
