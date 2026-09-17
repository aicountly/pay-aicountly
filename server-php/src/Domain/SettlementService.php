<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Payments\Events\EventNames;
use Aicountly\Api\Payments\Events\Outbox;
use Aicountly\Api\Payments\Providers\ProviderRegistry;

/**
 * Where the money went after it was collected.
 *
 * THE CHAIN PAY TRACKS:
 *
 *     payment  →  provider batch  →  expected net  →  bank credit
 *
 * A merchant collected ₹8,42,320 today. Razorpay will settle ₹2,99,350 of it on
 * Thursday, having deducted their fee and the tax on that fee. Did ₹2,99,350
 * actually arrive? That is the whole question, and it is the one merchants ask
 * constantly and can rarely answer, because the provider's dashboard shows one
 * end and the bank statement shows the other.
 *
 * THE RULE THAT KEEPS RECONCILIATION HONEST: no tax rate, no fee percentage and
 * no settlement cycle is computed here. `provider_fee_minor` and
 * `provider_tax_minor` are what the PROVIDER reported on their own record.
 *
 * Computing 18% of the fee and calling it GST would disagree with the
 * provider's own rounding on most batches, and the reconciler would then flag
 * every batch it ever saw — which trains merchants to ignore it, which is worse
 * than not having it.
 */
final class SettlementService
{
    public const TABLE = 'pay_settlements';
    public const ITEMS = 'pay_settlement_items';

    /**
     * How late a batch has to be before it is worth a case.
     *
     * Two days past the expected date. Settlements are routinely a day late
     * over a weekend or a bank holiday, and a case opened at T+1 would be
     * noise on every Monday.
     */
    private const DELAYED_AFTER_DAYS = 2;

    /**
     * Pull settlement batches from a provider and record them.
     *
     * @return array{ok:bool, imported:int, updated:int, error_code?:string, error_message?:string}
     */
    public static function importFromProvider(Context $ctx, array $connection, array $filters = []): array
    {
        $provider = ProviderRegistry::forConnection($connection);
        if ($provider === null) {
            return ['ok' => false, 'imported' => 0, 'updated' => 0, 'error_code' => 'provider_unavailable',
                    'error_message' => 'That provider could not be prepared. Check its credentials.'];
        }

        if (!($provider->capabilities()['settlement_api'] ?? false)) {
            // Said plainly rather than returning an empty list. An empty list
            // would have the reconciler conclude that nothing settled, and then
            // report every payment as unsettled.
            return ['ok' => false, 'imported' => 0, 'updated' => 0, 'error_code' => 'settlements_unsupported',
                    'error_message' => ProviderRegistry::displayNameFor((string) $connection['provider_code'])
                        . ' does not expose settlements through its API. Import the settlement file instead.'];
        }

        $result = $provider->fetchSettlement($filters + [
            'from' => $filters['from'] ?? gmdate('Y-m-d', strtotime('-30 days')),
            'to'   => $filters['to'] ?? gmdate('Y-m-d'),
        ]);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'imported' => 0, 'updated' => 0,
                    'error_code' => (string) ($result['error_code'] ?? 'provider_error'),
                    'error_message' => (string) ($result['error_message'] ?? 'Settlements could not be read.')];
        }

        $imported = 0;
        $updated = 0;

        foreach ($result['settlements'] ?? [] as $batch) {
            $outcome = self::record($ctx, $connection, $batch);
            $outcome === 'inserted' ? $imported++ : $updated++;
        }

        return ['ok' => true, 'imported' => $imported, 'updated' => $updated];
    }

    /**
     * Record one batch.
     *
     * Idempotent on (company, provider, provider settlement id), so importing
     * the same statement twice is a no-op rather than a doubled figure.
     *
     * @param array<string, mixed> $batch
     * @return 'inserted'|'updated'
     */
    public static function record(Context $ctx, array $connection, array $batch): string
    {
        $providerSettlementId = (string) ($batch['provider_settlement_id'] ?? '');
        $currency = Money::normaliseCurrency((string) ($batch['currency'] ?? 'INR'));

        $gross = (int) ($batch['amount_minor'] ?? 0);
        $fee = (int) ($batch['fee_minor'] ?? 0);
        $tax = (int) ($batch['tax_minor'] ?? 0);
        $refunds = (int) ($batch['refund_minor'] ?? 0);
        $adjustment = (int) ($batch['adjustment_minor'] ?? 0);

        // Expected net is arithmetic over what the provider stated, not a
        // formula of our own. Every term comes from them.
        $expectedNet = $gross - $refunds - $fee - $tax + $adjustment;

        $existing = $providerSettlementId === '' ? null : Db::first(
            'SELECT settlement_id, status FROM ' . self::TABLE . '
             WHERE cmp_id = :cmp AND provider_code = :code AND provider_settlement_id = :pid',
            ['cmp' => $ctx->cmpId, 'code' => (string) $connection['provider_code'], 'pid' => $providerSettlementId],
        );

        $values = [
            'connection_id'      => (int) $connection['connection_id'],
            'provider_code'      => (string) $connection['provider_code'],
            'provider_mode'      => (string) $connection['provider_mode'],
            'settlement_date'    => isset($batch['settlement_date']) ? gmdate('Y-m-d', strtotime((string) $batch['settlement_date']) ?: time()) : null,
            'status'             => (string) ($batch['status'] ?? States::SETTLEMENT_PROCESSING),
            'currency'           => $currency,
            'gross_minor'        => $gross,
            'refund_minor'       => $refunds,
            'provider_fee_minor' => $fee,
            'provider_tax_minor' => $tax,
            'adjustment_minor'   => $adjustment,
            'expected_net_minor' => $expectedNet,
            'bank_reference'     => isset($batch['utr']) && $batch['utr'] !== '' ? (string) $batch['utr'] : null,
            'imported_at'        => gmdate('Y-m-d H:i:s'),
            'updated_at'         => gmdate('Y-m-d H:i:s'),
        ];

        if ($existing !== null) {
            Db::update(self::TABLE, $values, ['settlement_id' => (int) $existing['settlement_id']]);
            self::assess($ctx, (int) $existing['settlement_id']);

            return 'updated';
        }

        $settlementId = (int) Db::insert(self::TABLE, $values + [
            'settlement_uuid'        => Ids::mint(Ids::SETTLEMENT),
            'cmp_id'                 => $ctx->cmpId,
            'provider_settlement_id' => $providerSettlementId === '' ? null : $providerSettlementId,
        ], 'settlement_id');

        self::assess($ctx, $settlementId);

        return 'inserted';
    }

    /** A settlement arriving as a webhook rather than a poll. */
    public static function importFromWebhook(Context $ctx, array $connection, array $normalized): void
    {
        $providerSettlementId = (string) ($normalized['provider_settlement_id'] ?? '');
        if ($providerSettlementId === '') {
            return;
        }

        // The webhook usually carries less than the settlement API does, so
        // this records what arrived and then asks the provider for the full
        // batch. Recording the thin version alone would leave the fee at zero
        // and make every batch look like a mismatch.
        self::record($ctx, $connection, [
            'provider_settlement_id' => $providerSettlementId,
            'settlement_date'        => $normalized['occurred_at'] ?? gmdate('c'),
            'status'                 => States::SETTLEMENT_PROCESSING,
            'currency'               => (string) ($normalized['currency'] ?? 'INR'),
            'amount_minor'           => (int) ($normalized['amount_minor'] ?? 0),
        ]);

        self::importFromProvider($ctx, $connection, ['settlement_id' => $providerSettlementId]);
    }

    /**
     * Record that the bank credit arrived.
     *
     * This is the step no provider can do for us: only the merchant, or their
     * bank feed, knows what actually landed. Everything before this is the
     * provider's word for it.
     */
    public static function confirmBankCredit(
        Context $ctx,
        Auth $auth,
        int $settlementId,
        Money $credited,
        string $reference,
        ?string $creditedAt,
        string $matchedBy = 'MANUAL',
    ): array {
        $settlement = self::require($ctx, $settlementId);
        $currency = (string) $settlement['currency'];

        $expected = Money::minor((int) $settlement['expected_net_minor'], $currency);
        $difference = $credited->minus($expected);

        $status = match (true) {
            $difference->isZero()     => States::SETTLEMENT_SETTLED,
            $credited->isZero()       => States::SETTLEMENT_FAILED,
            $credited->greaterThan($expected) => States::SETTLEMENT_RECONCILIATION_REQUIRED,
            default                   => States::SETTLEMENT_PARTIALLY_SETTLED,
        };

        Db::update(self::TABLE, [
            'settled_net_minor' => $credited->minor,
            'difference_minor'  => $difference->minor,
            'status'            => $status,
            'bank_reference'    => substr($reference, 0, 120),
            'bank_credited_at'  => $creditedAt ?? gmdate('Y-m-d H:i:s'),
            'bank_matched_by'   => $matchedBy,
            'bank_matched_at'   => gmdate('Y-m-d H:i:s'),
            'updated_at'        => gmdate('Y-m-d H:i:s'),
        ], ['settlement_id' => $settlementId, 'cmp_id' => $ctx->cmpId]);

        Audit::record($ctx, $auth, 'settlement.bank_confirmed', 'settlement', $settlementId, null, [
            'credited'  => $credited->toMajor(),
            'expected'  => $expected->toMajor(),
            'reference' => $reference,
        ]);

        if (!$difference->isZero()) {
            ReconciliationService::openCase($ctx, [
                'case_kind'        => ReconciliationService::SETTLEMENT_AMOUNT_MISMATCH,
                'severity'         => 'HIGH',
                'settlement_id'    => $settlementId,
                'expected_minor'   => $expected->minor,
                'actual_minor'     => $credited->minor,
                'difference_minor' => $difference->minor,
                'currency'         => $currency,
                'summary'          => sprintf(
                    '%s was expected from %s but %s was credited — a difference of %s.',
                    $expected->format(),
                    ProviderRegistry::displayNameFor((string) $settlement['provider_code']),
                    $credited->format(),
                    Money::minor(abs($difference->minor), $currency)->format(),
                ),
                'detail'           => ['bank_reference' => $reference],
            ]);

            Outbox::queue($ctx, EventNames::SETTLEMENT_MISMATCH, [], merchantOnly: true);
        } else {
            Outbox::queue($ctx, EventNames::SETTLEMENT_RECEIVED, [], merchantOnly: true);
        }

        $fresh = self::require($ctx, $settlementId);
        self::markItemsSettled($ctx, $settlementId, $status === States::SETTLEMENT_SETTLED);

        return self::present($fresh);
    }

    /**
     * Look at a batch and raise whatever it deserves.
     *
     * Runs after every import. Each check is separate because each has a
     * different fix, and telling a merchant "something is wrong with settlement
     * 4821" helps nobody.
     */
    public static function assess(Context $ctx, int $settlementId): void
    {
        $settlement = self::find($ctx, $settlementId);
        if ($settlement === null) {
            return;
        }

        $currency = (string) $settlement['currency'];
        $status = (string) $settlement['status'];
        $expectedDate = $settlement['settlement_date'] === null ? null : strtotime((string) $settlement['settlement_date']);

        // Late, and still not credited.
        if ($expectedDate !== null
            && $settlement['bank_credited_at'] === null
            && $expectedDate < strtotime('-' . self::DELAYED_AFTER_DAYS . ' days')
            && !in_array($status, [States::SETTLEMENT_SETTLED, States::SETTLEMENT_FAILED], true)) {
            $days = (int) floor((time() - $expectedDate) / 86400);

            ReconciliationService::openCase($ctx, [
                'case_kind'      => ReconciliationService::DELAYED_SETTLEMENT,
                'severity'       => $days > 5 ? 'HIGH' : 'MEDIUM',
                'settlement_id'  => $settlementId,
                'expected_minor' => (int) $settlement['expected_net_minor'],
                'currency'       => $currency,
                'summary'        => sprintf(
                    '%s from %s was due %d days ago and has not been credited.',
                    Money::minor((int) $settlement['expected_net_minor'], $currency)->format(),
                    ProviderRegistry::displayNameFor((string) $settlement['provider_code']),
                    $days,
                ),
                'detail'         => ['settlement_date' => (string) $settlement['settlement_date']],
            ]);
        }

        // A fee that is a surprising share of the batch. Deliberately a wide
        // threshold: this catches a pricing change or a wrongly-applied rate,
        // not ordinary variation between card and UPI mixes.
        $gross = (int) $settlement['gross_minor'];
        $charged = (int) $settlement['provider_fee_minor'] + (int) $settlement['provider_tax_minor'];
        if ($gross > 0 && $charged > 0 && ($charged / $gross) > 0.05) {
            ReconciliationService::openCase($ctx, [
                'case_kind'      => ReconciliationService::UNEXPECTED_PROVIDER_FEE,
                'severity'       => 'MEDIUM',
                'settlement_id'  => $settlementId,
                'expected_minor' => $gross,
                'actual_minor'   => $charged,
                'currency'       => $currency,
                'summary'        => sprintf(
                    '%s charged %s on a %s batch — %.1f%%, which is higher than usual.',
                    ProviderRegistry::displayNameFor((string) $settlement['provider_code']),
                    Money::minor($charged, $currency)->format(),
                    Money::minor($gross, $currency)->format(),
                    ($charged / $gross) * 100,
                ),
                'detail'         => ['fee' => (int) $settlement['provider_fee_minor'], 'tax' => (int) $settlement['provider_tax_minor']],
            ]);
        }
    }

    /**
     * Payments that should have settled by now and have not.
     *
     * The other half of the question: not "is this batch late" but "is this
     * payment in any batch at all". A payment that no settlement ever claimed
     * is money the provider took and has not accounted for.
     *
     * @return int cases opened
     */
    public static function findUnsettledPayments(Context $ctx, int $olderThanDays = 7): int
    {
        $rows = Db::all(
            'SELECT a.attempt_id, a.amount_minor, a.currency, a.paid_at, a.provider_code, a.attempt_uuid
             FROM ' . PaymentService::TABLE . ' a
             WHERE a.cmp_id = :cmp
               AND a.status = ANY(:settled)
               AND a.settlement_id IS NULL
               AND a.provider_mode <> :external
               AND a.paid_at < NOW() - (:days || \' days\')::interval
             ORDER BY a.paid_at ASC
             LIMIT 200',
            [
                'cmp'      => $ctx->cmpId,
                'settled'  => '{' . implode(',', States::ATTEMPT_SETTLED_MONEY) . '}',
                'external' => 'EXTERNAL',
                'days'     => $olderThanDays,
            ],
        );

        $opened = 0;
        foreach ($rows as $row) {
            $amount = Money::minor((int) $row['amount_minor'], (string) $row['currency']);

            ReconciliationService::openCase($ctx, [
                'case_kind'    => ReconciliationService::UNRECONCILED_TRANSACTION,
                'severity'     => 'MEDIUM',
                'attempt_id'   => (int) $row['attempt_id'],
                'actual_minor' => $amount->minor,
                'currency'     => (string) $row['currency'],
                'summary'      => sprintf(
                    '%s collected on %s through %s has not appeared in any settlement.',
                    $amount->format(),
                    gmdate('j M', strtotime((string) $row['paid_at'])),
                    ProviderRegistry::displayNameFor((string) ($row['provider_code'] ?? 'UNKNOWN')),
                ),
                'detail'       => ['payment_id' => (string) $row['attempt_uuid']],
            ]);
            $opened++;
        }

        return $opened;
    }

    /** Refunds that went out and were never deducted from a settlement. */
    public static function findUnsettledRefunds(Context $ctx, int $olderThanDays = 10): int
    {
        $rows = Db::all(
            'SELECT refund_id, amount_minor, currency, refunded_at, provider_code, refund_uuid
             FROM ' . RefundService::TABLE . '
             WHERE cmp_id = :cmp AND status = :refunded AND settlement_id IS NULL
               AND refunded_at < NOW() - (:days || \' days\')::interval
             ORDER BY refunded_at ASC LIMIT 200',
            ['cmp' => $ctx->cmpId, 'refunded' => States::REFUND_REFUNDED, 'days' => $olderThanDays],
        );

        $opened = 0;
        foreach ($rows as $row) {
            $amount = Money::minor((int) $row['amount_minor'], (string) $row['currency']);

            ReconciliationService::openCase($ctx, [
                'case_kind'    => ReconciliationService::REFUND_NOT_SETTLED,
                'severity'     => 'MEDIUM',
                'refund_id'    => (int) $row['refund_id'],
                'actual_minor' => $amount->minor,
                'currency'     => (string) $row['currency'],
                'summary'      => sprintf(
                    'A %s refund sent on %s has not been deducted from any settlement.',
                    $amount->format(),
                    gmdate('j M', strtotime((string) $row['refunded_at'])),
                ),
                'detail'       => ['refund_id' => (string) $row['refund_uuid']],
            ]);
            $opened++;
        }

        return $opened;
    }

    private static function markItemsSettled(Context $ctx, int $settlementId, bool $fully): void
    {
        try {
            Db::run(
                'UPDATE ' . PaymentService::TABLE . '
                 SET settlement_status = :status, updated_at = NOW()
                 WHERE settlement_id = :id AND cmp_id = :cmp',
                ['status' => $fully ? 'SETTLED' : 'PARTIAL', 'id' => $settlementId, 'cmp' => $ctx->cmpId],
            );
        } catch (\Throwable $e) {
            error_log('[settlement] could not mark items settled: ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $settlementId): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE settlement_id = :id AND cmp_id = :cmp', ['id' => $settlementId, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed>|null */
    public static function findByUuid(Context $ctx, string $uuid): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE settlement_uuid = :uuid AND cmp_id = :cmp', ['uuid' => $uuid, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed> */
    public static function require(Context $ctx, int $settlementId): array
    {
        $row = self::find($ctx, $settlementId);
        if ($row === null) {
            Http::notFound('That settlement could not be found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    public static function present(array $row): array
    {
        $currency = (string) $row['currency'];
        $m = static fn (mixed $v): ?float => $v === null ? null : Money::minor((int) $v, $currency)->toMajor();

        return [
            'settlement_id'  => (string) $row['settlement_uuid'],
            'provider'       => (string) $row['provider_code'],
            'provider_name'  => ProviderRegistry::displayNameFor((string) $row['provider_code']),
            'provider_mode'  => (string) $row['provider_mode'],
            'status'         => (string) $row['status'],
            'status_label'   => States::label((string) $row['status']),
            'settlement_date' => $row['settlement_date'] === null ? null : (string) $row['settlement_date'],
            'currency'       => $currency,
            'gross'          => $m($row['gross_minor']),
            'refunds'        => $m($row['refund_minor']),
            'provider_fee'   => $m($row['provider_fee_minor']),
            'provider_tax'   => $m($row['provider_tax_minor']),
            'adjustment'     => $m($row['adjustment_minor']),
            'expected_net'   => $m($row['expected_net_minor']),
            'settled_net'    => $m($row['settled_net_minor']),
            'difference'     => $m($row['difference_minor']),
            'bank_reference' => $row['bank_reference'] === null ? null : (string) $row['bank_reference'],
            'bank_credited_at' => $row['bank_credited_at'] === null ? null : (string) $row['bank_credited_at'],
            'transaction_count' => (int) $row['transaction_count'],
        ];
    }
}
