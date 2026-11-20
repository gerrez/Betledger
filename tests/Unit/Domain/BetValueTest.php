<?php

namespace Tests\Unit\Domain;

use App\Domain\BetValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BetValueTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function calculations(): array
    {
        return [
            'value bet' => ['2.1000', '55', '115.50'],
            'fair odds' => ['2.0000', '50', '100.00'],
            'no value' => ['1.8000', '50', '90.00'],
            'rounds half up' => ['1.9500', '55.5', '108.23'],
            'rounds down' => ['1.9500', '55.51', '108.24'],
            'accumulator total odds' => ['2.7750', '40', '111.00'],
            'certain' => ['1.0100', '100', '101.00'],
            'highest total odds' => ['9999999.9990', '100', '999999999.90'],
        ];
    }

    #[DataProvider('calculations')]
    public function test_the_bet_value_is_the_total_odds_times_the_probability(string $totalOdds, string $probability, string $expected): void
    {
        $this->assertSame($expected, BetValue::fromProbability($totalOdds, $probability));
    }

    public function test_a_bet_stores_the_calculated_value_when_it_has_a_probability(): void
    {
        $this->assertSame('115.50', BetValue::forBet('2.1000', '55', null));
        $this->assertSame('115.50', BetValue::forBet('2.1000', '55', '130'));
    }

    public function test_a_bet_without_a_probability_stores_the_value_as_entered_or_none(): void
    {
        $this->assertSame('104.5', BetValue::forBet('2.1000', null, '104.5'));
        $this->assertNull(BetValue::forBet('2.1000', null, null));
    }

    public function test_the_calculation_refuses_invalid_odds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BetValue::fromProbability('1', '50');
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function probabilities(): array
    {
        return [
            'whole' => ['55', true],
            'two decimals' => ['55.55', true],
            'smallest' => ['0.01', true],
            'certain' => ['100', true],
            'certain with decimals' => ['100.00', true],
            'zero' => ['0', false],
            'above 100' => ['100.01', false],
            'three decimals' => ['55.555', false],
            'negative' => ['-5', false],
            'percent sign' => ['55%', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('probabilities')]
    public function test_a_probability_is_above_0_and_at_most_100_with_two_decimals(string $probability, bool $isValid): void
    {
        $this->assertSame($isValid, BetValue::isValidProbability($probability));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function betValues(): array
    {
        return [
            'whole' => ['105', true],
            'two decimals' => ['104.55', true],
            'below 100' => ['85.5', true],
            'highest' => ['999999999.99', true],
            'too large' => ['1000000000', false],
            'zero' => ['0', false],
            'three decimals' => ['104.555', false],
            'negative' => ['-5', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('betValues')]
    public function test_a_bet_value_is_above_0_with_two_decimals(string $betValue, bool $isValid): void
    {
        $this->assertSame($isValid, BetValue::isValid($betValue));
    }

    public function test_formats_a_percentage_without_trailing_zeros(): void
    {
        $this->assertSame('55.5%', BetValue::formatPercent('55.50'));
        $this->assertSame('107%', BetValue::formatPercent('107.00'));
        $this->assertSame('108.23%', BetValue::formatPercent('108.23'));
    }
}
