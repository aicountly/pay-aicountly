<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Idempotency;
use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Events\Outbox;
use Aicountly\Api\Payments\Providers\ProviderRegistry;
use Aicountly\Api\Permissions;

/**
 * Refunds — the one thing in this product that moves money OUT.
 *
 * EVERYTHING HERE IS SHAPED BY ONE FACT: a refund cannot be taken back. A
 * mistaken invoice can be cancelled, a mistaken payment link can be expired, a
 * mistaken reconciliation can be reopened. A refund sent to a customer is gone,
 * and getting it back means asking them nicely.
 *
 * So:
 *
 *   * REQUESTING and SENDING are different acts with different permissions. A
 *     collections clerk may ask; only somebody holding `refunds.approve` sends.
 *   * A requested refund RESERVES the money immediately, before it is approved,
 *     so two clerks cannot each request a full refund of the same payment and
 *     have both go out. The reservation is in PaymentService::refundable().
 *   * The approver cannot be the requester. Enforced here AND by a database
 *     constraint, because a check in one code path is a check that one day gets
 *     a second code path.
 *   * The provider call is idempotent on a key derived from the refund's own
 *     uuid, so a retry after a timeout reaches the same key and the provider
 *     replays rather than sending the money twice.
 *
 * PAY DOES NOT CREATE A CREDIT NOTE. It tells the source app a refund happened;
 * whether that becomes a credit note, a journal or nothing at all is an
 * accounting decision that belongs to Books.
 */
final class RefundService
{
    public const TABLE = 'pay_refunds';

    /**
     * Ask for a refund.
     *
     * @param array{amount?:mixed, reason:string, reason_code?:string} $input
     * @return array<string, mixed>
     */
    public static function request(Context $ctx, Auth $auth, array $attempt, array $input): array
    {
        Permissions::assert($ctx, $auth, 'refunds.request');

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            // Required, not optional. A refund with no stated reason is one
            // nobody can explain six months later when it is queried.
            Http::validationFailed('Say why this refund is being made.', ['field' => 'reason']);
        }

        $currency = (string) $attempt['currency'];

        if (!in_array((string) $attempt['status'], States::ATTEMPT_SETTLED_MONEY, true)) {
            Http::conflict('Only a payment that actually succeeded can be refunded. This one is ' . strtolower(States::label((string) $attempt['status'])) . '.');
        }

        $refundable = PaymentService::refundable($ctx, $attempt);
        if (!$refundable->isPositive()) {
            Http::conflict('This payment has already been fully refunded, or a refund is already in progress for the whole of it.');
        }

        $amount = isset($input['amount']) && $input['amount'] !== null && $input['amount'] !== ''
            ? Money::fromMajor($input['amount'], $currency)
            : $refundable;

        if (!$amount->isPositive()) {
            Http::validationFailed('A refund needs an amount greater than zero.', ['field' => 'amount']);
        }

        if ($amount->greaterThan($refundable)) {
            Http::validationFailed(
                'Only ' . $refundable->format() . ' can still be refunded on this payment.',
                ['field' => 'amount', 'refundable' => $refundable->toMajor()],
            );
        }

        $full = $amount->atLeast($refundable) && $refundable->atLeast(
            Money::minor((int) $attempt['amount_minor'], $currency)
        );

        $settings = Settings::for($ctx);
        $needsApproval = self::needsApproval($settings, $amount);

        $key = Idempotency::required();

        [$result] = Idempotency::once($ctx, 'refund.request', $key, static function () use (
            $ctx, $auth, $attempt, $amount, $currency, $reason, $input, $full, $needsApproval
        ): array {
            $refundId = Db::transaction(static function () use ($ctx, $auth, $attempt, $amount, $currency, $reason, $input, $full): int {
                // Re-check inside the transaction with the row locked. Between
                // the check above and here, another clerk's refund could have
                // taken the balance — which is exactly the race this whole
                // reservation design exists to close.
                $locked = Db::first(
                    'SELECT * FROM ' . PaymentService::TABLE . ' WHERE attempt_id = :id AND cmp_id = :cmp FOR UPDATE',
                    ['id' => (int) $attempt['attempt_id'], 'cmp' => $ctx->cmpId],
                );
                if ($locked === null) {
                    Http::notFound('That payment could not be found.');
                }

                $stillRefundable = PaymentService::refundable($ctx, $locked);
                if ($amount->greaterThan($stillRefundable)) {
                    Http::conflict(
                        'Another refund was raised against this payment a moment ago. Only ' . $stillRefundable->format() . ' can still be refunded.',
                    );
                }

                return (int) Db::insert(self::TABLE, [
                    'refund_uuid'  => Ids::mint(Ids::REFUND),
                    'cmp_id'       => $ctx->cmpId,
                    'bo_id'        => (int) $attempt['bo_id'],
                    'attempt_id'   => (int) $attempt['attempt_id'],
                    'request_id'   => $attempt['request_id'] === null ? null : (int) $attempt['request_id'],
                    'amount_minor' => $amount->minor,
                    'currency'     => $currency,
                    'refund_kind'  => $full ? 'FULL' : 'PARTIAL',
                    'status'       => States::REFUND_REQUESTED,
                    'reason_code'  => isset($input['reason_code']) ? substr((string) $input['reason_code'], 0, 60) : null,
                    'reason'       => substr($reason, 0, 1000),
                    'provider_code' => $attempt['provider_code'] === null ? null : (string) $attempt['provider_code'],
                    'requested_by' => $auth->uuid,
                    'source_sync_status' => $attempt['request_id'] === null ? 'NOT_APPLICABLE' : 'PENDING',
                ], 'refund_id');
            });

            Audit::record($ctx, $auth, Audit::REFUND_REQUESTED, 'refund', $refundId, null, [
                'amount'     => $amount->toMajor(),
                'currency'   => $currency,
                'attempt'    => (string) $attempt['attempt_uuid'],
                'kind'       => $full ? 'FULL' : 'PARTIAL',
            ], $reason);

            $refund = self::require($ctx, $refundId);

            Outbox::queue($ctx, EventNames::REFUND_REQUESTED, ['refund' => $refund], merchantOnly: true);

            return self::present($refund) + ['needs_approval' => $needsApproval];
        });

        // A refund that needs nobody's approval is sent straight away — but
        // only if the SAME person also holds the approve permission. Otherwise
        // it waits, which is the correct outcome for a clerk in a business that
        // has not turned approval on but has not given them that right either.
        if (!$needsApproval && Permissions::allows($ctx, $auth, 'refunds.approve')) {
            $refund = self::findByUuid($ctx, (string) $result['refund_id']);
            if ($refund !== null && (string) $refund['status'] === States::REFUND_REQUESTED) {
                return self::send($ctx, $auth, $refund, autoApproved: true);
            }
        }

        return $result;
    }

    /**
     * Approve and send.
     *
     * @return array<string, mixed>
     */
    public static function approve(Context $ctx, Auth $auth, array $refund): array
    {
        Permissions::assert($ctx, $auth, 'refunds.approve');

        if ((string) $refund['status'] !== States::REFUND_REQUESTED) {
            Http::conflict('This refund is ' . strtolower(States::label((string) $refund['status'])) . ' and cannot be approved again.');
        }

        // Four eyes. The database constraint says the same thing; this says it
        // in words the person reading the screen can act on.
        if ((string) $refund['requested_by'] === $auth->uuid) {
            Http::forbidden('A refund has to be approved by somebody other than the person who requested it.');
        }

        return self::send($ctx, $auth, $refund, autoApproved: false);
    }

    public static function reject(Context $ctx, Auth $auth, array $refund, string $reason): array
    {
        Permissions::assert($ctx, $auth, 'refunds.approve');

        if ((string) $refund['status'] !== States::REFUND_REQUESTED) {
            Http::conflict('Only a refund that is still waiting can be rejected.');
        }

        Db::update(self::TABLE, [
            'status'          => States::REFUND_REJECTED,
            'rejected_by'     => $auth->uuid,
            'rejected_at'     => gmdate('Y-m-d H:i:s'),
            'rejected_reason' => substr($reason, 0, 1000),
            'updated_at'      => gmdate('Y-m-d H:i:s'),
        ], ['refund_id' => (int) $refund['refund_id'], 'cmp_id' => $ctx->cmpId]);

        Audit::record($ctx, $auth, Audit::REFUND_REJECTED, 'refund', (int) $refund['refund_id'], null, null, $reason);

        // Rejecting releases the reservation, because REJECTED is not in
        // States::REFUND_RESERVES_MONEY. The money is refundable again.
        return self::present(self::require($ctx, (int) $refund['refund_id']));
    }

    /**
     * Actually send it to the provider.
     *
     * @return array<string, mixed>
     */
    private static function send(Context $ctx, Auth $auth, array $refund, bool $autoApproved): array
    {
        $refundId = (int) $refund['refund_id'];
        $attempt = PaymentService::require($ctx, (int) $refund['attempt_id']);

        $connection = $attempt['connection_id'] === null
            ? null
            : ProviderRegistry::connection($ctx, (int) $attempt['connection_id']);

        if ($connection === null) {
            return self::fail($ctx, $refundId, 'provider_unavailable', 'The provider that took this payment is no longer connected.');
        }

        $provider = ProviderRegistry::forConnection($connection);
        if ($provider === null) {
            return self::fail($ctx, $refundId, 'provider_unavailable', 'The provider that took this payment cannot be reached. Check its credentials.');
        }

        // Approve and move to PROCESSING BEFORE the call. If the process dies
        // mid-call, the refund is left PROCESSING and gets reconciled against
        // the provider — which is recoverable. Left REQUESTED, a retry would
        // send a second refund.
        Db::update(self::TABLE, array_filter([
            'status'      => States::REFUND_PROCESSING,
            'approved_by' => $autoApproved ? null : $auth->uuid,
            'approved_at' => $autoApproved ? null : gmdate('Y-m-d H:i:s'),
            'processed_at' => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null), ['refund_id' => $refundId, 'cmp_id' => $ctx->cmpId]);

        if (!$autoApproved) {
            Audit::record($ctx, $auth, Audit::REFUND_APPROVED, 'refund', $refundId, null, [
                'amount'   => Money::minor((int) $refund['amount_minor'], (string) $refund['currency'])->toMajor(),
                'currency' => (string) $refund['currency'],
            ]);
        }

        $result = $provider->refundPayment([
            'provider_payment_id' => (string) ($attempt['provider_payment_id'] ?? ''),
            'provider_order_id'   => (string) ($attempt['provider_order_id'] ?? ''),
            'amount_minor'        => (int) $refund['amount_minor'],
            'currency'            => (string) $refund['currency'],
            'reason'              => (string) $refund['reason'],
            'refund_uuid'         => (string) $refund['refund_uuid'],
            // Derived from the refund's own uuid, so every retry of THIS refund
            // reaches the same key. A fresh key per attempt would defeat the
            // provider's own duplicate protection.
            'idempotency_key'     => 'refund:' . (string) $refund['refund_uuid'],
        ]);

        if (!($result['ok'] ?? false)) {
            return self::fail(
                $ctx,
                $refundId,
                (string) ($result['error_code'] ?? 'refund_failed'),
                (string) ($result['error_message'] ?? 'The provider refused this refund.'),
            );
        }

        Db::update(self::TABLE, array_filter([
            'provider_refund_id' => $result['provider_refund_id'] ?? null,
            'provider_status'    => $result['status'] ?? null,
            'status'             => self::mapStatus((string) ($result['status'] ?? States::REFUND_PROCESSING)),
            'updated_at'         => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null), ['refund_id' => $refundId, 'cmp_id' => $ctx->cmpId]);

        $fresh = self::require($ctx, $refundId);

        // A provider that confirms synchronously is settled now; most report
        // PROCESSING and confirm by webhook later.
        if ((string) $fresh['status'] === States::REFUND_REFUNDED) {
            self::markRefunded($ctx, $refundId);
            $fresh = self::require($ctx, $refundId);
        } else {
            Outbox::queue($ctx, EventNames::REFUND_PROCESSING, ['refund' => $fresh], merchantOnly: true);
        }

        return self::present($fresh);
    }

    /**
     * The provider says the refund is done.
     *
     * Called from the webhook ingest and from a status poll. Updates the
     * attempt's refunded total and recalculates the request — in one
     * transaction, so a request can never show a refund the attempt does not.
     */
    public static function markRefunded(Context $ctx, int $refundId): array
    {
        return Db::transaction(static function () use ($ctx, $refundId): array {
            $refund = Db::first(
                'SELECT * FROM ' . self::TABLE . ' WHERE refund_id = :id AND cmp_id = :cmp FOR UPDATE',
                ['id' => $refundId, 'cmp' => $ctx->cmpId],
            );
            if ($refund === null) {
                Http::notFound('That refund could not be found.');
            }

            if ((string) $refund['status'] === States::REFUND_REFUNDED) {
                // Redelivered webhook. Already counted.
                return self::present($refund);
            }

            Db::update(self::TABLE, [
                'status'      => States::REFUND_REFUNDED,
                'refunded_at' => gmdate('Y-m-d H:i:s'),
                'updated_at'  => gmdate('Y-m-d H:i:s'),
            ], ['refund_id' => $refundId, 'cmp_id' => $ctx->cmpId]);

            // The attempt's refunded total is the SUM of its completed refunds,
            // recomputed rather than incremented — for the same reason the
            // request's paid total is. An increment applied twice is a figure
            // nothing can repair.
            Db::run(
                'UPDATE ' . PaymentService::TABLE . ' a
                 SET refunded_minor = (
                        SELECT COALESCE(SUM(r.amount_minor), 0) FROM ' . self::TABLE . ' r
                        WHERE r.attempt_id = a.attempt_id AND r.status = :refunded
                     ),
                     updated_at = NOW()
                 WHERE a.attempt_id = :id AND a.cmp_id = :cmp',
                ['refunded' => States::REFUND_REFUNDED, 'id' => (int) $refund['attempt_id'], 'cmp' => $ctx->cmpId],
            );

            if ($refund['request_id'] !== null) {
                PaymentRequestService::recalculate($ctx, (int) $refund['request_id']);
            }

            $fresh = self::require($ctx, $refundId);
            Outbox::queue($ctx, EventNames::REFUND_SUCCESS, ['refund' => $fresh]);

            return self::present($fresh);
        });
    }

    /** @return array<string, mixed> */
    private static function fail(Context $ctx, int $refundId, string $code, string $message): array
    {
        Db::update(self::TABLE, [
            'status'         => States::REFUND_FAILED,
            'failure_code'   => substr($code, 0, 100),
            'failure_reason' => substr($message, 0, 500),
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ], ['refund_id' => $refundId, 'cmp_id' => $ctx->cmpId]);

        $refund = self::require($ctx, $refundId);
        Outbox::queue($ctx, EventNames::REFUND_FAILED, ['refund' => $refund]);

        // NOT an exception. A refused refund is an outcome the merchant has to
        // see and act on — usually by refunding through the provider's own
        // dashboard — not a 500 that loses the record of having tried.
        return self::present($refund) + ['error_code' => $code, 'error_message' => $message];
    }

    /** @param array<string, mixed> $settings */
    private static function needsApproval(array $settings, Money $amount): bool
    {
        if (!self::truthy($settings['refund_requires_approval'] ?? false)) {
            return false;
        }

        $threshold = $settings['refund_approval_threshold_minor'] ?? null;
        if ($threshold === null) {
            // Approval on, no threshold: everything needs approving.
            return true;
        }

        return $amount->minor >= (int) $threshold;
    }

    private static function mapStatus(string $providerStatus): string
    {
        $upper = strtoupper($providerStatus);

        return in_array($upper, States::all('refund'), true) ? $upper : States::REFUND_PROCESSING;
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $refundId): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE refund_id = :id AND cmp_id = :cmp', ['id' => $refundId, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed>|null */
    public static function findByUuid(Context $ctx, string $uuid): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE refund_uuid = :uuid AND cmp_id = :cmp', ['uuid' => $uuid, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed> */
    public static function require(Context $ctx, int $refundId): array
    {
        $row = self::find($ctx, $refundId);
        if ($row === null) {
            Http::notFound('That refund could not be found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $currency = (string) $row['currency'];

        return [
            'refund_id'      => (string) $row['refund_uuid'],
            'payment_id'     => null,
            'status'         => (string) $row['status'],
            'status_label'   => States::label((string) $row['status']),
            'amount'         => Money::minor((int) $row['amount_minor'], $currency)->toMajor(),
            'currency'       => $currency,
            'kind'           => (string) $row['refund_kind'],
            'reason'         => (string) $row['reason'],
            'reason_code'    => $row['reason_code'] === null ? null : (string) $row['reason_code'],
            'requested_by'   => (string) $row['requested_by'],
            'requested_at'   => (string) $row['requested_at'],
            'approved_by'    => $row['approved_by'] === null ? null : (string) $row['approved_by'],
            'approved_at'    => $row['approved_at'] === null ? null : (string) $row['approved_at'],
            'refunded_at'    => $row['refunded_at'] === null ? null : (string) $row['refunded_at'],
            'rejected_reason' => $row['rejected_reason'] === null ? null : (string) $row['rejected_reason'],
            'failure_reason' => $row['failure_reason'] === null ? null : (string) $row['failure_reason'],
            'provider'       => $row['provider_code'] === null ? null : (string) $row['provider_code'],
            'settlement_status' => (string) $row['settlement_status'],
            'source_sync_status' => (string) $row['source_sync_status'],
        ];
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1' || $value === 'true';
    }
}
