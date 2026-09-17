<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Domain\Money;

/**
 * A figure, plus everything needed to read it honestly.
 *
 * THE THREE RULES THIS CLASS EXISTS TO ENFORCE:
 *
 *  1. A FIGURE THAT COULD NOT BE READ IS NOT ZERO. `unavailable()` produces a
 *     metric the screen renders as "Unavailable" with a reason. Rendering a
 *     failed read as ₹0.00 is indistinguishable from a quiet day, and a
 *     merchant who sees ₹0 collected does not think "the query failed", they
 *     think their business stopped.
 *
 *  2. THE SERVER DECIDES WHETHER A RISE IS GOOD. +14% on collections is green;
 *     +14% on failed payments is not. The browser cannot know which, so `tone`
 *     travels with the comparison. Without it a rise in refund exceptions gets
 *     painted the same green as a rise in revenue.
 *
 *  3. A COMPARISON IS REFUSED WHEN IT WOULD MISLEAD. A percentage against a
 *     previous period of zero is not "+100%", it is undefined — and drawing an
 *     arrow for it is the most common lie on a dashboard.
 */
final class Metric
{
    public const BASIS_PERIOD = 'period';   // a movement over the window
    public const BASIS_AS_OF  = 'as_of';    // a balance right now
    public const BASIS_WINDOW = 'window';   // something falling due soon
    public const BASIS_COUNT  = 'count';

    /**
     * @param array<string, mixed>|null $comparison
     * @return array<string, mixed>
     */
    public static function money(
        string $id,
        string $label,
        int $minor,
        string $definition,
        string $basis = self::BASIS_PERIOD,
        ?int $previousMinor = null,
        string $currency = 'INR',
        string $risingIs = 'good',
        ?string $detail = null,
    ): array {
        return [
            'id'         => $id,
            'label'      => $label,
            'value'      => Money::minor($minor, $currency)->toMajor(),
            'format'     => 'money',
            'currency'   => $currency,
            'status'     => 'ready',
            'basis'      => $basis,
            'definition' => $definition,
            'detail'     => $detail,
            'comparison' => self::compare($minor, $previousMinor, $risingIs),
        ];
    }

    /** @return array<string, mixed> */
    public static function count(
        string $id,
        string $label,
        int $value,
        string $definition,
        ?int $previous = null,
        string $risingIs = 'good',
        ?string $detail = null,
    ): array {
        return [
            'id'         => $id,
            'label'      => $label,
            'value'      => $value,
            'format'     => 'count',
            'currency'   => null,
            'status'     => 'ready',
            'basis'      => self::BASIS_COUNT,
            'definition' => $definition,
            'detail'     => $detail,
            'comparison' => self::compare($value, $previous, $risingIs),
        ];
    }

    /** @return array<string, mixed> */
    public static function percent(
        string $id,
        string $label,
        ?float $value,
        string $definition,
        ?float $previous = null,
        string $risingIs = 'good',
        ?string $detail = null,
    ): array {
        if ($value === null) {
            // A rate with no denominator. "0% success" and "nobody tried" are
            // different facts and only one of them is alarming.
            return self::unavailable($id, $label, 'Nothing to measure in this period yet.', $definition);
        }

        $comparison = null;
        if ($previous !== null) {
            $delta = round($value - $previous, 1);
            $comparison = [
                'available' => true,
                // Percentage points, not a percentage of a percentage. A
                // success rate going from 92% to 96% rose by 4 points, not 4.3%,
                // and saying the latter invites arithmetic nobody wants.
                'label'     => ($delta >= 0 ? '+' : '') . number_format($delta, 1) . ' pts',
                'direction' => $delta >= 0 ? 'up' : 'down',
                'tone'      => self::toneFor($delta >= 0, $risingIs),
                'previous'  => $previous,
            ];
        }

        return [
            'id'         => $id,
            'label'      => $label,
            'value'      => round($value, 1),
            'format'     => 'percent',
            'currency'   => null,
            'status'     => 'ready',
            'basis'      => self::BASIS_PERIOD,
            'definition' => $definition,
            'detail'     => $detail,
            'comparison' => $comparison,
        ];
    }

    /**
     * A figure that could not be read.
     *
     * The reason is shown on the card. "Razorpay did not answer" tells a
     * merchant to wait; a zero tells them their business stopped.
     *
     * @return array<string, mixed>
     */
    public static function unavailable(string $id, string $label, string $reason, string $definition = ''): array
    {
        return [
            'id'         => $id,
            'label'      => $label,
            'value'      => null,
            'format'     => 'money',
            'currency'   => null,
            'status'     => 'unavailable',
            'basis'      => self::BASIS_AS_OF,
            'definition' => $definition,
            'reason'     => $reason,
            'detail'     => null,
            'comparison' => null,
        ];
    }

    /**
     * The like-for-like change, or an honest refusal to draw one.
     *
     * @return array<string, mixed>|null
     */
    private static function compare(int|float $current, int|float|null $previous, string $risingIs): ?array
    {
        if ($previous === null) {
            return null;
        }

        if ($previous == 0) {
            // Undefined, not infinite, and certainly not +100%. The figure is
            // still shown; the arrow is not.
            return [
                'available' => false,
                'label'     => $current > 0 ? 'First activity in this period' : 'Nothing in either period',
                'detail'    => 'There was nothing in the previous period to compare against.',
            ];
        }

        $delta = (($current - $previous) / abs($previous)) * 100;
        $rounded = round($delta, 1);

        return [
            'available' => true,
            'label'     => ($rounded >= 0 ? '+' : '') . number_format($rounded, 1) . '%',
            'percent'   => $rounded,
            'direction' => $rounded >= 0 ? 'up' : 'down',
            'tone'      => self::toneFor($rounded >= 0, $risingIs),
            'previous'  => $previous,
        ];
    }

    /**
     * Whether this direction is welcome.
     *
     * The whole point: a rise in failed payments is a warning, and only the
     * server knows that. `risingIs` is 'good' for collections and 'bad' for
     * failures, exceptions and refunds.
     */
    private static function toneFor(bool $rising, string $risingIs): string
    {
        if ($risingIs === 'bad') {
            return $rising ? 'warning' : 'positive';
        }
        if ($risingIs === 'neutral') {
            return 'neutral';
        }

        return $rising ? 'positive' : 'warning';
    }
}
