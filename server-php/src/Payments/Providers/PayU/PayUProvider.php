<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Providers\PayU;

use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Providers\AbstractProvider;

/**
 * PayU, in DIRECT mode.
 *
 * PayU is the odd one in this fleet and the reason the provider interface is
 * shaped the way it is. It has no REST resource you create a payment against:
 * the payment is a FORM POST from the payer's browser to PayU, signed with an
 * SHA-512 hash of a fixed field order, and PayU posts the result back to a URL
 * we nominated. There is no order id to hold on to until the payer has been.
 *
 * Any design that assumed "call the provider, get an id back" would have needed
 * a special case for PayU somewhere in the core. Instead `createPayment()` here
 * returns the signed form for the browser to submit, and everything downstream
 * — the attempt row, the routing decision, the reconciler — is unchanged.
 *
 * THE HASH IS THE WHOLE OF PAYU'S SECURITY MODEL, in both directions. The
 * request hash is over our salt and stops a payer editing the amount in the
 * form; the response hash is the same fields reversed and is the only thing
 * that distinguishes a genuine callback from a POST anybody can make to our
 * endpoint. Both are implemented below in the exact field order PayU
 * specifies — the order is not arbitrary and a single field out of place makes
 * every payment fail verification.
 */
final class PayUProvider extends AbstractProvider
{
    public function code(): string
    {
        return 'PAYU';
    }

    public function supportedMethods(): array
    {
        return ['UPI', 'CARD', 'NETBANKING', 'WALLET', 'EMI'];
    }

    public function supportedCurrencies(): array
    {
        return ['INR'];
    }

    public function capabilities(): array
    {
        return [
            // PayU's hosted page is reached by posting a form, not by following
            // a URL we can store, so this is false: the caller must render the
            // form. Saying true would have Pay store a link that does not exist.
            'hosted_link'     => false,
            'dynamic_qr'      => false,
            'static_qr'       => false,
            'partial_capture' => false,
            'partial_refund'  => true,
            'mandates'        => true,
            'settlement_api'  => false,
            'dispute_api'     => false,
            'international'   => false,
        ];
    }

    protected function baseUrl(): string
    {
        return $this->isTestMode() ? 'https://test.payu.in' : 'https://secure.payu.in';
    }

    /** PayU authenticates each call with a hash in the body, not a header. */
    protected function authHeaders(): array
    {
        return [];
    }

    protected function contentType(): string
    {
        return 'application/x-www-form-urlencoded';
    }

    /** @param array<string, mixed> $body */
    protected function encodeBody(array $body): string
    {
        return http_build_query($body, '', '&', PHP_QUERY_RFC3986);
    }

    public function createPayment(array $payload): array
    {
        $key = $this->credential('key_id');
        $salt = $this->credential('key_secret');

        if ($key === '' || $salt === '') {
            return $this->failure('credentials_invalid', 'This PayU connection has no merchant key and salt configured.');
        }

        // txnid must be unique per attempt and no longer than 25 characters.
        // Our attempt uuid is both, once trimmed.
        $txnId = substr(str_replace('-', '', (string) ($payload['attempt_uuid'] ?? '')), 0, 25);
        if ($txnId === '') {
            return $this->failure('reference_missing', 'PayU needs a transaction reference for this payment.');
        }

        // PayU works in rupees with two decimals as a STRING, and the hash is
        // over the string. Formatting it differently here and there is the
        // classic PayU integration bug: "100" and "100.00" hash differently.
        $amount = number_format($payload['amount_minor'] / 100, 2, '.', '');

        $fields = [
            'key'         => $key,
            'txnid'       => $txnId,
            'amount'      => $amount,
            'productinfo' => substr((string) ($payload['description'] ?? 'Payment'), 0, 100),
            'firstname'   => substr((string) ($payload['payer']['name'] ?? 'Customer'), 0, 60),
            'email'       => (string) ($payload['payer']['email'] ?? ''),
            'phone'       => (string) ($payload['payer']['mobile'] ?? ''),
            'surl'        => (string) ($payload['return_url'] ?? ''),
            'furl'        => (string) ($payload['return_url'] ?? ''),
            // udf1 carries our request uuid back on the callback, which is how a
            // response is attributed when txnid alone is not enough.
            'udf1'        => (string) ($payload['request_uuid'] ?? ''),
            'udf2'        => (string) ($payload['attempt_uuid'] ?? ''),
            'udf3'        => '',
            'udf4'        => '',
            'udf5'        => '',
        ];

        $fields['hash'] = $this->requestHash($fields, $salt);

        return [
            'ok'                => true,
            'provider_order_id' => $txnId,
            'status'            => 'CREATED',
            'raw'               => [
                // Everything the browser needs to post. The salt is NOT here —
                // only the hash computed from it, which reveals nothing.
                'form_action' => $this->baseUrl() . '/_payment',
                'form_fields' => $fields,
                'form_method' => 'POST',
            ],
        ];
    }

    public function fetchPayment(string $providerPaymentId): array
    {
        $result = $this->verificationCall('verify_payment', $providerPaymentId);

        if (!$result['ok']) {
            return $this->fromError($result, 'PayU could not be asked about this payment.');
        }

        $transaction = $this->firstTransaction($result['body'], $providerPaymentId);
        if ($transaction === null) {
            return ['ok' => true, 'status' => 'CREATED'];
        }

        return [
            'ok'            => true,
            'status'        => $this->mapStatus((string) ($transaction['status'] ?? '')),
            'amount_minor'  => isset($transaction['amt']) ? (int) round(((float) $transaction['amt']) * 100) : null,
            'currency'      => 'INR',
            'method'        => $this->normaliseMethod($transaction['mode'] ?? null),
            'network'       => isset($transaction['bankcode']) ? strtoupper((string) $transaction['bankcode']) : null,
            'last4'         => $this->last4($transaction['card_no'] ?? null),
            'vpa'           => $this->maskVpa($transaction['field3'] ?? null),
            'paid_at'       => $this->toIso($transaction['addedon'] ?? null),
            'error_code'    => isset($transaction['error']) ? (string) $transaction['error'] : null,
            'error_message' => isset($transaction['error_Message']) ? (string) $transaction['error_Message'] : null,
            'raw'           => ['mihpayid' => $transaction['mihpayid'] ?? null],
        ];
    }

    public function createPaymentLink(array $payload): array
    {
        return $this->failure(
            'unsupported',
            'PayU payments are collected through the Aicountly checkout, which posts the signed PayU form.',
        );
    }

    public function createQrCode(array $payload): array
    {
        return $this->failure('unsupported', 'This PayU integration does not issue standalone QR codes.');
    }

    public function refundPayment(array $payload): array
    {
        $result = $this->verificationCall(
            'cancel_refund_transaction',
            $payload['provider_payment_id'],
            [
                // PayU's own idempotency handle for a refund.
                substr((string) ($payload['refund_uuid'] ?? $payload['idempotency_key']), 0, 25),
                number_format($payload['amount_minor'] / 100, 2, '.', ''),
            ],
        );

        if (!$result['ok']) {
            return $this->fromError($result, 'PayU refused this refund.');
        }

        $status = (int) ($result['body']['status'] ?? 0);
        if ($status !== 1) {
            return $this->failure(
                'refund_rejected',
                (string) ($result['body']['msg'] ?? 'PayU refused this refund.'),
            );
        }

        return [
            'ok'                 => true,
            'provider_refund_id' => (string) ($result['body']['request_id'] ?? ''),
            'status'             => 'PROCESSING',
        ];
    }

    public function fetchRefund(string $providerRefundId): array
    {
        $result = $this->verificationCall('check_action_status', $providerRefundId);

        if (!$result['ok']) {
            return $this->fromError($result, 'PayU could not be asked about this refund.');
        }

        $transaction = $this->firstTransaction($result['body'], $providerRefundId);

        return [
            'ok'           => true,
            'status'       => $this->mapRefundStatus((string) ($transaction['status'] ?? '')),
            'amount_minor' => isset($transaction['amt']) ? (int) round(((float) $transaction['amt']) * 100) : 0,
            'processed_at' => $this->toIso($transaction['addedon'] ?? null),
        ];
    }

    public function fetchSettlement(array $filters): array
    {
        // PayU's settlement reporting is not part of this API surface. Rather
        // than return an empty list — which the reconciler would read as "no
        // settlements exist", and then report every payment as unsettled — this
        // says plainly that the provider cannot be asked.
        return $this->failure(
            'unsupported',
            'PayU does not expose settlements through this API. Import the settlement file instead.',
        );
    }

    public function verifyWebhook(array $headers, string $rawPayload): bool
    {
        $salt = $this->credential('key_secret');
        $key = $this->credential('key_id');
        if ($salt === '' || $key === '') {
            return false;
        }

        // PayU posts a form, not JSON, so the raw body is parsed here rather
        // than by the ingest's json_decode.
        parse_str($rawPayload, $fields);
        if (!is_array($fields) || ($fields['hash'] ?? '') === '') {
            return false;
        }

        $expected = $this->responseHash($fields, $key, $salt);

        return hash_equals($expected, strtolower((string) $fields['hash']));
    }

    public function normalizeWebhook(array $payload): array
    {
        // The ingest hands JSON-decoded arrays; PayU sends a form. When the
        // decode produced nothing the raw body is re-parsed here.
        if ($payload === [] && isset($payload['__raw'])) {
            parse_str((string) $payload['__raw'], $payload);
        }

        $status = (string) ($payload['status'] ?? '');

        return [
            'event'               => $this->mapEvent($status),
            'provider_event_id'   => isset($payload['mihpayid']) ? (string) $payload['mihpayid'] : null,
            'provider_event_type' => $status !== '' ? 'payment.' . strtolower($status) : null,
            'provider_payment_id' => isset($payload['mihpayid']) ? (string) $payload['mihpayid'] : null,
            'provider_order_id'   => isset($payload['txnid']) ? (string) $payload['txnid'] : null,
            'provider_refund_id'  => null,
            'provider_settlement_id' => null,
            'provider_dispute_id' => null,
            'status'              => $this->mapStatus($status),
            'amount_minor'        => isset($payload['amount']) ? (int) round(((float) $payload['amount']) * 100) : null,
            'currency'            => 'INR',
            'method'              => $this->normaliseMethod($payload['mode'] ?? null),
            'network'             => isset($payload['bankcode']) ? strtoupper((string) $payload['bankcode']) : null,
            'last4'               => $this->last4($payload['cardnum'] ?? null),
            'vpa'                 => $this->maskVpa($payload['field3'] ?? null),
            'error_code'          => isset($payload['error']) ? (string) $payload['error'] : null,
            'error_message'       => isset($payload['error_Message']) ? (string) $payload['error_Message'] : null,
            'occurred_at'         => $this->toIso($payload['addedon'] ?? null),
        ];
    }

    public function healthCheck(): array
    {
        if ($this->credential('key_id') === '' || $this->credential('key_secret') === '') {
            return ['ok' => false, 'latency_ms' => 0, 'detail' => 'No merchant key and salt are configured.', 'error_code' => 'credentials_invalid'];
        }

        // PayU has no ping. Verifying a transaction id that cannot exist proves
        // the host answers and the hash was accepted, and creates nothing.
        $result = $this->verificationCall('verify_payment', 'aicountly-health-probe');

        if ($result['ok']) {
            return ['ok' => true, 'latency_ms' => $result['latency_ms'], 'detail' => 'PayU answered.'];
        }

        return [
            'ok'         => false,
            'latency_ms' => $result['latency_ms'],
            'detail'     => 'PayU did not answer.',
            'error_code' => 'unreachable',
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * PayU's info API: command, variables, and a hash over key|command|var1|salt.
     *
     * @param list<string> $extraVars
     */
    private function verificationCall(string $command, string $var1, array $extraVars = []): array
    {
        $key = $this->credential('key_id');
        $salt = $this->credential('key_secret');

        $body = [
            'key'     => $key,
            'command' => $command,
            'var1'    => $var1,
            'hash'    => hash('sha512', $key . '|' . $command . '|' . $var1 . '|' . $salt),
        ];

        foreach ($extraVars as $index => $value) {
            $body['var' . ($index + 2)] = $value;
        }

        $host = $this->isTestMode() ? 'https://test.payu.in' : 'https://info.payu.in';

        // The info API lives on a different host from the payment form, so the
        // URL is built here rather than through baseUrl().
        $saved = $host . '/merchant/postservice.php?form=2';

        return $this->callAbsolute($saved, $body, $command);
    }

    /** @param array<string, mixed> $body */
    private function callAbsolute(string $url, array $body, string $operation): array
    {
        $startedAt = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => [], 'raw' => '', 'error' => 'curl_init_failed', 'latency_ms' => 0];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($body),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::READ_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);
        $ok = $raw !== false && $status >= 200 && $status < 300;

        \Aicountly\Api\Payments\Providers\ProviderMetrics::record(
            $this->companyId(),
            $this->connectionId(),
            $this->code(),
            $operation,
            null,
            $ok,
            $latencyMs,
            $ok ? null : 'http_' . $status,
        );

        $decoded = $raw === false ? null : json_decode((string) $raw, true);

        return [
            'ok'         => $ok,
            'status'     => $status,
            'body'       => is_array($decoded) ? $decoded : [],
            'raw'        => $raw === false ? '' : (string) $raw,
            'error'      => $ok ? null : 'HTTP ' . $status,
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * key|txnid|amount|productinfo|firstname|email|udf1..udf5|||||salt
     *
     * The five empty pipes are udf6 to udf10, which this integration does not
     * use but which PayU still counts. They are the single most common cause of
     * "hash mismatch" on a PayU integration and are spelled out here rather
     * than left to a loop somebody will later shorten.
     *
     * @param array<string, string> $fields
     */
    private function requestHash(array $fields, string $salt): string
    {
        $parts = [
            $fields['key'],
            $fields['txnid'],
            $fields['amount'],
            $fields['productinfo'],
            $fields['firstname'],
            $fields['email'],
            $fields['udf1'] ?? '',
            $fields['udf2'] ?? '',
            $fields['udf3'] ?? '',
            $fields['udf4'] ?? '',
            $fields['udf5'] ?? '',
            '', '', '', '', '',
            $salt,
        ];

        return hash('sha512', implode('|', $parts));
    }

    /**
     * The response hash is the request's fields reversed, with the status
     * inserted: salt|status|||||udf5..udf1|email|firstname|productinfo|amount|txnid|key
     *
     * @param array<string, mixed> $fields
     */
    private function responseHash(array $fields, string $key, string $salt): string
    {
        $get = static fn (string $name): string => isset($fields[$name]) ? (string) $fields[$name] : '';

        // An `additionalCharges` field, when PayU sends one, is prefixed to the
        // whole string. Omitting it whenever it is present makes every such
        // callback fail verification.
        $additional = $get('additionalCharges');

        $parts = [
            $salt,
            $get('status'),
            '', '', '', '', '',
            $get('udf5'), $get('udf4'), $get('udf3'), $get('udf2'), $get('udf1'),
            $get('email'),
            $get('firstname'),
            $get('productinfo'),
            $get('amount'),
            $get('txnid'),
            $key,
        ];

        $base = implode('|', $parts);

        return hash('sha512', $additional !== '' ? $additional . '|' . $base : $base);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function firstTransaction(array $body, string $reference): ?array
    {
        $details = $body['transaction_details'] ?? null;
        if (is_array($details)) {
            if (isset($details[$reference]) && is_array($details[$reference])) {
                return $details[$reference];
            }
            foreach ($details as $row) {
                if (is_array($row)) {
                    return $row;
                }
            }
        }

        return null;
    }

    private function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'success', 'captured' => 'SUCCESS',
            'failure', 'failed'   => 'FAILED',
            'cancel', 'cancelled', 'usercancelled' => 'CANCELLED',
            'pending', 'in progress', 'initiated'  => 'PENDING',
            'auth'                => 'AUTHORIZED',
            default               => 'PENDING',
        };
    }

    private function mapRefundStatus(string $status): string
    {
        return match (strtolower($status)) {
            'refund success', 'refunded', 'success' => 'REFUNDED',
            'refund failed', 'failure' => 'FAILED',
            default => 'PROCESSING',
        };
    }

    private function mapEvent(string $status): string
    {
        return match ($this->mapStatus($status)) {
            'SUCCESS'   => EventNames::PAYMENT_SUCCESS,
            'FAILED', 'CANCELLED' => EventNames::PAYMENT_FAILED,
            'PENDING', 'AUTHORIZED' => EventNames::PAYMENT_PENDING,
            default     => EventNames::UNKNOWN,
        };
    }

    /** @param array{ok:bool, status:int, body:array<string,mixed>, error:?string} $result */
    private function fromError(array $result, string $fallback): array
    {
        $message = (string) ($result['body']['msg'] ?? '');

        return $this->failure(
            'provider_error',
            $message !== '' ? $message : $fallback,
            $this->isRetryableStatus($result['status']),
        );
    }
}
