<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Providers\Mock;

use Aicountly\Api\Env;
use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Providers\AbstractProvider;
use Aicountly\Api\Payments\Providers\Contracts\ManagedPaymentProviderInterface;
use RuntimeException;

/**
 * A provider that answers without a network, for development and the test suite.
 *
 * IT REFUSES TO EXIST IN PRODUCTION. The constructor throws when APP_ENV is
 * production, and ProviderRegistry never offers it there. This is the one rule
 * in this file that matters: a mock that can be reached on a live host is a
 * payment page that tells a customer their money went through when nothing
 * happened, and the merchant finds out at settlement.
 *
 * WHAT IT IS FOR. The integration tests need to drive every path — a capture, a
 * decline, a timeout, a duplicate webhook, a failed refund — deterministically
 * and without calling anybody's sandbox. Behaviour is chosen by the AMOUNT,
 * which is the one field every code path already carries, so a test asks for a
 * decline by requesting ₹111.11 rather than by reaching into the provider.
 *
 * It also implements the managed interface, so the Aicountly Managed onboarding
 * screens can be exercised end to end before a partner agreement exists.
 */
final class MockProvider extends AbstractProvider implements ManagedPaymentProviderInterface
{
    /** Amounts (in minor units) that steer the outcome. */
    public const AMOUNT_DECLINE   = 11111;
    public const AMOUNT_TIMEOUT   = 22222;
    public const AMOUNT_PENDING   = 33333;
    public const AMOUNT_AUTHORIZE = 44444;
    public const AMOUNT_REFUND_FAIL = 55555;

    /**
     * @param array<string, string> $credentials
     * @param array<string, mixed>  $connection
     */
    public function __construct(array $credentials, array $connection = [])
    {
        if (strtolower(Env::get('APP_ENV', 'production')) === 'production') {
            // Not a log line and not a graceful degradation. If this is ever
            // constructed on a production host, something is badly wrong and
            // the request must not continue.
            throw new RuntimeException('The mock payment provider cannot be used in production.');
        }

        parent::__construct($credentials, $connection);
    }

    public function code(): string
    {
        return 'MOCK';
    }

    public function displayName(): string
    {
        return 'Test provider (development only)';
    }

    public function supportedMethods(): array
    {
        return ['UPI', 'CARD', 'NETBANKING', 'WALLET'];
    }

    public function supportedCurrencies(): array
    {
        return ['INR', 'USD'];
    }

    public function capabilities(): array
    {
        return [
            'hosted_link'     => true,
            'dynamic_qr'      => true,
            'static_qr'       => true,
            'partial_capture' => true,
            'partial_refund'  => true,
            'mandates'        => true,
            'settlement_api'  => true,
            'dispute_api'     => true,
            'international'   => true,
            'managed_onboarding' => true,
        ];
    }

    protected function baseUrl(): string
    {
        return 'https://mock.invalid';
    }

    protected function authHeaders(): array
    {
        return [];
    }

    public function createPayment(array $payload): array
    {
        $amount = (int) $payload['amount_minor'];

        if ($amount === self::AMOUNT_TIMEOUT) {
            return $this->failure('gateway_timeout', 'The test provider timed out.', true);
        }
        if ($amount === self::AMOUNT_DECLINE) {
            // Not retryable: a decline is the bank's answer, and the routing
            // engine must not try the fallback. The test asserts exactly this.
            return $this->failure('payment_declined', 'The test provider declined this payment.', false);
        }

        $id = 'mock_pay_' . substr(hash('sha256', $payload['idempotency_key'] ?? uniqid('', true)), 0, 20);

        return [
            'ok'                  => true,
            'provider_payment_id' => $id,
            'provider_order_id'   => 'mock_order_' . substr($id, 9),
            'status'              => match ($amount) {
                self::AMOUNT_PENDING   => 'PENDING',
                self::AMOUNT_AUTHORIZE => 'AUTHORIZED',
                default                => 'CREATED',
            },
            'checkout_url'        => 'https://mock.invalid/checkout/' . $id,
            'raw'                 => ['mock' => true, 'amount' => $amount],
        ];
    }

    public function fetchPayment(string $providerPaymentId): array
    {
        return [
            'ok'           => true,
            'status'       => 'SUCCESS',
            'amount_minor' => 100000,
            'currency'     => 'INR',
            'method'       => 'UPI',
            'network'      => 'MOCKBANK',
            'last4'        => null,
            'vpa'          => 'te••@mockbank',
            'paid_at'      => gmdate('c'),
            'raw'          => ['mock' => true],
        ];
    }

    public function createPaymentLink(array $payload): array
    {
        $id = 'mock_link_' . substr(hash('sha256', (string) ($payload['reference'] ?? uniqid('', true))), 0, 16);

        return [
            'ok'               => true,
            'provider_link_id' => $id,
            'url'              => 'https://mock.invalid/l/' . $id,
            'expires_at'       => $payload['expires_at'] ?? null,
        ];
    }

    public function createQrCode(array $payload): array
    {
        return [
            'ok'             => true,
            'provider_qr_id' => 'mock_qr_' . substr(hash('sha256', (string) ($payload['reference'] ?? '')), 0, 16),
            'payload'        => 'upi://pay?pa=mock@mockbank&am=' . number_format(((int) $payload['amount_minor']) / 100, 2, '.', ''),
            'reusable'       => (bool) ($payload['reusable'] ?? false),
            'expires_at'     => $payload['expires_at'] ?? null,
        ];
    }

    public function refundPayment(array $payload): array
    {
        if ((int) $payload['amount_minor'] === self::AMOUNT_REFUND_FAIL) {
            return $this->failure('refund_rejected', 'The test provider refused this refund.', false);
        }

        return [
            'ok'                 => true,
            'provider_refund_id' => 'mock_rfnd_' . substr(hash('sha256', $payload['idempotency_key']), 0, 18),
            'status'             => 'PROCESSING',
        ];
    }

    public function fetchRefund(string $providerRefundId): array
    {
        return ['ok' => true, 'status' => 'REFUNDED', 'amount_minor' => 0, 'processed_at' => gmdate('c')];
    }

    public function fetchSettlement(array $filters): array
    {
        return ['ok' => true, 'settlements' => []];
    }

    public function verifyWebhook(array $headers, string $rawPayload): bool
    {
        $secret = $this->credential('webhook_secret');
        if ($secret === '') {
            return false;
        }

        $signature = $headers['x-mock-signature'] ?? '';

        // A real HMAC even in the mock, so the test suite exercises the same
        // comparison the real providers use — including the negative case,
        // which is the one worth testing.
        return is_string($signature)
            && $signature !== ''
            && hash_equals(hash_hmac('sha256', $rawPayload, $secret), $signature);
    }

    public function normalizeWebhook(array $payload): array
    {
        return [
            'event'               => (string) ($payload['event'] ?? EventNames::UNKNOWN),
            'provider_event_id'   => isset($payload['id']) ? (string) $payload['id'] : null,
            'provider_event_type' => isset($payload['type']) ? (string) $payload['type'] : null,
            'provider_payment_id' => isset($payload['payment_id']) ? (string) $payload['payment_id'] : null,
            'provider_order_id'   => isset($payload['order_id']) ? (string) $payload['order_id'] : null,
            'provider_refund_id'  => isset($payload['refund_id']) ? (string) $payload['refund_id'] : null,
            'provider_settlement_id' => isset($payload['settlement_id']) ? (string) $payload['settlement_id'] : null,
            'provider_dispute_id' => isset($payload['dispute_id']) ? (string) $payload['dispute_id'] : null,
            'status'              => isset($payload['status']) ? (string) $payload['status'] : null,
            'amount_minor'        => isset($payload['amount_minor']) ? (int) $payload['amount_minor'] : null,
            'currency'            => (string) ($payload['currency'] ?? 'INR'),
            'method'              => isset($payload['method']) ? (string) $payload['method'] : null,
            'network'             => isset($payload['network']) ? (string) $payload['network'] : null,
            'last4'               => isset($payload['last4']) ? (string) $payload['last4'] : null,
            'vpa'                 => isset($payload['vpa']) ? (string) $payload['vpa'] : null,
            'error_code'          => isset($payload['error_code']) ? (string) $payload['error_code'] : null,
            'error_message'       => isset($payload['error_message']) ? (string) $payload['error_message'] : null,
            'occurred_at'         => isset($payload['occurred_at']) ? (string) $payload['occurred_at'] : gmdate('c'),
        ];
    }

    public function healthCheck(): array
    {
        return ['ok' => true, 'latency_ms' => 1, 'detail' => 'Test provider — no network call was made.'];
    }

    // --- Managed, for exercising the onboarding screens ---------------------

    public function createMerchant(array $payload): array
    {
        return ['ok' => true, 'provider_merchant_id' => 'mock_mid_' . substr(hash('sha256', json_encode($payload) ?: ''), 0, 12), 'status' => 'IN_PROGRESS'];
    }

    public function updateMerchant(string $merchantId, array $payload): array
    {
        return ['ok' => true, 'status' => 'IN_PROGRESS'];
    }

    public function submitKyc(string $merchantId, array $payload): array
    {
        // UNDER_REVIEW and never VERIFIED, even here. A mock that hands back a
        // verified merchant teaches the calling code that verification is
        // something this product can produce, and the one place that must never
        // be true is the code path that decides whether a merchant may take
        // real money.
        return ['ok' => true, 'status' => 'UNDER_REVIEW', 'required_actions' => []];
    }

    public function uploadKycDocument(string $merchantId, array $document): array
    {
        return ['ok' => true, 'provider_document_id' => 'mock_doc_' . $document['kind'], 'status' => 'UPLOADED'];
    }

    public function fetchOnboardingStatus(string $merchantId): array
    {
        return ['ok' => true, 'status' => 'UNDER_REVIEW', 'detail' => 'Test provider — the partner is reviewing.', 'required_actions' => []];
    }

    public function fetchSettlementAccount(string $merchantId): array
    {
        return ['ok' => true, 'last4' => '4821', 'ifsc' => 'MOCK0000001', 'bank_name' => 'Mock Bank', 'verified' => false];
    }
}
