<?php

namespace App\Livewire\Statistics;

use App\Domain\Currency;
use App\Domain\Decimal;
use App\Domain\Money;
use App\Domain\Percent;
use App\Domain\Statistics\Period;
use App\Domain\Statistics\ProfitPoint;
use App\Domain\Statistics\ProfitSeries;
use Illuminate\Support\Str;

/**
 * The profit-over-time series as the statistics page draws it: an SVG line from 0 at the
 * start of the first period through the cumulative profit at the end of each period, on
 * a value axis with round steps that always includes 0, and dates under a few of the
 * periods. Coordinates are in a WIDTH × HEIGHT view box the SVG stretches to fit.
 */
final readonly class ProfitChart
{
    public const WIDTH = 1000;

    public const HEIGHT = 260;

    /** The number of steps the value axis aims for. */
    public const STEPS = 4;

    /** The most periods labelled under the chart (the 2nd and 4th are hidden on phones). */
    public const DATE_LABELS = 5;

    /**
     * @param  string  $line  the SVG path of the cumulative profit line
     * @param  string  $area  the SVG path of the area between the line and 0
     * @param  list<array{value: string, y: string, isZero: bool}>  $ticks  the value axis, top to bottom
     * @param  list<array{label: string, left: string, isLast: bool, isMinor: bool}>  $dates  the date axis, left to right
     */
    private function __construct(
        public string $caption,
        public string $description,
        public string $line,
        public string $area,
        public array $ticks,
        public array $dates,
    ) {}

    /**
     * The chart for a series, or null when it has no points (nothing settled).
     */
    public static function fromSeries(ProfitSeries $series, Currency $currency): ?self
    {
        $points = $series->points;

        if ($points === []) {
            return null;
        }

        $values = [0, ...array_map(fn (ProfitPoint $point): int => $point->cumulativeProfit, $points)];
        $step = self::step(max($values) - min($values));
        $top = self::ceilToStep(max($values), $step);
        $bottom = self::floorToStep(min($values), $step);

        if ($top === $bottom) {
            $top = $bottom + self::STEPS * $step;
        }

        $y = fn (int $value): string => self::coordinate($top - $value, $top - $bottom, self::HEIGHT);
        $periods = count($points);
        $coordinates = [];

        foreach ($values as $index => $value) {
            $coordinates[] = self::coordinate($index, $periods, self::WIDTH).' '.$y($value);
        }

        $line = 'M'.implode(' L', $coordinates);
        $zero = $y(0);
        $ticks = [];

        for ($value = $top; $value >= $bottom; $value -= $step) {
            $ticks[] = ['value' => Str::beforeLast(Money::formatWithoutSymbol($value), '.'), 'y' => $y($value), 'isZero' => $value === 0];
        }

        $last = $points[$periods - 1];

        return new self(
            caption: "Cumulative, by {$series->period->value} settled",
            description: "Cumulative profit over {$periods} ".Str::plural($series->period->value, $periods)
                .', ending at '.Money::format($last->cumulativeProfit, $currency, signed: true),
            line: $line,
            area: $line.' L'.self::WIDTH." {$zero} L0 {$zero} Z",
            ticks: $ticks,
            dates: self::dates($series),
        );
    }

    /**
     * Up to DATE_LABELS periods spread evenly from the first to the last, each placed
     * under its point.
     *
     * @return list<array{label: string, left: string, isLast: bool, isMinor: bool}>
     */
    private static function dates(ProfitSeries $series): array
    {
        $periods = count($series->points);
        $gaps = self::DATE_LABELS - 1;
        $dates = [];

        for ($label = 0; $label <= $gaps; $label++) {
            $number = 1 + intdiv(2 * $label * ($periods - 1) + $gaps, 2 * $gaps);

            if (isset($dates[$number])) {
                continue;
            }

            $dates[$number] = [
                'label' => $series->points[$number - 1]->start->format($series->period === Period::Month ? 'M Y' : 'j M'),
                'left' => Percent::format(Decimal::divide((string) $number, (string) $periods)),
                'isLast' => $number === $periods,
                'isMinor' => $label % 2 === 1 && $number !== $periods,
            ];
        }

        return array_values($dates);
    }

    /**
     * A round step for the value axis, in minor units: the smallest of 1, 2 or 5 whole
     * units, then 10, 20, 25 or 50 times a power of ten, that covers the span in STEPS
     * steps.
     */
    private static function step(int $span): int
    {
        foreach ([100, 200, 500] as $step) {
            if ($step * self::STEPS >= $span) {
                return $step;
            }
        }

        for ($magnitude = 100; ; $magnitude *= 10) {
            foreach ([10, 20, 25, 50] as $factor) {
                if ($factor * $magnitude * self::STEPS >= $span) {
                    return $factor * $magnitude;
                }
            }
        }
    }

    private static function floorToStep(int $value, int $step): int
    {
        return intdiv($value, $step) * $step - ($value % $step < 0 ? $step : 0);
    }

    private static function ceilToStep(int $value, int $step): int
    {
        return -self::floorToStep(-$value, $step);
    }

    /**
     * The share numerator ÷ denominator of a length, rounded to one decimal place.
     */
    private static function coordinate(int $numerator, int $denominator, int $length): string
    {
        return Decimal::normalize(Decimal::round(Decimal::divide(Decimal::multiply((string) $numerator, (string) $length), (string) $denominator), 1));
    }
}
