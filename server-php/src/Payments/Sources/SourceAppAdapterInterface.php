<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Sources;

use Aicountly\Api\Context;

/**
 * How Pay talks to the application that asked for the money.
 *
 * THE ARCHITECTURAL POINT OF THIS FILE. Without it, the payment engine would
 * grow conditionals: `if ($sourceApp === 'BOOKS') { ... } elseif ('BILLING')`.
 * Every new Aicountly product would then mean editing the payment engine, and
 * the payment engine would slowly learn what an invoice is, what a subscription
 * is, and what a POS bill is — which is exactly the knowledge it must not have.
 *
 * Instead the core knows four verbs: fetch the document, fetch the customer,
 * tell them a payment happened, tell them a refund happened. An adapter
 * translates those into whatever that product's API calls them.
 *
 * WHAT AN ADAPTER MAY NEVER DO:
 *
 *   * write a row in the other product's database — every method here is an
 *     HTTP call to that product's own API, which decides for itself;
 *   * create an accounting document. Pay says "₹50,000 arrived against invoice
 *     92841". Books decides whether that becomes a receipt voucher, which
 *     ledger it hits and what it does to GST. Pay must not know and must not
 *     ask;
 *   * cache what it fetched. A source document read here is read live, used for
 *     this request, and discarded.
 *
 * THE NOTIFY METHODS DO NOT SEND ANYTHING THEMSELVES. They describe an event;
 * the outbox delivers it. A payment that succeeded must be recorded even when
 * Books is down, and a synchronous POST inside the payment transaction would
 * either roll the payment back or block on a dead host with the customer's
 * money already taken.
 */
interface SourceAppAdapterInterface
{
    /** BOOKS | BILLING | SALES | POS | EXTERNAL — matches pay_payment_requests.source_app. */
    public function app(): string;

    /** The product's name, for a screen. */
    public function displayName(): string;

    /** Whether this deployment can actually reach it. A product that is not deployed is not an error. */
    public function isAvailable(): bool;

    /**
     * The document the payment is against, read live.
     *
     * @param array{ses_key?:string, actor_uuid?:string, source_type?:string} $options
     * @return array{ok:bool, document?:array{
     *     reference:?string, description:?string, amount_minor:?int, currency:string,
     *     outstanding_minor:?int, customer_ref:?string, customer_name:?string,
     *     document_date:?string, due_date:?string, status:?string, url:?string
     * }, error_code?:string, error_message?:string}
     */
    public function fetchSourceDocument(Context $ctx, string $sourceId, array $options = []): array;

    /**
     * The customer, read live from whoever owns them.
     *
     * @param array{ses_key?:string, actor_uuid?:string} $options
     * @return array{ok:bool, customer?:array{
     *     name:?string, email:?string, mobile:?string, reference:?string
     * }, error_code?:string, error_message?:string}
     */
    public function fetchCustomer(Context $ctx, string $customerRef, array $options = []): array;

    /**
     * Where a callback for this app should be POSTed.
     *
     * Returning null means "this app does not accept payment callbacks", and
     * the outbox marks the event NOT_APPLICABLE rather than retrying six times
     * against nothing.
     */
    public function callbackPath(string $event): ?string;

    /**
     * Shape the callback body for this app.
     *
     * The common fields are the same for everybody — the contract in
     * docs/PAY_INTEGRATION_GUIDE.md — and an adapter adds only what its own
     * product needs. Books wants the financial year; POS does not have one.
     *
     * @param array<string, mixed> $event the normalised event from the outbox
     * @return array<string, mixed>
     */
    public function shapeCallback(array $event): array;
}
