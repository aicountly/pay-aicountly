<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Providers;

use Aicountly\Api\Payments\Providers\Contracts\PaymentProviderInterface;

/**
 * The HTTP plumbing every provider adapter shares, and none of the meaning.
 *
 * What lives here: making the call, timing it, recording the metric, turning a
 * transport failure into a normalised refusal. What does not: anything that
 * knows what a provider calls its fields. That belongs in the adapter, because
 * the day Razorpay renames something, exactly one file should change.
 *
 * TIMEOUTS ARE SHORT AND BOUNDED, both connect and total. A provider that is up
 * but not answering will otherwise hold a PHP-FPM child for the default 60
 * seconds, and at a handful of concurrent checkouts the pool is gone and every
 * merchant on the host sees a 504 — including the ones whose provider is fine.
 */
abstract class AbstractProvider implements PaymentProviderInterface
{
    protected const CONNECT_TIMEOUT = 4;
    protected const TOTAL_TIMEOUT   = 20;
    /** A read that a screen is waiting on can afford less. */
    protected const READ_TIMEOUT    = 10;

    /**
     * @param array<string, string> $credentials plaintext, opened from the vault
     *        by ProviderRegistry. Never logged, never returned.
     * @param array<string, mixed>  $connection  the pay_provider_connections row
     */
    public function __construct(
        protected readonly array $credentials,
        protected readonly array $connection = [],
    ) {
    }

    public function displayName(): string
    {
        return (string) ($this->connection['display_name'] ?? $this->code());
    }

    /** The provider's API root. Test and live are different hosts for some, one host for others. */
    abstract protected function baseUrl(): string;

    /** @return array<string, string> Auth headers for this provider's scheme. */
    abstract protected function authHeaders(): array;

    protected function isTestMode(): bool
    {
        return strtoupper((string) ($this->connection['environment'] ?? 'LIVE')) === 'TEST';
    }

    protected function connectionId(): int
    {
        return (int) ($this->connection['connection_id'] ?? 0);
    }

    protected function companyId(): int
    {
        return (int) ($this->connection['cmp_id'] ?? 0);
    }

    protected function credential(string $kind): string
    {
        return $this->credentials[$kind] ?? '';
    }

    /**
     * One call to the provider.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extraHeaders
     * @return array{ok:bool, status:int, body:array<string,mixed>, raw:string, error:?string, latency_ms:int}
     */
    protected function call(
        string $method,
        string $path,
        ?array $body = null,
        array $extraHeaders = [],
        string $operation = 'request',
        ?string $paymentMethod = null,
    ): array {
        $url = rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');
        $startedAt = microtime(true);

        $ch = curl_init($url);
        if ($ch === false) {
            return $this->transportFailure($operation, 'curl_init_failed', 0.0, $paymentMethod);
        }

        $headers = ['Accept: application/json'];
        foreach ($this->authHeaders() + $extraHeaders as $name => $value) {
            if ($value !== '') {
                $headers[] = $name . ': ' . $value;
            }
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => strtoupper($method) === 'GET' ? self::READ_TIMEOUT : self::TOTAL_TIMEOUT,
            // Certificate verification is never relaxed. A payment API reached
            // over an unverified connection is a payment API somebody else can
            // be.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($body !== null) {
            $encoded = $this->encodeBody($body);
            $headers[] = 'Content-Type: ' . $this->contentType();
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($ch);
        curl_close($ch);

        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($raw === false || $status === 0) {
            return $this->transportFailure(
                $operation,
                $transportError !== '' ? 'transport' : 'no_response',
                (float) $latencyMs,
                $paymentMethod,
            );
        }

        $decoded = json_decode((string) $raw, true);
        $ok = $status >= 200 && $status < 300;

        ProviderMetrics::record(
            $this->companyId(),
            $this->connectionId(),
            $this->code(),
            $operation,
            $paymentMethod,
            $ok,
            $latencyMs,
            $ok ? null : 'http_' . $status,
        );

        // The response body is NOT logged. A provider's error payload echoes
        // back what we sent, which on a payment call includes the payer's
        // contact details and on an onboarding call includes their PAN.
        if (!$ok) {
            error_log(sprintf(
                '[provider] %s %s failed: status=%d operation=%s latency=%dms',
                $this->code(),
                $this->isTestMode() ? '(test)' : '(live)',
                $status,
                $operation,
                $latencyMs,
            ));
        }

        return [
            'ok'         => $ok,
            'status'     => $status,
            'body'       => is_array($decoded) ? $decoded : [],
            'raw'        => (string) $raw,
            'error'      => $ok ? null : 'HTTP ' . $status,
            'latency_ms' => $latencyMs,
        ];
    }

    /** @return array{ok:false, status:int, body:array<string,mixed>, raw:string, error:string, latency_ms:int} */
    private function transportFailure(string $operation, string $reason, float $latencyMs, ?string $paymentMethod): array
    {
        ProviderMetrics::record(
            $this->companyId(),
            $this->connectionId(),
            $this->code(),
            $operation,
            $paymentMethod,
            false,
            (int) $latencyMs,
            $reason,
        );

        error_log(sprintf('[provider] %s unreachable: operation=%s reason=%s', $this->code(), $operation, $reason));

        return [
            'ok'         => false,
            'status'     => 0,
            'body'       => [],
            'raw'        => '',
            'error'      => $reason,
            'latency_ms' => (int) $latencyMs,
        ];
    }

    /** @param array<string, mixed> $body */
    protected function encodeBody(array $body): string
    {
        return (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function contentType(): string
    {
        return 'application/json';
    }

    /**
     * A refusal in the shape every caller in this product expects.
     *
     * `retryable` is the field that matters downstream: it decides whether the
     * routing engine tries the fallback provider or gives up. "Your card was
     * declined" must not fail over to a second provider — the customer's bank
     * said no and asking a different gateway will not change that, it will just
     * put a second decline on their statement. "Gateway timed out" should.
     */
    protected function failure(string $code, string $message, bool $retryable = false): array
    {
        return ['ok' => false, 'error_code' => $code, 'error_message' => $message, 'retryable' => $retryable];
    }

    /**
     * Whether an HTTP status means "try somewhere else".
     *
     * 401 and 403 are deliberately NOT retryable: our credentials are wrong, and
     * the fallback provider cannot fix that. They mark the connection invalid
     * instead, which is the thing the merchant actually needs to be told.
     */
    protected function isRetryableStatus(int $status): bool
    {
        return $status === 0 || $status === 408 || $status === 429 || $status >= 500;
    }

    /** ISO 8601 from whatever a provider used: a unix timestamp, a date, or nothing. */
    protected function toIso(mixed $value): ?string
    {
        if (is_int($value) && $value > 0) {
            return gmdate('c', $value);
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);

            return $ts === false ? null : gmdate('c', $ts);
        }

        return null;
    }

    /**
     * The last four of a card, and only that.
     *
     * A defensive trim: if a provider ever returns more than four digits in a
     * field we expected four in, the extra digits do not reach our database.
     */
    protected function last4(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return $digits === '' ? null : substr($digits, -4);
    }

    /** `user@okhdfcbank` → `us••@okhdfcbank`. Enough to recognise, not enough to collect from. */
    protected function maskVpa(mixed $vpa): ?string
    {
        if (!is_string($vpa) || !str_contains($vpa, '@')) {
            return null;
        }
        [$handle, $bank] = explode('@', $vpa, 2);
        $visible = substr($handle, 0, 2);

        return $visible . str_repeat('•', max(2, strlen($handle) - 2)) . '@' . $bank;
    }

    /** Providers each spell their methods differently; Pay has one vocabulary. */
    protected function normaliseMethod(?string $providerMethod): ?string
    {
        if ($providerMethod === null || $providerMethod === '') {
            return null;
        }

        return match (strtolower($providerMethod)) {
            'upi', 'upi_collect', 'upi_intent', 'upi_qr' => 'UPI',
            'card', 'cards', 'credit_card', 'debit_card' => 'CARD',
            'netbanking', 'net_banking', 'nb'            => 'NETBANKING',
            'wallet', 'app', 'wallets'                   => 'WALLET',
            'emi', 'cardless_emi'                        => 'EMI',
            'paylater', 'pay_later'                      => 'PAYLATER',
            default                                      => 'OTHER',
        };
    }
}
