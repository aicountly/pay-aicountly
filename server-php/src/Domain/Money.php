<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use InvalidArgumentException;

/**
 * Money, in the smallest unit of its currency.
 *
 * ₹1,180.00 is 118000 paise, stored as a BIGINT and moved around as an int.
 *
 * WHY NOT A DECIMAL, WHICH THE REST OF THE FLEET USES. Books stores
 * NUMERIC(18,4) and is right to: it computes tax, apportions discounts and
 * needs the extra places to round correctly at the end. Pay computes nothing of
 * the sort. It receives an amount somebody else already decided, hands it to a
 * provider, and reconciles what comes back against what went out.
 *
 * And the providers settle that question anyway. Razorpay, Stripe, Cashfree and
 * PayU all take and return integer minor units. Storing a decimal here would
 * mean converting at every boundary, and a payment system that converts money
 * at a boundary is a payment system with a rounding difference waiting in it.
 * The one place a fraction of a paisa appears is a provider's own fee, which
 * arrives from the provider already rounded and is stored exactly as sent.
 *
 * Currencies with no minor unit (JPY) and with three places (KWD, BHD) are
 * handled by EXPONENTS below rather than by assuming two everywhere, because
 * ¥1000 read as two-decimal would settle as ¥10.
 */
final class Money
{
    /** Minor-unit exponent per ISO 4217 code. Anything unlisted is two places. */
    private const EXPONENTS = [
        'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'ISK' => 0, 'UGX' => 0, 'RWF' => 0,
        'BHD' => 3, 'KWD' => 3, 'OMR' => 3, 'JOD' => 3, 'TND' => 3, 'IQD' => 3, 'LYD' => 3,
    ];

    public const DEFAULT_CURRENCY = 'INR';

    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {
    }

    public static function minor(int $minor, string $currency = self::DEFAULT_CURRENCY): self
    {
        return new self($minor, self::normaliseCurrency($currency));
    }

    public static function zero(string $currency = self::DEFAULT_CURRENCY): self
    {
        return new self(0, self::normaliseCurrency($currency));
    }

    /**
     * Read an amount a caller sent.
     *
     * Accepts 1180.50 and "1180.50" as major units, which is what a source app
     * and a human form both send. It does NOT accept 1180.505 for a two-place
     * currency: silently dropping a half-paisa is how the total of a list stops
     * matching the sum of its rows.
     *
     * @throws InvalidArgumentException with a message safe to show a user
     */
    public static function fromMajor(mixed $value, string $currency = self::DEFAULT_CURRENCY): self
    {
        $currency = self::normaliseCurrency($currency);

        if (is_int($value)) {
            return new self($value * (10 ** self::exponent($currency)), $currency);
        }

        if (!is_float($value) && !is_string($value)) {
            throw new InvalidArgumentException('That amount is not a number.');
        }

        $text = trim((string) $value);
        if ($text === '' || preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            throw new InvalidArgumentException('That amount is not a number.');
        }

        $exponent = self::exponent($currency);
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        $negative = str_starts_with($whole, '-');
        $whole = ltrim($whole, '-');

        if (strlen($fraction) > $exponent) {
            // Keep the check rather than rounding. A caller that sent more
            // places than the currency has is a caller with a bug, and rounding
            // it away here hides the bug inside a payment.
            if (rtrim(substr($fraction, $exponent), '0') !== '') {
                throw new InvalidArgumentException(sprintf(
                    '%s has %d decimal place%s, so %s cannot be paid exactly.',
                    $currency,
                    $exponent,
                    $exponent === 1 ? '' : 's',
                    $text,
                ));
            }
            $fraction = substr($fraction, 0, $exponent);
        }

        $minor = (int) ($whole . str_pad($fraction, $exponent, '0'));

        return new self($negative ? -$minor : $minor, $currency);
    }

    /** The exponent for a currency: 2 for most, 0 for JPY, 3 for KWD. */
    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[self::normaliseCurrency($currency)] ?? 2;
    }

    /** The major-unit value, for a JSON response a browser will format. */
    public function toMajor(): float
    {
        return $this->minor / (10 ** self::exponent($this->currency));
    }

    /**
     * Indian digit grouping for INR, the locale's own for everything else.
     *
     * Used in outbound messages — an SMS, a WhatsApp payment link, a receipt —
     * where the text is composed server-side. Screens format their own.
     */
    public function format(): string
    {
        $major = $this->toMajor();

        if ($this->currency === 'INR') {
            return '₹' . self::indianGrouping($major, self::exponent($this->currency));
        }

        return $this->currency . ' ' . number_format($major, self::exponent($this->currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function atLeast(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor >= $other->minor;
    }

    /** Never below zero. Outstanding on an over-paid request is nothing owed, not a negative debt. */
    public function clampToZero(): self
    {
        return $this->minor < 0 ? new self(0, $this->currency) : $this;
    }

    /**
     * What share of the whole this is, 0–100, to one place.
     *
     * Returns null rather than zero when the whole is zero: "0% collected of
     * nothing" is a figure a screen would draw as a bar, and there is no bar.
     */
    public function shareOf(self $whole): ?float
    {
        $this->assertSameCurrency($whole);
        if ($whole->minor === 0) {
            return null;
        }

        return round(($this->minor / $whole->minor) * 100, 1);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            // Adding USD to INR produces a number that looks like money and is
            // not. Refusing is the only honest option; conversion belongs to
            // whoever holds the rate, and that is not Pay.
            throw new InvalidArgumentException(
                'Cannot combine ' . $this->currency . ' with ' . $other->currency . '.',
            );
        }
    }

    public static function normaliseCurrency(string $currency): string
    {
        $upper = strtoupper(trim($currency));

        return preg_match('/^[A-Z]{3}$/', $upper) === 1 ? $upper : self::DEFAULT_CURRENCY;
    }

    public static function isSupportedCurrency(string $currency): bool
    {
        return preg_match('/^[A-Z]{3}$/', strtoupper(trim($currency))) === 1;
    }

    /** 842320.00 → 8,42,320.00 — lakhs and crores, not thousands. */
    private static function indianGrouping(float $value, int $places): string
    {
        $negative = $value < 0;
        $fixed = number_format(abs($value), $places, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $fixed, 2), 2, '');

        if (strlen($whole) > 3) {
            $last3 = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
            $whole = $rest . ',' . $last3;
        }

        return ($negative ? '-' : '') . $whole . ($places > 0 ? '.' . $fraction : '');
    }
}
