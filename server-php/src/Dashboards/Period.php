<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

/**
 * The window a dashboard is showing, and the one before it to compare against.
 *
 * WHY THE PREVIOUS PERIOD IS COMPUTED HERE AND NOT GUESSED. "+12% vs yesterday"
 * is only true if yesterday means the same number of hours. Comparing a Monday
 * that is four hours old against the whole of Sunday shows every business
 * collapsing every morning, which is how a merchant learns to ignore the arrow.
 *
 * So the comparison window is the SAME LENGTH as the current one, ending where
 * the current one began — and for a period that is still running, it is
 * truncated to the same elapsed fraction.
 *
 * All windows are in the merchant's timezone, not UTC. "Collected today" that
 * starts at 5:30 in the morning is a figure nobody at the counter recognises.
 */
final class Period
{
    public const TODAY = 'today';
    public const YESTERDAY = 'yesterday';
    public const WEEK = 'week';
    public const MONTH = 'month';
    public const QUARTER = 'quarter';
    public const YEAR = 'year';
    public const LAST_7 = 'last_7';
    public const LAST_30 = 'last_30';

    private function __construct(
        public readonly string $key,
        public readonly string $from,
        public readonly string $to,
        public readonly string $label,
        public readonly string $previousFrom,
        public readonly string $previousTo,
        public readonly string $previousLabel,
        public readonly string $timezone,
        /** True while the window is still running, so a comparison is part-way. */
        public readonly bool $inProgress,
    ) {
    }

    public static function resolve(string $key, string $timezone = 'Asia/Kolkata'): self
    {
        $tz = self::safeTimezone($timezone);
        $now = new \DateTimeImmutable('now', $tz);

        // `$to` is the END OF THE PERIOD, not "now", even while the period is
        // still running.
        //
        // This is not a detail. With `to` set to the current instant, a payment
        // captured in the same second as the dashboard loads falls outside its
        // own window — `paid_at < to` is false when both are the same second —
        // and a merchant watching a payment come in sees the figure not move.
        // "Today" means the whole of today, and a payment made a moment ago is
        // in it.
        //
        // The comparison window is handled separately below, so widening this
        // one costs nothing in honesty.
        // The fourth element is the CALENDAR step back to the comparison
        // window. Stepping back by the period's length in seconds would put
        // "last month" starting on the 2nd of August after a 30-day September,
        // and the two months would not be comparable. A month steps back a
        // month, whatever length that month happens to be.
        [$from, $to, $label, $inProgress, $step] = match ($key) {
            self::YESTERDAY => [
                $now->modify('-1 day')->setTime(0, 0),
                $now->setTime(0, 0),
                'Yesterday',
                false,
                '-1 day',
            ],
            self::WEEK => [
                $now->modify('monday this week')->setTime(0, 0),
                $now->modify('monday this week')->setTime(0, 0)->modify('+7 days'),
                'This week',
                true,
                '-7 days',
            ],
            self::MONTH => [
                $now->modify('first day of this month')->setTime(0, 0),
                $now->modify('first day of next month')->setTime(0, 0),
                'This month',
                true,
                '-1 month',
            ],
            self::QUARTER => self::quarter($now),
            self::YEAR => [
                $now->modify('first day of january this year')->setTime(0, 0),
                $now->modify('first day of january next year')->setTime(0, 0),
                'This year',
                true,
                '-1 year',
            ],
            self::LAST_7 => [
                $now->modify('-6 days')->setTime(0, 0),
                $now->modify('+1 day')->setTime(0, 0),
                'Last 7 days',
                true,
                '-7 days',
            ],
            self::LAST_30 => [
                $now->modify('-29 days')->setTime(0, 0),
                $now->modify('+1 day')->setTime(0, 0),
                'Last 30 days',
                true,
                '-30 days',
            ],
            default => [
                $now->setTime(0, 0),
                $now->modify('+1 day')->setTime(0, 0),
                'Today',
                true,
                '-1 day',
            ],
        };

        $key = $key === '' ? self::TODAY : $key;

        // THE COMPARISON IS LIKE FOR LIKE, which for a period still running
        // means the SAME ELAPSED FRACTION of the previous one.
        //
        // Comparing the whole of a Monday that is four hours old against the
        // whole of Sunday shows every business collapsing every morning, and a
        // merchant who sees that once stops reading the arrow. So the previous
        // window starts one period earlier and runs for however long this one
        // has actually been running.
        $periodSeconds = $to->getTimestamp() - $from->getTimestamp();
        $elapsedSeconds = $inProgress
            ? max(1, min($periodSeconds, $now->getTimestamp() - $from->getTimestamp()))
            : $periodSeconds;

        $previousFrom = $from->modify($step);
        $previousTo = $previousFrom->modify('+' . $elapsedSeconds . ' seconds');

        return new self(
            $key,
            // ISO 8601 WITH THE OFFSET, not a bare local timestamp. Every time
            // column in this product is TIMESTAMPTZ, and a bare "2026-01-14
            // 00:00:00" is interpreted in the DATABASE SERVER'S timezone —
            // which is UTC. A merchant in IST would then get a window five and
            // a half hours out, and "collected today" would miss the morning.
            $from->format('c'),
            $to->format('c'),
            $label,
            $previousFrom->format('c'),
            $previousTo->format('c'),
            self::previousLabelFor($key),
            $tz->getName(),
            $inProgress,
        );
    }

    /** @return array{0:\DateTimeImmutable, 1:\DateTimeImmutable, 2:string, 3:bool, 4:string} */
    private static function quarter(\DateTimeImmutable $now): array
    {
        $year = (int) $now->format('Y');
        $startMonth = (int) (floor(((int) $now->format('n') - 1) / 3) * 3) + 1;

        $from = $now->setDate($year, $startMonth, 1)->setTime(0, 0);

        return [$from, $from->modify('+3 months'), 'This quarter', true, '-3 months'];
    }

    /**
     * How many days the window spans, for grouping a trend.
     *
     * Capped so a "this year" trend does not try to draw 365 bars into 700
     * pixels; the caller widens the bucket instead.
     */
    public function days(): int
    {
        $seconds = strtotime($this->to) - strtotime($this->from);

        return max(1, (int) ceil($seconds / 86400));
    }

    /** DAY | WEEK | MONTH — what a trend should be grouped by for this window. */
    public function bucket(): string
    {
        $days = $this->days();

        return match (true) {
            $days <= 62  => 'DAY',
            $days <= 365 => 'WEEK',
            default      => 'MONTH',
        };
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'key'            => $this->key,
            'from'           => $this->from,
            'to'             => $this->to,
            'label'          => $this->label,
            'previous_from'  => $this->previousFrom,
            'previous_to'    => $this->previousTo,
            'previous_label' => $this->previousLabel,
            'timezone'       => $this->timezone,
            'in_progress'    => $this->inProgress,
            'bucket'         => $this->bucket(),
        ];
    }

    /** @return list<string> */
    public static function available(): array
    {
        return [self::TODAY, self::YESTERDAY, self::WEEK, self::MONTH, self::QUARTER, self::YEAR, self::LAST_7, self::LAST_30];
    }

    private static function previousLabelFor(string $key): string
    {
        return match ($key) {
            self::TODAY     => 'vs. yesterday',
            self::YESTERDAY => 'vs. the day before',
            self::WEEK      => 'vs. last week',
            self::MONTH     => 'vs. last month',
            self::QUARTER   => 'vs. last quarter',
            self::YEAR      => 'vs. last year',
            self::LAST_7    => 'vs. the 7 days before',
            self::LAST_30   => 'vs. the 30 days before',
            default         => 'vs. the previous period',
        };
    }

    private static function safeTimezone(string $timezone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timezone === '' ? 'Asia/Kolkata' : $timezone);
        } catch (\Throwable) {
            // A bad timezone should not take down a dashboard.
            return new \DateTimeZone('Asia/Kolkata');
        }
    }
}
