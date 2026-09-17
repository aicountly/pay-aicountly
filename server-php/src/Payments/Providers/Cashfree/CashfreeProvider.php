<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Providers\Cashfree;

use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Providers\AbstractProvider;
use Aicountly\Api\Payments\Providers\Contracts\ManagedPaymentProviderInterface;

/**
 * Cashfree — usable in DIRECT mode, and the first candidate for MANAGED.
 *
 * It implements ManagedPaymentProviderInterface because Cashfree's Easy Split /
 * partner APIs are the shape Aicountly Managed needs: a platform creates
 * sub-merchants, submits their KYC, and the partner decides. The onboarding
 * half of this class is therefore written against that contract and is INERT
 * UNTIL PARTNER CREDENTIALS EXIST — see the guard in every managed method.
 *
 * WHY IT IS WRITTEN NOW AND NOT LATER. The managed flow shapes the connection
 * table, the KYC state machine, the onboarding screens and the way the router
 * distinguishes a managed connection from a direct one. Discovering its shape
 * after all of those were built for direct-only is how a product ends up with a
 * second parallel payment path. Writing the adapter first proves the contract
 * fits a real provider's API rather than an imagined one.
 *
 * WHAT IS NOT CLAIMED. Nothing here states that Aicountly has a partner
 * agreement in place or that Managed mode is live. Without PAY_MANAGED_CASHFREE
 * credentials the managed methods return `partner_not_configured` and the UI
 * says "Partner credentials not configured" — which is the truth.
 */
final class CashfreeProvider extends AbstractProvider implements ManagedPaymentProviderInterface
{
    /** Cashfree pins its API by date rather than by a version in the path. */
    private const API_VERSION = '2023-08-01';

    public function code(): string
    {
        return 'CASHFREE';
    }

    public function supportedMethods(): array
    {
        return ['UPI', 'CARD', 'NETBANKING', 'WALLET', 'EMI', 'PAYLATER'];
    }

    public function supportedCurrencies(): array
    {
        return ['INR'];
    }

    public function capabilities(): array
    {
        return [
            'hosted_link'     => true,
            'dynamic_qr'      => true,
            // Cashfree's UPI QR is issued per order, so a genuinely reusable
            // static QR is not something this adapter can promise. Saying false
            // here is what stops the UI offering a switch that would not work.
            'static_qr'       => false,
            'partial_capture' => false,
            'partial_refund'  => true,
            'mandates'        => true,
            'settlement_api'  => true,
            'dispute_api'     => true,
            'international'   => false,
            // The one capability that separates this class from the others.
            'managed_onboarding' => true,
        ];
    }

    protected function baseUrl(): string
    {
        return $this->isTestMode()
            ? 'https://sandbox.cashfree.com/pg'
            : 'https://api.cashfree.com/pg';
    }

    protected function authHeaders(): array
    {
        return [
            'x-api-version' => self::API_VERSION,
            'x-client-id'     => $this->credential('key_id'),
            'x-client-secret' => $this->credential('key_secret'),
        ];
    }

    public function createPayment(array $payload): array
    {
        // Cashfree needs a customer id it can key a returning payer on. Our
        // request uuid is stable, opaque and already unique — and unlike an
        // email address it is not personal data being handed to a third party
        // for a purpose the payer did not agree to.
        $customerId = substr((string) ($payload['payer_uuid'] ?? $payload['request_uuid'] ?? 'guest'), 0, 50);

        $result = $this->call('POST', 'orders', array_filter([
            'order_id'       => substr((string) ($payload['attempt_uuid'] ?? $payload['reference'] ?? ''), 0, 45),
            'order_amount'   => round($payload['amount_minor'] / 100, 2),
            'order_currency' => $payload['currency'],
            'order_note'     => substr((string) ($payload['description'] ?? ''), 0, 200),
            'customer_details' => array_filter([
                'customer_id'    => $customerId,
                'customer_name'  => $payload['payer']['name'] ?? null,
                'customer_email' => $payload['payer']['email'] ?? null,
                'customer_phone' => $payload['payer']['mobile'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''),
            'order_meta' => array_filter([
                'return_url' => $payload['return_url'] ?? null,
                // Notify goes to OUR webhook endpoint, which is configured on
                // the connection rather than sent per order, so it cannot be
                // pointed elsewhere by a malformed request.
            ], static fn ($v) => $v !== null),
            'order_tags' => $this->tags($payload),
        ], static fn ($v) => $v !== null && $v !== [] && $v !== ''), [
            'Idempotency-Key' => $payload['idempotency_key'],
        ], 'createPayment', $payload['method'] ?? null);

        if (!$result['ok']) {
            return $this->fromError($result, 'Cashfree could not start this payment.');
        }

        $order = $result['body'];

        return [
            'ok'                => true,
            'provider_order_id' => (string) ($order['order_id'] ?? ''),
            'provider_payment_id' => null,
            'status'            => 'CREATED',
            'raw'               => [
                // The session token the browser SDK needs. It is scoped to this
                // one order and expires; it is not a credential.
                'payment_session_id' => $order['payment_session_id'] ?? null,
                'order_id'           => $order['order_id'] ?? null,
            ],
        ];
    }

    public function fetchPayment(string $providerPaymentId): array
    {
        // Cashfree is order-first: payments are read through their order.
        $result = $this->call('GET', 'orders/' . rawurlencode($providerPaymentId) . '/payments', null, [], 'fetchPayment');

        if (!$result['ok']) {
            return $this->fromError($result, 'Cashfree could not be asked about this payment.');
        }

        $payments = is_array($result['body']) ? array_values(array_filter($result['body'], 'is_array')) : [];
        if ($payments === []) {
            // The order exists and nobody has paid it. Not an error — this is
            // the answer for every link that has been sent and not yet opened.
            return ['ok' => true, 'status' => 'CREATED'];
        }

        // The successful one if there is one; otherwise the most recent.
        $chosen = $payments[0];
        foreach ($payments as $payment) {
            if (strtoupper((string) ($payment['payment_status'] ?? '')) === 'SUCCESS') {
                $chosen = $payment;
                break;
            }
        }

        $method = $this->methodFrom($chosen);

        return [
            'ok'            => true,
            'status'        => $this->mapStatus((string) ($chosen['payment_status'] ?? '')),
            'amount_minor'  => isset($chosen['payment_amount']) ? (int) round((float) $chosen['payment_amount'] * 100) : null,
            'currency'      => (string) ($chosen['payment_currency'] ?? 'INR'),
            'method'        => $method['method'],
            'network'       => $method['network'],
            'last4'         => $method['last4'],
            'vpa'           => $method['vpa'],
            'paid_at'       => $this->toIso($chosen['payment_completion_time'] ?? ($chosen['payment_time'] ?? null)),
            'error_code'    => isset($chosen['error_details']['error_code']) ? (string) $chosen['error_details']['error_code'] : null,
            'error_message' => isset($chosen['error_details']['error_description']) ? (string) $chosen['error_details']['error_description'] : null,
            'raw'           => ['cf_payment_id' => $chosen['cf_payment_id'] ?? null],
        ];
    }

    public function createPaymentLink(array $payload): array
    {
        $result = $this->call('POST', 'links', array_filter([
            'link_id'       => substr((string) ($payload['link_uuid'] ?? $payload['reference'] ?? ''), 0, 45),
            'link_amount'   => round($payload['amount_minor'] / 100, 2),
            'link_currency' => $payload['currency'],
            'link_purpose'  => substr((string) ($payload['description'] ?? 'Payment'), 0, 500),
            'customer_details' => array_filter([
                'customer_name'  => $payload['payer']['name'] ?? null,
                'customer_email' => $payload['payer']['email'] ?? null,
                'customer_phone' => $payload['payer']['mobile'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''),
            'link_partial_payments' => (bool) ($payload['allow_partial'] ?? false),
            'link_minimum_partial_amount' => isset($payload['min_partial_minor'])
                ? round(((int) $payload['min_partial_minor']) / 100, 2) : null,
            'link_expiry_time' => isset($payload['expires_at']) ? gmdate('c', strtotime((string) $payload['expires_at'])) : null,
            // Pay runs its own reminders. Letting the provider send its own too
            // means a customer gets two different messages about one bill.
            'link_notify' => ['send_email' => false, 'send_sms' => false],
            'link_auto_reminders' => false,
            'link_meta' => array_filter(['return_url' => $payload['return_url'] ?? null]),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []), [], 'createPaymentLink');

        if (!$result['ok']) {
            return $this->fromError($result, 'Cashfree could not create this payment link.');
        }

        return [
            'ok'               => true,
            'provider_link_id' => (string) ($result['body']['link_id'] ?? ''),
            'url'              => (string) ($result['body']['link_url'] ?? ''),
            'expires_at'       => $this->toIso($result['body']['link_expiry_time'] ?? null),
        ];
    }

    public function createQrCode(array $payload): array
    {
        // Cashfree serves the UPI QR through its order flow rather than a
        // standalone QR resource, so this adapter does not pretend to a
        // dedicated one: Pay renders the QR from the order's UPI intent.
        $order = $this->createPayment($payload + ['method' => 'UPI']);
        if (!($order['ok'] ?? false)) {
            return $order;
        }

        return [
            'ok'             => true,
            'provider_qr_id' => (string) ($order['provider_order_id'] ?? ''),
            'payload'        => (string) ($order['raw']['payment_session_id'] ?? ''),
            // Never reusable on this provider. Claiming otherwise would print a
            // code on a counter card that stops working after one customer.
            'reusable'       => false,
            'expires_at'     => $payload['expires_at'] ?? null,
        ];
    }

    public function refundPayment(array $payload): array
    {
        $orderId = (string) ($payload['provider_order_id'] ?? $payload['provider_payment_id']);

        $result = $this->call('POST', 'orders/' . rawurlencode($orderId) . '/refunds', [
            // Cashfree's refund_id is the idempotency mechanism: the same id
            // never refunds twice. Ours is derived from the refund uuid, which
            // is stable across every retry of the same refund.
            'refund_id'     => substr((string) ($payload['refund_uuid'] ?? $payload['idempotency_key']), 0, 40),
            'refund_amount' => round($payload['amount_minor'] / 100, 2),
            'refund_note'   => substr($payload['reason'], 0, 100),
            'refund_speed'  => 'STANDARD',
        ], [], 'refundPayment');

        if (!$result['ok']) {
            return $this->fromError($result, 'Cashfree refused this refund.');
        }

        return [
            'ok'                 => true,
            'provider_refund_id' => (string) ($result['body']['refund_id'] ?? ''),
            'status'             => $this->mapRefundStatus((string) ($result['body']['refund_status'] ?? '')),
        ];
    }

    public function fetchRefund(string $providerRefundId): array
    {
        // Cashfree scopes a refund to its order, so the caller passes
        // "<order>:<refund>" and this splits it.
        [$orderId, $refundId] = array_pad(explode(':', $providerRefundId, 2), 2, '');
        if ($refundId === '') {
            return $this->failure('reference_incomplete', 'Cashfree needs the order id alongside the refund id.');
        }

        $result = $this->call('GET', 'orders/' . rawurlencode($orderId) . '/refunds/' . rawurlencode($refundId), null, [], 'fetchRefund');

        if (!$result['ok']) {
            return $this->fromError($result, 'Cashfree could not be asked about this refund.');
        }

        return [
            'ok'           => true,
            'status'       => $this->mapRefundStatus((string) ($result['body']['refund_status'] ?? '')),
            'amount_minor' => (int) round(((float) ($result['body']['refund_amount'] ?? 0)) * 100),
            'processed_at' => $this->toIso($result['body']['processed_at'] ?? null),
        ];
    }

    public function fetchSettlement(array $filters): array
    {
        $result = $this->call('POST', 'settlements', array_filter([
            'filters' => array_filter([
                'start_date' => $filters['from'] ?? null,
                'end_date'   => $filters['to'] ?? null,
            ]),
            'pagination' => ['limit' => min(100, (int) ($filters['limit'] ?? 100))],
        ]), [], 'fetchSettlement');

        if (!$result['ok']) {
            return $this->fromError($result, 'Cashfree could not be asked about settlements.');
        }

        $settlements = [];
        foreach ((array) ($result['body']['data'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $settlements[] = [
                'provider_settlement_id' => (string) ($item['settlement_id'] ?? ''),
                'settlement_date'        => $this->toIso($item['settlement_date'] ?? null),
                'status'                 => $this->mapSettlementStatus((string) ($item['status'] ?? '')),
                'currency'               => 'INR',
                'amount_minor'           => (int) round(((float) ($item['amount_settled'] ?? 0)) * 100),
                'fee_minor'              => (int) round(((float) ($item['service_charge'] ?? 0)) * 100),
                'tax_minor'              => (int) round(((float) ($item['service_tax'] ?? 0)) * 100),
                'refund_minor'           => (int) round(((float) ($item['adjustment'] ?? 0)) * 100),
                'utr'                    => (string) ($item['utr'] ?? ''),
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

        $signature = $headers['x-webhook-signature'] ?? '';
        $timestamp = $headers['x-webhook-timestamp'] ?? '';
        if (!is_string($signature) || $signature === '' || !is_string($timestamp) || $timestamp === '') {
            return false;
        }

        // Cashfree signs timestamp + raw body, base64 of the HMAC. Including the
        // timestamp is what makes a captured callback un-replayable later, so
        // the freshness check below is part of the verification, not an extra.
        $expected = base64_encode(hash_hmac('sha256', $timestamp . $rawPayload, $secret, true));

        if (!hash_equals($expected, $signature)) {
            return false;
        }

        // Five minutes. A correctly signed body replayed an hour later is
        // somebody with a copy of a callback, not Cashfree.
        return abs(time() - (int) $timestamp) <= 300;
    }

    public function normalizeWebhook(array $payload): array
    {
        $type = (string) ($payload['type'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $order = is_array($data['order'] ?? null) ? $data['order'] : [];
        $payment = is_array($data['payment'] ?? null) ? $data['payment'] : [];
        $refund = is_array($data['refund'] ?? null) ? $data['refund'] : [];
        $dispute = is_array($data['dispute'] ?? null) ? $data['dispute'] : [];

        $method = $this->methodFrom($payment);

        return [
            'event'               => $this->mapEvent($type),
            'provider_event_id'   => isset($payment['cf_payment_id']) ? (string) $payment['cf_payment_id'] : null,
            'provider_event_type' => $type !== '' ? $type : null,
            'provider_payment_id' => isset($payment['cf_payment_id']) ? (string) $payment['cf_payment_id'] : null,
            'provider_order_id'   => isset($order['order_id']) ? (string) $order['order_id'] : null,
            'provider_refund_id'  => isset($refund['refund_id']) ? (string) $refund['refund_id'] : null,
            'provider_settlement_id' => isset($data['settlement']['settlement_id']) ? (string) $data['settlement']['settlement_id'] : null,
            'provider_dispute_id' => isset($dispute['dispute_id']) ? (string) $dispute['dispute_id'] : null,
            'status'              => isset($payment['payment_status']) ? $this->mapStatus((string) $payment['payment_status']) : null,
            'amount_minor'        => isset($payment['payment_amount'])
                ? (int) round(((float) $payment['payment_amount']) * 100)
                : (isset($refund['refund_amount']) ? (int) round(((float) $refund['refund_amount']) * 100) : null),
            'currency'            => (string) ($payment['payment_currency'] ?? $order['order_currency'] ?? 'INR'),
            'method'              => $method['method'],
            'network'             => $method['network'],
            'last4'               => $method['last4'],
            'vpa'                 => $method['vpa'],
            'error_code'          => isset($payment['error_details']['error_code']) ? (string) $payment['error_details']['error_code'] : null,
            'error_message'       => isset($payment['error_details']['error_description']) ? (string) $payment['error_details']['error_description'] : null,
            'occurred_at'         => $this->toIso($payload['event_time'] ?? ($payment['payment_time'] ?? null)),
        ];
    }

    public function healthCheck(): array
    {
        // Asking for an order that cannot exist. A 404 proves the host answered
        // and the credentials were accepted; a 401 proves they were not. Nothing
        // is created either way.
        $result = $this->call('GET', 'orders/aicountly-health-probe', null, [], 'healthCheck');

        if ($result['status'] === 404) {
            return ['ok' => true, 'latency_ms' => $result['latency_ms'], 'detail' => 'Cashfree answered.'];
        }
        if ($result['ok']) {
            return ['ok' => true, 'latency_ms' => $result['latency_ms'], 'detail' => 'Cashfree answered.'];
        }

        return [
            'ok'         => false,
            'latency_ms' => $result['latency_ms'],
            'detail'     => in_array($result['status'], [401, 403], true)
                ? 'Cashfree rejected these credentials.'
                : 'Cashfree did not answer.',
            'error_code' => in_array($result['status'], [401, 403], true) ? 'credentials_invalid' : 'unreachable',
        ];
    }

    // -----------------------------------------------------------------------
    // Aicountly Managed — inert until a partner agreement exists
    // -----------------------------------------------------------------------

    public function createMerchant(array $payload): array
    {
        $guard = $this->requirePartner();
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->call('POST', 'easy-split/vendors', $payload, [], 'createMerchant');

        return $result['ok']
            ? ['ok' => true, 'provider_merchant_id' => (string) ($result['body']['vendor_id'] ?? ''), 'status' => 'IN_PROGRESS']
            : $this->fromError($result, 'The payment partner could not create this merchant account.');
    }

    public function updateMerchant(string $merchantId, array $payload): array
    {
        $guard = $this->requirePartner();
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->call('PATCH', 'easy-split/vendors/' . rawurlencode($merchantId), $payload, [], 'updateMerchant');

        return $result['ok']
            ? ['ok' => true, 'status' => $this->mapKycStatus((string) ($result['body']['status'] ?? ''))]
            : $this->fromError($result, 'The payment partner could not update this merchant account.');
    }

    public function submitKyc(string $merchantId, array $payload): array
    {
        $guard = $this->requirePartner();
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->call('POST', 'easy-split/vendors/' . rawurlencode($merchantId) . '/kyc', $payload, [], 'submitKyc');

        if (!$result['ok']) {
            return $this->fromError($result, 'The payment partner could not accept this KYC submission.');
        }

        return [
            'ok'               => true,
            // Whatever the partner says, mapped but never invented. Nothing in
            // this product may return VERIFIED off its own bat.
            'status'           => $this->mapKycStatus((string) ($result['body']['status'] ?? '')),
            'required_actions' => (array) ($result['body']['remarks'] ?? []),
        ];
    }

    public function uploadKycDocument(string $merchantId, array $document): array
    {
        $guard = $this->requirePartner();
        if ($guard !== null) {
            return $guard;
        }

        // Multipart, and the file contents pass straight through: nothing is
        // written to disk here and nothing is stored afterwards. What Pay keeps
        // is the id the partner gives back.
        $result = $this->call('POST', 'easy-split/vendors/' . rawurlencode($merchantId) . '/docs', [
            'doc_type'  => $document['kind'],
            'doc_value' => base64_encode($document['contents']),
            'file_name' => $document['filename'],
        ], [], 'uploadKycDocument');

        return $result['ok']
            ? [
                'ok' => true,
                'provider_document_id' => (string) ($result['body']['doc_id'] ?? $document['kind']),
                'status' => (string) ($result['body']['status'] ?? 'UPLOADED'),
            ]
            : $this->fromError($result, 'The payment partner could not accept this document.');
    }

    public function fetchOnboardingStatus(string $merchantId): array
    {
        $guard = $this->requirePartner();
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->call('GET', 'easy-split/vendors/' . rawurlencode($merchantId), null, [], 'fetchOnboardingStatus');

        if (!$result['ok']) {
            return $this->fromError($result, 'The payment partner could not be asked about this application.');
        }

        return [
            'ok'               => true,
            'status'           => $this->mapKycStatus((string) ($result['body']['status'] ?? '')),
            // The partner's own words, kept verbatim: they are what the merchant
            // has to act on, and a paraphrase could send them to fix the wrong
            // document.
            'detail'           => (string) ($result['body']['remarks']['reason'] ?? ''),
            'required_actions' => (array) ($result['body']['remarks']['pending'] ?? []),
        ];
    }

    public function fetchSettlementAccount(string $merchantId): array
    {
        $guard = $this->requirePartner();
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->call('GET', 'easy-split/vendors/' . rawurlencode($merchantId) . '/bank', null, [], 'fetchSettlementAccount');

        if (!$result['ok']) {
            return $this->fromError($result, 'The payment partner could not be asked about the settlement account.');
        }

        return [
            'ok'        => true,
            'last4'     => $this->last4($result['body']['account_number'] ?? null),
            'ifsc'      => (string) ($result['body']['ifsc'] ?? ''),
            'bank_name' => (string) ($result['body']['bank_name'] ?? ''),
            'verified'  => (bool) ($result['body']['verified'] ?? false),
        ];
    }

    /**
     * The honest refusal, when Aicountly has no partner credentials configured.
     *
     * Returned rather than thrown so the screen can say "Partner credentials not
     * configured" instead of showing an error. Every managed method begins with
     * this, which is what makes the whole managed surface inert rather than
     * half-working on a host that was never set up for it.
     */
    private function requirePartner(): ?array
    {
        if ($this->credential('key_id') === '' || $this->credential('key_secret') === '') {
            return $this->failure(
                'partner_not_configured',
                'Aicountly Managed Payments is not configured on this deployment. Partner credentials are required before an application can be submitted.',
            );
        }

        return null;
    }

    private function mapKycStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'ACTIVE', 'VERIFIED', 'APPROVED' => 'VERIFIED',
            'REJECTED', 'BLOCKED'            => 'REJECTED',
            'SUSPENDED', 'DEACTIVATED'       => 'SUSPENDED',
            'ACTION_REQUIRED', 'PENDING_DOCS' => 'ACTION_REQUIRED',
            'IN_REVIEW', 'UNDER_REVIEW'      => 'UNDER_REVIEW',
            ''                               => 'NOT_STARTED',
            default                          => 'IN_PROGRESS',
        };
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $payload */
    private function tags(array $payload): array
    {
        return array_filter([
            'pay_request' => (string) ($payload['request_uuid'] ?? ''),
            'pay_attempt' => (string) ($payload['attempt_uuid'] ?? ''),
        ], static fn (string $v) => $v !== '');
    }

    /**
     * @param array<string, mixed> $payment
     * @return array{method:?string, network:?string, last4:?string, vpa:?string}
     */
    private function methodFrom(array $payment): array
    {
        $group = $payment['payment_group'] ?? null;
        $detail = is_array($payment['payment_method'] ?? null) ? $payment['payment_method'] : [];

        $card = is_array($detail['card'] ?? null) ? $detail['card'] : [];
        $upi = is_array($detail['upi'] ?? null) ? $detail['upi'] : [];
        $netbanking = is_array($detail['netbanking'] ?? null) ? $detail['netbanking'] : [];
        $wallet = is_array($detail['app'] ?? null) ? $detail['app'] : [];

        $network = null;
        foreach ([$card['card_network'] ?? null, $netbanking['netbanking_bank_name'] ?? null, $wallet['provider'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $network = strtoupper($candidate);
                break;
            }
        }

        return [
            'method'  => $this->normaliseMethod(is_string($group) ? $group : (array_key_first($detail) ?: null)),
            'network' => $network,
            'last4'   => $this->last4($card['card_number'] ?? null),
            'vpa'     => $this->maskVpa($upi['upi_id'] ?? null),
        ];
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'SUCCESS'    => 'SUCCESS',
            'FAILED'     => 'FAILED',
            'CANCELLED', 'USER_DROPPED' => 'CANCELLED',
            'PENDING'    => 'PENDING',
            'NOT_ATTEMPTED' => 'CREATED',
            default      => 'PENDING',
        };
    }

    private function mapRefundStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'SUCCESS'  => 'REFUNDED',
            'CANCELLED', 'FAILED' => 'FAILED',
            default    => 'PROCESSING',
        };
    }

    private function mapSettlementStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'PAID', 'SETTLED' => 'SETTLED',
            'FAILED', 'REVERSED' => 'FAILED',
            default   => 'PROCESSING',
        };
    }

    private function mapEvent(string $type): string
    {
        return match (strtoupper($type)) {
            'PAYMENT_SUCCESS_WEBHOOK'      => EventNames::PAYMENT_SUCCESS,
            'PAYMENT_FAILED_WEBHOOK'       => EventNames::PAYMENT_FAILED,
            'PAYMENT_USER_DROPPED_WEBHOOK' => EventNames::PAYMENT_FAILED,
            'REFUND_STATUS_WEBHOOK'        => EventNames::REFUND_SUCCESS,
            'SETTLEMENT_WEBHOOK'           => EventNames::SETTLEMENT_RECEIVED,
            'DISPUTE_CREATED_WEBHOOK'      => EventNames::DISPUTE_CREATED,
            'DISPUTE_UPDATED_WEBHOOK', 'DISPUTE_CLOSED_WEBHOOK' => EventNames::DISPUTE_UPDATED,
            default                        => EventNames::UNKNOWN,
        };
    }

    /** @param array{ok:bool, status:int, body:array<string,mixed>, error:?string} $result */
    private function fromError(array $result, string $fallback): array
    {
        $code = (string) ($result['body']['code'] ?? $result['body']['type'] ?? '');
        $message = (string) ($result['body']['message'] ?? '');

        if (in_array($result['status'], [401, 403], true)) {
            return $this->failure('credentials_invalid', 'Cashfree rejected the stored credentials for this account.', false);
        }

        return $this->failure(
            $code !== '' ? strtolower($code) : 'provider_error',
            $message !== '' ? $message : $fallback,
            $this->isRetryableStatus($result['status']),
        );
    }
}
