<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

/**
 * One KPI card, as a contract rather than a number.
 *
 * A dashboard number with no stated basis is a number nobody can defend in a
 * meeting, so every metric carries: what it counts, over what period, in what
 * currency or unit, what it is being compared against, whether a bigger number
 * is good news, and where to click to see the rows behind it.
 *
 * THREE STATES, deliberately distinct:
 *
 *   ready + raw_value "0"   we looked, and the answer is nothing.
 *   ready + raw_value null  not applicable to this scope.
 *   unavailable             we could not ask. NEVER rendered as 0 or 0%.
 *
 * The third is the one that matters. A payables card showing ₹0 because Books
 * timed out is a lie that looks like good news.
 */
final class Metric
{
    public const HIGHER_IS_BETTER = 'higher_is_better';
    public const LOWER_IS_BETTER  = 'lower_is_better';
    public const NEUTRAL          = 'neutral';

    /**
     * @param array{
     *   currency?: string, unit?: string, format?: string, scale?: int,
     *   direction?: string, explanation?: string,
     *   previous?: string|null, comparison_label?: string, comparison_unavailable_reason?: string,
     *   drilldown?: array{route: string, filters: array<string, string>},
     *   footnote?: string, compact?: bool, footer?: string,
     *   trend?: list<array{period: string, value: string|null, formatted?: string}>
     * } $options
     *
     * @return array<string, mixed>
     */
    public static function ready(string $id, string $label, ?string $rawValue, string $basis, array $options = []): array
    {
        $format = $options['format'] ?? 'count';
        $currency = $options['currency'] ?? null;
        $unit = $options['unit'] ?? null;
        $direction = $options['direction'] ?? self::NEUTRAL;

        $exact = $rawValue === null
            ? null
            : self::render($rawValue, $format, $currency, $unit, $options['scale'] ?? null);

        // A card shows the short form; everything that can be reconciled against
        // — the tooltip, the table, the export — shows `exact_value`.
        $compact = ($options['compact'] ?? false) === true && $format === 'currency' && $rawValue !== null;
        $formatted = $rawValue === null
            ? 'Not applicable'
            : ($compact ? Format::compactMoney($rawValue, $currency ?? 'INR') : (string) $exact);

        $metric = [
            'id'              => $id,
            'label'           => $label,
            'status'          => 'ready',
            'raw_value'       => $rawValue,
            'formatted_value' => $formatted,
            'format'          => $format,
            'currency'        => $currency,
            'unit'            => $unit,
            'basis'           => $basis,
            'explanation'     => $options['explanation'] ?? $basis,
            'direction'       => $direction,
            'footnote'        => $options['footnote'] ?? null,
            'exact_value'     => $exact,
            // The shape behind the figure. Null entries are GAPS — a month with
            // nothing to plot is not a month at zero.
            'trend'           => self::trend($options['trend'] ?? [], $format, $currency, $unit, $options['scale'] ?? null),
        ];

        $metric += self::comparison($rawValue, $options, $format, $currency, $unit, $direction, $compact);

        // The line at the foot of the card: the previous period's own figure,
        // because "+12.5%" without it is half a sentence. Only where a
        // percentage was actually produced — with a zero baseline the delta
        // line already says there was nothing, and saying it twice is noise.
        $previousFormatted = ($metric['comparison']['change_pc'] ?? null) === null
            ? null
            : ($metric['comparison']['previous_formatted'] ?? null);
        $metric['footer'] = $previousFormatted === null
            ? ($options['footer'] ?? $options['footnote'] ?? null)
            : $previousFormatted . ' in the previous period';

        if (isset($options['drilldown'])) {
            $metric['drilldown'] = $options['drilldown'];
        }

        return $metric;
    }

    /**
     * The card we could not fill in.
     *
     * `reason` is shown to the user, so it says which product did not answer
     * rather than "an error occurred".
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function unavailable(string $id, string $label, string $reason, string $basis, array $options = []): array
    {
        return [
            'id'                 => $id,
            'label'              => $label,
            'status'             => 'unavailable',
            'raw_value'          => null,
            'formatted_value'    => null,
            'format'             => $options['format'] ?? 'count',
            'currency'           => $options['currency'] ?? null,
            'unit'               => $options['unit'] ?? null,
            'basis'              => $basis,
            'explanation'        => $options['explanation'] ?? $basis,
            'direction'          => $options['direction'] ?? self::NEUTRAL,
            'unavailable_reason' => $reason,
            'comparison'         => [
                'available' => false,
                'text'      => 'Comparison unavailable',
                'tone'      => 'is-neutral',
                'reason'    => $reason,
            ],
            'comparison_text'    => 'Comparison unavailable',
            'change_tone'        => 'is-neutral',
            'footnote'           => $options['footnote'] ?? null,
            'exact_value'        => null,
            'trend'              => [],
            'footer'             => $options['footer'] ?? $options['footnote'] ?? null,
        ];
    }

    /**
     * The previous-period line under the value.
     *
     * Two rules worth stating, because both are commonly got wrong:
     *
     *  - A zero baseline has no percentage. "+∞%" and "+100%" are both wrong;
     *    the absolute change is what gets shown instead.
     *  - Favourable is not the same as bigger. Overdue payables going up is a
     *    red line even though the number grew, which is what `direction` is for.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function comparison(
        ?string $rawValue,
        array $options,
        string $format,
        ?string $currency,
        ?string $unit,
        string $direction,
        bool $compact = false,
    ): array {
        $previous = $options['previous'] ?? null;
        $label = $options['comparison_label'] ?? 'vs previous period';

        if ($rawValue === null || $previous === null || Decimal::parse($previous) === null) {
            $reason = $options['comparison_unavailable_reason']
                ?? 'No comparable previous period for this scope.';

            return [
                'comparison' => ['available' => false, 'text' => 'Comparison unavailable', 'tone' => 'is-neutral', 'reason' => $reason],
                'comparison_text' => 'Comparison unavailable',
                'change_tone' => 'is-neutral',
            ];
        }

        $previous = Decimal::of($previous);
        $previousFormatted = $compact && $format === 'currency'
            ? Format::compactMoney($previous, $currency ?? 'INR')
            : self::render($previous, $format, $currency, $unit, $options['scale'] ?? null);
        $change = Decimal::sub($rawValue, $previous);
        $changePc = Decimal::percentChange($previous, $rawValue, 1);

        if (Decimal::isZero($change)) {
            $text = 'No change ' . $label;
            $tone = 'is-neutral';
        } elseif ($changePc === null) {
            // A zero baseline cannot produce a percentage, and the absolute
            // change is the headline figure again — printing it twice tells a
            // reader nothing. What they need is why there is no percentage.
            $text = 'Nothing in the previous period';
            $tone = 'is-neutral';
        } else {
            $up = !Decimal::isNegative($change);
            $arrow = $up ? '▲' : '▼';
            $magnitude = Decimal::fixed(Decimal::isNegative($changePc) ? Decimal::negate($changePc) : $changePc, 1) . '%';

            $text = $arrow . ' ' . $magnitude . ' ' . $label;
            $tone = match ($direction) {
                self::HIGHER_IS_BETTER => $up ? 'is-positive' : 'is-negative',
                self::LOWER_IS_BETTER  => $up ? 'is-negative' : 'is-positive',
                default                => 'is-neutral',
            };
        }

        return [
            'comparison' => [
                'available'    => true,
                'text'         => $text,
                'tone'         => $tone,
                'previous_raw' => $previous,
                'previous_formatted' => $previousFormatted,
                'change_raw'   => $change,
                'change_pc'    => $changePc,
                'label'        => $label,
            ],
            'comparison_text' => $text,
            'change_tone'     => $tone,
        ];
    }

    /**
     * The sparkline series, formatted here so the card never formats a figure.
     *
     * @param list<array{period: string, value: string|null, formatted?: string}> $points
     * @return list<array{period: string, value: string|null, formatted: string}>
     */
    private static function trend(array $points, string $format, ?string $currency, ?string $unit, ?int $scale): array
    {
        $out = [];
        foreach ($points as $point) {
            $value = $point['value'] ?? null;
            $value = $value === null ? null : Decimal::of($value);
            $out[] = [
                'period'    => (string) $point['period'],
                'value'     => $value,
                'formatted' => $point['formatted']
                    ?? ($value === null ? 'Not rated' : self::render($value, $format, $currency, $unit, $scale)),
            ];
        }

        return $out;
    }

    private static function render(string $value, string $format, ?string $currency, ?string $unit, ?int $scale): string
    {
        return match ($format) {
            'currency' => Format::money($value, $currency ?? 'INR'),
            'percent'  => Format::percent($value, $scale ?? 1),
            'quantity' => Format::quantity($value, $unit),
            'days'     => Format::days($value),
            default    => Format::count($value),
        };
    }
}
