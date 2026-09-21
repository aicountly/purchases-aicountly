<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

/**
 * A weighted score out of 100, assembled from components that may be missing.
 *
 * Every score this product shows is built here, under one rule: A COMPONENT
 * WITH NO DENOMINATOR IS NOT A COMPONENT WORTH ZERO. A company that has raised
 * no purchase orders this month has no "orders on schedule" ratio — it does not
 * have a bad one. Scoring the absence as zero is how a quiet month gets
 * reported as a crisis, so a component without inputs is reported, excluded,
 * and the remaining weights are re-normalised over what actually counted.
 *
 * The components travel with the score for the same reason: a headline number
 * whose arithmetic cannot be reconstructed is a number nobody can act on, and a
 * buyer told their procurement health is 82 needs to know which part of it is
 * dragging before they can do anything about it.
 *
 * Nothing here invents a benchmark. Each component is supplied as a percentage
 * that the caller computed from a real numerator over a real denominator, and
 * the caller states both in `basis`.
 */
final class CompositeScore
{
    /**
     * @param list<array{key: string, label: string, weight: int, value: ?string, basis?: string, sample?: int}> $inputs
     * @return array{value: ?string, components: list<array<string, mixed>>, missing: list<string>, counted_weight: int}
     */
    public static function of(array $inputs): array
    {
        $weightTotal = 0;
        $components = [];
        $missing = [];

        foreach ($inputs as $input) {
            $counted = $input['value'] !== null;
            if (!$counted) {
                $missing[] = $input['label'];
            } else {
                $weightTotal += $input['weight'];
            }

            $components[] = [
                'key'     => $input['key'],
                'label'   => $input['label'],
                'weight'  => $input['weight'],
                'value'   => $input['value'],
                'counted' => $counted,
                'basis'   => $input['basis'] ?? null,
                'sample'  => $input['sample'] ?? null,
            ];
        }

        if ($weightTotal === 0) {
            return ['value' => null, 'components' => $components, 'missing' => $missing, 'counted_weight' => 0];
        }

        $weighted = Decimal::ZERO;
        foreach ($components as $component) {
            if (!$component['counted']) {
                continue;
            }
            $weighted = Decimal::add($weighted, Decimal::mul((string) $component['value'], (string) $component['weight']));
        }

        return [
            'value'          => Decimal::div($weighted, (string) $weightTotal, 1),
            'components'     => $components,
            'missing'        => $missing,
            'counted_weight' => $weightTotal,
        ];
    }

    /**
     * A ratio of two counts as a percentage, or null when there is nothing to
     * divide by.
     *
     * `$good` of `$total` were fine. A total of zero returns null rather than
     * 0% or 100%: neither is true of a thing that did not happen.
     */
    public static function rate(int $good, int $total): ?string
    {
        if ($total <= 0) {
            return null;
        }

        return Decimal::percentOf((string) max($good, 0), (string) $total, 1);
    }

    /**
     * The same, for two exact decimal amounts.
     */
    public static function rateOfAmounts(string $good, string $total): ?string
    {
        if (Decimal::isZero($total) || Decimal::isNegative($total)) {
            return null;
        }
        $clamped = Decimal::isNegative($good) ? Decimal::ZERO : $good;

        return Decimal::percentOf($clamped, $total, 1);
    }

    /**
     * Which band a score falls in, in words as well as in a tone.
     *
     * The tone is a hint for a colour; the label is the statement. Status
     * communicated only by colour is status a colour-blind reader and a printed
     * copy both lose.
     *
     * @param array{good: string, fair: string} $thresholds score at or above which each band starts
     * @param array{good: string, fair: string, poor: string, unknown: string} $labels
     * @return array{id: string, label: string, tone: string}
     */
    public static function band(?string $score, array $thresholds, array $labels): array
    {
        if ($score === null) {
            return ['id' => 'unknown', 'label' => $labels['unknown'], 'tone' => 'neutral'];
        }
        if (Decimal::cmp($score, $thresholds['good']) >= 0) {
            return ['id' => 'good', 'label' => $labels['good'], 'tone' => 'success'];
        }
        if (Decimal::cmp($score, $thresholds['fair']) >= 0) {
            return ['id' => 'fair', 'label' => $labels['fair'], 'tone' => 'warning'];
        }

        return ['id' => 'poor', 'label' => $labels['poor'], 'tone' => 'danger'];
    }
}
