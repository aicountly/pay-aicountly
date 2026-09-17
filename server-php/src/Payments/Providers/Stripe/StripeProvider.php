<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Providers\Stripe;

use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Providers\AbstractProvider;

/**
 * Stripe, in DIRECT mode. In this fleet its job is the one the Indian providers
 * cannot do: international cards.
 *
 * That is why `supportedMethods()` here is short. Stripe can technically do UPI
 * in India, but a merchant who has connected both Razorpay and Stripe almost
 * always wants domestic traffic on the Indian provider — lower fees, faster
 * settlement — and Stripe for the customer paying from abroad. Advertising
 * every method Stripe has would have the router sending domestic UPI to it on a
 * tie-break, which is the wrong answer expensively.
 *
 * Stripe's PaymentIntent maps onto our attempt almost exactly: it is created
 * server-side with the amount, confirmed by the browser, and reaches a terminal
 * state we can read back. Its `client_secret` is what the browser needs, and
 * despite the name it is NOT a credential — it authorises exactly one payment
 * and is safe to send to the payer. The API key is the secret, and it never
 * leaves this class.
 *
 * Stripe takes form-encoded bodies, not JSON. That is the reason
 * `encodeBody()` and `contentType()` are overridden here and nowhere else.
 */
final class StripeProvider extends AbstractProvider
{
    private const API_VERSION = '2024-06-20';

    public function code(): string
    {
        return 'STRIPE';
    }

    public function supportedMethods(): array
    {
        return ['CARD', 'WALLET'];
    }

    public function supportedCurrencies(): array
    {
        return ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD', 'JPY'];
    }

    public function capabilities(): array
    {
        return [
            'hosted_link'     => true,
            'dynamic_qr'      => false,
            'static_qr'       => false,
            'partial_capture' => true,
            'partial_refund'  => true,
            'mandates'        => true,
            'settlement_api'  => true,
            'dispute_api'     => true,
            // The reason this provider is in the fleet at all.
            'international'   => true,
        ];
    }

    protected function baseUrl(): string
    {
        // One host for both; the key decides. Stripe's test keys carry `_test_`,
        // which ProviderRegistry checks against the connection's environment.
        return 'https://api.stripe.com/v1';
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization'  => 'Bearer ' . $this->credential('key_secret'),
            'Stripe-Version' => self::API_VERSION,
        ];
    }

    protected function contentType(): string
    {
        return 'application/x-www-form-urlencoded';
    }

    /** @param array<string, mixed> $body */
    protected function encodeBody(array $body): string
    {
        // http_build_query produces exactly Stripe's bracket notation for
        // nested arrays (`metadata[pay_request]=…`), which is what its API
        // expects.
        return http_build_query($body, '', '&', PHP_QUERY_RFC3986);
    }

    public function createPayment(array $payload): array
    {
        $result = $this->call('POST', 'payment_intents', array_filter([
            'amount'   => $payload['amount_minor'],
            'currency' => strtolower($payload['currency']),
            'description' => substr((string) ($payload['description'] ?? ''), 0, 350),
            // Stripe requires this for Indian merchants: an export transaction
            // needs a stated customer name and address on the intent.
            'metadata' => $this->metadata($payload),
            'automatic_payment_methods' => ['enabled' => 'true'],
            'receipt_email' => $payload['payer']['email'] ?? null,
        ], static fn ($v) => $v !== null && $v !== ''), [
            // Stripe's own idempotency, which replays the first answer rather
            // than creating a second intent. Ours is passed straight through.
            'Idempotency-Key' => $payload['idempotency_key'],
        ], 'createPayment', $payload['method'] ?? null);

        if (!$result['ok']) {
            return $this->fromError($result, 'Stripe could not start this payment.');
        }

        $intent = $result['body'];

        return [
            'ok'                  => true,
            'provider_payment_id' => (string) ($intent['id'] ?? ''),
            'status'              => $this->mapStatus((string) ($intent['status'] ?? '')),
            'raw'                 => [
                // Safe to send to the browser: it authorises this one payment
                // and nothing else. Not to be confused with the API key.
                'client_secret'    => $intent['client_secret'] ?? null,
                'publishable_key'  => $this->credential('key_id'),
                'payment_intent_id' => $intent['id'] ?? null,
            ],
        ];
    }

    public function fetchPayment(string $providerPaymentId): array
    {
        $result = $this->call('GET', 'payment_intents/' . rawurlencode($providerPaymentId), null, [], 'fetchPayment');

        if (!$result['ok']) {
            return $this->fromError($result, 'Stripe could not be asked about this payment.');
        }

        $intent = $result['body'];
        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];
        $card = is_array($charge['payment_method_details']['card'] ?? null) ? $charge['payment_method_details']['card'] : [];

        return [
            'ok'            => true,
            'status'        => $this->mapStatus((string) ($intent['status'] ?? '')),
            'amount_minor'  => isset($intent['amount_received']) && (int) $intent['amount_received'] > 0
                ? (int) $intent['amount_received']
                : (isset($intent['amount']) ? (int) $intent['amount'] : null),
            'currency'      => strtoupper((string) ($intent['currency'] ?? 'inr')),
            'method'        => $this->normaliseMethod(is_array($charge['payment_method_details'] ?? null)
                ? (string) array_key_first($charge['payment_method_details']) : null),
            'network'       => isset($card['brand']) ? strtoupper((string) $card['brand']) : null,
            'last4'         => $this->last4($card['last4'] ?? null),
            'paid_at'       => $this->toIso($intent['created'] ?? null),
            'error_code'    => isset($intent['last_payment_error']['code']) ? (string) $intent['last_payment_error']['code'] : null,
            'error_message' => isset($intent['last_payment_error']['message']) ? (string) $intent['last_payment_error']['message'] : null,
            'raw'           => ['id' => $intent['id'] ?? null, 'status' => $intent['status'] ?? null],
        ];
    }

    public function createPaymentLink(array $payload): array
    {
        // Stripe's Payment Links need a Price, which needs a Product. Creating
        // one product per invoice would litter the merchant's catalogue with
        // thousands of one-off items, so Pay serves its own checkout for Stripe
        // and confirms the intent in the browser instead.
        return $this->failure(
            'unsupported',
            'Stripe payments are collected through the Aicountly checkout rather than a Stripe-hosted link.',
        );
    }

    public function createQrCode(array $payload): array
    {
        return $this->failure('unsupported', 'Stripe does not issue UPI QR codes for Indian merchants.');
    }

    public function refundPayment(array $payload): array
    {
        $result = $this->call('POST', 'refunds', array_filter([
            'payment_intent' => $payload['provider_payment_id'],
            'amount'         => $payload['amount_minor'],
            'reason'         => $this->mapRefundReason($payload['reason']),
            'metadata'       => ['pay_reason' => substr($payload['reason'], 0, 400)],
        ], static fn ($v) => $v !== null && $v !== ''), [
            'Idempotency-Key' => $payload['idempotency_key'],
        ], 'refundPayment');

        if (!$result['ok']) {
            return $this->fromError($result, 'Stripe refused this refund.');
        }

        return [
            'ok'                 => true,
            'provider_refund_id' => (string) ($result['body']['id'] ?? ''),
            'status'             => $this->mapRefundStatus((string) ($result['body']['status'] ?? '')),
        ];
    }

    public function fetchRefund(string $providerRefundId): array
    {
        $result = $this->call('GET', 'refunds/' . rawurlencode($providerRefundId), null, [], 'fetchRefund');

        if (!$result['ok']) {
            return $this->fromError($result, 'Stripe could not be asked about this refund.');
        }

        return [
            'ok'           => true,
            'status'       => $this->mapRefundStatus((string) ($result['body']['status'] ?? '')),
            'amount_minor' => (int) ($result['body']['amount'] ?? 0),
            'processed_at' => $this->toIso($result['body']['created'] ?? null),
        ];
    }

    public function fetchSettlement(array $filters): array
    {
        $query = ['limit' => min(100, (int) ($filters['limit'] ?? 100))];
        if (isset($filters['from'])) {
            $query['created[gte]'] = strtotime((string) $filters['from']);
        }
        if (isset($filters['to'])) {
            $query['created[lte]'] = strtotime((string) $filters['to']);
        }

        $result = $this->call('GET', 'payouts?' . http_build_query($query), null, [], 'fetchSettlement');

        if (!$result['ok']) {
            return $this->fromError($result, 'Stripe could not be asked about payouts.');
        }

        $settlements = [];
        foreach ((array) ($result['body']['data'] ?? []) as $payout) {
            if (!is_array($payout)) {
                continue;
            }
            $settlements[] = [
                'provider_settlement_id' => (string) ($payout['id'] ?? ''),
                'settlement_date'        => $this->toIso($payout['arrival_date'] ?? null),
                'status'                 => $this->mapPayoutStatus((string) ($payout['status'] ?? '')),
                'currency'               => strtoupper((string) ($payout['currency'] ?? 'inr')),
                'amount_minor'           => (int) ($payout['amount'] ?? 0),
                // Stripe nets its fee out of the balance transaction rather than
                // stating it on the payout, so this is left at zero rather than
                // guessed. The reconciler reports it as "fee not stated by
                // provider" instead of inventing one.
                'fee_minor'              => 0,
                'tax_minor'              => 0,
                'utr'                    => (string) ($payout['id'] ?? ''),
            ];
        }

        return ['ok' => true, 'settlements' => $settlements];
    }

    public function verifyWebhook(array $headers, string $rawPayload): bool
    {
        $secret = $this->credential('webhook_secret');
        if ($secret === '') {
            return false;
        }

        $header = $headers['stripe-signature'] ?? '';
        if (!is_string($header) || $header === '') {
            return false;
        }

        // Stripe's header is `t=<timestamp>,v1=<sig>,v1=<sig>` — more than one
        // v1 during a secret rotation, and any of them matching is valid.
        $timestamp = '';
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === '' || $signatures === []) {
            return false;
        }

        // Five minutes' tolerance, as Stripe's own libraries use. Without it a
        // captured callback can be replayed indefinitely.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret);
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function normalizeWebhook(array $payload): array
    {
        $type = (string) ($payload['type'] ?? '');
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
        $card = is_array($object['payment_method_details']['card'] ?? null) ? $object['payment_method_details']['card'] : [];

        $isRefund = str_starts_with($type, 'refund.') || str_starts_with($type, 'charge.refund');
        $isDispute = str_starts_with($type, 'charge.dispute');
        $isPayout = str_starts_with($type, 'payout.');

        return [
            'event'               => $this->mapEvent($type),
            'provider_event_id'   => isset($payload['id']) ? (string) $payload['id'] : null,
            'provider_event_type' => $type !== '' ? $type : null,
            'provider_payment_id' => $this->paymentIdFrom($object, $isRefund || $isDispute),
            'provider_refund_id'  => $isRefund && isset($object['id']) ? (string) $object['id'] : null,
            'provider_settlement_id' => $isPayout && isset($object['id']) ? (string) $object['id'] : null,
            'provider_dispute_id' => $isDispute && isset($object['id']) ? (string) $object['id'] : null,
            'provider_order_id'   => null,
            'status'              => isset($object['status']) ? $this->mapStatus((string) $object['status']) : null,
            'amount_minor'        => isset($object['amount_received']) && (int) $object['amount_received'] > 0
                ? (int) $object['amount_received']
                : (isset($object['amount']) ? (int) $object['amount'] : null),
            'currency'            => strtoupper((string) ($object['currency'] ?? 'inr')),
            'method'              => $this->normaliseMethod(is_array($object['payment_method_details'] ?? null)
                ? (string) array_key_first($object['payment_method_details']) : null),
            'network'             => isset($card['brand']) ? strtoupper((string) $card['brand']) : null,
            'last4'               => $this->last4($card['last4'] ?? null),
            'vpa'                 => null,
            'error_code'          => isset($object['last_payment_error']['code']) ? (string) $object['last_payment_error']['code'] : null,
            'error_message'       => isset($object['last_payment_error']['message']) ? (string) $object['last_payment_error']['message'] : null,
            'occurred_at'         => $this->toIso($payload['created'] ?? null),
        ];
    }

    public function healthCheck(): array
    {
        $result = $this->call('GET', 'balance', null, [], 'healthCheck');

        if ($result['ok']) {
            return ['ok' => true, 'latency_ms' => $result['latency_ms'], 'detail' => 'Stripe answered.'];
        }

        return [
            'ok'         => false,
            'latency_ms' => $result['latency_ms'],
            'detail'     => $result['status'] === 401 ? 'Stripe rejected this API key.' : 'Stripe did not answer.',
            'error_code' => $result['status'] === 401 ? 'credentials_invalid' : 'unreachable',
        ];
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $object */
    private function paymentIdFrom(array $object, bool $indirect): ?string
    {
        if ($indirect) {
            foreach (['payment_intent', 'charge'] as $key) {
                if (isset($object[$key]) && is_string($object[$key])) {
                    return $object[$key];
                }
            }

            return null;
        }

        return isset($object['id']) ? (string) $object['id'] : null;
    }

    /** @param array<string, mixed> $payload */
    private function metadata(array $payload): array
    {
        return array_filter([
            'pay_request' => (string) ($payload['request_uuid'] ?? ''),
            'pay_attempt' => (string) ($payload['attempt_uuid'] ?? ''),
            'reference'   => substr((string) ($payload['reference'] ?? ''), 0, 60),
        ], static fn (string $v) => $v !== '');
    }

    private function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'succeeded'                => 'SUCCESS',
            'requires_capture'         => 'AUTHORIZED',
            'canceled'                 => 'CANCELLED',
            'requires_payment_method'  => 'FAILED',
            'requires_confirmation', 'requires_action', 'processing' => 'PENDING',
            default                    => 'PENDING',
        };
    }

    private function mapRefundStatus(string $status): string
    {
        return match (strtolower($status)) {
            'succeeded' => 'REFUNDED',
            'failed', 'canceled' => 'FAILED',
            default     => 'PROCESSING',
        };
    }

    private function mapPayoutStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid'     => 'SETTLED',
            'failed', 'canceled' => 'FAILED',
            default    => 'PROCESSING',
        };
    }

    /** Stripe accepts only three reasons; everything else goes in the metadata. */
    private function mapRefundReason(string $reason): ?string
    {
        $lower = strtolower($reason);

        return match (true) {
            str_contains($lower, 'fraud')     => 'fraudulent',
            str_contains($lower, 'duplicate') => 'duplicate',
            default                           => 'requested_by_customer',
        };
    }

    private function mapEvent(string $type): string
    {
        return match ($type) {
            'payment_intent.succeeded'          => EventNames::PAYMENT_SUCCESS,
            'payment_intent.payment_failed'     => EventNames::PAYMENT_FAILED,
            'payment_intent.canceled'           => EventNames::PAYMENT_FAILED,
            'payment_intent.processing',
            'payment_intent.requires_action'    => EventNames::PAYMENT_PENDING,
            'charge.refunded', 'refund.updated' => EventNames::REFUND_SUCCESS,
            'refund.failed', 'charge.refund.updated' => EventNames::REFUND_FAILED,
            'payout.paid'                       => EventNames::SETTLEMENT_RECEIVED,
            'payout.failed'                     => EventNames::SETTLEMENT_MISMATCH,
            'charge.dispute.created'            => EventNames::DISPUTE_CREATED,
            'charge.dispute.updated', 'charge.dispute.closed' => EventNames::DISPUTE_UPDATED,
            default                             => EventNames::UNKNOWN,
        };
    }

    /** @param array{ok:bool, status:int, body:array<string,mixed>, error:?string} $result */
    private function fromError(array $result, string $fallback): array
    {
        $error = is_array($result['body']['error'] ?? null) ? $result['body']['error'] : [];
        $code = (string) ($error['code'] ?? $error['type'] ?? '');
        $message = (string) ($error['message'] ?? '');

        if ($result['status'] === 401) {
            return $this->failure('credentials_invalid', 'Stripe rejected the stored API key for this account.', false);
        }

        // A declined card is the customer's bank saying no. Failing over to
        // another provider would put a second decline on their statement and
        // would not change the answer.
        $declined = $code === 'card_declined' || ($error['type'] ?? '') === 'card_error';

        return $this->failure(
            $code !== '' ? $code : 'provider_error',
            $message !== '' ? $message : $fallback,
            !$declined && $this->isRetryableStatus($result['status']),
        );
    }
}
