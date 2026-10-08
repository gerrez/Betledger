<?php

namespace Tests\Unit\Domain\Settlement;

use App\Domain\Settlement\Leg;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Settlement\SettlementCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SettlementCalculatorTest extends TestCase
{
    /**
     * The worked examples from docs/spec.md (stake 100.00 = 10000 minor units).
     *
     * @return array<string, array{bool, list<Leg>, string, int, int}>
     */
    public static function specWorkedExamples(): array
    {
        return [
            'single @ 2.10, won' => [
                false, [new Leg('2.10', SelectionResult::Won)], '2.1', 21000, 11000,
            ],
            'single @ 1.95, half won' => [
                false, [new Leg('1.95', SelectionResult::HalfWon)], '1.475', 14750, 4750,
            ],
            'single @ 1.95, half lost' => [
                false, [new Leg('1.95', SelectionResult::HalfLost)], '0.5', 5000, -5000,
            ],
            'acca 1.50 × 2.00 × 1.80, middle leg void' => [
                false,
                [
                    new Leg('1.50', SelectionResult::Won),
                    new Leg('2.00', SelectionResult::Void),
                    new Leg('1.80', SelectionResult::Won),
                ],
                '2.7', 27000, 17000,
            ],
            'free bet single @ 3.00, won' => [
                true, [new Leg('3.00', SelectionResult::Won)], '3', 20000, 20000,
            ],
            'free bet acca, one leg void, rest lost' => [
                true,
                [
                    new Leg('1.80', SelectionResult::Void),
                    new Leg('2.20', SelectionResult::Lost),
                    new Leg('1.60', SelectionResult::Lost),
                ],
                '0', 0, 0,
            ],
        ];
    }

    /**
     * @param  list<Leg>  $legs
     */
    #[DataProvider('specWorkedExamples')]
    public function test_settles_the_spec_worked_examples(bool $isFreeBet, array $legs, string $factor, int $payout, int $profit): void
    {
        $settlement = (new SettlementCalculator)->settle(10000, $isFreeBet, $legs);

        $this->assertNotNull($settlement);
        $this->assertSame($factor, $settlement->factor);
        $this->assertSame($payout, $settlement->payout);
        $this->assertSame($profit, $settlement->profit);
    }

    public function test_lost_single_loses_the_stake(): void
    {
        $settlement = (new SettlementCalculator)->settle(10000, false, [new Leg('2.10', SelectionResult::Lost)]);

        $this->assertNotNull($settlement);
        $this->assertSame('0', $settlement->factor);
        $this->assertSame(0, $settlement->payout);
        $this->assertSame(-10000, $settlement->profit);
    }

    public function test_void_single_returns_the_stake(): void
    {
        $settlement = (new SettlementCalculator)->settle(10000, false, [new Leg('2.10', SelectionResult::Void)]);

        $this->assertNotNull($settlement);
        $this->assertSame(10000, $settlement->payout);
        $this->assertSame(0, $settlement->profit);
    }

    public function test_void_free_bet_has_no_payout_and_no_profit(): void
    {
        $settlement = (new SettlementCalculator)->settle(10000, true, [new Leg('2.10', SelectionResult::Void)]);

        $this->assertNotNull($settlement);
        $this->assertSame(0, $settlement->payout);
        $this->assertSame(0, $settlement->profit);
    }

    public function test_half_won_free_bet_pays_only_the_winnings(): void
    {
        $settlement = (new SettlementCalculator)->settle(10000, true, [new Leg('1.95', SelectionResult::HalfWon)]);

        $this->assertNotNull($settlement);
        $this->assertSame(4750, $settlement->payout);
        $this->assertSame(4750, $settlement->profit);
    }

    public function test_half_lost_free_bet_pays_nothing(): void
    {
        $settlement = (new SettlementCalculator)->settle(10000, true, [new Leg('1.95', SelectionResult::HalfLost)]);

        $this->assertNotNull($settlement);
        $this->assertSame(0, $settlement->payout);
        $this->assertSame(0, $settlement->profit);
    }

    public function test_accumulator_multiplies_half_results_with_the_other_legs(): void
    {
        $legs = [
            new Leg('1.95', SelectionResult::HalfWon),
            new Leg('2.00', SelectionResult::Won),
            new Leg('1.80', SelectionResult::HalfLost),
        ];

        $settlement = (new SettlementCalculator)->settle(10000, false, $legs);

        $this->assertNotNull($settlement);
        $this->assertSame('1.475', $settlement->factor);
        $this->assertSame(14750, $settlement->payout);
        $this->assertSame(4750, $settlement->profit);
    }

    public function test_accumulator_where_every_leg_is_void_returns_the_stake(): void
    {
        $legs = [new Leg('1.50', SelectionResult::Void), new Leg('2.00', SelectionResult::Void)];

        $settlement = (new SettlementCalculator)->settle(10000, false, $legs);

        $this->assertNotNull($settlement);
        $this->assertSame('1', $settlement->factor);
        $this->assertSame(10000, $settlement->payout);
        $this->assertSame(0, $settlement->profit);
    }

    public function test_free_bet_accumulator_with_half_lost_leg_and_net_factor_above_one_pays_the_excess(): void
    {
        $legs = [new Leg('3.00', SelectionResult::Won), new Leg('1.90', SelectionResult::HalfLost)];

        $settlement = (new SettlementCalculator)->settle(10000, true, $legs);

        $this->assertNotNull($settlement);
        $this->assertSame('1.5', $settlement->factor);
        $this->assertSame(5000, $settlement->payout);
        $this->assertSame(5000, $settlement->profit);
    }

    public function test_accumulator_with_a_lost_leg_is_settled_as_lost_while_other_legs_are_pending(): void
    {
        $legs = [
            new Leg('1.50', SelectionResult::Won),
            new Leg('2.00', SelectionResult::Lost),
            new Leg('1.80'),
        ];

        $settlement = (new SettlementCalculator)->settle(10000, false, $legs);

        $this->assertNotNull($settlement);
        $this->assertSame('0', $settlement->factor);
        $this->assertSame(0, $settlement->payout);
        $this->assertSame(-10000, $settlement->profit);
    }

    public function test_accumulator_with_a_pending_leg_and_no_lost_leg_is_not_settled(): void
    {
        $calculator = new SettlementCalculator;
        $legs = [
            new Leg('1.50', SelectionResult::Won),
            new Leg('2.00', SelectionResult::HalfLost),
            new Leg('1.80'),
        ];

        $this->assertFalse($calculator->isSettled($legs));
        $this->assertNull($calculator->factor($legs));
        $this->assertNull($calculator->settle(10000, false, $legs));
    }

    public function test_pending_single_is_not_settled(): void
    {
        $this->assertNull((new SettlementCalculator)->settle(10000, false, [new Leg('2.10')]));
    }

    public function test_payout_rounds_half_up_to_whole_minor_units(): void
    {
        $calculator = new SettlementCalculator;

        $this->assertSame(5, $calculator->settle(3, false, [new Leg('1.50', SelectionResult::Won)])?->payout);
        $this->assertSame(1476, $calculator->settle(1001, false, [new Leg('1.95', SelectionResult::HalfWon)])?->payout);
        $this->assertSame(1478, $calculator->settle(1002, false, [new Leg('1.95', SelectionResult::HalfWon)])?->payout);
    }

    public function test_payout_is_rounded_once_and_not_per_leg(): void
    {
        $legs = [new Leg('1.50', SelectionResult::Won), new Leg('1.50', SelectionResult::Won)];

        $settlement = (new SettlementCalculator)->settle(1, false, $legs);

        $this->assertNotNull($settlement);
        $this->assertSame('2.25', $settlement->factor);
        $this->assertSame(2, $settlement->payout);
    }

    public function test_factor_of_many_half_won_legs_is_exact(): void
    {
        $legs = array_fill(0, 4, new Leg('1.333', SelectionResult::HalfWon));

        $factor = (new SettlementCalculator)->factor($legs);

        $this->assertSame('1.8515650416450625', $factor);
    }

    public function test_profit_follows_a_manual_payout(): void
    {
        $calculator = new SettlementCalculator;

        $this->assertSame(10500, $calculator->profit(10000, 20500, false));
        $this->assertSame(-10000, $calculator->profit(10000, 0, false));
        $this->assertSame(20500, $calculator->profit(10000, 20500, true));
    }

    public function test_rejects_a_bet_without_selections(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SettlementCalculator)->settle(10000, false, []);
    }

    public function test_rejects_a_stake_that_is_not_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SettlementCalculator)->settle(0, false, [new Leg('2.10', SelectionResult::Won)]);
    }

    public function test_rejects_a_negative_manual_payout(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SettlementCalculator)->profit(10000, -1, false);
    }
}
