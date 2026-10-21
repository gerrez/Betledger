<?php

namespace App\Domain;

/**
 * Closing line value (CLV): how much better the odds taken were than the closing odds,
 * as a fraction ("0.037" = 3.7 % better). Handled as decimal strings, never floats.
 */
final class ClosingLineValue
{
    /**
     * CLV of a bet: total odds ÷ the product of the selections' closing odds − 1. Null
     * unless every selection has closing odds.
     *
     * @param  list<string|null>  $closingOdds  one per selection, null when it has none
     */
    public static function forBet(string $totalOdds, array $closingOdds): ?string
    {
        if ($closingOdds === [] || in_array(null, $closingOdds, true)) {
            return null;
        }

        $closingTotal = '1';

        foreach ($closingOdds as $odds) {
            $closingTotal = Decimal::multiply($closingTotal, Odds::assertValid($odds));
        }

        return Decimal::subtract(Decimal::divide(Odds::assertValid($totalOdds), $closingTotal), '1');
    }

    /**
     * A CLV as a percentage with one decimal, rounded half up and always signed:
     * "0.0372340" → "+3.7%", "-0.05" → "−5.0%", "0" → "0.0%".
     */
    public static function formatPercent(string $clv): string
    {
        $percent = Decimal::round(Decimal::multiply($clv, '100'), 1);

        return match (Decimal::compare($percent, '0')) {
            1 => '+'.$percent.'%',
            -1 => Money::MINUS.ltrim($percent, '-').'%',
            default => '0.0%',
        };
    }
}
