<?php

namespace Tests\Unit\Domain\Statistics;

use App\Domain\BetType;
use App\Domain\Statistics\BetFigures;
use App\Domain\Statistics\Dimension;
use App\Domain\Statistics\Group;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
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
        $bet = $this->bet(stake: 1000, profit: null, settledAt: null);

        $this->assertFalse($bet->isSettled());
        $this->assertNull($bet->profit);
        $this->assertSame(1000, $bet->stake);
    }

    public function test_a_settled_bet_needs_a_profit_and_an_open_bet_none(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->bet(stake: 1000, profit: null);
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
    private function bet(int $stake = 1000, ?int $profit = 0, string $exchangeRate = '1', string $totalOdds = '2.0000', ?string $settledAt = '2026-10-21 12:00', array $closingOdds = [], array $groups = []): BetFigures
    {
        return new BetFigures(
            stake: $stake,
            profit: $profit,
            exchangeRate: $exchangeRate,
            type: BetType::Accumulator,
            isFreeBet: false,
            totalOdds: $totalOdds,
            settledAt: $settledAt === null ? null : CarbonImmutable::parse($settledAt),
            closingOdds: $closingOdds,
            groups: $groups,
        );
    }
}
