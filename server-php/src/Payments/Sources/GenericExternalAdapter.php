<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Sources;

use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * Everything that is not one of the fleet's own products.
 *
 * THIS ADAPTER IS THE REASON A FUTURE AICOUNTLY APP NEEDS NO CHANGES TO PAY.
 *
 * Appointments does not exist yet. Contracts does not exist yet. When they do,
 * they register an API client in Developers under their own `source_app` name,
 * POST a payment request with the generic contract, and appear in "Open
 * requests by source" and on every dashboard from the first payment — because
 * Pay never needed to know what an appointment is.
 *
 * It also covers a merchant's own website and their custom ERP, which are the
 * same case from Pay's point of view: somebody outside the fleet who told us an
 * amount and a reference.
 *
 * WHAT IT CANNOT DO, honestly: there is nothing to read back. A generic caller
 * has no API Pay knows how to query, so `fetchSourceDocument()` returns what
 * the caller told us at request time rather than pretending to verify it. That
 * is a real limitation and it is stated rather than papered over — the
 * difference matters, because for Books the amount is confirmed live and here
 * it is taken on trust from an authenticated API client.
 *
 * Callbacks DO work: an external caller registers a webhook endpoint and Pay
 * signs and delivers to it exactly as it does to a sibling product.
 */
final class GenericExternalAdapter extends AbstractSourceAdapter
{
    public function __construct(private readonly string $app = 'EXTERNAL')
    {
    }

    public function app(): string
    {
        return $this->app;
    }

    public function displayName(): string
    {
        return $this->app === 'EXTERNAL' ? 'External' : ucfirst(strtolower($this->app));
    }

    public function isAvailable(): bool
    {
        return true;
    }

    protected function productionBase(): string
    {
        return '';
    }

    protected function sandboxBase(): string
    {
        return '';
    }

    protected function baseEnvKey(): string
    {
        return 'EXTERNAL_API_BASE';
    }

    /**
     * What the caller told us, read back from our own request row.
     *
     * Not a verification. The distinction is in the `verified` flag so a screen
     * can say "as stated by the caller" rather than implying Pay checked.
     */
    public function fetchSourceDocument(Context $ctx, string $sourceId, array $options = []): array
    {
        $row = Db::first(
            'SELECT source_reference, description, amount_minor, currency, paid_minor, payer_name
             FROM pay_payment_requests
             WHERE cmp_id = :cmp AND source_app = :app AND source_id = :id
             ORDER BY request_id DESC LIMIT 1',
            ['cmp' => $ctx->cmpId, 'app' => $this->app, 'id' => $sourceId],
        );

        if ($row === null) {
            return $this->failure(
                'source_document_not_found',
                'Nothing in Pay refers to that document, and ' . $this->displayName() . ' has no API for Pay to ask.',
            );
        }

        $amount = (int) $row['amount_minor'];

        return [
            'ok'       => true,
            'document' => [
                'reference'         => $row['source_reference'] === null ? null : (string) $row['source_reference'],
                'description'       => $row['description'] === null ? null : (string) $row['description'],
                'amount_minor'      => $amount,
                'currency'          => (string) $row['currency'],
                'outstanding_minor' => max(0, $amount - (int) $row['paid_minor']),
                'customer_ref'      => null,
                'customer_name'     => $row['payer_name'] === null ? null : (string) $row['payer_name'],
                'document_date'     => null,
                'due_date'          => null,
                'status'            => null,
                'url'               => null,
                // The honest part. Books' figures are confirmed live; these are
                // what an authenticated caller asserted.
                'verified'          => false,
            ],
        ];
    }

    public function fetchCustomer(Context $ctx, string $customerRef, array $options = []): array
    {
        return $this->failure(
            'customer_lookup_unsupported',
            $this->displayName() . ' does not expose a customer API. Pay uses the payer details sent with the request.',
        );
    }

    /**
     * External callers receive events at the webhook endpoint they registered,
     * not at a path Pay assumes.
     */
    public function callbackPath(string $event): ?string
    {
        return null;
    }
}
