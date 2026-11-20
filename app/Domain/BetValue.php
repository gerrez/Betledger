<?php

namespace App\Domain;

/**
 * A bet's probability (the user's estimate of the chance it wins) and its value, both
 * percentages handled as decimal strings, never floats: probability "55.5" = 55.5 %,
 * bet value "107.25" = 107.25 % of the stake back on average (above 100 is a value bet).
 */
final class BetValue
{
    /** Decimal places of a stored probability and bet value. */
    public const PLACES = 2;

    /** The highest bet value: the highest total odds at 100 % (decimal(11,2)). */
    public const MAX = '999999999.99';

    /** A probability as entered: 0–100 with up to 2 decimals. */
    private const PROBABILITY_PATTERN = '/^\d{1,3}(\.\d{1,2})?$/';

    /** A bet value as entered: up to 9 digits before the point and 2 after it. */
    private const VALUE_PATTERN = '/^\d{1,9}(\.\d{1,2})?$/';

    /**
     * Whether a probability can be stored: above 0 and at most 100, with at most two
     * decimals.
     */
    public static function isValidProbability(string $probability): bool
    {
        return preg_match(self::PROBABILITY_PATTERN, $probability) === 1
            && Decimal::compare($probability, '0') > 0
            && Decimal::compare($probability, '100') <= 0;
    }

    /**
     * Whether a bet value can be stored: above 0 and at most MAX, with at most two
     * decimals.
     */
    public static function isValid(string $betValue): bool
    {
        return preg_match(self::VALUE_PATTERN, $betValue) === 1
            && Decimal::compare($betValue, '0') > 0
            && Decimal::compare($betValue, self::MAX) <= 0;
    }

    /**
     * The bet value from the total odds and the probability: odds × probability,
     * rounded half up to two decimals. "2.1000" at "55" → "115.50".
     *
     * Void, half-won and half-lost outcomes are not taken into account yet (see the
     * open questions in docs/spec.md).
     */
    public static function fromProbability(string $totalOdds, string $probability): string
    {
        return Decimal::round(Decimal::multiply(Odds::assertValid($totalOdds), Decimal::assertValid($probability)), self::PLACES);
    }

    /**
     * The value the bet stores: calculated when there is a probability, else the
     * value as entered (or none).
     */
    public static function forBet(string $totalOdds, ?string $probability, ?string $enteredValue): ?string
    {
        return $probability === null ? $enteredValue : self::fromProbability($totalOdds, $probability);
    }

    /**
     * A percentage for display, without trailing zeros: "55.50" → "55.5%",
     * "107.00" → "107%".
     */
    public static function formatPercent(string $percent): string
    {
        return Decimal::normalize($percent).'%';
    }
}
