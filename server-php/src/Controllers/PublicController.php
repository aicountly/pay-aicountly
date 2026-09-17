<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\LinkService;
use Aicountly\Api\Domain\Money;
use Aicountly\Api\Domain\PaymentRequestService;
use Aicountly\Api\Domain\PaymentService;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Http;
use Aicountly\Api\ManageAccess;
use Aicountly\Api\Payments\Routing\RoutingEngine;

/**
 * The public payment page — the only part of Pay a stranger ever sees.
 *
 * NO LOGIN, AND NO COMPANY IN THE REQUEST. The caller is a customer with a link.
 * The company is read OFF THE LINK, never from a parameter, which is what makes
 * it impossible to point one merchant's token at another merchant's company.
 *
 * WHAT THIS ENDPOINT WILL NOT DO:
 *
 *   * trust an amount from the browser. `start()` reads what is outstanding out
 *     of the database. A posted amount is a REQUEST to pay part, checked
 *     against the request's own partial-payment rules, and a page that posts
 *     `amount=1` either pays the full invoice or is refused;
 *   * expose an internal id. Everything it returns is a uuid or a token;
 *   * confirm or deny a token's existence in a way worth probing. An unknown
 *     token and an expired one both answer 404 with the same words;
 *   * leak the merchant's data. The response carries a display name, a logo, an
 *     amount and a reference — what a payer needs to recognise what they are
 *     paying — and nothing about the merchant's other business.
 */
final class PublicController
{
    /** How many times one IP may hit the public endpoints before it is slowed down. */
    private const RATE_LIMIT_PER_MINUTE = 60;

    /**
     * What the payment page shows.
     *
     * GET /api/v1/public/pay/{token}
     */
    public static function show(string $token): never
    {
        self::rateLimit('view');

        $link = LinkService::findByToken($token);
        if ($link === null) {
            // Deliberately identical to the expired case below. A different
            // answer would let somebody walk the token space and learn which
            // ones exist.
            Http::notFound('This payment link is not valid. Ask for a new one.');
        }

        $ctx = Context::forBackground((int) $link['cmp_id']);
        $request = PaymentRequestService::find($ctx, (int) $link['request_id']);

        if ($request === null) {
            Http::notFound('This payment link is not valid. Ask for a new one.');
        }

        if (in_array((string) $link['status'], [LinkService::CANCELLED], true)) {
            Http::notFound('This payment link is not valid. Ask for a new one.');
        }

        PaymentRequestService::recordView((int) $request['request_id'], (int) $link['link_id']);

        $payable = PaymentRequestService::assertPayable($request);
        $currency = (string) $request['currency'];
        $outstanding = PaymentRequestService::outstanding($request);

        Http::data([
            'merchant' => self::merchant($ctx),
            'payment'  => [
                // A token, never an id.
                'token'       => (string) $link['public_token'],
                'reference'   => $request['source_reference'] === null ? null : (string) $request['source_reference'],
                'description' => $request['description'] === null ? null : (string) $request['description'],
                'amount'      => Money::minor((int) $request['amount_minor'], $currency)->toMajor(),
                'paid'        => Money::minor((int) $request['paid_minor'], $currency)->toMajor(),
                'outstanding' => $outstanding->toMajor(),
                'currency'    => $currency,
                'formatted'   => [
                    'amount'      => Money::minor((int) $request['amount_minor'], $currency)->format(),
                    'outstanding' => $outstanding->format(),
                ],
                'allow_partial' => $request['allow_partial'] === true || $request['allow_partial'] === 't',
                'min_partial' => $request['min_partial_minor'] === null
                    ? null : Money::minor((int) $request['min_partial_minor'], $currency)->toMajor(),
                'expires_at'  => $request['expires_at'] === null ? null : (string) $request['expires_at'],
                'status'      => (string) $request['status'],
            ],
            'payer' => [
                // Pre-filled so the payer is not asked for what the merchant
                // already told us. The email is masked: somebody forwarded this
                // link should not learn the intended recipient's address.
                'name'  => $request['payer_name'] === null ? null : (string) $request['payer_name'],
                'email_hint' => self::maskEmail($request['payer_email'] === null ? null : (string) $request['payer_email']),
            ],
            'payable' => $payable['ok'],
            'reason'  => $payable['ok'] ? null : $payable['error_message'],
            'methods' => self::availableMethods($ctx, $request),
            'branding' => ['powered_by' => 'Secure payments powered by Aicountly Pay'],
        ]);
    }

    /**
     * Begin a payment from the public page.
     *
     * POST /api/v1/public/pay/{token}/start
     */
    public static function start(string $token): never
    {
        self::rateLimit('start');

        $link = LinkService::findByToken($token);
        if ($link === null) {
            Http::notFound('This payment link is not valid. Ask for a new one.');
        }

        $ctx = Context::forBackground((int) $link['cmp_id']);
        $request = PaymentRequestService::find($ctx, (int) $link['request_id']);

        if ($request === null || (string) $link['status'] === LinkService::CANCELLED) {
            Http::notFound('This payment link is not valid. Ask for a new one.');
        }

        $body = Http::body();

        // THE AMOUNT IS VERIFIED SERVER-SIDE. What the browser sends is a
        // proposal; PaymentService::start checks it against what is actually
        // outstanding and against this request's partial-payment rules.
        $result = PaymentService::start(
            $ctx,
            // A public payer is not a user and has no permissions. The service
            // caller is Pay itself acting for the link.
            \Aicountly\Api\Auth::forApiClient('public:' . (string) $link['link_uuid'], 'PAY', $ctx->cmpId, [], false),
            $request,
            [
                'amount'  => $body['amount'] ?? null,
                'method'  => $body['method'] ?? null,
                'channel' => (string) $link['channel'],
                'link_id' => (int) $link['link_id'],
                'idempotency_key' => (string) (Http::header('Idempotency-Key') ?: ''),
            ],
        );

        if (!($result['ok'] ?? false)) {
            Http::error(
                422,
                (string) ($result['error_code'] ?? 'payment_failed'),
                (string) ($result['error_message'] ?? 'This payment could not be started.'),
            );
        }

        Http::data([
            'payment_id' => $result['attempt']['payment_id'],
            'provider'   => $result['provider'],
            'amount'     => $result['attempt']['amount'],
            'currency'   => $result['attempt']['currency'],
        ], 201);
    }

    /**
     * What the payer's browser polls after returning from a provider.
     *
     * GET /api/v1/public/pay/{token}/status/{paymentId}
     *
     * Answers only about a payment that belongs to THIS link, so a token
     * cannot be used to read another payment's state.
     */
    public static function status(string $token, string $paymentUuid): never
    {
        self::rateLimit('status');

        $link = LinkService::findByToken($token);
        if ($link === null) {
            Http::notFound('This payment link is not valid.');
        }

        $ctx = Context::forBackground((int) $link['cmp_id']);
        $attempt = PaymentService::findByUuid($ctx, $paymentUuid);

        if ($attempt === null || (int) ($attempt['request_id'] ?? 0) !== (int) $link['request_id']) {
            Http::notFound('That payment could not be found.');
        }

        $request = PaymentRequestService::find($ctx, (int) $link['request_id']);
        $currency = (string) $attempt['currency'];

        Http::data([
            'payment_id' => (string) $attempt['attempt_uuid'],
            'status'     => (string) $attempt['status'],
            'amount'     => Money::minor((int) $attempt['amount_minor'], $currency)->toMajor(),
            'currency'   => $currency,
            // A payer is told the outcome, never the provider's reference or
            // failure code.
            'message'    => self::payerMessage((string) $attempt['status']),
            'request_status' => $request === null ? null : (string) $request['status'],
            'outstanding' => $request === null ? null : PaymentRequestService::outstanding($request)->toMajor(),
            'return_url' => $request === null || $request['return_url'] === null ? null : (string) $request['return_url'],
        ]);
    }

    // -----------------------------------------------------------------------

    /**
     * The merchant, as a payer should see them.
     *
     * The trading name they configured for checkout, falling back to the
     * company name from Manage — read live, because a name copied once is a
     * name that keeps showing the old one on the single screen where being
     * wrong costs the payment.
     *
     * @return array<string, mixed>
     */
    private static function merchant(Context $ctx): array
    {
        $settings = Settings::for($ctx);

        $name = $settings['checkout_display_name'] === null ? null : (string) $settings['checkout_display_name'];

        if ($name === null) {
            try {
                // No session here, so this uses the service key. A failure
                // degrades to a generic name rather than failing the page: a
                // payment page that will not load because Manage is slow is a
                // payment that does not happen.
                $result = (new ManageClient())->companyInfo($ctx->cmpId);
                if ($result['ok']) {
                    $company = ManageAccess::companyRow($result['body'] ?? null);
                    foreach (['cmp_name', 'company_name', 'name', 'trade_name'] as $key) {
                        if (isset($company[$key]) && is_string($company[$key]) && $company[$key] !== '') {
                            $name = (string) $company[$key];
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('[public] could not read the company name: ' . $e->getMessage());
            }
        }

        return [
            'name'          => $name ?? 'Payment',
            'logo_url'      => $settings['checkout_logo_url'] === null ? null : (string) $settings['checkout_logo_url'],
            'support_email' => $settings['checkout_support_email'] === null ? null : (string) $settings['checkout_support_email'],
            'support_phone' => $settings['checkout_support_phone'] === null ? null : (string) $settings['checkout_support_phone'],
            'terms_url'     => $settings['checkout_terms_url'] === null ? null : (string) $settings['checkout_terms_url'],
        ];
    }

    /**
     * Methods a payer can actually be offered right now.
     *
     * Asked of the routing engine rather than listed from the request, so a
     * method no connected provider can serve is never shown — a payer choosing
     * UPI and being told afterwards that nobody takes it is the worst moment on
     * this page.
     *
     * @param array<string, mixed> $request
     * @return list<array{method:string, label:string}>
     */
    private static function availableMethods(Context $ctx, array $request): array
    {
        $allowed = Db::jsonColumn($request['allowed_methods'] ?? null);
        $candidates = $allowed !== [] ? $allowed : ['UPI', 'CARD', 'NETBANKING', 'WALLET'];

        $out = [];
        foreach ($candidates as $method) {
            $eligible = RoutingEngine::eligible($ctx, (string) $method, (string) $request['currency']);
            if ($eligible['usable'] !== []) {
                $out[] = [
                    'method' => (string) $method,
                    'label'  => \Aicountly\Api\Dashboards\PaymentReadings::methodLabel((string) $method),
                ];
            }
        }

        return $out;
    }

    private static function payerMessage(string $status): string
    {
        return match ($status) {
            'SUCCESS', 'CAPTURED' => 'Payment received. Thank you.',
            'AUTHORIZED' => 'Your payment is authorised and is being confirmed.',
            'PENDING'    => 'Your bank is still confirming this payment. It can take a few minutes.',
            'FAILED'     => 'That payment did not go through. You can try again.',
            'CANCELLED'  => 'That payment was cancelled.',
            default      => 'Your payment is being processed.',
        };
    }

    private static function maskEmail(?string $email): ?string
    {
        if ($email === null || !str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1) . str_repeat('•', max(2, strlen($local) - 1)) . '@' . $domain;
    }

    /**
     * A simple per-IP limit for the public surface.
     *
     * Not a defence against a distributed attack, and not meant to be. It stops
     * one client walking tokens or hammering the status poll, which is the
     * realistic abuse of an endpoint with no login on it.
     */
    private static function rateLimit(string $bucket): void
    {
        $ip = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $ip = trim(explode(',', $ip)[0]);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return;
        }

        $key = 'pay_rl_' . $bucket . '_' . hash('sha256', $ip) . '_' . (int) floor(time() / 60);
        $path = sys_get_temp_dir() . '/' . $key;

        $count = is_file($path) ? (int) file_get_contents($path) : 0;
        if ($count >= self::RATE_LIMIT_PER_MINUTE) {
            header('Retry-After: 60');
            Http::error(429, 'too_many_requests', 'Too many requests. Please wait a moment and try again.');
        }

        @file_put_contents($path, (string) ($count + 1));
    }
}
