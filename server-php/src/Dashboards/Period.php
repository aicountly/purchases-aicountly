<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Http;

/**
 * The date range a dashboard is answering for, and what it is compared against.
 *
 * The comparison period is the SAME NUMBER OF DAYS immediately before the
 * selected range. Comparing a 16-day month-to-date against a full previous
 * month is the most common way a dashboard reports a collapse in purchasing
 * that did not happen.
 */
final class Period
{
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $compareFrom,
        public readonly ?string $compareTo,
        public readonly string $preset,
        public readonly string $comparisonMode,
        public readonly string $timezone,
    ) {
    }

    /** Presets the UI offers. Resolved on the server so every endpoint agrees. */
    public const PRESETS = ['today', 'this_week', 'this_month', 'last_month', 'last_7_days', 'last_30_days', 'last_90_days', 'this_quarter', 'this_year', 'custom'];

    public static function fromRequest(): self
    {
        $timezone = 'Asia/Kolkata';
        $today = new \DateTimeImmutable('now', new \DateTimeZone($timezone));

        $preset = (string) (Http::param('preset') ?? '');
        $from = self::dateParam('from');
        $to = self::dateParam('to');

        if ($preset === '' || !in_array($preset, self::PRESETS, true)) {
            $preset = ($from !== null || $to !== null) ? 'custom' : 'this_month';
        }

        if ($preset !== 'custom') {
            [$from, $to] = self::resolvePreset($preset, $today);
        } else {
            $from ??= $today->modify('first day of this month')->format('Y-m-d');
            $to ??= $today->format('Y-m-d');
        }

        // A range the wrong way round is a slip, not a reason to show nothing.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $mode = (string) (Http::param('compare') ?? 'previous_period');
        if (!in_array($mode, ['previous_period', 'none'], true)) {
            $mode = 'previous_period';
        }

        $compareFrom = null;
        $compareTo = null;
        if ($mode === 'previous_period') {
            $start = new \DateTimeImmutable($from, new \DateTimeZone($timezone));
            $end = new \DateTimeImmutable($to, new \DateTimeZone($timezone));
            $days = (int) $start->diff($end)->days + 1;
            $compareTo = $start->modify('-1 day')->format('Y-m-d');
            $compareFrom = $start->modify('-' . $days . ' days')->format('Y-m-d');
        }

        return new self($from, $to, $compareFrom, $compareTo, $preset, $mode, $timezone);
    }

    /**
     * A period from two explicit dates, for callers that already have them.
     *
     * Used by the original summary endpoint, which takes from/to directly and
     * has no preset. The comparison period is the equally sized window
     * immediately before, as everywhere else.
     */
    public static function forDates(string $from, string $to, string $timezone = 'Asia/Kolkata'): self
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $start = new \DateTimeImmutable($from, new \DateTimeZone($timezone));
        $end = new \DateTimeImmutable($to, new \DateTimeZone($timezone));
        $days = (int) $start->diff($end)->days + 1;

        return new self(
            $from,
            $to,
            $start->modify('-' . $days . ' days')->format('Y-m-d'),
            $start->modify('-1 day')->format('Y-m-d'),
            'custom',
            'previous_period',
            $timezone,
        );
    }

    /** @return array{0:string, 1:string} */
    private static function resolvePreset(string $preset, \DateTimeImmutable $today): array
    {
        return match ($preset) {
            'today' => [$today->format('Y-m-d'), $today->format('Y-m-d')],
            // Monday-start, which is what a purchase week is run on here. A
            // Sunday-start week would put two working days in the wrong bucket
            // every time somebody read the dashboard on a Monday morning.
            'this_week' => [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d')],
            'last_month' => [
                $today->modify('first day of last month')->format('Y-m-d'),
                $today->modify('last day of last month')->format('Y-m-d'),
            ],
            'last_7_days'  => [$today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'last_30_days' => [$today->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'last_90_days' => [$today->modify('-89 days')->format('Y-m-d'), $today->format('Y-m-d')],
            'this_quarter' => [
                $today->setDate((int) $today->format('Y'), (intdiv((int) $today->format('n') - 1, 3) * 3) + 1, 1)->format('Y-m-d'),
                $today->format('Y-m-d'),
            ],
            'this_year' => [$today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d'), $today->format('Y-m-d')],
            default     => [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')],
        };
    }

    private static function dateParam(string $name): ?string
    {
        $raw = Http::param($name);
        if ($raw === null || $raw === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    public function days(): int
    {
        $start = new \DateTimeImmutable($this->from);
        $end = new \DateTimeImmutable($this->to);

        return (int) $start->diff($end)->days + 1;
    }

    public function label(): string
    {
        return Format::date($this->from) . ' – ' . Format::date($this->to);
    }

    public function comparisonLabel(): string
    {
        if ($this->compareFrom === null) {
            return 'no comparison';
        }

        return 'vs ' . Format::date($this->compareFrom) . ' – ' . Format::date($this->compareTo);
    }

    /** @return array<string, string> the bindings a range query needs */
    public function params(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }

    /** @return array<string, string>|null */
    public function compareParams(): ?array
    {
        if ($this->compareFrom === null) {
            return null;
        }

        return ['from' => $this->compareFrom, 'to' => $this->compareTo];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from'             => $this->from,
            'to'               => $this->to,
            'preset'           => $this->preset,
            'label'            => $this->label(),
            'days'             => $this->days(),
            'timezone'         => $this->timezone,
            'comparison_mode'  => $this->comparisonMode,
            'compare_from'     => $this->compareFrom,
            'compare_to'       => $this->compareTo,
            'comparison_label' => $this->comparisonLabel(),
        ];
    }
}
