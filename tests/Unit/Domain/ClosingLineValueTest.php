<?php

namespace Tests\Unit\Domain;

use App\Domain\ClosingLineValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClosingLineValueTest extends TestCase
{
    public function test_a_bets_clv_is_its_total_odds_over_the_closing_odds_minus_one(): void
    {
        $this->assertSame('0.0372340425', ClosingLineValue::forBet('1.9500', ['1.880']));
        $this->assertSame('0.0526315789', ClosingLineValue::forBet('3.0000', ['1.900', '1.500']));
        $this->assertSame('-0.0714285715', ClosingLineValue::forBet('1.9500', ['2.100']));
        $this->assertSame('0.0000000000', ClosingLineValue::forBet('2.0000', ['2.000']));
    }

    public function test_a_bet_needs_closing_odds_on_every_selection(): void
    {
        $this->assertNull(ClosingLineValue::forBet('3.0000', ['1.900', null]));
        $this->assertNull(ClosingLineValue::forBet('3.0000', []));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function percentages(): array
    {
        return [
            'positive' => ['0.0372340425', '+3.7%'],
            'rounds half up' => ['0.0365', '+3.7%'],
            'rounds down' => ['0.03649', '+3.6%'],
            'negative' => ['-0.0714285714', "\u{2212}7.1%"],
            'negative half rounds away from zero' => ['-0.0365', "\u{2212}3.7%"],
            'zero' => ['0', '0.0%'],
            'rounds to zero from below' => ['-0.0004', '0.0%'],
            'rounds to zero from above' => ['0.0004', '0.0%'],
            'large' => ['1.5', '+150.0%'],
        ];
    }

    #[DataProvider('percentages')]
    public function test_formats_a_clv_as_a_signed_percentage(string $clv, string $expected): void
    {
        $this->assertSame($expected, ClosingLineValue::formatPercent($clv));
    }
}
