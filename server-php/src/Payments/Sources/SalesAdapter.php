<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Sources;

use Aicountly\Api\Context;

/**
 * Aicountly Sales — quotations, sales orders and the advance a customer pays
 * before anything ships.
 *
 * The advance is why this adapter exists separately from Books'. A payment
 * against a SALES_ORDER is not a payment against an invoice: there is no
 * invoice yet, the amount is whatever the sales process agreed as an advance,
 * and what Sales does on receipt is release the order — not file a receipt.
 * Pay does not need to know any of that. It needs to know the order's number,
 * what is being asked for, and who to ask.
 */
final class SalesAdapter extends AbstractSourceAdapter
{
    public function app(): string
    {
        return 'SALES';
    }

    public function displayName(): string
    {
        return 'Sales';
    }

    protected function productionBase(): string
    {
        return 'https://sales.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://sales.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'SALES_API_BASE';
    }

    public function fetchSourceDocument(Context $ctx, string $sourceId, array $options = []): array
    {
        $client = $this->client($options);
        if (!$client->hasCredentials()) {
            return $this->failure('source_unauthenticated', 'Pay has no way to authenticate with Sales.');
        }

        $type = strtoupper((string) ($options['source_type'] ?? 'SALES_ORDER'));
        $path = $type === 'QUOTATION'
            ? 'v1/quotations/' . rawurlencode($sourceId)
            : 'v1/sales-orders/' . rawurlencode($sourceId);

        $result = $client->get($path, $ctx->asQuery());

        if (!$result['ok']) {
            return $this->failure(
                $result['status'] === 404 ? 'source_document_not_found' : 'source_unavailable',
                $result['status'] === 404
                    ? 'Sales has no document with that reference.'
                    : 'Sales could not be reached to confirm this document.',
            );
        }

        $doc = $this->unwrap($result['body']);
        if ($doc === []) {
            return $this->failure('source_document_not_found', 'Sales returned no document for that reference.');
        }

        // An advance is what Sales says is due now, which is usually a fraction
        // of the order. Falling back to the order total when no advance is
        // stated would ask the customer for the whole thing.
        $advance = $this->toMinor($this->pick($doc, ['advance_due', 'advance_amount', 'payable_now']));
        $total = $this->toMinor($this->pick($doc, ['grand_total', 'total', 'order_value']));

        return [
            'ok'       => true,
            'document' => [
                'reference'         => (string) ($this->pick($doc, ['order_no', 'document_no', 'quotation_no']) ?? $sourceId),
                'description'       => ($type === 'QUOTATION' ? 'Quotation ' : 'Sales order ')
                    . (string) ($this->pick($doc, ['order_no', 'document_no', 'quotation_no']) ?? $sourceId),
                'amount_minor'      => $advance ?? $total,
                'currency'          => (string) ($this->pick($doc, ['currency']) ?? 'INR'),
                'outstanding_minor' => $this->toMinor($this->pick($doc, ['balance', 'outstanding'])) ?? $advance ?? $total,
                'customer_ref'      => (string) ($this->pick($doc, ['customer_id', 'account_id', 'party_id']) ?? ''),
                'customer_name'     => $this->pick($doc, ['customer_name', 'party_name']) === null
                    ? null : (string) $this->pick($doc, ['customer_name', 'party_name']),
                'document_date'     => $this->pick($doc, ['order_date', 'date']) === null ? null : (string) $this->pick($doc, ['order_date', 'date']),
                'due_date'          => $this->pick($doc, ['due_date', 'expected_date']) === null ? null : (string) $this->pick($doc, ['due_date', 'expected_date']),
                'status'            => $this->pick($doc, ['status']) === null ? null : (string) $this->pick($doc, ['status']),
                'url'               => $client->documentUrl('/orders/' . rawurlencode($sourceId)),
            ],
        ];
    }

    public function fetchCustomer(Context $ctx, string $customerRef, array $options = []): array
    {
        $client = $this->client($options);
        if (!$client->hasCredentials()) {
            return $this->failure('source_unauthenticated', 'Pay has no way to authenticate with Sales.');
        }

        $result = $client->get('v1/customers/' . rawurlencode($customerRef), $ctx->asQuery());

        if (!$result['ok']) {
            return $this->failure(
                $result['status'] === 404 ? 'customer_not_found' : 'source_unavailable',
                $result['status'] === 404
                    ? 'Sales has no customer with that reference.'
                    : 'Sales could not be reached to look up this customer.',
            );
        }

        $customer = $this->unwrap($result['body']);

        return [
            'ok'       => true,
            'customer' => [
                'name'      => $this->pick($customer, ['customer_name', 'name']) === null ? null : (string) $this->pick($customer, ['customer_name', 'name']),
                'email'     => $this->pick($customer, ['email']) === null ? null : (string) $this->pick($customer, ['email']),
                'mobile'    => $this->pick($customer, ['mobile', 'phone']) === null ? null : (string) $this->pick($customer, ['mobile', 'phone']),
                'reference' => $customerRef,
            ],
        ];
    }
}
