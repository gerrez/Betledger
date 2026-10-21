<?php

namespace App\Domain;

/**
 * Fractions shown as percentages ("0.057" → "5.7%"), handled as decimal strings, never
 * floats.
 */
final class Percent
{
    /**
     * A fraction as a percentage, rounded half up to the given decimals. Signed, a
     * positive value gets a "+" (zero never gets a sign): "0.0572" → "+5.7%",
     * "-0.05" → "−5.0%". Negative values always show the minus sign (U+2212).
     */
    public static function format(string $fraction, bool $signed = false, int $places = 1): string
    {
        $percent = Decimal::round(Decimal::multiply($fraction, '100'), $places);

        return match (Decimal::compare($percent, '0')) {
            1 => ($signed ? '+' : '').$percent.'%',
            -1 => Money::MINUS.ltrim($percent, '-').'%',
            default => ltrim($percent, '-').'%',
        };
    }
}
