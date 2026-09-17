<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Sources;

use Aicountly\Api\Context;

/**
 * Aicountly Billing — bills, receivables and subscriptions.
 *
 * THE BOUNDARY WORTH SPELLING OUT IS RECURRING BILLING, because it is the one
 * that looks like a Pay concern and is not.
 *
 * Billing owns the plan, its price, its cycle, when the next invoice is raised,
 * what happens on renewal and what happens when a customer's card keeps
 * failing. Pay owns the MANDATE: the payer's standing authority to be debited,
 * the provider's token for it, and whether the last debit went through.
 *
 * So when a subscription renews, Billing raises the bill and asks Pay to
 * collect against the mandate. Pay debits and reports the outcome. Pay does not
 * know the plan's name, does not know when the next one is due, and does not
 * decide whether three failures mean cancellation — and there is deliberately
 * no plan, cycle or renewal field anywhere in this product to tempt it.
 */
final class BillingAdapter extends AbstractSourceAdapter
{
    public function app(): string
    {
        return 'BILLING';
    }

    public function displayName(): string
    {
        return 'Billing';
    }

    protected function productionBase(): string
    {
        return 'https://billing.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://billing.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'BILLING_API_BASE';
    }

    public function fetchSourceDocument(Context $ctx, string $sourceId, array $options = []): array
    {
        $client = $this->client($options);
        if (!$client->hasCredentials()) {
            return $this->failure('source_unauthenticated', 'Pay has no way to authenticate with Billing.');
        }

        $result = $client->get('v1/transactions/' . rawurlencode($sourceId), $ctx->asQuery());

        if (!$result['ok']) {
            return $this->failure(
                $result['status'] === 404 ? 'source_document_not_found' : 'source_unavailable',
                $result['status'] === 404
                    ? 'Billing has no bill with that reference.'
                    : 'Billing could not be reached to confirm this bill.',
            );
        }

        $bill = $this->unwrap($result['body']);
        if ($bill === []) {
            return $this->failure('source_document_not_found', 'Billing returned no bill for that reference.');
        }

        return [
            'ok'       => true,
            'document' => [
                'reference'         => (string) ($this->pick($bill, ['document_no', 'bill_no', 'voucher_no']) ?? $sourceId),
                'description'       => 'Bill ' . (string) ($this->pick($bill, ['document_no', 'bill_no']) ?? $sourceId),
                'amount_minor'      => $this->toMinor($this->pick($bill, ['grand_total', 'entered_value', 'amount'])),
                'currency'          => (string) ($this->pick($bill, ['currency']) ?? 'INR'),
                'outstanding_minor' => $this->toMinor($this->pick($bill, ['balance', 'outstanding'])),
                'customer_ref'      => (string) ($this->pick($bill, ['party_account_id', 'account_id', 'customer_id']) ?? ''),
                'customer_name'     => $this->pick($bill, ['party_name', 'account_name']) === null
                    ? null : (string) $this->pick($bill, ['party_name', 'account_name']),
                'document_date'     => $this->pick($bill, ['date', 'document_date']) === null ? null : (string) $this->pick($bill, ['date', 'document_date']),
                'due_date'          => $this->pick($bill, ['due_date']) === null ? null : (string) $this->pick($bill, ['due_date']),
                'status'            => $this->pick($bill, ['status']) === null ? null : (string) $this->pick($bill, ['status']),
                'url'               => $client->documentUrl('/sales/' . rawurlencode($sourceId)),
            ],
        ];
    }

    public function fetchCustomer(Context $ctx, string $customerRef, array $options = []): array
    {
        $client = $this->client($options);
        if (!$client->hasCredentials()) {
            return $this->failure('source_unauthenticated', 'Pay has no way to authenticate with Billing.');
        }

        // Billing does not own customers either — it reads them from Books. So
        // this asks Billing's own catalog endpoint, which is a read-through,
        // rather than inventing a second path to the same answer.
        $result = $client->get('v1/catalog/parties', $ctx->asQuery() + ['q' => $customerRef, 'limit' => 1]);

        if (!$result['ok']) {
            return $this->failure('source_unavailable', 'Billing could not be reached to look up this customer.');
        }

        $party = $this->unwrap($result['body']);
        if ($party === []) {
            return $this->failure('customer_not_found', 'Billing has no customer with that reference.');
        }

        return [
            'ok'       => true,
            'customer' => [
                'name'      => $this->pick($party, ['account_name', 'name']) === null ? null : (string) $this->pick($party, ['account_name', 'name']),
                'email'     => $this->pick($party, ['email']) === null ? null : (string) $this->pick($party, ['email']),
                'mobile'    => $this->pick($party, ['mobile', 'phone']) === null ? null : (string) $this->pick($party, ['mobile', 'phone']),
                'reference' => $customerRef,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function shapeCallback(array $event): array
    {
        $body = parent::shapeCallback($event);

        // The mandate, when this debit came from one. Billing needs it to tie
        // the payment to its own subscription — which is Billing's record, not
        // ours.
        $body['mandate_id'] = $event['mandate_uuid'] ?? null;
        $body['fy_id'] = $event['source_fy_id'] ?? null;

        return $body;
    }
}
