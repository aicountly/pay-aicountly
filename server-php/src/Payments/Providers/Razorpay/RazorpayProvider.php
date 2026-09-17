<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Providers\Razorpay;

use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Providers\AbstractProvider;

/**
 * Razorpay, in DIRECT mode: the merchant's own Razorpay account.
 *
 * Razorpay's model, and how it maps onto ours:
 *
 *   Razorpay order    →  what we create when a payment starts. It is the thing
 *                        the amount is fixed against, and Razorpay refuses a
 *                        capture for more than the order says. That is why the
 *                        order is created server-side from OUR amount and the
 *                        browser is never asked what the payment is worth.
 *   Razorpay payment  →  our attempt. One order can carry several, which is
 *                        exactly why attempts are their own table.
 *   Razorpay refund   →  our refund.
 *   Razorpay settlement → our settlement batch.
 *
 * Amounts are in paise on both sides, so there is no conversion here at all —
 * which is the reason Domain/Money works in minor units.
 *
 * Razorpay has ONE base URL for test and live; the environment is decided by
 * which key you present. So a test key in a live connection silently takes test
 * payments, which is why ProviderRegistry refuses to build a LIVE connection
 * from a key whose prefix says test.
 */
final class RazorpayProvider extends AbstractProvider
{
    public function code(): string
    {
        return 'RAZORPAY';
    }

    public function supportedMethods(): array
    {
        return ['UPI', 'CARD', 'NETBANKING', 'WALLET', 'EMI', 'PAYLATER'];
    }

    public function supportedCurrencies(): array
    {
        // Razorpay settles Indian merchants in INR. International acceptance is
        // an account-level feature, so the honest default is INR only and the
        // merchant's connection row widens it if their account actually has it.
        return ['INR'];
    }

    public function capabilities(): array
    {
        return [
            'hosted_link'     => true,
            'dynamic_qr'      => true,
            // Razorpay's QR codes can be made reusable, but only for UPI and
            // only on accounts with QR enabled. Claimed here, checked at
            // creation, and downgraded honestly if the API says otherwise.
            'static_qr'       => true,
            'partial_capture' => true,
            'partial_refund'  => true,
            'mandates'        => true,
            'settlement_api'  => true,
            'dispute_api'     => true,
            'international'   => false,
        ];
    }

    protected function baseUrl(): string
    {
        return 'https://api.razorpay.com/v1';
    }

    protected function authHeaders(): array
    {
        // HTTP Basic: key id as the user, key secret as the password.
        $pair = $this->credential('key_id') . ':' . $this->credential('key_secret');

        return ['Authorization' => 'Basic ' . base64_encode($pair)];
    }

    public function createPayment(array $payload): array
    {
        $result = $this->call('POST', 'orders', [
            'amount'   => $payload['amount_minor'],
            'currency' => $payload['currency'],
            // Razorpay's receipt is capped at 40 characters and must be unique
            // per order. Our reference can be longer, so it is truncated here
            // rather than letting Razorpay reject the whole order.
            'receipt'  => substr((string) ($payload['reference'] ?? ''), 0, 40),
            'notes'    => $this->notes($payload),
        ], ['X-Razorpay-Account' => (string) ($this->connection['merchant_ref'] ?? '')], 'createPayment', $payload['method'] ?? null);

        if (!$result['ok']) {
            return $this->fromError($result, 'Razorpay could not start this payment.');
        }

        $order = $result['body'];

        return [
            'ok'                => true,
            'provider_order_id' => (string) ($order['id'] ?? ''),
            'status'            => 'CREATED',
            // Razorpay is collected through its own checkout widget, which the
            // browser opens with the order id and the PUBLIC key id. There is
            // no server-side redirect URL, and the key id in the response is
            // the publishable half — never the secret.
            'raw'               => [
                'order_id'   => $order['id'] ?? null,
                'key_id'     => $this->credential('key_id'),
                'amount'     => $order['amount'] ?? null,
                'currency'   => $order['currency'] ?? null,
            ],
        ];
    }

    public function fetchPayment(string $providerPaymentId): array
    {
        $result = $this->call('GET', 'payments/' . rawurlencode($providerPaymentId), null, [], 'fetchPayment');

        if (!$result['ok']) {
            return $this->fromError($result, 'Razorpay could not be asked about this payment.');
        }

        $p = $result['body'];

        return [
            'ok'           => true,
            'status'       => $this->mapStatus((string) ($p['status'] ?? '')),
            'amount_minor' => isset($p['amount']) ? (int) $p['amount'] : null,
            'currency'     => (string) ($p['currency'] ?? 'INR'),
            'method'       => $this->normaliseMethod($p['method'] ?? null),
            'network'      => $this->networkOf($p),
            'last4'        => $this->last4($p['card']['last4'] ?? null),
            'vpa'          => $this->maskVpa($p['vpa'] ?? null),
            'paid_at'      => $this->toIso($p['created_at'] ?? null),
            'error_code'   => isset($p['error_code']) ? (string) $p['error_code'] : null,
            'error_message' => isset($p['error_description']) ? (string) $p['error_description'] : null,
            'raw'          => ['id' => $p['id'] ?? null, 'status' => $p['status'] ?? null],
        ];
    }

    public function createPaymentLink(array $payload): array
    {
        $result = $this->call('POST', 'payment_links', [
            'amount'      => $payload['amount_minor'],
            'currency'    => $payload['currency'],
            'description' => substr((string) ($payload['description'] ?? ''), 0, 2048),
            'customer'    => array_filter([
                'name'    => $payload['payer']['name'] ?? null,
                'email'   => $payload['payer']['email'] ?? null,
                'contact' => $payload['payer']['mobile'] ?? null,
            ]),
            // Razorpay notifies on our behalf only when the merchant asked for
            // it; Pay's own reminder flow is separate and must not double up.
            'notify'      => ['sms' => false, 'email' => false],
            'reminder_enable' => false,
            'expire_by'   => isset($payload['expires_at']) ? strtotime((string) $payload['expires_at']) : null,
            'reference_id' => substr((string) ($payload['reference'] ?? ''), 0, 40),
            'notes'       => $this->notes($payload),
            'callback_url' => $payload['return_url'] ?? null,
            'callback_method' => isset($payload['return_url']) ? 'get' : null,
        ], [], 'createPaymentLink');

        if (!$result['ok']) {
            return $this->fromError($result, 'Razorpay could not create this payment link.');
        }

        return [
            'ok'               => true,
            'provider_link_id' => (string) ($result['body']['id'] ?? ''),
            'url'              => (string) ($result['body']['short_url'] ?? ''),
            'expires_at'       => $this->toIso($result['body']['expire_by'] ?? null),
        ];
    }

    public function createQrCode(array $payload): array
    {
        $reusable = (bool) ($payload['reusable'] ?? false);

        $result = $this->call('POST', 'payments/qr_codes', array_filter([
            'type'        => 'upi_qr',
            'name'        => substr((string) ($payload['description'] ?? 'Payment'), 0, 60),
            'usage'       => $reusable ? 'multiple_use' : 'single_use',
            // A single-use QR carries the amount. A reusable one cannot: it is
            // the same code for every payer, so the amount is entered in their
            // UPI app.
            'fixed_amount' => !$reusable,
            'payment_amount' => $reusable ? null : $payload['amount_minor'],
            'close_by'    => isset($payload['expires_at']) ? strtotime((string) $payload['expires_at']) : null,
            'notes'       => $this->notes($payload),
        ], static fn ($v) => $v !== null), [], 'createQrCode', 'UPI');

        if (!$result['ok']) {
            return $this->fromError($result, 'Razorpay could not create this QR code.');
        }

        $qr = $result['body'];

        return [
            'ok'              => true,
            'provider_qr_id'  => (string) ($qr['id'] ?? ''),
            'payload'         => (string) ($qr['image_url'] ?? ''),
            'image_url'       => (string) ($qr['image_url'] ?? ''),
            // What Razorpay actually gave us, not what we asked for. Asking for
            // a reusable QR and getting a single-use one back is a real
            // response on accounts without the feature, and recording our
            // request instead of their answer would print a code that stops
            // working after one payment.
            'reusable'        => (string) ($qr['usage'] ?? '') === 'multiple_use',
            'expires_at'      => $this->toIso($qr['close_by'] ?? null),
        ];
    }

    public function refundPayment(array $payload): array
    {
        $result = $this->call(
            'POST',
            'payments/' . rawurlencode($payload['provider_payment_id']) . '/refund',
            [
                'amount' => $payload['amount_minor'],
                'speed'  => 'normal',
                'notes'  => ['reason' => substr($payload['reason'], 0, 250)],
            ],
            // Razorpay honours an idempotency header on refunds. It is the
            // difference between a retried timeout and a customer refunded
            // twice, so it is always sent.
            ['X-Razorpay-Idempotency-Key' => $payload['idempotency_key']],
            'refundPayment',
        );

        if (!$result['ok']) {
            return $this->fromError($result, 'Razorpay refused this refund.');
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
            return $this->fromError($result, 'Razorpay could not be asked about this refund.');
        }

        return [
            'ok'           => true,
            'status'       => $this->mapRefundStatus((string) ($result['body']['status'] ?? '')),
            'amount_minor' => (int) ($result['body']['amount'] ?? 0),
            'processed_at' => $this->toIso($result['body']['created_at'] ?? null),
        ];
    }

    public function fetchSettlement(array $filters): array
    {
        $query = array_filter([
            'from'  => isset($filters['from']) ? strtotime((string) $filters['from']) : null,
            'to'    => isset($filters['to']) ? strtotime((string) $filters['to']) : null,
            'count' => min(100, (int) ($filters['limit'] ?? 100)),
        ], static fn ($v) => $v !== null && $v !== false);

        $result = $this->call('GET', 'settlements?' . http_build_query($query), null, [], 'fetchSettlement');

        if (!$result['ok']) {
            return $this->fromError($result, 'Razorpay could not be asked about settlements.');
        }

        $settlements = [];
        foreach ((array) ($result['body']['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $settlements[] = [
                'provider_settlement_id' => (string) ($item['id'] ?? ''),
                'settlement_date'        => $this->toIso($item['created_at'] ?? null),
                'status'                 => $this->mapSettlementStatus((string) ($item['status'] ?? '')),
                'currency'               => 'INR',
                // Razorpay reports its own fee and tax on the batch. They are
                // taken verbatim: see the note in migration 003 about never
                // computing a tax percentage here.
                'amount_minor'           => (int) ($item['amount'] ?? 0),
                'fee_minor'              => (int) ($item['fees'] ?? 0),
                'tax_minor'              => (int) ($item['tax'] ?? 0),
                'utr'                    => (string) ($item['utr'] ?? ''),
            ];
        }

        return ['ok' => true, 'settlements' => $settlements];
    }

    public function verifyWebhook(array $headers, string $rawPayload): bool
    {
        $secret = $this->credential('webhook_secret');
        if ($secret === '') {
            // No secret configured means we cannot tell a real callback from a
            // forged one. Refusing everything is the only safe answer; the
            // ingest records the reason so the merchant is told to finish
            // setting the provider up.
            return false;
        }

        $signature = $headers['x-razorpay-signature'] ?? $headers['X-Razorpay-Signature'] ?? '';
        if (!is_string($signature) || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, $secret);

        return hash_equals($expected, $signature);
    }

    public function normalizeWebhook(array $payload): array
    {
        $type = (string) ($payload['event'] ?? '');
        $entity = $payload['payload'] ?? [];

        $payment    = $entity['payment']['entity'] ?? [];
        $refund     = $entity['refund']['entity'] ?? [];
        $settlement = $entity['settlement']['entity'] ?? [];
        $dispute    = $entity['dispute']['entity'] ?? [];

        return [
            'event'               => $this->mapEvent($type),
            'provider_event_id'   => isset($payload['id']) ? (string) $payload['id'] : null,
            'provider_event_type' => $type !== '' ? $type : null,
            'provider_payment_id' => isset($payment['id']) ? (string) $payment['id'] : null,
            'provider_refund_id'  => isset($refund['id']) ? (string) $refund['id'] : null,
            'provider_settlement_id' => isset($settlement['id']) ? (string) $settlement['id'] : null,
            'provider_dispute_id' => isset($dispute['id']) ? (string) $dispute['id'] : null,
            'provider_order_id'   => isset($payment['order_id']) ? (string) $payment['order_id'] : null,
            'status'              => isset($payment['status']) ? $this->mapStatus((string) $payment['status']) : null,
            'amount_minor'        => isset($payment['amount']) ? (int) $payment['amount']
                : (isset($refund['amount']) ? (int) $refund['amount'] : null),
            'currency'            => (string) ($payment['currency'] ?? $refund['currency'] ?? 'INR'),
            'method'              => $this->normaliseMethod($payment['method'] ?? null),
            'network'             => $this->networkOf($payment),
            'last4'               => $this->last4($payment['card']['last4'] ?? null),
            'vpa'                 => $this->maskVpa($payment['vpa'] ?? null),
            'error_code'          => isset($payment['error_code']) ? (string) $payment['error_code'] : null,
            'error_message'       => isset($payment['error_description']) ? (string) $payment['error_description'] : null,
            'occurred_at'         => $this->toIso($payload['created_at'] ?? ($payment['created_at'] ?? null)),
        ];
    }

    public function healthCheck(): array
    {
        // A list of one payment: cheap, read-only, and it proves both that the
        // host answers and that the credentials are accepted. A call that
        // creates anything would leave litter in the merchant's account every
        // time the health sweep runs.
        $result = $this->call('GET', 'payments?count=1', null, [], 'healthCheck');

        if ($result['ok']) {
            return ['ok' => true, 'latency_ms' => $result['latency_ms'], 'detail' => 'Razorpay answered.'];
        }

        return [
            'ok'         => false,
            'latency_ms' => $result['latency_ms'],
            'detail'     => $result['status'] === 401
                ? 'Razorpay rejected these credentials.'
                : 'Razorpay did not answer.',
            'error_code' => $result['status'] === 401 ? 'credentials_invalid' : 'unreachable',
        ];
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $payload */
    private function notes(array $payload): array
    {
        // Notes come back on the webhook, which is how a callback that lost its
        // order id can still be attributed. Kept short: Razorpay caps them at
        // 15 keys and 256 characters each.
        return array_filter([
            'pay_request' => (string) ($payload['request_uuid'] ?? ''),
            'pay_attempt' => (string) ($payload['attempt_uuid'] ?? ''),
            'source_app'  => (string) ($payload['source_app'] ?? ''),
        ], static fn (string $v) => $v !== '');
    }

    /** @param array<string, mixed> $payment */
    private function networkOf(array $payment): ?string
    {
        foreach ([$payment['card']['network'] ?? null, $payment['bank'] ?? null, $payment['wallet'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return strtoupper($candidate);
            }
        }

        return null;
    }

    private function mapStatus(string $razorpayStatus): string
    {
        return match (strtolower($razorpayStatus)) {
            'created'    => 'CREATED',
            'authorized' => 'AUTHORIZED',
            'captured'   => 'SUCCESS',
            'refunded'   => 'SUCCESS',
            'failed'     => 'FAILED',
            default      => 'PENDING',
        };
    }

    private function mapRefundStatus(string $status): string
    {
        return match (strtolower($status)) {
            'processed' => 'REFUNDED',
            'failed'    => 'FAILED',
            default     => 'PROCESSING',
        };
    }

    private function mapSettlementStatus(string $status): string
    {
        return match (strtolower($status)) {
            'processed' => 'SETTLED',
            'failed'    => 'FAILED',
            default     => 'PROCESSING',
        };
    }

    private function mapEvent(string $razorpayEvent): string
    {
        return match ($razorpayEvent) {
            'payment.authorized'   => EventNames::PAYMENT_PENDING,
            'payment.captured'     => EventNames::PAYMENT_SUCCESS,
            'payment.failed'       => EventNames::PAYMENT_FAILED,
            'order.paid'           => EventNames::PAYMENT_SUCCESS,
            'refund.created'       => EventNames::REFUND_PROCESSING,
            'refund.processed'     => EventNames::REFUND_SUCCESS,
            'refund.failed'        => EventNames::REFUND_FAILED,
            'settlement.processed' => EventNames::SETTLEMENT_RECEIVED,
            'payment.dispute.created' => EventNames::DISPUTE_CREATED,
            'payment.dispute.won', 'payment.dispute.lost', 'payment.dispute.closed' => EventNames::DISPUTE_UPDATED,
            'subscription.charged' => EventNames::PAYMENT_SUCCESS,
            default                => EventNames::UNKNOWN,
        };
    }

    /** @param array{ok:bool, status:int, body:array<string,mixed>, error:?string} $result */
    private function fromError(array $result, string $fallback): array
    {
        $error = $result['body']['error'] ?? [];
        $code = is_array($error) ? (string) ($error['code'] ?? '') : '';
        $description = is_array($error) ? (string) ($error['description'] ?? '') : '';

        if ($result['status'] === 401 || $result['status'] === 403) {
            return $this->failure('credentials_invalid', 'Razorpay rejected the stored credentials for this account.', false);
        }

        return $this->failure(
            $code !== '' ? strtolower($code) : 'provider_error',
            $description !== '' ? $description : $fallback,
            $this->isRetryableStatus($result['status']),
        );
    }
}
