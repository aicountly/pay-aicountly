<?php

declare(strict_types=1);

namespace Aicountly\Api\Payments\Events;

/**
 * Pay's own event vocabulary.
 *
 * WHY WE HAVE ONE AT ALL. Razorpay calls it `payment.captured`. Stripe calls it
 * `payment_intent.succeeded`. Cashfree calls it `PAYMENT_SUCCESS_WEBHOOK`. PayU
 * posts a form with `status=success`. Four names for one fact.
 *
 * If those names reached the rest of the product, then every consumer of an
 * event — the request recalculator, the outbox, Pay Pulse, the dashboards, and
 * every Aicountly app receiving our callbacks — would need to know all four,
 * and adding a fifth provider would mean touching all of them. Instead each
 * adapter maps its provider's name onto one of these, once, in its own file.
 *
 * THESE NAMES ARE A PUBLIC CONTRACT. They appear in the `event` field of every
 * callback Pay sends to Books, Billing, Sales, POS and to merchants' own
 * webhook endpoints. Renaming one breaks every integration that ever consumed
 * it, so they are added to and not edited.
 */
final class EventNames
{
    // Payment request lifecycle
    public const PAYMENT_REQUEST_CREATED   = 'PAYMENT_REQUEST_CREATED';
    public const PAYMENT_REQUEST_CANCELLED = 'PAYMENT_REQUEST_CANCELLED';
    public const PAYMENT_REQUEST_EXPIRED   = 'PAYMENT_REQUEST_EXPIRED';

    // Payment lifecycle
    public const PAYMENT_INITIATED = 'PAYMENT_INITIATED';
    public const PAYMENT_PENDING   = 'PAYMENT_PENDING';
    public const PAYMENT_SUCCESS   = 'PAYMENT_SUCCESS';
    public const PAYMENT_FAILED    = 'PAYMENT_FAILED';

    /**
     * Sent INSTEAD OF PAYMENT_SUCCESS when money arrives against a request that
     * is still not fully paid.
     *
     * A separate name because the receiving app's action is different: Books
     * files a part receipt and leaves the invoice open, where PAYMENT_SUCCESS
     * would have it close the bill. An app that does not care can treat both
     * the same; one that does must be able to tell them apart without doing
     * arithmetic on two numbers we sent it.
     */
    public const PARTIAL_PAYMENT_RECEIVED = 'PARTIAL_PAYMENT_RECEIVED';

    /** Money that arrived outside Pay entirely: cash, a cheque, a bank transfer. */
    public const EXTERNAL_PAYMENT_RECORDED = 'EXTERNAL_PAYMENT_RECORDED';
    public const EXTERNAL_PAYMENT_REVERSED = 'EXTERNAL_PAYMENT_REVERSED';

    // Refunds
    public const REFUND_REQUESTED  = 'REFUND_REQUESTED';
    public const REFUND_PROCESSING = 'REFUND_PROCESSING';
    public const REFUND_SUCCESS    = 'REFUND_SUCCESS';
    public const REFUND_FAILED     = 'REFUND_FAILED';

    // Disputes
    public const DISPUTE_CREATED = 'DISPUTE_CREATED';
    public const DISPUTE_UPDATED = 'DISPUTE_UPDATED';

    // Settlements
    public const SETTLEMENT_EXPECTED = 'SETTLEMENT_EXPECTED';
    public const SETTLEMENT_RECEIVED = 'SETTLEMENT_RECEIVED';
    public const SETTLEMENT_MISMATCH = 'SETTLEMENT_MISMATCH';

    // Mandates
    public const MANDATE_CREATED   = 'MANDATE_CREATED';
    public const MANDATE_ACTIVE    = 'MANDATE_ACTIVE';
    public const MANDATE_PAUSED    = 'MANDATE_PAUSED';
    public const MANDATE_CANCELLED = 'MANDATE_CANCELLED';
    public const MANDATE_FAILED    = 'MANDATE_FAILED';

    // Provider health — internal, never sent to a source app. A merchant's own
    // endpoint may subscribe to them.
    public const PROVIDER_DEGRADED = 'PROVIDER_DEGRADED';
    public const PROVIDER_RECOVERED = 'PROVIDER_RECOVERED';

    // The state of Pay's conversation with the app that raised the request.
    public const SOURCE_SYNC_PENDING = 'SOURCE_SYNC_PENDING';
    public const SOURCE_SYNC_SUCCESS = 'SOURCE_SYNC_SUCCESS';
    public const SOURCE_SYNC_FAILED  = 'SOURCE_SYNC_FAILED';

    /**
     * A provider event we have no mapping for.
     *
     * Deliberately not an error. Providers add event types without asking, and
     * a callback we do not understand is stored, marked IGNORED and left alone.
     * Failing on it would mean a provider's new feature could stop a merchant's
     * payments from being recorded.
     */
    public const UNKNOWN = 'UNKNOWN';

    /**
     * Events a source application can subscribe to.
     *
     * The internal ones are absent on purpose: `PROVIDER_DEGRADED` is an
     * operational fact about the merchant's gateway and is none of Books'
     * business, and `SOURCE_SYNC_*` describes our own delivery to that very app.
     *
     * @return list<string>
     */
    public static function deliverableToSourceApps(): array
    {
        return [
            self::PAYMENT_SUCCESS,
            self::PARTIAL_PAYMENT_RECEIVED,
            self::PAYMENT_FAILED,
            self::PAYMENT_PENDING,
            self::EXTERNAL_PAYMENT_RECORDED,
            self::EXTERNAL_PAYMENT_REVERSED,
            self::REFUND_SUCCESS,
            self::REFUND_FAILED,
            self::DISPUTE_CREATED,
            self::DISPUTE_UPDATED,
            self::PAYMENT_REQUEST_CANCELLED,
            self::PAYMENT_REQUEST_EXPIRED,
            self::MANDATE_ACTIVE,
            self::MANDATE_CANCELLED,
            self::MANDATE_FAILED,
        ];
    }

    /** Everything a merchant's own webhook endpoint may subscribe to. */
    public static function deliverableToMerchants(): array
    {
        return array_merge(self::deliverableToSourceApps(), [
            self::PAYMENT_REQUEST_CREATED,
            self::PAYMENT_INITIATED,
            self::REFUND_REQUESTED,
            self::REFUND_PROCESSING,
            self::SETTLEMENT_RECEIVED,
            self::SETTLEMENT_MISMATCH,
            self::PROVIDER_DEGRADED,
            self::PROVIDER_RECOVERED,
        ]);
    }

    public static function isKnown(string $event): bool
    {
        static $all = null;
        if ($all === null) {
            $all = (new \ReflectionClass(self::class))->getConstants();
        }

        return in_array($event, $all, true);
    }

    /** The event in the words a timeline would use. */
    public static function label(string $event): string
    {
        return match ($event) {
            self::PAYMENT_SUCCESS            => 'Payment received',
            self::PARTIAL_PAYMENT_RECEIVED   => 'Part payment received',
            self::PAYMENT_FAILED             => 'Payment failed',
            self::PAYMENT_PENDING            => 'Payment pending with the bank',
            self::PAYMENT_INITIATED          => 'Payment started',
            self::EXTERNAL_PAYMENT_RECORDED  => 'Payment recorded outside Pay',
            self::EXTERNAL_PAYMENT_REVERSED  => 'External payment reversed',
            self::REFUND_SUCCESS             => 'Refund sent',
            self::REFUND_PROCESSING          => 'Refund in progress',
            self::REFUND_FAILED              => 'Refund failed',
            self::SETTLEMENT_RECEIVED        => 'Settlement credited',
            self::SETTLEMENT_MISMATCH        => 'Settlement did not match',
            self::DISPUTE_CREATED            => 'Dispute opened',
            self::SOURCE_SYNC_FAILED         => 'Could not tell the source app',
            self::UNKNOWN                    => 'Provider event (not acted on)',
            default                          => ucfirst(strtolower(str_replace('_', ' ', $event))),
        };
    }
}
