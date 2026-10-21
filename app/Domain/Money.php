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

    /** A stake as entered: up to 999,999,999.99, so payouts at any odds fit in an integer. */
    private const STAKE_PATTERN = '/^\d{1,9}(\.\d{1,2})?$/';

    /** A payout entered by hand: zero up to 999,999,999,999.99, so profit always fits in an integer. */
    private const PAYOUT_PATTERN = '/^\d{1,12}(\.\d{1,2})?$/';

    /** The minus sign shown in front of negative amounts (U+2212, not a hyphen). */
    public const MINUS = "\u{2212}";

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
     * Whether an entered stake is an amount above zero with at most two decimals that
     * isn't absurdly large.
     */
    public static function isValidStake(string $amount): bool
    {
        return preg_match(self::STAKE_PATTERN, $amount) === 1 && self::toMinorUnits($amount) > 0;
    }

    /**
     * Whether an entered payout (the amount a bookmaker actually paid) is zero or more,
     * with at most two decimals, and not absurdly large.
     */
    public static function isValidPayout(string $amount): bool
    {
        return preg_match(self::PAYOUT_PATTERN, $amount) === 1;
    }

    /**
     * Format minor units for display with thousands separators and the currency symbol:
     * 149200 DKK → "1,492.00 kr", -10000 EUR → "−€100.00". With `$signed`, positive
     * amounts get a "+" (profit and loss are never shown by colour alone); zero never
     * gets a sign.
     */
    public static function format(int $minorUnits, Currency $currency, bool $signed = false): string
    {
        [$whole, $fraction] = explode('.', ltrim(self::fromMinorUnits($minorUnits), '-'));
        $grouped = ltrim(strrev(chunk_split(strrev($whole), 3, ',')), ',');

        $sign = match (true) {
            $minorUnits < 0 => self::MINUS,
            $signed && $minorUnits > 0 => '+',
            default => '',
        };

        return $sign.$currency->withSymbol("{$grouped}.{$fraction}");
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
