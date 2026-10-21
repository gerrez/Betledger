<?php

namespace Tests\Unit\Domain\Statistics;

use App\Domain\BetType;
use App\Domain\Statistics\BetFigures;
use App\Domain\Statistics\Dimension;
use App\Domain\Statistics\Group;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BetFiguresTest extends TestCase
{
    public function test_stake_and_profit_are_converted_with_the_bets_own_rate_and_rounded_half_up_each(): void
    {
        $won = $this->bet(stake: 2500, profit: 3750, exchangeRate: '0.1341');
        $lost = $this->bet(stake: 1500, profit: -750, exchangeRate: '1.17');

        $this->assertSame([335, 503], [$won->stake, $won->profit]);
        $this->assertSame([1755, -878], [$lost->stake, $lost->profit]);
    }

    public function test_an_open_bet_has_no_profit(): void
    {
        $bet = $this->bet(stake: 1000, profit: null, factor: null, settledAt: null);

        $this->assertFalse($bet->isSettled());
        $this->assertNull($bet->profit);
        $this->assertSame(1000, $bet->stake);
    }

    public function test_a_settled_bet_needs_a_profit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->bet(stake: 1000, profit: null);
    }

    public function test_a_settled_bet_needs_a_factor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->bet(stake: 1000, factor: null);
    }

    public function test_an_open_bet_has_no_factor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->bet(stake: 1000, profit: null, factor: '1', settledAt: null);
    }

    /**
     * @return array<string, array{bool, int, string, bool, bool}>
     */
    public static function outcomes(): array
    {
        return [
            'cash bet won' => [false, 1100, '2.10', true, false],
            'cash bet half lost' => [false, -500, '0.5', false, true],
            'cash bet lost' => [false, -1000, '0', false, true],
            'cash bet void' => [false, 0, '1', false, false],
            'cash bet paid back its stake by hand' => [false, 0, '2.10', false, false],
            'free bet won' => [true, 2000, '3.00', true, false],
            'free bet lost' => [true, 0, '0', false, true],
            'free bet half lost' => [true, 0, '0.5', false, true],
            'free bet void' => [true, 0, '1', false, false],
            'free bet won with a manual payout of 0' => [true, 0, '3.00', false, false],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_wins_and_losses_for_the_strike_rate(bool $isFreeBet, int $profit, string $factor, bool $isWin, bool $isLoss): void
    {
        $bet = $this->bet(profit: $profit, factor: $factor, isFreeBet: $isFreeBet);

        $this->assertSame([$isWin, $isLoss], [$bet->isWin(), $bet->isLoss()]);
    }

    public function test_an_open_bet_is_neither_a_win_nor_a_loss(): void
    {
        $bet = $this->bet(profit: null, factor: null, settledAt: null, isFreeBet: true);

        $this->assertSame([false, false], [$bet->isWin(), $bet->isLoss()]);
    }

    public function test_clv_needs_closing_odds_on_every_selection(): void
    {
        $this->assertSame('0.0526315789', $this->bet(totalOdds: '3.0000', closingOdds: ['1.900', '1.500'])->clv);
        $this->assertNull($this->bet(totalOdds: '3.0000', closingOdds: ['1.900', null])->clv);
    }

    public function test_a_bet_counts_once_under_each_distinct_value_and_under_none_without_one(): void
    {
        $bet = $this->bet(groups: [
            Dimension::Sport->value => [new Group(1, 'Football'), new Group(2, 'Tennis'), new Group(1, 'Football')],
            Dimension::Competition->value => [new Group(10, 'Premier League'), Group::none(), Group::none()],
        ]);

        $this->assertEquals([new Group(1, 'Football'), new Group(2, 'Tennis')], $bet->groupsIn(Dimension::Sport));
        $this->assertEquals([new Group(10, 'Premier League'), Group::none()], $bet->groupsIn(Dimension::Competition));
        $this->assertEquals([Group::none()], $bet->groupsIn(Dimension::Tipster));
        $this->assertEquals([new Group('accumulator', 'Accumulators')], $bet->groupsIn(Dimension::BetType));
    }

    /**
     * @param  list<string|null>  $closingOdds
     * @param  array<value-of<Dimension>, list<Group>>  $groups
     */
    private function bet(int $stake = 1000, ?int $profit = 0, ?string $factor = '1', bool $isFreeBet = false, string $exchangeRate = '1', string $totalOdds = '2.0000', ?string $settledAt = '2026-10-21 12:00', array $closingOdds = [], array $groups = []): BetFigures
    {
        return new BetFigures(
            stake: $stake,
            profit: $profit,
            factor: $factor,
            exchangeRate: $exchangeRate,
            type: BetType::Accumulator,
            isFreeBet: $isFreeBet,
            totalOdds: $totalOdds,
            settledAt: $settledAt === null ? null : CarbonImmutable::parse($settledAt),
            closingOdds: $closingOdds,
            groups: $groups,
        );
    }
}
