<?php

namespace App\Domain;

use InvalidArgumentException;

/**
 * Decimal odds, handled as strings ("2.10") and never as floats.
 */
final class Odds
{
    /** Decimal places of a bet's stored total odds. */
    public const TOTAL_PLACES = 4;

    /** Odds of a selection as entered: up to 7 digits before the point and 3 after it (decimal(10,3)). */
    private const SELECTION_PATTERN = '/^\d{1,7}(\.\d{1,3})?$/';

    public static function isValid(string $odds): bool
    {
        return Decimal::isValid($odds) && Decimal::compare($odds, '1') > 0;
    }

    /**
     * Whether the odds can be stored on a selection: greater than 1, with at most three
     * decimals and seven digits before the point.
     */
    public static function isValidSelectionOdds(string $odds): bool
    {
        return preg_match(self::SELECTION_PATTERN, $odds) === 1 && self::isValid($odds);
    }

    /**
     * @throws InvalidArgumentException when the odds are not a decimal greater than 1
     */
    public static function assertValid(string $odds): string
    {
        if (! self::isValid($odds)) {
            throw new InvalidArgumentException("[{$odds}] is not valid decimal odds; odds must be greater than 1.");
        }

        return $odds;
    }

    /**
     * The total odds of a bet: the product of its selection odds, rounded half up to
     * four decimal places.
     *
     * @param  list<string>  $odds
     *
     * @throws InvalidArgumentException when the list is empty or holds invalid odds
     */
    public static function total(array $odds): string
    {
        if ($odds === []) {
            throw new InvalidArgumentException('A bet needs at least one selection.');
        }

        $product = '1';

        foreach ($odds as $selectionOdds) {
            $product = Decimal::multiply($product, self::assertValid($selectionOdds));
        }

        return Decimal::round($product, self::TOTAL_PLACES);
    }
}
