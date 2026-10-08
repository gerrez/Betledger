<?php

namespace App\Domain;

use InvalidArgumentException;

/**
 * Amounts are integers in minor units (cents/øre). v1 only supports currencies with
 * two decimal places, so one major unit is always 100 minor units.
 */
final class Money
{
    private const AMOUNT_PATTERN = '/^\d+(\.\d{1,2})?$/';

    /**
     * Parse an entered amount such as "100", "100.5" or "100.50" into minor units.
     *
     * @throws InvalidArgumentException for negative amounts, more than two decimals or anything else that is not a plain amount
     */
    public static function toMinorUnits(string $amount): int
    {
        if (preg_match(self::AMOUNT_PATTERN, $amount) !== 1) {
            throw new InvalidArgumentException("[{$amount}] is not a valid amount.");
        }

        return Decimal::toInteger(Decimal::multiply($amount, '100'));
    }

    /**
     * Format minor units as a plain decimal string: 10050 → "100.50", -5000 → "-50.00".
     */
    public static function fromMinorUnits(int $minorUnits): string
    {
        $digits = str_pad(ltrim((string) $minorUnits, '-'), 3, '0', STR_PAD_LEFT);
        $sign = $minorUnits < 0 ? '-' : '';

        return $sign.substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    /**
     * Convert an amount to the base currency with the given rate (1 unit of the amount's
     * currency = rate units of the base currency), rounded half up to minor units.
     *
     * @throws InvalidArgumentException when the rate is not a positive decimal
     */
    public static function convert(int $minorUnits, string $exchangeRate): int
    {
        if (Decimal::compare($exchangeRate, '0') <= 0) {
            throw new InvalidArgumentException("[{$exchangeRate}] is not a valid exchange rate.");
        }

        return Decimal::toInteger(Decimal::multiply((string) $minorUnits, $exchangeRate));
    }
}
