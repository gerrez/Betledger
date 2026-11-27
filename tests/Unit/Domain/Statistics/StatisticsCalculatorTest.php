<?php

namespace Tests\Unit\Domain\Statistics;

use App\Domain\BetType;
use App\Domain\Statistics\BetFigures;
use App\Domain\Statistics\BreakdownRow;
use App\Domain\Statistics\Dimension;
use App\Domain\Statistics\Drawdown;
use App\Domain\Statistics\Group;
use App\Domain\Statistics\OddsBand;
use App\Domain\Statistics\OddsBandRow;
use App\Domain\Statistics\Period;
use App\Domain\Statistics\ProfitPoint;
use App\Domain\Statistics\RiskFigures;
use App\Domain\Statistics\StatisticsCalculator;
use App\Domain\Statistics\Streak;
use App\Domain\Statistics\Totals;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Every expected number below was worked out by hand (and checked with `bc`) from the
 * fixture in bets(). Base currency EUR; Unibet is in EUR, Bet365 in GBP at 1.17 and
 * Danske Spil in DKK at 0.1341. In EUR minor units:
 *
 * | #  | Bookmaker   | Stake | Profit | Odds    | Settled    | Notes                         |
 * |----|-------------|-------|--------|---------|------------|-------------------------------|
 * | 1  | Unibet      | 2000  | +2200  | 2.1000  | 2026-10-01 | CLV +0.0769230769             |
 * | 2  | Bet365      | 1755  | −878   | 1.9500  | 2026-10-02 | −750 GBP × 1.17 = −877.5; CLV −0.0346534654 |
 * | 3  | Unibet      | 1000  | +1700  | 5.4000  | 2026-10-03 | acca, football + tennis; CLV +0.1020408163 |
 * | 4  | Bet365      | 1170  | +2340  | 3.0000  | 2026-10-05 | free bet                      |
 * | 5  | Danske Spil | 1341  | −1341  | 1.7500  | 2026-10-08 | CLV +0.0294117647             |
 * | 6  | Unibet      | 3000  | 0      | 2.0500  | 2026-10-08 | void: not in the strike rate; CLV 0 |
 * | 7  | Unibet      | 500   | 0      | 12.0000 | 2026-10-15 | free bet, lost: a loss in the strike rate |
 * | 8  | Danske Spil | 335   | +503   | 2.5000  | 2026-10-20 | 2500 DKK → 335.25; 3750 DKK → 502.875 |
 * | 9  | Unibet      | 5000  | +2500  | 1.5000  | 2026-10-21 |                               |
 * | 10 | Unibet      | 2000  | open   | 1.9000  |            |                               |
 * | 11 | Danske Spil | 1341  | open   | 3.6300  |            | acca, football + tennis       |
 * | 12 | Bet365      | 585   | open   | 1.8000  |            | free bet                      |
 */
class StatisticsCalculatorTest extends TestCase
{
    private const RATES = ['Unibet' => '1', 'Bet365' => '1.17', 'Danske Spil' => '0.1341'];

    private const IDS = [
        'Unibet' => 1, 'Bet365' => 2, 'Danske Spil' => 3,
        'Football' => 1, 'Tennis' => 2, 'Basketball' => 3,
        'Premier League' => 10, 'La Liga' => 11, 'Wimbledon' => 12, 'NBA' => 13, 'US Open' => 14, 'Champions League' => 15,
        'Arsenal' => 100, 'Chelsea' => 101, 'Real Madrid' => 102, 'FC Barcelona' => 103, 'Liverpool' => 104,
        'Carlos Alcaraz' => 105, 'Jannik Sinner' => 106, 'Boston Celtics' => 107, 'Los Angeles Lakers' => 108,
        '1X2' => 20, 'Asian handicap' => 21, 'Over/Under' => 22,
        'Own model' => 30,
        'value' => 40, 'live' => 41,
    ];

    private StatisticsCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new StatisticsCalculator;
    }

    public function test_totals_of_the_fixture(): void
    {
        $this->assertEquals(new Totals(
            settledBets: 9,
            openBets: 3,
            turnover: 14431,
            profit: 7024,
            roi: '0.4867299563',
            strikeRate: '0.6250000000',
            winningBets: 5,
            losingBets: 3,
            averageOdds: '3.5833333333',
            averageClv: '0.0347444385',
            clvBets: 5,
            beatCloseBets: 3,
            beatCloseRate: '0.6000000000',
            openStake: 3341,
        ), $this->calculator->totals($this->bets()));
    }

    public function test_totals_of_no_bets_have_no_ratios(): void
    {
        $this->assertEquals(new Totals(0, 0, 0, 0, null, null, 0, 0, null, null, 0, 0, null, 0), $this->calculator->totals([]));
    }

    public function test_free_bets_add_profit_but_no_turnover(): void
    {
        $totals = $this->calculator->totals([
            $this->bet('Bet365', 1000, 2000, '3.00', '3.0000', '2026-10-05', [$this->leg('Basketball', 'NBA')], isFreeBet: true),
            $this->bet('Bet365', 500, null, null, '1.8000', null, [$this->leg('Basketball', 'NBA')], isFreeBet: true),
        ]);

        $this->assertSame(0, $totals->turnover);
        $this->assertSame(2340, $totals->profit);
        $this->assertNull($totals->roi);
        $this->assertSame(0, $totals->openStake);
        $this->assertSame(1, $totals->openBets);
    }

    public function test_breakdown_by_sport_counts_an_accumulator_under_each_of_its_sports(): void
    {
        $rows = $this->calculator->breakdown($this->bets(), Dimension::Sport);

        $this->assertSame(['Football', 'Tennis', 'Basketball'], $this->labels($rows));
        $this->assertSame([7, 2, 1], array_map(fn (BreakdownRow $row): int => $row->totals->settledBets, $rows));

        [$football, $tennis, $basketball] = $rows;
        $this->assertSame(1, $football->group->key);
        $this->assertSame([13090, 6025, '0.4602750190', '0.6666666666', 2, 3341], $this->summary($football->totals));
        $this->assertSame([2341, 359, '0.1533532678', '0.5000000000', 1, 1341], $this->summary($tennis->totals));
        $this->assertSame('0.0657262905', $tennis->totals->averageClv);
        $this->assertSame([0, 2340, null, '1.0000000000', 1, 0], $this->summary($basketball->totals));
    }

    public function test_breakdown_by_bookmaker_orders_ties_by_name(): void
    {
        $rows = $this->calculator->breakdown($this->bets(), Dimension::Bookmaker);

        $this->assertSame(['Unibet', 'Bet365', 'Danske Spil'], $this->labels($rows));
        $this->assertSame([11000, 6400, '0.5818181818', '0.7500000000', 1, 2000], $this->summary($rows[0]->totals));
        $this->assertSame([1755, 1462, '0.8330484330', '0.5000000000', 1, 0], $this->summary($rows[1]->totals));
        $this->assertSame([1676, -838, '-0.5000000000', '0.5000000000', 1, 1341], $this->summary($rows[2]->totals));
    }

    public function test_bets_without_a_value_come_last(): void
    {
        $tipsters = $this->calculator->breakdown($this->bets(), Dimension::Tipster);

        $this->assertSame(['Own model', null], $this->labels($tipsters));
        $this->assertSame([3, 6], array_map(fn (BreakdownRow $row): int => $row->totals->settledBets, $tipsters));
        $this->assertTrue($tipsters[1]->group->isNone());
    }

    public function test_breakdown_by_market_counts_an_accumulator_without_a_market_on_one_selection_under_none_too(): void
    {
        $rows = $this->calculator->breakdown($this->bets(), Dimension::Market);

        $this->assertSame(['1X2', 'Over/Under', 'Asian handicap', null], $this->labels($rows));
        $this->assertSame([7, 2, 1, 1], array_map(fn (BreakdownRow $row): int => $row->totals->settledBets, $rows));
        $this->assertSame(1700, $rows[3]->totals->profit);
    }

    public function test_breakdown_by_tag_counts_a_bet_under_each_of_its_tags(): void
    {
        $rows = $this->calculator->breakdown($this->bets(), Dimension::Tag);

        $this->assertSame(['live', 'value', null], $this->labels($rows));
        $this->assertSame([2, 2, 6], array_map(fn (BreakdownRow $row): int => $row->totals->settledBets, $rows));
        $this->assertSame([4040, 3900, 784], array_map(fn (BreakdownRow $row): int => $row->totals->profit, $rows));
    }

    public function test_breakdown_by_team_covers_every_event_the_team_took_part_in(): void
    {
        $arsenal = $this->calculator->breakdown($this->bets(), Dimension::Team)[0];

        $this->assertSame('Arsenal', $arsenal->group->label);
        $this->assertSame([8000, 6400, '0.8000000000', '0.7500000000', 1, 2000], $this->summary($arsenal->totals));
        $this->assertSame(4, $arsenal->totals->settledBets);
    }

    public function test_breakdown_by_bet_type(): void
    {
        $rows = $this->calculator->breakdown($this->bets(), Dimension::BetType);

        $this->assertEquals([new Group('single', 'Singles'), new Group('accumulator', 'Accumulators')], array_map(fn (BreakdownRow $row): Group => $row->group, $rows));
        $this->assertSame([8, 2], [$rows[0]->totals->settledBets, $rows[0]->totals->openBets]);
        $this->assertSame([1, 1], [$rows[1]->totals->settledBets, $rows[1]->totals->openBets]);
    }

    public function test_odds_bands(): void
    {
        $rows = $this->calculator->oddsBands($this->bets());

        $this->assertSame(OddsBand::cases(), array_map(fn (OddsBandRow $row): OddsBand => $row->band, $rows));
        $this->assertSame([1, 2, 4, 0, 1, 1], array_map(fn (OddsBandRow $row): int => $row->totals->settledBets, $rows));
        $this->assertSame([0, 2, 0, 1, 0, 0], array_map(fn (OddsBandRow $row): int => $row->totals->openBets, $rows));
        $this->assertSame([5000, 3096, 5335, 0, 1000, 0], array_map(fn (OddsBandRow $row): int => $row->totals->turnover, $rows));
        $this->assertSame([2500, -2219, 5043, 0, 1700, 0], array_map(fn (OddsBandRow $row): int => $row->totals->profit, $rows));
        $this->assertSame(['0.5000000000', '-0.7167312661', '0.9452671040', null, '1.7000000000', null], array_map(fn (OddsBandRow $row): ?string => $row->totals->roi, $rows));
        $this->assertSame(['1.0000000000', '0.0000000000', '1.0000000000', null, '1.0000000000', '0.0000000000'], array_map(fn (OddsBandRow $row): ?string => $row->totals->strikeRate, $rows));
    }

    public function test_profit_over_time_by_week(): void
    {
        $series = $this->calculator->profitOverTime($this->bets(), period: Period::Week);

        $this->assertSame(Period::Week, $series->period);
        $this->assertSame([
            ['2026-09-28', 3022, 3022],
            ['2026-10-05', 999, 4021],
            ['2026-10-12', 0, 4021],
            ['2026-10-19', 3003, 7024],
        ], $this->points($series->points));
    }

    public function test_profit_over_time_by_month_fills_the_whole_range(): void
    {
        $series = $this->calculator->profitOverTime($this->bets(), $this->date('2026-09-01'), $this->date('2026-10-31'), Period::Month);

        $this->assertSame([['2026-09-01', 0, 0], ['2026-10-01', 7024, 7024]], $this->points($series->points));
    }

    public function test_profit_over_time_without_a_range_runs_from_the_first_to_the_last_settled_bet(): void
    {
        $series = $this->calculator->profitOverTime($this->bets());
        $points = $this->points($series->points);

        $this->assertSame(Period::Day, $series->period);
        $this->assertCount(21, $points);
        $this->assertSame(['2026-10-01', 2200, 2200], $points[0]);
        $this->assertSame(['2026-10-04', 0, 3022], $points[3]);
        $this->assertSame(['2026-10-08', -1341, 4021], $points[7]);
        $this->assertSame(['2026-10-21', 2500, 7024], $points[20]);
    }

    public function test_profit_over_time_picks_the_period_from_the_range(): void
    {
        $series = $this->calculator->profitOverTime($this->bets(), $this->date('2026-07-24'), $this->date('2026-10-21'));

        $this->assertSame(Period::Week, $series->period);
        $this->assertSame(['2026-07-20', 0, 0], $this->points($series->points)[0]);
        $this->assertSame(['2026-10-19', 3003, 7024], $this->points($series->points)[13]);
        $this->assertCount(14, $series->points);
    }

    public function test_profit_over_time_without_settled_bets_has_no_points(): void
    {
        $open = array_values(array_filter($this->bets(), fn (BetFigures $bet): bool => ! $bet->isSettled()));

        $this->assertSame([], $this->calculator->profitOverTime($open, $this->date('2026-10-01'), $this->date('2026-10-21'))->points);
        $this->assertSame([], $this->calculator->profitOverTime([])->points);
    }

    public function test_profit_over_time_needs_the_event_date_of_every_settled_bet(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->profitOverTime([$this->bet('Unibet', 1000, 1000, '2', '2.0000', null, [$this->leg('Football', 'Premier League')])]);
    }

    /**
     * In event order the cumulative profit runs 2200, 1322, 3022, 5362, 4021, 4021 (void),
     * 4021 (lost free bet), 4524, 7024: the largest fall is 5362 → 4021 and it has
     * recovered by the last bet. Win/loss run: W L W W L (push) L W W.
     */
    public function test_risk_figures_of_the_fixture(): void
    {
        $bets = $this->bets();
        $risk = $this->calculator->risk($bets);

        $this->assertEquals(new Drawdown(1341, $this->date('2026-10-05 23:30'), $this->date('2026-10-08 15:00')), $risk->maxDrawdown);
        $this->assertNull($risk->currentDrawdown);
        $this->assertSame([2, 2], [$risk->longestWinningStreak, $risk->longestLosingStreak]);
        $this->assertEquals(new Streak(true, 2), $risk->currentStreak);
        $this->assertSame($bets[8], $risk->biggestWin);
        $this->assertSame($bets[4], $risk->biggestLoss);
        // Settled cash stakes 14431 over 7 bets = 2061.57…
        $this->assertSame(2062, $risk->averageStake);
    }

    /**
     * Without the two free bets: 2200, 1322, 3022, 1681, 1681 (void), 2184, 4684. The
     * peak before the largest fall is now 3 October, and the losing run is only one.
     */
    public function test_risk_figures_count_free_bets_only_when_they_are_in_the_set(): void
    {
        $cash = array_values(array_filter($this->bets(), fn (BetFigures $bet): bool => ! $bet->isFreeBet));
        $risk = $this->calculator->risk($cash);

        $this->assertEquals(new Drawdown(1341, $this->date('2026-10-03 22:00'), $this->date('2026-10-08 15:00')), $risk->maxDrawdown);
        $this->assertSame([2, 1], [$risk->longestWinningStreak, $risk->longestLosingStreak]);
        $this->assertSame(2062, $risk->averageStake);
    }

    public function test_risk_figures_without_settled_bets(): void
    {
        $open = array_values(array_filter($this->bets(), fn (BetFigures $bet): bool => ! $bet->isSettled()));

        $this->assertEquals(new RiskFigures(null, null, 0, 0, null, null, null, null), $this->calculator->risk($open));
    }

    public function test_risk_figures_with_only_wins_have_no_drawdown_or_loss(): void
    {
        $risk = $this->calculator->risk([
            $this->settled(1000, 500, '2026-10-01'),
            $this->settled(1000, 1200, '2026-10-02'),
            $this->settled(1000, 300, '2026-10-03'),
        ]);

        $this->assertNull($risk->maxDrawdown);
        $this->assertNull($risk->currentDrawdown);
        $this->assertSame([3, 0], [$risk->longestWinningStreak, $risk->longestLosingStreak]);
        $this->assertEquals(new Streak(true, 3), $risk->currentStreak);
        $this->assertSame(1200, $risk->biggestWin?->profit);
        $this->assertNull($risk->biggestLoss);
    }

    /**
     * Cumulative 1000, 200, 700, 600: the largest fall (800) is over, but the profit is
     * still 400 below the peak of 1 October.
     */
    public function test_current_drawdown_is_the_fall_from_the_peak_to_the_last_bet(): void
    {
        $risk = $this->calculator->risk([
            $this->settled(1000, 1000, '2026-10-01'),
            $this->settled(800, -800, '2026-10-02'),
            $this->settled(500, 500, '2026-10-03'),
            $this->settled(100, -100, '2026-10-04'),
        ]);

        $this->assertEquals(new Drawdown(800, $this->date('2026-10-01'), $this->date('2026-10-02')), $risk->maxDrawdown);
        $this->assertEquals(new Drawdown(400, $this->date('2026-10-01'), $this->date('2026-10-04')), $risk->currentDrawdown);
        $this->assertEquals(new Streak(false, 1), $risk->currentStreak);
    }

    public function test_a_drawdown_from_the_first_bet_has_no_peak_date(): void
    {
        $risk = $this->calculator->risk([
            $this->settled(500, -500, '2026-10-01'),
            $this->settled(200, 200, '2026-10-02'),
        ]);

        $this->assertEquals(new Drawdown(500, null, $this->date('2026-10-01')), $risk->maxDrawdown);
        $this->assertEquals(new Drawdown(300, null, $this->date('2026-10-02')), $risk->currentDrawdown);
    }

    /**
     * In event order, ties by id: +100 (id 2), −300 (id 3), +50 (id 1): cumulative 100,
     * −200, −150. Taken in the order given, or by time alone, the peak would differ.
     */
    public function test_risk_figures_take_bets_in_event_order_with_ties_by_id(): void
    {
        $risk = $this->calculator->risk([
            $this->settled(50, 50, '2026-10-02 12:00', betId: 1),
            $this->settled(300, -300, '2026-10-01 18:00', betId: 3),
            $this->settled(100, 100, '2026-10-01 18:00', betId: 2),
        ]);

        $this->assertEquals(new Drawdown(300, $this->date('2026-10-01 18:00'), $this->date('2026-10-01 18:00')), $risk->maxDrawdown);
        $this->assertEquals(new Drawdown(250, $this->date('2026-10-01 18:00'), $this->date('2026-10-02 12:00')), $risk->currentDrawdown);
        $this->assertEquals(new Streak(true, 1), $risk->currentStreak);
    }

    public function test_pushes_neither_extend_nor_break_a_streak(): void
    {
        $risk = $this->calculator->risk([
            $this->settled(100, -100, '2026-10-01'),
            $this->settled(100, 0, '2026-10-02', factor: '1'),
            $this->settled(100, -100, '2026-10-03'),
        ]);

        $this->assertSame(2, $risk->longestLosingStreak);
        $this->assertEquals(new Streak(false, 2), $risk->currentStreak);
    }

    public function test_average_stake_leaves_out_free_bets_and_rounds_half_up_once(): void
    {
        $risk = $this->calculator->risk([
            $this->settled(1000, 1000, '2026-10-01'),
            $this->settled(1001, -1001, '2026-10-02'),
            $this->settled(5000, 5000, '2026-10-03', isFreeBet: true),
        ]);

        $this->assertSame(1001, $risk->averageStake);
    }

    public function test_risk_figures_need_the_event_date_of_every_settled_bet(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->risk([$this->bet('Unibet', 1000, 1000, '2', '2.0000', null, [$this->leg('Football', 'Premier League')])]);
    }

    /**
     * A settled single at Unibet (EUR) on football; the factor follows from the profit
     * unless given.
     */
    private function settled(int $stake, int $profit, string $eventAt, ?string $factor = null, bool $isFreeBet = false, ?int $betId = null): BetFigures
    {
        return new BetFigures(
            stake: $stake,
            profit: $profit,
            factor: $factor ?? ($profit > 0 ? '2' : ($profit < 0 ? '0' : '1')),
            exchangeRate: '1',
            type: BetType::Single,
            isFreeBet: $isFreeBet,
            totalOdds: '2.0000',
            eventAt: CarbonImmutable::parse($eventAt),
            betId: $betId,
        );
    }

    /**
     * @return list<BetFigures>
     */
    private function bets(): array
    {
        return [
            $this->bet('Unibet', 2000, 2200, '2.10', '2.1000', '2026-10-01 10:00', [$this->leg('Football', 'Premier League', '1X2', ['Arsenal', 'Chelsea'])], tipster: 'Own model', tags: ['value'], closingOdds: ['1.950']),
            $this->bet('Bet365', 1500, -750, '0.5', '1.9500', '2026-10-02 21:00', [$this->leg('Football', 'La Liga', 'Asian handicap', ['Real Madrid', 'FC Barcelona'])], closingOdds: ['2.020']),
            $this->bet('Unibet', 1000, 1700, '2.70', '5.4000', '2026-10-03 22:00', [
                $this->leg('Football', 'Premier League', '1X2', ['Liverpool', 'Arsenal']),
                $this->leg('Tennis', 'Wimbledon', null, ['Carlos Alcaraz', 'Jannik Sinner']),
                $this->leg('Football', 'La Liga', 'Over/Under', ['Real Madrid', 'FC Barcelona']),
            ], tipster: 'Own model', tags: ['value', 'live'], closingOdds: ['1.400', '2.000', '1.750']),
            $this->bet('Bet365', 1000, 2000, '3.00', '3.0000', '2026-10-05 23:30', [$this->leg('Basketball', 'NBA', '1X2', ['Boston Celtics', 'Los Angeles Lakers'])], isFreeBet: true, tags: ['live']),
            $this->bet('Danske Spil', 10000, -10000, '0', '1.7500', '2026-10-08 15:00', [$this->leg('Tennis', 'US Open', '1X2', ['Jannik Sinner', 'Carlos Alcaraz'])], tipster: 'Own model', closingOdds: ['1.700']),
            $this->bet('Unibet', 3000, 0, '1', '2.0500', '2026-10-08 20:00', [$this->leg('Football', 'Premier League', 'Over/Under', ['Chelsea', 'Liverpool'])], closingOdds: ['2.050']),
            $this->bet('Unibet', 500, 0, '0', '12.0000', '2026-10-15 20:00', [$this->leg('Football', 'Premier League', '1X2', ['Arsenal', 'Chelsea'])], isFreeBet: true),
            $this->bet('Danske Spil', 2500, 3750, '2.50', '2.5000', '2026-10-20 22:00', [$this->leg('Football', 'Champions League', '1X2', ['Liverpool', 'Real Madrid'])]),
            $this->bet('Unibet', 5000, 2500, '1.50', '1.5000', '2026-10-21 18:00', [$this->leg('Football', 'Premier League', '1X2', ['Arsenal', 'Liverpool'])]),
            $this->bet('Unibet', 2000, null, null, '1.9000', null, [$this->leg('Football', 'Premier League', 'Over/Under', ['Arsenal', 'Liverpool'])]),
            $this->bet('Danske Spil', 10000, null, null, '3.6300', null, [
                $this->leg('Football', 'Premier League', '1X2', ['Liverpool', 'Chelsea']),
                $this->leg('Tennis', 'US Open', '1X2', ['Jannik Sinner', 'Carlos Alcaraz']),
            ]),
            $this->bet('Bet365', 500, null, null, '1.8000', null, [$this->leg('Basketball', 'NBA', '1X2', ['Boston Celtics', 'Los Angeles Lakers'])], isFreeBet: true),
        ];
    }

    /**
     * @param  int  $stake  minor units of the bookmaker's currency
     * @param  int|null  $profit  minor units of the bookmaker's currency
     * @param  string|null  $factor  the bet factor, null while open
     * @param  list<array{sport: string, competition: string, market: string|null, teams: list<string>}>  $legs
     * @param  list<string>  $tags
     * @param  list<string|null>  $closingOdds
     */
    private function bet(string $bookmaker, int $stake, ?int $profit, ?string $factor, string $totalOdds, ?string $eventAt, array $legs, bool $isFreeBet = false, ?string $tipster = null, array $tags = [], array $closingOdds = []): BetFigures
    {
        $group = fn (string $name): Group => new Group(self::IDS[$name], $name);

        return new BetFigures(
            stake: $stake,
            profit: $profit,
            factor: $factor,
            exchangeRate: self::RATES[$bookmaker],
            type: BetType::forSelectionCount(count($legs)),
            isFreeBet: $isFreeBet,
            totalOdds: $totalOdds,
            closingOdds: $closingOdds,
            groups: [
                Dimension::Bookmaker->value => [$group($bookmaker)],
                Dimension::Tipster->value => $tipster === null ? [] : [$group($tipster)],
                Dimension::Tag->value => array_map($group, $tags),
                Dimension::Sport->value => array_map(fn (array $leg): Group => $group($leg['sport']), $legs),
                Dimension::Competition->value => array_map(fn (array $leg): Group => $group($leg['competition']), $legs),
                Dimension::Market->value => array_map(fn (array $leg): Group => $leg['market'] === null ? Group::none() : $group($leg['market']), $legs),
                Dimension::Team->value => array_merge(...array_map(fn (array $leg): array => array_map($group, $leg['teams']), $legs)),
            ],
            eventAt: $eventAt === null ? null : CarbonImmutable::parse($eventAt),
        );
    }

    /**
     * @param  list<string>  $teams
     * @return array{sport: string, competition: string, market: string|null, teams: list<string>}
     */
    private function leg(string $sport, string $competition, ?string $market = '1X2', array $teams = []): array
    {
        return ['sport' => $sport, 'competition' => $competition, 'market' => $market, 'teams' => $teams];
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    /**
     * @param  list<BreakdownRow>  $rows
     * @return list<string|null>
     */
    private function labels(array $rows): array
    {
        return array_map(fn (BreakdownRow $row): ?string => $row->group->label, $rows);
    }

    /**
     * Turnover, profit, ROI, strike rate, open bets and open stake.
     *
     * @return array{int, int, string|null, string|null, int, int}
     */
    private function summary(Totals $totals): array
    {
        return [$totals->turnover, $totals->profit, $totals->roi, $totals->strikeRate, $totals->openBets, $totals->openStake];
    }

    /**
     * @param  list<ProfitPoint>  $points
     * @return list<array{string, int, int}>
     */
    private function points(array $points): array
    {
        return array_map(fn (ProfitPoint $point): array => [$point->start->toDateString(), $point->profit, $point->cumulativeProfit], $points);
    }
}
