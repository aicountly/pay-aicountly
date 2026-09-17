<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;

/**
 * The exceptions — the things that did not add up, with somebody's name on them.
 *
 * WHAT RECONCILIATION MEANS HERE, and where it stops. Pay answers exactly one
 * question: did the money we collected arrive in the merchant's bank, less what
 * the provider said they would charge?
 *
 *     payment → provider batch → expected net → bank credit
 *
 * It does NOT answer "which ledger does this bank line belong to and what is
 * the accounting effect". That is Books' question, Books has the ledgers to
 * answer it, and a second answer computed here would be a second set of books.
 * There is no ledger, no account and no accounting date anywhere in this class.
 *
 * WHY A CASE AND NOT A LOG LINE. "7 unreconciled transactions" that nobody can
 * be assigned is a number that stays at 7 forever. A case has a kind, a
 * severity, a one-sentence summary a human can act on, an owner and a
 * resolution — and it can be closed, which is the only way the number ever goes
 * down.
 */
final class ReconciliationService
{
    public const TABLE = 'pay_reconciliation_cases';

    // The taxonomy. Every kind here is something that actually happens, and
    // each one has a different fix — which is why they are not one
    // "discrepancy" bucket.
    public const UNRECONCILED_TRANSACTION   = 'UNRECONCILED_TRANSACTION';
    public const SETTLEMENT_AMOUNT_MISMATCH = 'SETTLEMENT_AMOUNT_MISMATCH';
    public const MISSING_BANK_SETTLEMENT    = 'MISSING_BANK_SETTLEMENT';
    public const UNEXPECTED_PROVIDER_FEE    = 'UNEXPECTED_PROVIDER_FEE';
    public const DELAYED_SETTLEMENT         = 'DELAYED_SETTLEMENT';
    public const REFUND_NOT_SETTLED         = 'REFUND_NOT_SETTLED';
    public const DUPLICATE_SETTLEMENT_ENTRY = 'DUPLICATE_SETTLEMENT_ENTRY';
    public const PARTIAL_SETTLEMENT         = 'PARTIAL_SETTLEMENT';
    public const DISPUTE_ADJUSTMENT         = 'DISPUTE_ADJUSTMENT';
    public const SOURCE_SYNC_FAILED         = 'SOURCE_SYNC_FAILED';
    public const PROVIDER_STATE_CONFLICT    = 'PROVIDER_STATE_CONFLICT';
    public const DUPLICATE_CALLBACK         = 'DUPLICATE_CALLBACK';

    public const OPEN          = 'OPEN';
    public const INVESTIGATING = 'INVESTIGATING';
    public const RESOLVED      = 'RESOLVED';
    public const WRITTEN_OFF   = 'WRITTEN_OFF';
    public const IGNORED       = 'IGNORED';

    /** Words a person would use, for the Exception Centre. */
    public const LABELS = [
        self::UNRECONCILED_TRANSACTION   => 'Unreconciled transaction',
        self::SETTLEMENT_AMOUNT_MISMATCH => 'Settlement amount mismatch',
        self::MISSING_BANK_SETTLEMENT    => 'Missing in bank statement',
        self::UNEXPECTED_PROVIDER_FEE    => 'Unexpected provider fees',
        self::DELAYED_SETTLEMENT         => 'Delayed settlement',
        self::REFUND_NOT_SETTLED         => 'Refund not settled',
        self::DUPLICATE_SETTLEMENT_ENTRY => 'Duplicate settlement entry',
        self::PARTIAL_SETTLEMENT         => 'Partial settlement',
        self::DISPUTE_ADJUSTMENT         => 'Dispute adjustment',
        self::SOURCE_SYNC_FAILED         => 'Source app not updated',
        self::PROVIDER_STATE_CONFLICT    => 'Provider disagrees with Pay',
        self::DUPLICATE_CALLBACK         => 'Duplicate callback',
    ];

    /**
     * Open a case, or leave the existing one alone.
     *
     * IDEMPOTENT BY DESIGN. The reconciler runs on a schedule and would
     * otherwise open the same case every time it ran, which turns the Exception
     * Centre into a wall nobody reads. The partial unique indexes in migration
     * 003 enforce one open case per (kind, subject).
     *
     * @param array{case_kind:string, severity?:string, summary:string,
     *     settlement_id?:?int, attempt_id?:?int, refund_id?:?int,
     *     expected_minor?:?int, actual_minor?:?int, difference_minor?:?int,
     *     currency?:string, detail?:array<string,mixed>} $case
     */
    public static function openCase(Context $ctx, array $case): ?int
    {
        try {
            $existing = self::findOpen(
                $ctx,
                (string) $case['case_kind'],
                $case['settlement_id'] ?? null,
                $case['attempt_id'] ?? null,
                $case['refund_id'] ?? null,
            );

            if ($existing !== null) {
                // Refresh the figures — a delayed settlement gets later every
                // day — without resetting who is working on it.
                Db::update(self::TABLE, array_filter([
                    'expected_minor'   => $case['expected_minor'] ?? null,
                    'actual_minor'     => $case['actual_minor'] ?? null,
                    'difference_minor' => $case['difference_minor'] ?? null,
                    'summary'          => $case['summary'],
                    'updated_at'       => gmdate('Y-m-d H:i:s'),
                ], static fn ($v) => $v !== null), ['case_id' => $existing]);

                return $existing;
            }

            return (int) Db::insert(self::TABLE, [
                'case_uuid'        => Ids::mint(Ids::CASE_),
                'cmp_id'           => $ctx->cmpId,
                'case_kind'        => (string) $case['case_kind'],
                'severity'         => (string) ($case['severity'] ?? 'MEDIUM'),
                'status'           => self::OPEN,
                'settlement_id'    => $case['settlement_id'] ?? null,
                'attempt_id'       => $case['attempt_id'] ?? null,
                'refund_id'        => $case['refund_id'] ?? null,
                'expected_minor'   => $case['expected_minor'] ?? null,
                'actual_minor'     => $case['actual_minor'] ?? null,
                'difference_minor' => $case['difference_minor'] ?? null,
                'currency'         => (string) ($case['currency'] ?? 'INR'),
                'summary'          => substr((string) $case['summary'], 0, 500),
                'detail'           => $case['detail'] ?? [],
            ], 'case_id');
        } catch (\Throwable $e) {
            // Two workers racing to open the same case both lose to the unique
            // index, and that is the correct outcome — one case exists. Not
            // worth failing the payment that triggered it.
            error_log('[reconciliation] could not open a ' . ($case['case_kind'] ?? '?') . ' case: ' . $e->getMessage());

            return null;
        }
    }

    private static function findOpen(Context $ctx, string $kind, ?int $settlementId, ?int $attemptId, ?int $refundId): ?int
    {
        $row = Db::first(
            'SELECT case_id FROM ' . self::TABLE . '
             WHERE cmp_id = :cmp AND case_kind = :kind AND status IN (:open, :investigating)
               AND settlement_id IS NOT DISTINCT FROM :settlement
               AND attempt_id    IS NOT DISTINCT FROM :attempt
               AND refund_id     IS NOT DISTINCT FROM :refund
             LIMIT 1',
            [
                'cmp' => $ctx->cmpId, 'kind' => $kind,
                'open' => self::OPEN, 'investigating' => self::INVESTIGATING,
                'settlement' => $settlementId, 'attempt' => $attemptId, 'refund' => $refundId,
            ],
        );

        return $row === null ? null : (int) $row['case_id'];
    }

    /**
     * Close a case.
     *
     * A note is required for WRITTEN_OFF and IGNORED. Those are the two
     * outcomes where money is being let go or a difference is being accepted,
     * and "resolved" with no explanation is exactly what an auditor asks about.
     */
    public static function resolve(Context $ctx, Auth $auth, int $caseId, string $status, string $note): array
    {
        $case = self::require($ctx, $caseId);

        if (!in_array($status, [self::RESOLVED, self::WRITTEN_OFF, self::IGNORED, self::INVESTIGATING], true)) {
            Http::validationFailed('That is not a state a case can be moved to.', ['field' => 'status']);
        }

        if (in_array($status, [self::WRITTEN_OFF, self::IGNORED], true) && trim($note) === '') {
            Http::validationFailed(
                'Say why this difference is being ' . ($status === self::WRITTEN_OFF ? 'written off' : 'ignored') . '.',
                ['field' => 'note'],
            );
        }

        $closing = $status !== self::INVESTIGATING;

        Db::update(self::TABLE, array_filter([
            'status'          => $status,
            'resolved_by'     => $closing ? $auth->uuid : null,
            'resolved_at'     => $closing ? gmdate('Y-m-d H:i:s') : null,
            'resolution_note' => trim($note) === '' ? null : substr($note, 0, 1000),
            'assigned_to'     => $status === self::INVESTIGATING ? $auth->uuid : null,
            'updated_at'      => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null), ['case_id' => $caseId, 'cmp_id' => $ctx->cmpId]);

        Audit::record($ctx, $auth, Audit::RECONCILIATION_RESOLVED, 'reconciliation_case', $caseId, [
            'status' => (string) $case['status'],
        ], ['status' => $status], $note);

        return self::present(self::require($ctx, $caseId));
    }

    /**
     * Counts per kind, for the Exception Centre.
     *
     * @return list<array{kind:string, label:string, count:int, amount:float, severity:string}>
     */
    public static function summary(Context $ctx): array
    {
        $rows = Db::all(
            'SELECT case_kind,
                    COUNT(*) AS count,
                    COALESCE(SUM(ABS(COALESCE(difference_minor, actual_minor, 0))), 0) AS amount,
                    MAX(CASE severity WHEN \'HIGH\' THEN 3 WHEN \'MEDIUM\' THEN 2 ELSE 1 END) AS worst
             FROM ' . self::TABLE . '
             WHERE cmp_id = :cmp AND status IN (:open, :investigating)
             GROUP BY case_kind
             ORDER BY worst DESC, count DESC',
            ['cmp' => $ctx->cmpId, 'open' => self::OPEN, 'investigating' => self::INVESTIGATING],
        );

        $out = [];
        foreach ($rows as $row) {
            $kind = (string) $row['case_kind'];
            $out[] = [
                'kind'     => $kind,
                'label'    => self::LABELS[$kind] ?? ucfirst(strtolower(str_replace('_', ' ', $kind))),
                'count'    => (int) $row['count'],
                'amount'   => Money::minor((int) $row['amount'])->toMajor(),
                'severity' => match ((int) $row['worst']) { 3 => 'HIGH', 2 => 'MEDIUM', default => 'LOW' },
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $caseId): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE case_id = :id AND cmp_id = :cmp', ['id' => $caseId, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed> */
    public static function require(Context $ctx, int $caseId): array
    {
        $row = self::find($ctx, $caseId);
        if ($row === null) {
            Http::notFound('That reconciliation case could not be found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    public static function present(array $row): array
    {
        $currency = (string) $row['currency'];

        return [
            'case_id'    => (string) $row['case_uuid'],
            'kind'       => (string) $row['case_kind'],
            'label'      => self::LABELS[(string) $row['case_kind']] ?? (string) $row['case_kind'],
            'severity'   => (string) $row['severity'],
            'status'     => (string) $row['status'],
            'summary'    => (string) $row['summary'],
            'expected'   => $row['expected_minor'] === null ? null : Money::minor((int) $row['expected_minor'], $currency)->toMajor(),
            'actual'     => $row['actual_minor'] === null ? null : Money::minor((int) $row['actual_minor'], $currency)->toMajor(),
            'difference' => $row['difference_minor'] === null ? null : Money::minor((int) $row['difference_minor'], $currency)->toMajor(),
            'currency'   => $currency,
            'detail'     => Db::jsonColumn($row['detail'] ?? null),
            'assigned_to' => $row['assigned_to'] === null ? null : (string) $row['assigned_to'],
            'resolution_note' => $row['resolution_note'] === null ? null : (string) $row['resolution_note'],
            'opened_at'  => (string) $row['opened_at'],
            'resolved_at' => $row['resolved_at'] === null ? null : (string) $row['resolved_at'],
        ];
    }
}
