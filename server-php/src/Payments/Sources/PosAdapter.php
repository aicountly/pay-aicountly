<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Sources;

use Aicountly\Api\Context;

/**
 * Aicountly POS — the counter.
 *
 * POS is the one source whose payments are usually SYNCHRONOUS in a way the
 * others are not: a customer is standing at the till with a phone in their
 * hand, and the cashier needs to know within seconds whether the UPI payment
 * landed. That shapes nothing in this adapter — the contract is the same — but
 * it is why a POS bill's payment request is usually created with an expiry of
 * minutes rather than days, and why the QR channel matters most here.
 *
 * What POS does NOT have is a financial year on its bill, so unlike Books this
 * adapter sends no fy_id. That is the whole reason Context treats fy_id as
 * optional context rather than as part of the scope.
 */
final class PosAdapter extends AbstractSourceAdapter
{
    public function app(): string
    {
        return 'POS';
    }

    public function displayName(): string
    {
        return 'POS';
    }

    protected function productionBase(): string
    {
        return 'https://pos.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://pos.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'POS_API_BASE';
    }

    public function fetchSourceDocument(Context $ctx, string $sourceId, array $options = []): array
    {
        $client = $this->client($options);
        if (!$client->hasCredentials()) {
            return $this->failure('source_unauthenticated', 'Pay has no way to authenticate with POS.');
        }

        $result = $client->get('v1/bills/' . rawurlencode($sourceId), $ctx->asQuery());

        if (!$result['ok']) {
            return $this->failure(
                $result['status'] === 404 ? 'source_document_not_found' : 'source_unavailable',
                $result['status'] === 404
                    ? 'POS has no bill with that reference.'
                    : 'POS could not be reached to confirm this bill.',
            );
        }

        $bill = $this->unwrap($result['body']);
        if ($bill === []) {
            return $this->failure('source_document_not_found', 'POS returned no bill for that reference.');
        }

        return [
            'ok'       => true,
            'document' => [
                'reference'         => (string) ($this->pick($bill, ['bill_no', 'invoice_no', 'document_no']) ?? $sourceId),
                'description'       => 'Bill ' . (string) ($this->pick($bill, ['bill_no', 'invoice_no']) ?? $sourceId),
                'amount_minor'      => $this->toMinor($this->pick($bill, ['grand_total', 'net_amount', 'total'])),
                'currency'          => (string) ($this->pick($bill, ['currency']) ?? 'INR'),
                'outstanding_minor' => $this->toMinor($this->pick($bill, ['balance', 'due_amount']))
                    ?? $this->toMinor($this->pick($bill, ['grand_total', 'net_amount', 'total'])),
                // A walk-in has no customer record, and that is normal at a
                // counter. An empty reference here produces a STANDALONE payer,
                // which is exactly right.
                'customer_ref'      => (string) ($this->pick($bill, ['customer_id', 'party_id']) ?? ''),
                'customer_name'     => $this->pick($bill, ['customer_name']) === null ? null : (string) $this->pick($bill, ['customer_name']),
                'document_date'     => $this->pick($bill, ['bill_date', 'date']) === null ? null : (string) $this->pick($bill, ['bill_date', 'date']),
                'due_date'          => null,
                'status'            => $this->pick($bill, ['status']) === null ? null : (string) $this->pick($bill, ['status']),
                'url'               => $client->documentUrl('/bills/' . rawurlencode($sourceId)),
            ],
        ];
    }

    public function fetchCustomer(Context $ctx, string $customerRef, array $options = []): array
    {
        $client = $this->client($options);
        if (!$client->hasCredentials()) {
            return $this->failure('source_unauthenticated', 'Pay has no way to authenticate with POS.');
        }

        $result = $client->get('v1/customers/' . rawurlencode($customerRef), $ctx->asQuery());

        if (!$result['ok']) {
            return $this->failure(
                $result['status'] === 404 ? 'customer_not_found' : 'source_unavailable',
                $result['status'] === 404 ? 'POS has no customer with that reference.' : 'POS could not be reached.',
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
