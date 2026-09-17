<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Events\Outbox;
use Aicountly\Api\Permissions;

/**
 * Chargebacks and disputes.
 *
 * DELIBERATELY PROVIDER-NEUTRAL, INCLUDING THE REASON CODES. A chargeback reason
 * is the card network's, not the provider's and certainly not ours: "4853 —
 * Cardholder Dispute, Goods or Services Not as Described" is the exact string a
 * merchant has to quote when they respond. Normalising it into our own
 * vocabulary would lose the code they need and gain nothing.
 *
 * So `reason_code` and `reason_description` are stored verbatim, and only the
 * lifecycle — open, under review, evidence submitted, won, lost — is mapped.
 *
 * THE DEADLINE IS THE PRODUCT. Miss the evidence date and the dispute is lost
 * by default, whatever the merits. That is why `evidence_due_at` is indexed,
 * why it appears on the Action Centre, and why it is the first thing the
 * present() shape carries.
 *
 * Providers whose API has no dispute endpoint are not pretended to: those
 * disputes are recorded by hand, and the screen says where the record came
 * from.
 */
final class DisputeService
{
    public const TABLE = 'pay_disputes';

    /**
     * Apply a provider's dispute event.
     *
     * @param array<string, mixed> $normalized
     */
    public static function applyProviderEvent(Context $ctx, array $connection, array $normalized): ?int
    {
        $providerDisputeId = isset($normalized['provider_dispute_id']) ? (string) $normalized['provider_dispute_id'] : '';
        if ($providerDisputeId === '') {
            return null;
        }

        $providerCode = (string) $connection['provider_code'];

        $attempt = null;
        if (isset($normalized['provider_payment_id'])) {
            $attempt = Db::first(
                'SELECT * FROM ' . PaymentService::TABLE . ' WHERE provider_code = :code AND provider_payment_id = :pid',
                ['code' => $providerCode, 'pid' => (string) $normalized['provider_payment_id']],
            );
        }

        $existing = Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE provider_code = :code AND provider_dispute_id = :did',
            ['code' => $providerCode, 'did' => $providerDisputeId],
        );

        $status = self::mapStatus((string) ($normalized['dispute_status'] ?? $normalized['status'] ?? ''));

        if ($existing !== null) {
            $current = (string) $existing['status'];

            // Out-of-order news about a dispute that has already closed is
            // ignored, the same rule the attempts follow.
            if (!States::allows('dispute', $current, $status)) {
                return (int) $existing['dispute_id'];
            }

            Db::update(self::TABLE, array_filter([
                'status'     => $status,
                'closed_at'  => in_array($status, [States::DISPUTE_WON, States::DISPUTE_LOST, States::DISPUTE_CLOSED], true)
                    ? gmdate('Y-m-d H:i:s') : null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], static fn ($v) => $v !== null), ['dispute_id' => (int) $existing['dispute_id']]);

            Outbox::queue($ctx, EventNames::DISPUTE_UPDATED, ['attempt' => $attempt], merchantOnly: true);

            // A lost dispute is money gone that a settlement will claw back,
            // and the merchant's reconciliation has to expect it.
            if ($status === States::DISPUTE_LOST) {
                ReconciliationService::openCase($ctx, [
                    'case_kind'    => ReconciliationService::DISPUTE_ADJUSTMENT,
                    'severity'     => 'HIGH',
                    'attempt_id'   => $attempt === null ? null : (int) $attempt['attempt_id'],
                    'actual_minor' => (int) $existing['amount_minor'],
                    'currency'     => (string) $existing['currency'],
                    'summary'      => 'A chargeback was lost. The provider will deduct this from a future settlement.',
                    'detail'       => ['provider_dispute_id' => $providerDisputeId],
                ]);
            }

            return (int) $existing['dispute_id'];
        }

        $disputeId = (int) Db::insert(self::TABLE, [
            'dispute_uuid'       => Ids::mint(Ids::DISPUTE),
            'cmp_id'             => $ctx->cmpId,
            'attempt_id'         => $attempt === null ? null : (int) $attempt['attempt_id'],
            'payer_id'           => $attempt === null || $attempt['payer_id'] === null ? null : (int) $attempt['payer_id'],
            'provider_code'      => $providerCode,
            'provider_dispute_id' => $providerDisputeId,
            'amount_minor'       => (int) ($normalized['amount_minor'] ?? ($attempt['amount_minor'] ?? 0)),
            'currency'           => (string) ($normalized['currency'] ?? ($attempt['currency'] ?? 'INR')),
            'dispute_kind'       => strtoupper((string) ($normalized['dispute_kind'] ?? 'CHARGEBACK')),
            // Verbatim. See the class comment.
            'reason_code'        => isset($normalized['reason_code']) ? substr((string) $normalized['reason_code'], 0, 60) : null,
            'reason_description' => isset($normalized['reason_description']) ? substr((string) $normalized['reason_description'], 0, 500) : null,
            'status'             => $status === States::DISPUTE_CLOSED ? States::DISPUTE_OPEN : $status,
            'evidence_due_at'    => isset($normalized['evidence_due_at'])
                ? gmdate('Y-m-d H:i:s', strtotime((string) $normalized['evidence_due_at']) ?: time()) : null,
            'opened_at'          => isset($normalized['occurred_at'])
                ? gmdate('Y-m-d H:i:s', strtotime((string) $normalized['occurred_at']) ?: time()) : gmdate('Y-m-d H:i:s'),
        ], 'dispute_id');

        Outbox::queue($ctx, EventNames::DISPUTE_CREATED, ['attempt' => $attempt]);

        return $disputeId;
    }

    /**
     * Record a dispute the provider's API never told us about.
     *
     * Real and common: a merchant is notified by their bank or by the provider's
     * own email, and Pay has no API to learn it from. Recording it by hand is
     * better than a dispute that exists only in somebody's inbox.
     *
     * @param array<string, mixed> $input
     */
    public static function recordManually(Context $ctx, Auth $auth, array $input): array
    {
        Permissions::assert($ctx, $auth, 'disputes.manage');

        $attempt = null;
        if (isset($input['payment_id'])) {
            $attempt = PaymentService::findByUuid($ctx, (string) $input['payment_id']);
            if ($attempt === null) {
                Http::notFound('That payment could not be found.');
            }
        }

        $currency = $attempt === null
            ? Money::normaliseCurrency((string) ($input['currency'] ?? 'INR'))
            : (string) $attempt['currency'];

        $amount = Money::fromMajor($input['amount'] ?? ($attempt['amount_minor'] ?? 0) / 100, $currency);

        $disputeId = (int) Db::insert(self::TABLE, [
            'dispute_uuid'       => Ids::mint(Ids::DISPUTE),
            'cmp_id'             => $ctx->cmpId,
            'attempt_id'         => $attempt === null ? null : (int) $attempt['attempt_id'],
            'payer_id'           => $attempt === null || $attempt['payer_id'] === null ? null : (int) $attempt['payer_id'],
            'provider_code'      => $attempt === null ? null : (string) $attempt['provider_code'],
            'provider_dispute_id' => null,
            'amount_minor'       => $amount->minor,
            'currency'           => $currency,
            'dispute_kind'       => strtoupper((string) ($input['dispute_kind'] ?? 'CHARGEBACK')),
            'reason_code'        => isset($input['reason_code']) ? substr((string) $input['reason_code'], 0, 60) : null,
            'reason_description' => isset($input['reason_description']) ? substr((string) $input['reason_description'], 0, 500) : null,
            'status'             => States::DISPUTE_OPEN,
            'evidence_due_at'    => isset($input['evidence_due_at'])
                ? gmdate('Y-m-d H:i:s', strtotime((string) $input['evidence_due_at']) ?: time()) : null,
            'notes'              => isset($input['notes']) ? substr((string) $input['notes'], 0, 2000) : null,
        ], 'dispute_id');

        return self::present(self::require($ctx, $disputeId));
    }

    /** @param array<string, mixed> $input */
    public static function update(Context $ctx, Auth $auth, int $disputeId, array $input): array
    {
        Permissions::assert($ctx, $auth, 'disputes.manage');

        $dispute = self::require($ctx, $disputeId);
        $updates = ['updated_at' => gmdate('Y-m-d H:i:s')];

        if (isset($input['status'])) {
            $status = strtoupper((string) $input['status']);
            if (!States::allows('dispute', (string) $dispute['status'], $status)) {
                Http::conflict('A dispute that is ' . strtolower(States::label((string) $dispute['status'])) . ' cannot move to ' . strtolower(States::label($status)) . '.');
            }
            $updates['status'] = $status;
            if ($status === States::DISPUTE_EVIDENCE_SENT) {
                $updates['evidence_submitted_at'] = gmdate('Y-m-d H:i:s');
            }
            if (in_array($status, [States::DISPUTE_WON, States::DISPUTE_LOST, States::DISPUTE_CLOSED], true)) {
                $updates['closed_at'] = gmdate('Y-m-d H:i:s');
            }
        }

        if (isset($input['notes'])) {
            $updates['notes'] = substr((string) $input['notes'], 0, 2000);
        }

        if (isset($input['evidence']) && is_array($input['evidence'])) {
            // References to evidence held elsewhere, never file contents.
            $updates['evidence'] = array_values(array_filter(array_map(
                static fn ($item) => is_array($item) ? array_intersect_key($item, array_flip(['kind', 'provider_document_id', 'note', 'uploaded_at'])) : null,
                $input['evidence'],
            )));
        }

        Db::update(self::TABLE, $updates, ['dispute_id' => $disputeId, 'cmp_id' => $ctx->cmpId]);

        return self::present(self::require($ctx, $disputeId));
    }

    private static function mapStatus(string $providerStatus): string
    {
        return match (strtolower($providerStatus)) {
            'won'                          => States::DISPUTE_WON,
            'lost'                         => States::DISPUTE_LOST,
            'closed'                       => States::DISPUTE_CLOSED,
            'under_review', 'needs_response', 'warning_needs_response' => States::DISPUTE_UNDER_REVIEW,
            'evidence_submitted', 'under_dispute' => States::DISPUTE_EVIDENCE_SENT,
            default                        => States::DISPUTE_OPEN,
        };
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $disputeId): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE dispute_id = :id AND cmp_id = :cmp', ['id' => $disputeId, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed>|null */
    public static function findByUuid(Context $ctx, string $uuid): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE dispute_uuid = :uuid AND cmp_id = :cmp', ['uuid' => $uuid, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed> */
    public static function require(Context $ctx, int $disputeId): array
    {
        $row = self::find($ctx, $disputeId);
        if ($row === null) {
            Http::notFound('That dispute could not be found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    public static function present(array $row): array
    {
        $dueAt = $row['evidence_due_at'] === null ? null : strtotime((string) $row['evidence_due_at']);

        return [
            'dispute_id'   => (string) $row['dispute_uuid'],
            'status'       => (string) $row['status'],
            'status_label' => States::label((string) $row['status']),
            'kind'         => (string) $row['dispute_kind'],
            'amount'       => Money::minor((int) $row['amount_minor'], (string) $row['currency'])->toMajor(),
            'currency'     => (string) $row['currency'],
            'reason_code'  => $row['reason_code'] === null ? null : (string) $row['reason_code'],
            'reason'       => $row['reason_description'] === null ? null : (string) $row['reason_description'],
            'provider'     => $row['provider_code'] === null ? null : (string) $row['provider_code'],
            // The number that decides whether this dispute is winnable.
            'evidence_due_at'   => $row['evidence_due_at'] === null ? null : (string) $row['evidence_due_at'],
            'days_to_respond'   => $dueAt === null ? null : (int) ceil(($dueAt - time()) / 86400),
            'evidence_submitted' => $row['evidence_submitted_at'] !== null,
            'evidence'     => Db::jsonColumn($row['evidence'] ?? null),
            'notes'        => $row['notes'] === null ? null : (string) $row['notes'],
            'opened_at'    => (string) $row['opened_at'],
            'closed_at'    => $row['closed_at'] === null ? null : (string) $row['closed_at'],
            // Where the record came from. A hand-entered dispute is not one the
            // provider confirmed, and the screen should not imply it is.
            'source'       => $row['provider_dispute_id'] === null ? 'RECORDED_MANUALLY' : 'PROVIDER',
        ];
    }
}
