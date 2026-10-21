<?php

namespace Tests\Unit\Domain;

use App\Domain\Odds;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OddsTest extends TestCase
{
    public function test_accepts_only_decimal_odds_greater_than_one(): void
    {
        $this->assertTrue(Odds::isValid('1.01'));
        $this->assertTrue(Odds::isValid('2'));
        $this->assertFalse(Odds::isValid('1'));
        $this->assertFalse(Odds::isValid('1.000'));
        $this->assertFalse(Odds::isValid('0.5'));
        $this->assertFalse(Odds::isValid('-2.10'));
        $this->assertFalse(Odds::isValid('2,10'));
        $this->assertFalse(Odds::isValid('1e3'));
        $this->assertFalse(Odds::isValid(''));
    }

    public function test_total_odds_of_a_single_are_its_odds_to_four_places(): void
    {
        $this->assertSame('2.1000', Odds::total(['2.10']));
    }

    public function test_total_odds_are_the_product_of_the_selection_odds(): void
    {
        $this->assertSame('5.4000', Odds::total(['1.50', '2.00', '1.80']));
    }

    public function test_total_odds_round_half_up_to_four_places(): void
    {
        $this->assertSame('1.7769', Odds::total(['1.333', '1.333']));
        $this->assertSame('1.0201', Odds::total(['1.005', '1.015']));
    }

    public function test_total_odds_reject_an_empty_bet(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Odds::total([]);
    }

    public function test_total_odds_reject_invalid_odds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Odds::total(['1.50', '1.00']);
    }

    public function test_selection_odds_are_above_1_with_at_most_three_decimals(): void
    {
        $this->assertTrue(Odds::isValidSelectionOdds('1.95'));
        $this->assertTrue(Odds::isValidSelectionOdds('1.001'));
        $this->assertTrue(Odds::isValidSelectionOdds('1000'));
        $this->assertTrue(Odds::isValidSelectionOdds('9999999.999'));

        $this->assertFalse(Odds::isValidSelectionOdds('1'));
        $this->assertFalse(Odds::isValidSelectionOdds('1.000'));
        $this->assertFalse(Odds::isValidSelectionOdds('0.5'));
        $this->assertFalse(Odds::isValidSelectionOdds('1.9524'));
        $this->assertFalse(Odds::isValidSelectionOdds('10000000'));
        $this->assertFalse(Odds::isValidSelectionOdds('1,95'));
    }

    public function test_total_odds_are_at_most_the_highest_selection_odds(): void
    {
        $this->assertTrue(Odds::isValidTotal('4.0950'));
        $this->assertTrue(Odds::isValidTotal('9999999.9990'));

        $this->assertFalse(Odds::isValidTotal('9999999.9991'));
        $this->assertFalse(Odds::isValidTotal('10000005.0000'));
        $this->assertFalse(Odds::isValidTotal('1.0000'));
    }

    public function test_odds_are_formatted_without_trailing_zeros_but_with_two_decimals(): void
    {
        $this->assertSame('4.095', Odds::format('4.0950'));
        $this->assertSame('1.95', Odds::format('1.950'));
        $this->assertSame('2.70', Odds::format('2.7000'));
        $this->assertSame('2.00', Odds::format('2.000'));
        $this->assertSame('3.00', Odds::format('3'));
        $this->assertSame('1.0201', Odds::format('1.0201'));
    }

    public function test_formatting_rejects_invalid_odds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Odds::format('1.000');
    }
}
