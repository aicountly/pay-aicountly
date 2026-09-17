<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

/**
 * The lifecycles in this product, and the transitions each one allows.
 *
 * THE RULE THAT SHAPES ALL OF THEM: a payment request and a payment attempt are
 * different things with different lives, and neither is a status column on the
 * other.
 *
 * One request for ₹1,00,000 can carry a failed UPI attempt, a second attempt
 * the customer abandoned at the bank page, a ₹40,000 card payment that
 * succeeded, and a later ₹60,000 UPI payment that also succeeded. Squashing
 * that into one `status` field means either losing the failures — and with them
 * the answer to "why did this take four days" — or letting a failure overwrite
 * a success. Both have happened in products that tried.
 *
 * So the request's state is DERIVED from its payments (see
 * PaymentRequestService::recalculate) and never set by hand from a webhook.
 * The attempt's state is what the provider told us about that one attempt.
 */
final class States
{
    // -----------------------------------------------------------------------
    // Payment request — what the merchant asked for
    // -----------------------------------------------------------------------

    public const REQUEST_DRAFT          = 'DRAFT';
    public const REQUEST_ACTIVE         = 'ACTIVE';
    public const REQUEST_PARTIALLY_PAID = 'PARTIALLY_PAID';
    public const REQUEST_PAID           = 'PAID';
    public const REQUEST_EXPIRED        = 'EXPIRED';
    public const REQUEST_CANCELLED      = 'CANCELLED';

    /** @var array<string, list<string>> */
    private const REQUEST_TRANSITIONS = [
        self::REQUEST_DRAFT => [self::REQUEST_ACTIVE, self::REQUEST_CANCELLED],
        // A live request can take money, run out of time, or be called off.
        self::REQUEST_ACTIVE => [
            self::REQUEST_PARTIALLY_PAID, self::REQUEST_PAID,
            self::REQUEST_EXPIRED, self::REQUEST_CANCELLED,
        ],
        // Part-paid can still complete, and can still expire with money on it —
        // which is a real state and not an error: the customer paid half and
        // never came back.
        self::REQUEST_PARTIALLY_PAID => [
            self::REQUEST_PAID, self::REQUEST_EXPIRED, self::REQUEST_CANCELLED,
            self::REQUEST_ACTIVE,
        ],
        // PAID is not final. A refund takes it back to part-paid or active, and
        // pretending otherwise leaves a fully-refunded request reading "Paid".
        self::REQUEST_PAID => [self::REQUEST_PARTIALLY_PAID, self::REQUEST_ACTIVE],
        // An expired request can be revived by extending its expiry.
        self::REQUEST_EXPIRED => [self::REQUEST_ACTIVE, self::REQUEST_CANCELLED],
        // Cancelled is final. Money already taken against it stays taken and is
        // refunded through the refund flow, not by un-cancelling.
        self::REQUEST_CANCELLED => [],
    ];

    /** States in which a customer may still be sent to a checkout. */
    public const REQUEST_PAYABLE = [self::REQUEST_ACTIVE, self::REQUEST_PARTIALLY_PAID];

    /** States that will never take another rupee. */
    public const REQUEST_CLOSED = [self::REQUEST_PAID, self::REQUEST_EXPIRED, self::REQUEST_CANCELLED];

    // -----------------------------------------------------------------------
    // Payment attempt — one journey through one provider
    // -----------------------------------------------------------------------

    public const ATTEMPT_CREATED    = 'CREATED';
    public const ATTEMPT_INITIATED  = 'INITIATED';
    public const ATTEMPT_PENDING    = 'PENDING';
    public const ATTEMPT_AUTHORIZED = 'AUTHORIZED';
    public const ATTEMPT_CAPTURED   = 'CAPTURED';
    public const ATTEMPT_SUCCESS    = 'SUCCESS';
    public const ATTEMPT_FAILED     = 'FAILED';
    public const ATTEMPT_CANCELLED  = 'CANCELLED';

    /** @var array<string, list<string>> */
    private const ATTEMPT_TRANSITIONS = [
        // CREATED can reach a money state directly, and must. A provider-hosted
        // link is paid without us seeing anything in between: the first we hear
        // is a webhook saying captured. Refusing that jump would drop a real
        // payment on the floor because our record was one step behind.
        self::ATTEMPT_CREATED => [
            self::ATTEMPT_INITIATED, self::ATTEMPT_PENDING, self::ATTEMPT_AUTHORIZED,
            self::ATTEMPT_CAPTURED, self::ATTEMPT_SUCCESS,
            self::ATTEMPT_FAILED, self::ATTEMPT_CANCELLED,
        ],
        self::ATTEMPT_INITIATED => [
            self::ATTEMPT_PENDING, self::ATTEMPT_AUTHORIZED, self::ATTEMPT_CAPTURED,
            self::ATTEMPT_SUCCESS, self::ATTEMPT_FAILED, self::ATTEMPT_CANCELLED,
        ],
        // PENDING is where UPI collect requests and net-banking journeys live
        // for minutes. It can still go anywhere.
        self::ATTEMPT_PENDING => [
            self::ATTEMPT_AUTHORIZED, self::ATTEMPT_CAPTURED, self::ATTEMPT_SUCCESS,
            self::ATTEMPT_FAILED, self::ATTEMPT_CANCELLED,
        ],
        // Authorized but not captured: the money is held, not taken. A merchant
        // who never captures it loses it back to the customer, which is why
        // this is a state and not a synonym for success.
        self::ATTEMPT_AUTHORIZED => [self::ATTEMPT_CAPTURED, self::ATTEMPT_SUCCESS, self::ATTEMPT_FAILED, self::ATTEMPT_CANCELLED],
        self::ATTEMPT_CAPTURED => [self::ATTEMPT_SUCCESS],
        // The three terminal states. A provider that later contradicts one of
        // these is not applied silently — see Transitions::isTerminalConflict.
        self::ATTEMPT_SUCCESS => [],
        self::ATTEMPT_FAILED => [],
        self::ATTEMPT_CANCELLED => [],
    ];

    /** An attempt whose money has actually arrived. */
    public const ATTEMPT_SETTLED_MONEY = [self::ATTEMPT_CAPTURED, self::ATTEMPT_SUCCESS];

    /** An attempt that is still moving. */
    public const ATTEMPT_OPEN = [
        self::ATTEMPT_CREATED, self::ATTEMPT_INITIATED, self::ATTEMPT_PENDING, self::ATTEMPT_AUTHORIZED,
    ];

    public const ATTEMPT_TERMINAL = [self::ATTEMPT_SUCCESS, self::ATTEMPT_FAILED, self::ATTEMPT_CANCELLED];

    // -----------------------------------------------------------------------
    // Refund
    // -----------------------------------------------------------------------

    public const REFUND_REQUESTED  = 'REQUESTED';
    public const REFUND_APPROVED   = 'APPROVED';
    public const REFUND_PROCESSING = 'PROCESSING';
    public const REFUND_REFUNDED   = 'REFUNDED';
    public const REFUND_FAILED     = 'FAILED';
    public const REFUND_REJECTED   = 'REJECTED';

    /** @var array<string, list<string>> */
    private const REFUND_TRANSITIONS = [
        self::REFUND_REQUESTED => [self::REFUND_APPROVED, self::REFUND_REJECTED, self::REFUND_PROCESSING],
        self::REFUND_APPROVED  => [self::REFUND_PROCESSING, self::REFUND_FAILED],
        self::REFUND_PROCESSING => [self::REFUND_REFUNDED, self::REFUND_FAILED],
        // A failed refund can be tried again — the customer is still owed.
        self::REFUND_FAILED => [self::REFUND_PROCESSING],
        self::REFUND_REFUNDED => [],
        self::REFUND_REJECTED => [],
    ];

    public const REFUND_OPEN = [self::REFUND_REQUESTED, self::REFUND_APPROVED, self::REFUND_PROCESSING];

    /**
     * Refund states that count against a payment's refundable balance.
     *
     * A requested-but-unsent refund is counted, so two clerks cannot each
     * request a full refund of the same payment and have both go out.
     */
    public const REFUND_RESERVES_MONEY = [
        self::REFUND_REQUESTED, self::REFUND_APPROVED, self::REFUND_PROCESSING, self::REFUND_REFUNDED,
    ];

    // -----------------------------------------------------------------------
    // Settlement
    // -----------------------------------------------------------------------

    public const SETTLEMENT_EXPECTED               = 'EXPECTED';
    public const SETTLEMENT_PROCESSING             = 'PROCESSING';
    public const SETTLEMENT_SETTLED                = 'SETTLED';
    public const SETTLEMENT_PARTIALLY_SETTLED      = 'PARTIALLY_SETTLED';
    public const SETTLEMENT_DELAYED                = 'DELAYED';
    public const SETTLEMENT_FAILED                 = 'FAILED';
    public const SETTLEMENT_RECONCILIATION_REQUIRED = 'RECONCILIATION_REQUIRED';

    /** @var array<string, list<string>> */
    private const SETTLEMENT_TRANSITIONS = [
        self::SETTLEMENT_EXPECTED => [
            self::SETTLEMENT_PROCESSING, self::SETTLEMENT_SETTLED, self::SETTLEMENT_PARTIALLY_SETTLED,
            self::SETTLEMENT_DELAYED, self::SETTLEMENT_FAILED, self::SETTLEMENT_RECONCILIATION_REQUIRED,
        ],
        self::SETTLEMENT_PROCESSING => [
            self::SETTLEMENT_SETTLED, self::SETTLEMENT_PARTIALLY_SETTLED, self::SETTLEMENT_DELAYED,
            self::SETTLEMENT_FAILED, self::SETTLEMENT_RECONCILIATION_REQUIRED,
        ],
        self::SETTLEMENT_DELAYED => [
            self::SETTLEMENT_PROCESSING, self::SETTLEMENT_SETTLED, self::SETTLEMENT_PARTIALLY_SETTLED,
            self::SETTLEMENT_FAILED, self::SETTLEMENT_RECONCILIATION_REQUIRED,
        ],
        self::SETTLEMENT_PARTIALLY_SETTLED => [self::SETTLEMENT_SETTLED, self::SETTLEMENT_RECONCILIATION_REQUIRED],
        // Settled can still be reopened: a difference found a week later at the
        // bank is exactly when this happens, and the batch was settled when we
        // said it was.
        self::SETTLEMENT_SETTLED => [self::SETTLEMENT_RECONCILIATION_REQUIRED],
        self::SETTLEMENT_RECONCILIATION_REQUIRED => [self::SETTLEMENT_SETTLED, self::SETTLEMENT_PARTIALLY_SETTLED],
        self::SETTLEMENT_FAILED => [self::SETTLEMENT_PROCESSING, self::SETTLEMENT_RECONCILIATION_REQUIRED],
    ];

    // -----------------------------------------------------------------------
    // Mandate — a standing authority to collect
    // -----------------------------------------------------------------------

    public const MANDATE_PENDING   = 'PENDING';
    public const MANDATE_ACTIVE    = 'ACTIVE';
    public const MANDATE_PAUSED    = 'PAUSED';
    public const MANDATE_CANCELLED = 'CANCELLED';
    public const MANDATE_EXPIRED   = 'EXPIRED';
    public const MANDATE_FAILED    = 'FAILED';

    /** @var array<string, list<string>> */
    private const MANDATE_TRANSITIONS = [
        self::MANDATE_PENDING => [self::MANDATE_ACTIVE, self::MANDATE_FAILED, self::MANDATE_CANCELLED],
        self::MANDATE_ACTIVE => [self::MANDATE_PAUSED, self::MANDATE_CANCELLED, self::MANDATE_EXPIRED, self::MANDATE_FAILED],
        self::MANDATE_PAUSED => [self::MANDATE_ACTIVE, self::MANDATE_CANCELLED, self::MANDATE_EXPIRED],
        self::MANDATE_FAILED => [self::MANDATE_ACTIVE, self::MANDATE_CANCELLED],
        self::MANDATE_CANCELLED => [],
        self::MANDATE_EXPIRED => [],
    ];

    // -----------------------------------------------------------------------
    // Dispute
    // -----------------------------------------------------------------------

    public const DISPUTE_OPEN            = 'OPEN';
    public const DISPUTE_UNDER_REVIEW    = 'UNDER_REVIEW';
    public const DISPUTE_EVIDENCE_SENT   = 'EVIDENCE_SUBMITTED';
    public const DISPUTE_WON             = 'WON';
    public const DISPUTE_LOST            = 'LOST';
    public const DISPUTE_CLOSED          = 'CLOSED';

    /** @var array<string, list<string>> */
    private const DISPUTE_TRANSITIONS = [
        self::DISPUTE_OPEN => [self::DISPUTE_UNDER_REVIEW, self::DISPUTE_EVIDENCE_SENT, self::DISPUTE_CLOSED, self::DISPUTE_LOST],
        self::DISPUTE_UNDER_REVIEW => [self::DISPUTE_EVIDENCE_SENT, self::DISPUTE_WON, self::DISPUTE_LOST, self::DISPUTE_CLOSED],
        self::DISPUTE_EVIDENCE_SENT => [self::DISPUTE_WON, self::DISPUTE_LOST, self::DISPUTE_CLOSED],
        self::DISPUTE_WON => [self::DISPUTE_CLOSED],
        self::DISPUTE_LOST => [self::DISPUTE_CLOSED],
        self::DISPUTE_CLOSED => [],
    ];

    // -----------------------------------------------------------------------

    /** @return array<string, list<string>> */
    public static function machine(string $entity): array
    {
        return match ($entity) {
            'request'    => self::REQUEST_TRANSITIONS,
            'attempt'    => self::ATTEMPT_TRANSITIONS,
            'refund'     => self::REFUND_TRANSITIONS,
            'settlement' => self::SETTLEMENT_TRANSITIONS,
            'mandate'    => self::MANDATE_TRANSITIONS,
            'dispute'    => self::DISPUTE_TRANSITIONS,
            default      => [],
        };
    }

    public static function allows(string $entity, string $from, string $to): bool
    {
        if ($from === $to) {
            // Re-applying the state something is already in is a no-op, not an
            // illegal move. Webhooks redeliver, and a redelivery must not 422.
            return true;
        }

        return in_array($to, self::machine($entity)[$from] ?? [], true);
    }

    /**
     * Whether a move would contradict something already final.
     *
     * The difference matters for webhooks. A provider sending "failed" for an
     * attempt we already recorded as SUCCESS is not a transition to reject
     * quietly — it means our record and theirs disagree about whether money
     * moved, and that needs a human, not a log line. The caller raises a
     * reconciliation case instead of applying it.
     */
    public static function isTerminalConflict(string $entity, string $from, string $to): bool
    {
        if ($from === $to) {
            return false;
        }

        $terminal = match ($entity) {
            'attempt' => self::ATTEMPT_TERMINAL,
            'refund'  => [self::REFUND_REFUNDED, self::REFUND_REJECTED],
            'request' => [self::REQUEST_CANCELLED],
            'mandate' => [self::MANDATE_CANCELLED, self::MANDATE_EXPIRED],
            default   => [],
        };

        return in_array($from, $terminal, true) && !self::allows($entity, $from, $to);
    }

    /** All states an entity can hold, for validation and for a filter dropdown. */
    public static function all(string $entity): array
    {
        return array_keys(self::machine($entity));
    }

    /** A state in the words a person would use, for a badge or a message. */
    public static function label(string $state): string
    {
        return match ($state) {
            self::REQUEST_PARTIALLY_PAID => 'Part paid',
            self::ATTEMPT_AUTHORIZED     => 'Authorised, not captured',
            self::SETTLEMENT_RECONCILIATION_REQUIRED => 'Needs reconciliation',
            self::SETTLEMENT_PARTIALLY_SETTLED => 'Part settled',
            self::DISPUTE_EVIDENCE_SENT  => 'Evidence submitted',
            default => ucfirst(strtolower(str_replace('_', ' ', $state))),
        };
    }
}
