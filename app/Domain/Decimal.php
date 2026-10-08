<?php

namespace App\Domain;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic on plain decimal strings ("2.10", "-0.5") using bcmath.
 *
 * Odds, exchange rates and factors must never pass through PHP floats. Addition,
 * subtraction and multiplication are exact; division keeps at least SCALE places.
 */
final class Decimal
{
    /** Minimum number of decimal places kept in intermediate results. */
    public const SCALE = 10;

    private const PATTERN = '/^-?\d+(\.\d+)?$/';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * @return numeric-string
     *
     * @throws InvalidArgumentException when the value is not a plain decimal string
     */
    public static function assertValid(string $value): string
    {
        if (! self::isValid($value) || ! is_numeric($value)) {
            throw new InvalidArgumentException("[{$value}] is not a decimal number.");
        }

        return $value;
    }

    public static function add(string $left, string $right): string
    {
        $left = self::assertValid($left);
        $right = self::assertValid($right);

        return bcadd($left, $right, max(self::places($left), self::places($right)));
    }

    public static function subtract(string $left, string $right): string
    {
        $left = self::assertValid($left);
        $right = self::assertValid($right);

        return bcsub($left, $right, max(self::places($left), self::places($right)));
    }

    public static function multiply(string $left, string $right): string
    {
        $left = self::assertValid($left);
        $right = self::assertValid($right);

        return bcmul($left, $right, max(self::SCALE, self::places($left) + self::places($right)));
    }

    public static function divide(string $dividend, string $divisor): string
    {
        $dividend = self::assertValid($dividend);
        $divisor = self::assertValid($divisor);

        if (bccomp($divisor, '0', self::places($divisor)) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv($dividend, $divisor, max(self::SCALE, self::places($dividend)));
    }

    /**
     * @return int<-1, 1> -1, 0 or 1 when the left value is smaller, equal or larger
     */
    public static function compare(string $left, string $right): int
    {
        $left = self::assertValid($left);
        $right = self::assertValid($right);

        return bccomp($left, $right, max(self::places($left), self::places($right)));
    }

    public static function max(string $left, string $right): string
    {
        return self::compare($left, $right) >= 0 ? $left : $right;
    }

    /**
     * Round half up to the given number of decimal places. Negative values round half
     * away from zero (-2.5 → -3), so a loss rounds the same way as an equal profit.
     */
    public static function round(string $value, int $places = 0): string
    {
        $value = self::assertValid($value);
        $half = '0.'.str_repeat('0', $places).'5';

        $rounded = str_starts_with($value, '-')
            ? bcsub($value, $half, $places)
            : bcadd($value, $half, $places);

        return $rounded === '-0' ? '0' : $rounded;
    }

    /**
     * Round half up to a whole number and return it as an integer.
     *
     * @throws InvalidArgumentException when the result does not fit in an integer
     */
    public static function toInteger(string $value): int
    {
        $rounded = self::round($value);

        if (self::compare($rounded, (string) PHP_INT_MAX) > 0 || self::compare($rounded, (string) PHP_INT_MIN) < 0) {
            throw new InvalidArgumentException("[{$value}] is too large.");
        }

        return (int) $rounded;
    }

    /**
     * Drop trailing zeros after the decimal point: "2.7000" → "2.7", "3.000" → "3".
     */
    public static function normalize(string $value): string
    {
        $value = self::assertValid($value);

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '-0' ? '0' : $value;
    }

    private static function places(string $value): int
    {
        $point = strpos($value, '.');

        return $point === false ? 0 : strlen($value) - $point - 1;
    }
}
