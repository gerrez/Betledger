<?php

namespace Tests\Unit\Domain;

use App\Domain\Currency;
use App\Domain\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_entered_amounts_into_minor_units(): void
    {
        $this->assertSame(10000, Money::toMinorUnits('100'));
        $this->assertSame(10050, Money::toMinorUnits('100.5'));
        $this->assertSame(10050, Money::toMinorUnits('100.50'));
        $this->assertSame(1, Money::toMinorUnits('0.01'));
        $this->assertSame(0, Money::toMinorUnits('0'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'more than two decimals' => ['100.505'],
            'negative' => ['-5'],
            'comma decimal separator' => ['100,50'],
            'thousands separator' => ['1,000.00'],
            'exponent' => ['1e3'],
            'empty' => [''],
            'trailing point' => ['100.'],
            'too large for an integer' => ['99999999999999999999'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_invalid_amounts(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toMinorUnits($amount);
    }

    public function test_formats_minor_units_with_two_decimals(): void
    {
        $this->assertSame('100.50', Money::fromMinorUnits(10050));
        $this->assertSame('0.05', Money::fromMinorUnits(5));
        $this->assertSame('0.00', Money::fromMinorUnits(0));
        $this->assertSame('-50.00', Money::fromMinorUnits(-5000));
        $this->assertSame('-0.07', Money::fromMinorUnits(-7));
    }

    public function test_converts_to_base_currency_rounding_half_up(): void
    {
        $this->assertSame(9204, Money::convert(1234, '7.4583'));
        $this->assertSame(2, Money::convert(150, '0.01'));
        $this->assertSame(10000, Money::convert(10000, '1'));
    }

    public function test_converts_losses_rounding_half_away_from_zero(): void
    {
        $this->assertSame(-9204, Money::convert(-1234, '7.4583'));
        $this->assertSame(-1, Money::convert(-50, '0.01'));
    }

    public function test_conversion_rejects_a_rate_that_is_not_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::convert(10000, '0');
    }

    public function test_formats_amounts_for_display_with_the_currency_symbol(): void
    {
        $this->assertSame('€390.00', Money::format(39000, Currency::Eur));
        $this->assertSame('1,492.00 kr', Money::format(149200, Currency::Dkk));
        $this->assertSame('CHF 1,234,567.89', Money::format(123456789, Currency::Chf));
        $this->assertSame('€0.05', Money::format(5, Currency::Eur));
    }

    public function test_signed_amounts_always_show_plus_or_minus(): void
    {
        $this->assertSame('+€190.00', Money::format(19000, Currency::Eur, signed: true));
        $this->assertSame('−€100.00', Money::format(-10000, Currency::Eur, signed: true));
        $this->assertSame('−50.00 kr', Money::format(-5000, Currency::Dkk));
        $this->assertSame('0.00 kr', Money::format(0, Currency::Dkk, signed: true));
    }

    public function test_accepts_stakes_above_zero_with_up_to_two_decimals(): void
    {
        $this->assertTrue(Money::isValidStake('25'));
        $this->assertTrue(Money::isValidStake('0.01'));
        $this->assertTrue(Money::isValidStake('999999999.99'));

        $this->assertFalse(Money::isValidStake('0'));
        $this->assertFalse(Money::isValidStake('0.00'));
        $this->assertFalse(Money::isValidStake('-5'));
        $this->assertFalse(Money::isValidStake('25.505'));
        $this->assertFalse(Money::isValidStake('25,50'));
        $this->assertFalse(Money::isValidStake('1000000000'));
        $this->assertFalse(Money::isValidStake(''));
    }
}
