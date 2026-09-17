<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * Per-company Pay settings, with defaults that are safe when nobody has chosen.
 *
 * The defaults matter more than the settings. A merchant who never opens the
 * Settings screen still has a product that behaves sensibly, and every default
 * here is chosen to fail towards caution: partial payments off, refund approval
 * off only because a one-person business has nobody to approve, and auto
 * routing off always — an AI that reroutes a merchant's money without them
 * asking is not a feature.
 */
final class Settings
{
    public const TABLE = 'pay_settings';

    /** @var array<int, array<string, mixed>> */
    private static array $cache = [];

    /** @return array<string, mixed> */
    public static function for(Context $ctx): array
    {
        if (isset(self::$cache[$ctx->cmpId])) {
            return self::$cache[$ctx->cmpId];
        }

        $row = Db::first('SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);

        return self::$cache[$ctx->cmpId] = $row ?? self::defaults();
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'checkout_display_name'   => null,
            'checkout_logo_url'       => null,
            'checkout_support_email'  => null,
            'checkout_support_phone'  => null,
            'checkout_terms_url'      => null,
            'default_currency'        => Money::DEFAULT_CURRENCY,
            // A week. Long enough that a customer who opens it on Monday can
            // pay on Friday; short enough that a forgotten link does not sit
            // collectable for a year.
            'default_link_expiry_hours' => 168,
            'allow_partial_by_default' => false,
            // ₹100. Below this the gateway fee eats most of the payment.
            'min_partial_minor'       => 10000,
            'refund_requires_approval' => false,
            'refund_approval_threshold_minor' => null,
            'auto_routing_enabled'    => false,
            'pay_pulse_enabled'       => true,
            'callback_retry_minutes'  => [1, 5, 30, 180, 720],
            'onboarded_at'            => null,
        ];
    }

    /**
     * Create the settings row for a company that has none.
     *
     * Also seeds the shipped roles, because a company with settings and no
     * roles has an owner who can do everything and nobody else who can do
     * anything — which looks like the permission system being broken.
     */
    public static function ensure(Context $ctx): array
    {
        $existing = Db::first('SELECT * FROM ' . self::TABLE . ' WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
        if ($existing !== null) {
            return $existing;
        }

        $defaults = self::defaults();
        Db::run(
            'INSERT INTO ' . self::TABLE . ' (cmp_id, default_currency, default_link_expiry_hours,
                 allow_partial_by_default, min_partial_minor, callback_retry_minutes, onboarded_at)
             VALUES (:cmp, :currency, :expiry, :partial, :min_partial, :retries, NOW())
             ON CONFLICT (cmp_id) DO NOTHING',
            [
                'cmp'         => $ctx->cmpId,
                'currency'    => $defaults['default_currency'],
                'expiry'      => $defaults['default_link_expiry_hours'],
                'partial'     => 'false',
                'min_partial' => $defaults['min_partial_minor'],
                'retries'     => json_encode($defaults['callback_retry_minutes']),
            ],
        );

        unset(self::$cache[$ctx->cmpId]);

        return self::for($ctx);
    }

    /** @param array<string, mixed> $values */
    public static function update(Context $ctx, array $values): array
    {
        self::ensure($ctx);

        $values['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::update(self::TABLE, $values, ['cmp_id' => $ctx->cmpId]);
        unset(self::$cache[$ctx->cmpId]);

        return self::for($ctx);
    }

    /** The retry schedule for outbound callbacks, in minutes. */
    public static function retrySchedule(Context $ctx): array
    {
        $configured = Db::jsonColumn(self::for($ctx)['callback_retry_minutes'] ?? null);
        $minutes = array_values(array_filter(array_map(
            static fn ($v) => is_numeric($v) ? max(1, (int) $v) : null,
            $configured,
        )));

        return $minutes === [] ? [1, 5, 30, 180, 720] : $minutes;
    }

    /** Test seam. */
    public static function forget(): void
    {
        self::$cache = [];
    }
}
