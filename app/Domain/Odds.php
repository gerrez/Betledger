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

    public static function isValid(string $odds): bool
    {
        return Decimal::isValid($odds) && Decimal::compare($odds, '1') > 0;
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
