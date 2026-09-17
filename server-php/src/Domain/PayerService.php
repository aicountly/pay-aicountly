<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * The counterparty, as Pay knows them.
 *
 * READ THE TABLE COMMENT IN MIGRATION 001 FIRST. The short version: this is not
 * a customer master and must never become one.
 *
 * Two kinds of payer exist, and the difference is who owns the truth:
 *
 *   SOURCE      Books, Billing, Sales or POS owns this customer. Pay keeps the
 *               app and their id, plus a snapshot of the contact details the
 *               payment link was actually sent to. The snapshot is part of the
 *               audit trail of that payment — "we sent it to this number" — and
 *               is NOT a current address book. When a screen needs today's
 *               details, it asks the owning app.
 *
 *   STANDALONE  Nobody else has a record. A walk-in paying a QR, a customer of
 *               an app with no customer master. Pay owns this one because
 *               otherwise nobody does.
 *
 * WHAT THIS CLASS WILL NOT DO: update a SOURCE payer's name from a payment.
 * If a customer types a different name at checkout, that is what they typed at
 * checkout — it goes on the attempt, not over Books' customer master. Letting a
 * payment page edit an accounting master is how a customer becomes "asdf".
 */
final class PayerService
{
    public const TABLE = 'pay_payers';

    public const KIND_SOURCE = 'SOURCE';
    public const KIND_STANDALONE = 'STANDALONE';

    /**
     * Find or create the payer for a payment request.
     *
     * @param array{source_app?:?string, customer_ref?:?string, name?:?string,
     *     email?:?string, mobile?:?string} $payer
     */
    public static function resolve(Context $ctx, array $payer): ?int
    {
        $sourceApp = self::clean($payer['source_app'] ?? null);
        $customerRef = self::clean($payer['customer_ref'] ?? null);
        $name = self::clean($payer['name'] ?? null);
        $email = self::cleanEmail($payer['email'] ?? null);
        $mobile = self::cleanMobile($payer['mobile'] ?? null);

        if ($sourceApp !== null && $customerRef !== null && $sourceApp !== 'PAY') {
            return self::resolveSource($ctx, $sourceApp, $customerRef, $name, $email, $mobile);
        }

        return self::resolveStandalone($ctx, $name, $email, $mobile);
    }

    /**
     * A payer that belongs to another app.
     *
     * The upsert keys on (company, app, their id) — never on the email or the
     * name — so two Books customers who happen to share a mobile number stay two
     * customers, and one customer whose email changes stays one.
     */
    private static function resolveSource(
        Context $ctx,
        string $sourceApp,
        string $customerRef,
        ?string $name,
        ?string $email,
        ?string $mobile,
    ): int {
        $existing = Db::first(
            'SELECT payer_id FROM ' . self::TABLE . '
             WHERE cmp_id = :cmp AND source_app = :app AND source_customer_ref = :ref',
            ['cmp' => $ctx->cmpId, 'app' => $sourceApp, 'ref' => $customerRef],
        );

        if ($existing !== null) {
            $payerId = (int) $existing['payer_id'];

            // Contact details are refreshed — they are the snapshot of where we
            // can reach this payer — but only from values we actually have.
            // COALESCE keeps the previous number when this request carried
            // none, rather than blanking a good one.
            $updates = array_filter([
                'display_name' => $name,
                'email'        => $email,
                'mobile'       => $mobile,
            ], static fn ($v) => $v !== null);

            if ($updates !== []) {
                $updates['updated_at'] = gmdate('Y-m-d H:i:s');
                Db::update(self::TABLE, $updates, ['payer_id' => $payerId]);
            }

            return $payerId;
        }

        return (int) Db::insert(self::TABLE, [
            'payer_uuid'          => Ids::mint(Ids::PAYER),
            'cmp_id'              => $ctx->cmpId,
            'payer_kind'          => self::KIND_SOURCE,
            'source_app'          => $sourceApp,
            'source_customer_ref' => $customerRef,
            // The name is a display label for Pay's own screens. The owning app
            // remains the authority and is asked when it matters.
            'display_name'        => $name ?? ($sourceApp . ' customer ' . $customerRef),
            'email'               => $email,
            'mobile'              => $mobile,
        ], 'payer_id');
    }

    /**
     * A payer only Pay knows about.
     *
     * Matched on mobile first, then email, within the company. Mobile first
     * because in India that is the identifier a payer actually has and reuses,
     * and because two people share a household email far more often than a
     * phone.
     *
     * With neither, a new row is created every time — correct for a walk-in QR
     * payment, where there genuinely is no identity to match on and pretending
     * otherwise would merge unrelated strangers into one "customer".
     */
    private static function resolveStandalone(Context $ctx, ?string $name, ?string $email, ?string $mobile): ?int
    {
        if ($name === null && $email === null && $mobile === null) {
            // An anonymous payment against a static QR. There is no payer to
            // record, and inventing an empty one would fill the Customers
            // screen with rows nobody can act on.
            return null;
        }

        if ($mobile !== null || $email !== null) {
            $existing = Db::first(
                'SELECT payer_id FROM ' . self::TABLE . '
                 WHERE cmp_id = :cmp AND payer_kind = :kind
                   AND ((:mobile <> \'\' AND mobile = :mobile) OR (:email <> \'\' AND email = :email))
                 ORDER BY payer_id ASC LIMIT 1',
                [
                    'cmp'    => $ctx->cmpId,
                    'kind'   => self::KIND_STANDALONE,
                    'mobile' => $mobile ?? '',
                    'email'  => $email ?? '',
                ],
            );

            if ($existing !== null) {
                $payerId = (int) $existing['payer_id'];
                $updates = array_filter([
                    'display_name' => $name,
                    'email'        => $email,
                    'mobile'       => $mobile,
                ], static fn ($v) => $v !== null);
                $updates['updated_at'] = gmdate('Y-m-d H:i:s');
                Db::update(self::TABLE, $updates, ['payer_id' => $payerId]);

                return $payerId;
            }
        }

        return (int) Db::insert(self::TABLE, [
            'payer_uuid'   => Ids::mint(Ids::PAYER),
            'cmp_id'       => $ctx->cmpId,
            'payer_kind'   => self::KIND_STANDALONE,
            'display_name' => $name ?? ($mobile ?? $email ?? 'Payer'),
            'email'        => $email,
            'mobile'       => $mobile,
        ], 'payer_id');
    }

    /**
     * Update the behaviour summary after a successful payment.
     *
     * Every figure here is computed from THIS company's own attempts. Nothing
     * is read from another product and nothing is shared between companies —
     * "customers like yours pay faster on UPI" would require a cross-tenant
     * model, and this product does not have one.
     *
     * Rebuildable from pay_payment_attempts at any time, which is what makes it
     * a cache rather than a second source of truth.
     */
    public static function recordPayment(Context $ctx, int $payerId, int $amountMinor, string $method, string $paidAt): void
    {
        if ($payerId <= 0) {
            return;
        }

        try {
            Db::run(
                'UPDATE ' . self::TABLE . '
                 SET payment_count    = payment_count + 1,
                     total_paid_minor = total_paid_minor + :amount,
                     first_paid_at    = COALESCE(first_paid_at, :paid_at::timestamptz),
                     last_paid_at     = GREATEST(COALESCE(last_paid_at, :paid_at::timestamptz), :paid_at::timestamptz),
                     preferred_method = :method,
                     updated_at       = NOW()
                 WHERE payer_id = :id AND cmp_id = :cmp',
                [
                    'amount'  => $amountMinor,
                    'paid_at' => $paidAt,
                    'method'  => $method,
                    'id'      => $payerId,
                    'cmp'     => $ctx->cmpId,
                ],
            );
        } catch (\Throwable $e) {
            // A behaviour counter is not worth failing a payment over.
            error_log('[payer] could not update behaviour for payer ' . $payerId . ': ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, int $payerId): ?array
    {
        return Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE payer_id = :id AND cmp_id = :cmp',
            ['id' => $payerId, 'cmp' => $ctx->cmpId],
        );
    }

    /** @return array<string, mixed>|null */
    public static function findByUuid(Context $ctx, string $uuid): ?array
    {
        return Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE payer_uuid = :uuid AND cmp_id = :cmp',
            ['uuid' => $uuid, 'cmp' => $ctx->cmpId],
        );
    }

    /** The shape a screen or a callback sees. Never the raw row. */
    public static function present(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'payer_id'     => (string) $row['payer_uuid'],
            'name'         => (string) $row['display_name'],
            'email'        => $row['email'] === null ? null : (string) $row['email'],
            // Masked on the way out. A payments list is a screen a lot of staff
            // can see, and it does not need to be an exportable phone book.
            'mobile'       => self::maskMobile($row['mobile'] === null ? null : (string) $row['mobile']),
            'kind'         => (string) $row['payer_kind'],
            'source_app'   => $row['source_app'] === null ? null : (string) $row['source_app'],
            'customer_ref' => $row['source_customer_ref'] === null ? null : (string) $row['source_customer_ref'],
            'payment_count' => (int) $row['payment_count'],
            'total_paid'   => Money::minor((int) $row['total_paid_minor'])->toMajor(),
            'last_paid_at' => $row['last_paid_at'] === null ? null : (string) $row['last_paid_at'],
            'segment'      => (string) $row['behaviour_segment'],
            'preferred_method' => $row['preferred_method'] === null ? null : (string) $row['preferred_method'],
        ];
    }

    // -----------------------------------------------------------------------

    private static function clean(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : substr($trimmed, 0, 180);
    }

    private static function cleanEmail(mixed $value): ?string
    {
        $email = self::clean($value);
        if ($email === null) {
            return null;
        }

        // Stored only when it is actually an address. A malformed one is worse
        // than none: it looks like a way to reach the payer and is not.
        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : strtolower($email);
    }

    /**
     * Digits only, and long enough to be a number.
     *
     * Kept in the form it arrived — with or without a country code — because
     * normalising to E.164 requires knowing the country, and guessing India for
     * a merchant selling abroad would silently corrupt every foreign number.
     */
    private static function cleanMobile(mixed $value): ?string
    {
        $raw = self::clean($value);
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', $raw) ?? '';

        return strlen(preg_replace('/\D/', '', $digits) ?? '') < 7 ? null : substr($digits, 0, 20);
    }

    private static function maskMobile(?string $mobile): ?string
    {
        if ($mobile === null || $mobile === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $mobile) ?? '';
        if (strlen($digits) <= 4) {
            return str_repeat('•', strlen($digits));
        }

        return str_repeat('•', strlen($digits) - 4) . substr($digits, -4);
    }
}
