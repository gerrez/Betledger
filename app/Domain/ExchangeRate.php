<?php

namespace App\Domain;

/**
 * Exchange rates ("7.46", "0.1341"): 1 unit of a bookmaker's or bet's currency = this
 * many units of the user's base currency. Stored as decimal(18,8), handled as strings.
 */
final class ExchangeRate
{
    /** The rate of a currency to itself. */
    public const SAME_CURRENCY = '1';

    private const PATTERN = '/^\d{1,10}(\.\d{1,8})?$/';

    /**
     * Whether the rate is a positive decimal that fits the column: at most 10 digits
     * before the decimal point and 8 after it.
     */
    public static function isValid(string $rate): bool
    {
        return preg_match(self::PATTERN, $rate) === 1 && Decimal::compare($rate, '0') > 0;
    }
}
