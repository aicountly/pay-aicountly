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
 * Mandates — a payer's standing authority to be debited.
 *
 * THE BOUNDARY, SPELLED OUT BECAUSE IT IS THE ONE PEOPLE GET WRONG:
 *
 *   BILLING owns the subscription. The plan, its price, its cycle, when the
 *   next invoice is raised, what renewal does, and what happens after three
 *   failed charges. Those are commercial decisions.
 *
 *   PAY owns the mandate. The payer said a provider may debit them, up to an
 *   amount, at a frequency. Pay holds the provider's token for that authority
 *   and knows whether the last debit worked.
 *
 * So there is no `plan_id`, no `billing_cycle` and no `next_invoice_date` in
 * this file or in its table, and there never should be. When a subscription
 * renews, Billing raises the bill and asks Pay to collect against the mandate.
 * Pay debits and reports. Pay does not decide that it is time to charge.
 *
 * `consecutive_failures` is counted here because it is a fact about the
 * MANDATE — the payer's bank keeps refusing — not about the subscription. What
 * to do about it after three is Billing's call.
 */
final class MandateService
{
    public const TABLE = 'pay_mandates';

    /**
     * Register a mandate the provider has created.
     *
     * @param array<string, mixed> $input
     */
    public static function register(Context $ctx, Auth $auth, array $input): array
    {
        $payerId = PayerService::resolve($ctx, [
            'source_app'   => $input['source_app'] ?? null,
            'customer_ref' => $input['customer_ref'] ?? null,
            'name'         => $input['payer_name'] ?? null,
            'email'        => $input['payer_email'] ?? null,
            'mobile'       => $input['payer_mobile'] ?? null,
        ]);

        $currency = Money::normaliseCurrency((string) ($input['currency'] ?? 'INR'));
        $maxAmount = isset($input['max_amount']) ? Money::fromMajor($input['max_amount'], $currency)->minor : null;

        $mandateId = (int) Db::insert(self::TABLE, [
            'mandate_uuid'       => Ids::mint(Ids::MANDATE),
            'cmp_id'             => $ctx->cmpId,
            'bo_id'              => $ctx->boId,
            'payer_id'           => $payerId,
            'source_app'         => isset($input['source_app']) ? strtoupper((string) $input['source_app']) : null,
            'source_type'        => $input['source_type'] ?? null,
            'source_id'          => $input['source_id'] ?? null,
            'source_reference'   => $input['source_reference'] ?? null,
            'connection_id'      => $input['connection_id'] ?? null,
            'provider_code'      => isset($input['provider_code']) ? strtoupper((string) $input['provider_code']) : null,
            'provider_mandate_id' => $input['provider_mandate_id'] ?? null,
            'provider_token_ref' => $input['provider_token_ref'] ?? null,
            'mandate_type'       => strtoupper((string) ($input['mandate_type'] ?? 'UPI_AUTOPAY')),
            'status'             => States::MANDATE_PENDING,
            // A ceiling the payer authorised, not a price. What is actually
            // charged each time comes from whoever raised the debit.
            'max_amount_minor'   => $maxAmount,
            'currency'           => $currency,
            'frequency'          => isset($input['frequency']) ? strtoupper((string) $input['frequency']) : null,
            'valid_from'         => isset($input['valid_from']) ? gmdate('Y-m-d', strtotime((string) $input['valid_from']) ?: time()) : null,
            'valid_until'        => isset($input['valid_until']) ? gmdate('Y-m-d', strtotime((string) $input['valid_until']) ?: time()) : null,
            'created_by'         => $auth->uuid,
        ], 'mandate_id');

        Outbox::queue($ctx, EventNames::MANDATE_CREATED, [], merchantOnly: true);

        return self::present(self::require($ctx, $mandateId));
    }

    /** @param array<string, mixed> $normalized */
    public static function applyProviderEvent(Context $ctx, array $connection, array $normalized): ?int
    {
        $providerMandateId = isset($normalized['provider_mandate_id']) ? (string) $normalized['provider_mandate_id'] : '';
        if ($providerMandateId === '') {
            return null;
        }

        $mandate = Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp AND provider_mandate_id = :pid',
            ['cmp' => $ctx->cmpId, 'pid' => $providerMandateId],
        );

        if ($mandate === null) {
            return null;
        }

        $status = match ((string) ($normalized['event'] ?? '')) {
            EventNames::MANDATE_ACTIVE    => States::MANDATE_ACTIVE,
            EventNames::MANDATE_CANCELLED => States::MANDATE_CANCELLED,
            EventNames::MANDATE_FAILED    => States::MANDATE_FAILED,
            default                       => null,
        };

        if ($status === null || !States::allows('mandate', (string) $mandate['status'], $status)) {
            return (int) $mandate['mandate_id'];
        }

        Db::update(self::TABLE, array_filter([
            'status'       => $status,
            'cancelled_at' => $status === States::MANDATE_CANCELLED ? gmdate('Y-m-d H:i:s') : null,
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null), ['mandate_id' => (int) $mandate['mandate_id']]);

        Outbox::queue($ctx, match ($status) {
            States::MANDATE_ACTIVE    => EventNames::MANDATE_ACTIVE,
            States::MANDATE_CANCELLED => EventNames::MANDATE_CANCELLED,
            default                   => EventNames::MANDATE_FAILED,
        }, []);

        return (int) $mandate['mandate_id'];
    }

    /** Record how a debit against this mandate went. */
    public static function recordDebit(Context $ctx, int $mandateId, bool $succeeded): void
    {
        try {
            Db::run(
                'UPDATE ' . self::TABLE . '
                 SET last_debit_at = NOW(),
                     last_debit_status = :status,
                     consecutive_failures = CASE WHEN :ok THEN 0 ELSE consecutive_failures + 1 END,
                     updated_at = NOW()
                 WHERE mandate_id = :id AND cmp_id = :cmp',
                [
                    'status' => $succeeded ? 'SUCCESS' : 'FAILED',
                    'ok'     => $succeeded ? 'true' : 'false',
                    'id'     => $mandateId,
                    'cmp'    => $ctx->cmpId,
                ],
            );
        } catch (\Throwable $e) {
            error_log('[mandate] could not record a debit outcome: ' . $e->getMessage());
        }
    }

    public static function setStatus(Context $ctx, Auth $auth, int $mandateId, string $status, string $reason = ''): array
    {
        Permissions::assert($ctx, $auth, 'mandates.manage');

        $mandate = self::require($ctx, $mandateId);
        $status = strtoupper($status);

        if (!States::allows('mandate', (string) $mandate['status'], $status)) {
            Http::conflict('A mandate that is ' . strtolower(States::label((string) $mandate['status'])) . ' cannot be moved to ' . strtolower(States::label($status)) . '.');
        }

        Db::update(self::TABLE, array_filter([
            'status'       => $status,
            'cancelled_at' => $status === States::MANDATE_CANCELLED ? gmdate('Y-m-d H:i:s') : null,
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null), ['mandate_id' => $mandateId, 'cmp_id' => $ctx->cmpId]);

        if ($status === States::MANDATE_CANCELLED) {
            // Billing has to know: a cancelled mandate means the next renewal
            // cannot be collected, and only Billing can decide what that does
            // to the subscription.
            Outbox::queue($ctx, EventNames::MANDATE_CANCELLED, ['reason' => $reason]);
        }

        return self::present(self::require($ctx, $mandateId));
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $mandateId): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE mandate_id = :id AND cmp_id = :cmp', ['id' => $mandateId, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed>|null */
    public static function findByUuid(Context $ctx, string $uuid): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE mandate_uuid = :uuid AND cmp_id = :cmp', ['uuid' => $uuid, 'cmp' => $ctx->cmpId]);
    }

    /** @return array<string, mixed> */
    public static function require(Context $ctx, int $mandateId): array
    {
        $row = self::find($ctx, $mandateId);
        if ($row === null) {
            Http::notFound('That mandate could not be found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    public static function present(array $row): array
    {
        return [
            'mandate_id'   => (string) $row['mandate_uuid'],
            'status'       => (string) $row['status'],
            'status_label' => States::label((string) $row['status']),
            'type'         => (string) $row['mandate_type'],
            'max_amount'   => $row['max_amount_minor'] === null
                ? null : Money::minor((int) $row['max_amount_minor'], (string) $row['currency'])->toMajor(),
            'currency'     => (string) $row['currency'],
            'frequency'    => $row['frequency'] === null ? null : (string) $row['frequency'],
            'valid_from'   => $row['valid_from'] === null ? null : (string) $row['valid_from'],
            'valid_until'  => $row['valid_until'] === null ? null : (string) $row['valid_until'],
            'provider'     => $row['provider_code'] === null ? null : (string) $row['provider_code'],
            'source_app'   => $row['source_app'] === null ? null : (string) $row['source_app'],
            'source_reference' => $row['source_reference'] === null ? null : (string) $row['source_reference'],
            'last_debit_at' => $row['last_debit_at'] === null ? null : (string) $row['last_debit_at'],
            'last_debit_status' => $row['last_debit_status'] === null ? null : (string) $row['last_debit_status'],
            'consecutive_failures' => (int) $row['consecutive_failures'],
            'created_at'   => (string) $row['created_at'],
        ];
    }
}
