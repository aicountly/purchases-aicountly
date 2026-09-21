<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

/**
 * One supplier scoring model, used by every screen that scores a supplier.
 *
 * It lived inside the Suppliers dashboard until the Overview needed to state a
 * supplier risk position too. Two implementations of "how good is this
 * supplier" would have been two answers to the same question on two screens of
 * the same product, and the first person to notice would rightly have stopped
 * trusting both.
 *
 * THE MODEL SHOWS ITS WORKING. Components, weights, the sample behind each one
 * and what could not be scored all travel with the number, because a supplier
 * score nobody can reconstruct is a score nobody can argue with — and a buyer
 * who cannot argue with it will not act on it either.
 *
 * A component below the minimum sample is REPORTED BUT NOT SCORED, and the
 * remaining weights are re-normalised over what did count. Scoring a supplier
 * with one delivery as though that delivery were the whole picture is how a
 * scorecard ends up recommending the supplier nobody has bought from yet.
 */
final class SupplierScore
{
    /** Weights for the composite. Published to the client alongside the score. */
    public const WEIGHTS = [
        'on_time'    => 40,
        'acceptance' => 35,
        'fulfilment' => 25,
    ];

    /** Below this many observations a component is reported but not scored. */
    public const MIN_SAMPLE = 3;

    /** The score at or above which a supplier position is treated as low risk. */
    private const LOW_RISK_AT = '80';

    /** Below this, high risk. Between the two, medium. */
    private const HIGH_RISK_BELOW = '60';

    /**
     * The composite, with its working.
     *
     * @return array{value: ?string, components: list<array<string, mixed>>, missing: list<string>}
     */
    public static function compose(?string $onTime, ?string $acceptance, ?string $fulfilment): array
    {
        $composed = CompositeScore::of([
            ['key' => 'on_time', 'label' => 'On-time delivery', 'weight' => self::WEIGHTS['on_time'], 'value' => $onTime],
            ['key' => 'acceptance', 'label' => 'Acceptance', 'weight' => self::WEIGHTS['acceptance'], 'value' => $acceptance],
            ['key' => 'fulfilment', 'label' => 'Line fulfilment', 'weight' => self::WEIGHTS['fulfilment'], 'value' => $fulfilment],
        ]);

        // The keys this model has always published. `basis` and `sample` are
        // additions of the shared composer that this score does not fill in,
        // and a client reading `score_components` should not suddenly find two
        // null columns it never asked for.
        $components = array_map(
            static fn (array $component) => [
                'key'     => $component['key'],
                'label'   => $component['label'],
                'weight'  => $component['weight'],
                'value'   => $component['value'],
                'counted' => $component['counted'],
            ],
            $composed['components'],
        );

        return ['value' => $composed['value'], 'components' => $components, 'missing' => $composed['missing']];
    }

    /**
     * Which risk band a score falls in.
     *
     * The score runs the way a reader expects a score to run — higher is safer —
     * and the band says so in words as well as in a colour, because a colour
     * alone is not a statement anyone can act on and is invisible to a
     * substantial minority of readers.
     *
     * @return array{id: string, label: string, tone: string, action: string}
     */
    public static function band(?string $score): array
    {
        if ($score === null) {
            return [
                'id'     => 'unknown',
                'label'  => 'Not enough data',
                'tone'   => 'neutral',
                'action' => 'Too few deliveries in this period to state a position.',
            ];
        }

        $band = CompositeScore::band(
            $score,
            ['good' => self::LOW_RISK_AT, 'fair' => self::HIGH_RISK_BELOW],
            ['good' => 'Low risk', 'fair' => 'Medium risk', 'poor' => 'High risk', 'unknown' => 'Not enough data'],
        );

        // The shared composer names its bands good / fair / poor. A supplier
        // band has always been low / medium / high risk, and renaming it here
        // would rename it in every client that already reads it.
        return [
            'id'     => match ($band['id']) { 'good' => 'low', 'fair' => 'medium', default => 'high' },
            'label'  => $band['label'],
            'tone'   => $band['tone'],
            'action' => match ($band['id']) {
                'good'  => 'Performing to expectation',
                'fair'  => 'Monitor',
                default => 'Review these suppliers',
            },
        ];
    }

    /**
     * How the model describes itself, for the client to show beside a score.
     *
     * @return array<string, mixed>
     */
    public static function model(string $periodLabel): array
    {
        return [
            'weights'     => self::WEIGHTS,
            'min_sample'  => self::MIN_SAMPLE,
            'period'      => $periodLabel,
            'bands'       => [
                'low_risk_at'     => self::LOW_RISK_AT,
                'high_risk_below' => self::HIGH_RISK_BELOW,
            ],
            'description' => 'A weighted average of the components that have at least ' . self::MIN_SAMPLE
                . ' observations in this period. A component with fewer is shown but not scored, and the weights are '
                . 're-normalised over the components that did count, so a supplier is never penalised for data that does not exist.',
        ];
    }
}
