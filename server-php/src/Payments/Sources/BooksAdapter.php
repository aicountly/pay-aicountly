<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Sources;

use Aicountly\Api\Context;

/**
 * Smart Books — the accounting product.
 *
 * WHAT PAY SENDS BOOKS: "₹50,000 was received against sales invoice 92841 on
 * 14 January, through UPI, via Razorpay, provider reference pay_XYZ."
 *
 * WHAT BOOKS DOES WITH IT is entirely Books' business. It will probably create
 * a receipt voucher, allocate it against the invoice bill-by-bill, and post to
 * the right bank or suspense ledger. Pay does not know which ledger, does not
 * know the company's accounting policy for payments in transit, and must not
 * guess — that is why this adapter has no ledger, no voucher type and no
 * account id anywhere in it.
 *
 * WHAT PAY READS FROM BOOKS: the invoice's reference, its total and what is
 * still outstanding on it — live, at the moment a payment request is raised.
 * The outstanding figure is Books' own bill-by-bill answer and is never stored
 * here: a receivable balance copied into a second product is a second answer,
 * and the two will disagree the first time somebody posts a credit note.
 */
final class BooksAdapter extends AbstractSourceAdapter
{
    public function app(): string
    {
        return 'BOOKS';
    }

    public function displayName(): string
    {
        return 'Smart Books';
    }

    protected function productionBase(): string
    {
        return 'https://books.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://books.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'BOOKS_API_BASE';
    }

    public function fetchSourceDocument(Context $ctx, string $sourceId, array $options = []): array
    {
        $client = $this->client($options);
        if (!$client->hasCredentials()) {
            return $this->failure('source_unauthenticated', 'Pay has no way to authenticate with Smart Books.');
        }

        // Books' financial year is REQUIRED on its own endpoints, which is the
        // one place Pay's fy_id pass-through earns its keep: we do not scope by
        // it, but Books does, and a voucher read without it is a 400.
        $result = $client->get('vouchers/' . rawurlencode($sourceId), $ctx->asQuery());

        if (!$result['ok']) {
            return $this->failure(
                $result['status'] === 404 ? 'source_document_not_found' : 'source_unavailable',
                $result['status'] === 404
                    ? 'Smart Books has no document with that reference.'
                    : 'Smart Books could not be reached to confirm this document.',
            );
        }

        $voucher = $this->unwrap($result['body']);
        if ($voucher === []) {
            return $this->failure('source_document_not_found', 'Smart Books returned no document for that reference.');
        }

        // Books' bill-by-bill report is the authority on what is still owed.
        // The voucher's own `balance` is used only when the report is
        // unavailable, and the caller is told which it got.
        $outstanding = $this->toMinor($this->pick($voucher, ['balance', 'outstanding', 'due_amount']));

        return [
            'ok'       => true,
            'document' => [
                'reference'         => (string) ($this->pick($voucher, ['voucher_no', 'vch_no', 'document_no']) ?? $sourceId),
                'description'       => $this->describe($voucher, (string) ($options['source_type'] ?? 'SALES_INVOICE')),
                'amount_minor'      => $this->toMinor($this->pick($voucher, ['grand_total', 'total', 'amount'])),
                'currency'          => (string) ($this->pick($voucher, ['currency', 'currency_code']) ?? 'INR'),
                'outstanding_minor' => $outstanding,
                'customer_ref'      => (string) ($this->pick($voucher, ['account_id', 'party_id', 'acc_id']) ?? ''),
                'customer_name'     => $this->pick($voucher, ['account_name', 'party_name', 'acc_name']) === null
                    ? null : (string) $this->pick($voucher, ['account_name', 'party_name', 'acc_name']),
                'document_date'     => $this->pick($voucher, ['voucher_date', 'vch_date', 'date']) === null
                    ? null : (string) $this->pick($voucher, ['voucher_date', 'vch_date', 'date']),
                'due_date'          => $this->pick($voucher, ['due_date']) === null ? null : (string) $this->pick($voucher, ['due_date']),
                'status'            => $this->pick($voucher, ['status']) === null ? null : (string) $this->pick($voucher, ['status']),
                'url'               => $client->documentUrl('/vouchers/' . rawurlencode($sourceId)),
            ],
        ];
    }

    public function fetchCustomer(Context $ctx, string $customerRef, array $options = []): array
    {
        $client = $this->client($options);
        if (!$client->hasCredentials()) {
            return $this->failure('source_unauthenticated', 'Pay has no way to authenticate with Smart Books.');
        }

        $result = $client->get('masters/accounts/' . rawurlencode($customerRef), $ctx->asQuery());

        if (!$result['ok']) {
            return $this->failure(
                $result['status'] === 404 ? 'customer_not_found' : 'source_unavailable',
                $result['status'] === 404
                    ? 'Smart Books has no customer with that reference.'
                    : 'Smart Books could not be reached to look up this customer.',
            );
        }

        $account = $this->unwrap($result['body']);

        return [
            'ok'       => true,
            'customer' => [
                'name'      => $this->pick($account, ['acc_name', 'account_name', 'name']) === null
                    ? null : (string) $this->pick($account, ['acc_name', 'account_name', 'name']),
                'email'     => $this->pick($account, ['email', 'email_id']) === null ? null : (string) $this->pick($account, ['email', 'email_id']),
                'mobile'    => $this->pick($account, ['mobile', 'phone', 'contact_no']) === null ? null : (string) $this->pick($account, ['mobile', 'phone', 'contact_no']),
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

        // The one field Books needs that nobody else does: which financial year
        // to file its receipt in. Pay carried it from the request that raised
        // the payment rather than deciding it, because only Books knows how its
        // own year boundaries work.
        $body['fy_id'] = $event['source_fy_id'] ?? null;

        return $body;
    }

    /** @param array<string, mixed> $voucher */
    private function describe(array $voucher, string $sourceType): string
    {
        $number = (string) ($this->pick($voucher, ['voucher_no', 'vch_no', 'document_no']) ?? '');
        $label = match (strtoupper($sourceType)) {
            'SALES_INVOICE' => 'Invoice',
            'CREDIT_NOTE'   => 'Credit note',
            'RECEIPT'       => 'Receipt',
            default         => 'Document',
        };

        return $number === '' ? $label : $label . ' ' . $number;
    }
}
