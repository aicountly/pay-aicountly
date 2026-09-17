<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Providers\Contracts;

/**
 * What every payment provider must be able to do, in Pay's words rather than
 * its own.
 *
 * WHY THIS INTERFACE IS THE MOST IMPORTANT FILE IN THE PRODUCT. Aicountly Pay
 * is a payment ORCHESTRATION layer. Its value is that a merchant can move from
 * Razorpay to Cashfree, or run both, without their invoices, their reminders,
 * their reconciliation or their dashboards changing at all. The moment a
 * controller contains `if ($provider === 'RAZORPAY')`, that stops being true,
 * and every later provider costs a rewrite instead of a class.
 *
 * So: no controller, no service and no dashboard in this codebase names a
 * provider. They ask the registry for a connection's provider and call these
 * methods. The four adapters that implement it are the only files that know
 * what Razorpay calls an order or what Stripe calls a PaymentIntent.
 *
 * EVERY METHOD RETURNS A NORMALISED SHAPE and never throws for a provider-side
 * refusal. A declined card is not an exception — it is an outcome the product
 * has to show the merchant. `['ok' => false, 'error_code' => …]` is how that
 * arrives, and an exception is reserved for a bug on our side.
 */
interface PaymentProviderInterface
{
    /** RAZORPAY | CASHFREE | STRIPE | PAYU | MOCK */
    public function code(): string;

    /** The name a merchant should see. Not the code. */
    public function displayName(): string;

    /**
     * Methods this provider can take at all, before the merchant's own
     * configuration narrows it: ['UPI', 'CARD', 'NETBANKING', 'WALLET'].
     *
     * @return list<string>
     */
    public function supportedMethods(): array;

    /** @return list<string> ISO 4217 codes this provider can charge in. */
    public function supportedCurrencies(): array;

    /**
     * Optional abilities, so the UI never offers what the provider cannot do.
     *
     * The keys that exist today: `hosted_link`, `dynamic_qr`, `static_qr`,
     * `partial_capture`, `partial_refund`, `mandates`, `settlement_api`,
     * `dispute_api`, `international`.
     *
     * A merchant on a provider with no `static_qr` must not be shown a
     * "reusable QR" switch that silently produces a single-use code.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array;

    /**
     * Start a payment.
     *
     * @param array{
     *     amount_minor:int, currency:string, reference:string, description:string,
     *     payer:array{name?:string, email?:string, mobile?:string},
     *     method?:string, return_url?:string, notes?:array<string,string>,
     *     idempotency_key:string
     * } $payload
     * @return array{ok:bool, provider_payment_id?:string, provider_order_id?:string,
     *     status?:string, checkout_url?:string, raw?:array<string,mixed>,
     *     error_code?:string, error_message?:string, retryable?:bool}
     */
    public function createPayment(array $payload): array;

    /**
     * What the provider currently believes about a payment.
     *
     * This is the reconciler's tool and the answer to a lost webhook: if we
     * never heard, we ask. A payment system that only learns through webhooks
     * has a hole exactly the size of one undelivered callback.
     *
     * @return array{ok:bool, status?:string, amount_minor?:int, currency?:string,
     *     method?:string, network?:string, last4?:string, paid_at?:string,
     *     error_code?:string, error_message?:string, raw?:array<string,mixed>}
     */
    public function fetchPayment(string $providerPaymentId): array;

    /**
     * A provider-hosted payment page.
     *
     * Providers that cannot host one answer `ok:false` with
     * `error_code: 'unsupported'`, and Pay serves its own checkout instead.
     *
     * @param array<string, mixed> $payload
     * @return array{ok:bool, provider_link_id?:string, url?:string, expires_at?:string,
     *     error_code?:string, error_message?:string}
     */
    public function createPaymentLink(array $payload): array;

    /**
     * A QR code payload.
     *
     * `reusable` is honoured only where the provider genuinely supports a static
     * QR. Where it does not, the provider answers with `reusable: false` rather
     * than pretending, and the caller decides what to tell the merchant.
     *
     * @param array<string, mixed> $payload
     * @return array{ok:bool, provider_qr_id?:string, payload?:string, image_url?:string,
     *     reusable?:bool, expires_at?:string, error_code?:string, error_message?:string}
     */
    public function createQrCode(array $payload): array;

    /**
     * Send money back.
     *
     * @param array{provider_payment_id:string, amount_minor:int, currency:string,
     *     reason:string, idempotency_key:string, notes?:array<string,string>} $payload
     * @return array{ok:bool, provider_refund_id?:string, status?:string,
     *     error_code?:string, error_message?:string, retryable?:bool}
     */
    public function refundPayment(array $payload): array;

    /**
     * @return array{ok:bool, status?:string, amount_minor?:int, processed_at?:string,
     *     error_code?:string, error_message?:string}
     */
    public function fetchRefund(string $providerRefundId): array;

    /**
     * Settlement batches, with their fees as the provider reports them.
     *
     * The fee and tax figures MUST come back as the provider stated them. Pay
     * never computes a percentage of its own — see the note in migration 003.
     *
     * @param array{from?:string, to?:string, settlement_id?:string, limit?:int} $filters
     * @return array{ok:bool, settlements?:list<array<string,mixed>>,
     *     error_code?:string, error_message?:string}
     */
    public function fetchSettlement(array $filters): array;

    /**
     * Is this callback genuinely from the provider?
     *
     * Given the RAW body, not a re-encoded array: every provider signs the exact
     * bytes they sent, and `json_encode(json_decode($body))` reorders keys and
     * rewrites unicode escapes. Passing a decoded array here is the single most
     * common way a webhook integration fails in a way nobody can reproduce.
     *
     * @param array<string, string> $headers
     */
    public function verifyWebhook(array $headers, string $rawPayload): bool;

    /**
     * Turn the provider's callback into Pay's own vocabulary.
     *
     * @param array<string, mixed> $payload
     * @return array{event:string, provider_event_id:?string, provider_event_type:?string,
     *     provider_payment_id:?string, provider_refund_id:?string,
     *     provider_settlement_id:?string, provider_dispute_id:?string,
     *     status:?string, amount_minor:?int, currency:?string, method:?string,
     *     network:?string, last4:?string, vpa:?string, error_code:?string,
     *     error_message:?string, occurred_at:?string}
     */
    public function normalizeWebhook(array $payload): array;

    /**
     * Can we reach this provider with these credentials, right now?
     *
     * Used by the connection wizard's "Test connection" step and by the health
     * sweep. Must be a cheap, read-only call — never one that creates anything.
     *
     * @return array{ok:bool, latency_ms:int, detail:string, error_code?:string}
     */
    public function healthCheck(): array;
}
