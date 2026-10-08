<?php

namespace Tests\Unit\Domain;

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
}
