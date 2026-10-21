<?php

namespace Tests\Feature\Statistics;

use App\Domain\BetType;
use App\Domain\Currency;
use App\Domain\Decimal;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Statistics\BetFigures;
use App\Domain\Statistics\Dimension;
use App\Domain\Statistics\Group;
use App\Domain\Statistics\StatisticsFilter;
use App\Domain\Statistics\StatisticsQuery;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Competition;
use App\Models\Market;
use App\Models\Selection;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsQueryTest extends TestCase
{
    use RefreshDatabase;

    private StatisticsQuery $query;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = app(StatisticsQuery::class);
        $this->user = User::factory()->create();
    }

    public function test_only_the_users_own_bets_count(): void
    {
        Bet::factory()->for($this->user)->settled()->create(['stake' => 1000]);
        $someoneElses = Bet::factory()->settled()->create(['stake' => 2000]);

        $this->assertSame([1000], $this->stakes(new StatisticsFilter));
        $this->assertSame([2000], array_map(fn (BetFigures $bet): int => $bet->stake, $this->query->bets($someoneElses->user, new StatisticsFilter)));
        $this->assertSame([], $this->stakes(new StatisticsFilter(bookmakerId: $someoneElses->bookmaker_id)));
    }

    public function test_amounts_are_converted_with_the_bets_own_rate(): void
    {
        $bookmaker = Bookmaker::factory()->for($this->user)->inCurrency(Currency::Gbp, '1.17')->create();
        Bet::factory()->forBookmaker($bookmaker)->single('2.10')->settled()->create(['stake' => 1000]);
        $bookmaker->update(['exchange_rate' => '2']);

        [$bet] = $this->query->bets($this->user, new StatisticsFilter);

        $this->assertSame(1170, $bet->stake);
        $this->assertSame(1287, $bet->profit);
    }

    public function test_settled_bets_count_by_settlement_date_and_open_bets_by_placed_date(): void
    {
        $this->settledBet(100, placedAt: '2026-09-20 12:00', settledAt: '2026-10-01 00:00');
        $this->settledBet(200, placedAt: '2026-10-10 12:00', settledAt: '2026-10-21 23:59');
        $this->settledBet(300, placedAt: '2026-10-10 12:00', settledAt: '2026-10-22 00:00');
        $this->settledBet(400, placedAt: '2026-09-29 12:00', settledAt: '2026-09-30 23:59');
        Bet::factory()->for($this->user)->create(['stake' => 500, 'placed_at' => '2026-10-21 23:00']);
        Bet::factory()->for($this->user)->create(['stake' => 600, 'placed_at' => '2026-09-30 22:00']);

        $this->assertSame([100, 200, 500], $this->stakes(new StatisticsFilter(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-21'))));
        $this->assertSame([100, 200, 300, 500], $this->stakes(new StatisticsFilter(from: CarbonImmutable::parse('2026-10-01'))));
        $this->assertSame([400, 600], $this->stakes(new StatisticsFilter(to: CarbonImmutable::parse('2026-09-30'))));
    }

    public function test_bet_filters(): void
    {
        $bookmaker = Bookmaker::factory()->for($this->user)->create();
        $tipster = Tipster::factory()->for($this->user)->create();
        $tag = Tag::factory()->for($this->user)->create();

        Bet::factory()->forBookmaker($bookmaker)->create(['stake' => 100]);
        Bet::factory()->for($this->user)->create(['stake' => 200, 'tipster_id' => $tipster->id]);
        Bet::factory()->for($this->user)->create(['stake' => 300])->tags()->attach($tag);
        Bet::factory()->for($this->user)->accumulator()->create(['stake' => 400]);
        Bet::factory()->for($this->user)->freeBet()->create(['stake' => 500]);

        $this->assertSame([100], $this->stakes(new StatisticsFilter(bookmakerId: $bookmaker->id)));
        $this->assertSame([200], $this->stakes(new StatisticsFilter(tipsterId: $tipster->id)));
        $this->assertSame([300], $this->stakes(new StatisticsFilter(tagId: $tag->id)));
        $this->assertSame([400], $this->stakes(new StatisticsFilter(type: BetType::Accumulator)));
        $this->assertSame([100, 200, 300, 500], $this->stakes(new StatisticsFilter(type: BetType::Single)));
        $this->assertSame([100, 200, 300, 400], $this->stakes(new StatisticsFilter(includeFreeBets: false)));
        $this->assertSame([100, 200, 300, 400, 500], $this->stakes(new StatisticsFilter));
    }

    public function test_selection_filters_match_a_bet_when_any_selection_matches(): void
    {
        $sport = Sport::factory()->for($this->user)->create();
        $competition = Competition::factory()->for($this->user)->for($sport)->create();
        $team = Team::factory()->for($this->user)->for($sport)->create();
        $market = Market::factory()->for($this->user)->create();

        $accumulator = Bet::factory()->for($this->user)->accumulator(['1.50', '2.00'])->create(['stake' => 100]);
        $this->selection($accumulator, 2)->update(['sport_id' => $sport->id, 'competition_id' => $competition->id, 'market_id' => $market->id]);
        $this->selection($accumulator, 2)->teams()->attach($team);
        Bet::factory()->for($this->user)->create(['stake' => 200]);

        $this->assertSame([100], $this->stakes(new StatisticsFilter(sportId: $sport->id)));
        $this->assertSame([100], $this->stakes(new StatisticsFilter(competitionId: $competition->id)));
        $this->assertSame([100], $this->stakes(new StatisticsFilter(teamId: $team->id)));
        $this->assertSame([100], $this->stakes(new StatisticsFilter(marketId: $market->id)));
        $this->assertSame([], $this->stakes(new StatisticsFilter(sportId: $sport->id, bookmakerId: Bookmaker::factory()->for($this->user)->create()->id)));
    }

    public function test_a_bet_is_reduced_to_its_figures_and_groups(): void
    {
        $football = Sport::factory()->for($this->user)->create(['name' => 'Football']);
        $premierLeague = Competition::factory()->for($this->user)->for($football)->create(['name' => 'Premier League']);
        $arsenal = Team::factory()->for($this->user)->for($football)->create(['name' => 'Arsenal']);
        $chelsea = Team::factory()->for($this->user)->for($football)->create(['name' => 'Chelsea']);
        $market = Market::factory()->for($this->user)->create(['name' => '1X2']);
        $bookmaker = Bookmaker::factory()->for($this->user)->create(['name' => 'Unibet']);
        $value = Tag::factory()->for($this->user)->create(['name' => 'value']);

        $bet = Bet::factory()->forBookmaker($bookmaker)->accumulator(['1.50', '2.00'])->freeBet()->create(['stake' => 1000]);
        $this->selection($bet, 1)->update(['sport_id' => $football->id, 'competition_id' => $premierLeague->id, 'market_id' => $market->id, 'closing_odds' => '1.40']);
        $this->selection($bet, 1)->teams()->attach([$arsenal->id, $chelsea->id]);
        $this->selection($bet, 2)->update(['sport_id' => $football->id, 'closing_odds' => '2.00']);
        $bet->tags()->attach($value);

        [$figures] = $this->query->bets($this->user, new StatisticsFilter);

        $this->assertSame(BetType::Accumulator, $figures->type);
        $this->assertTrue($figures->isFreeBet);
        $this->assertFalse($figures->isSettled());
        $this->assertSame('3.0000', $figures->totalOdds);
        $this->assertSame('0.0714285714', $figures->clv);
        $this->assertEquals([new Group($bookmaker->id, 'Unibet')], $figures->groupsIn(Dimension::Bookmaker));
        $this->assertEquals([new Group($football->id, 'Football')], $figures->groupsIn(Dimension::Sport));
        $this->assertEquals([new Group($premierLeague->id, 'Premier League'), Group::none()], $figures->groupsIn(Dimension::Competition));
        $this->assertEquals([new Group($market->id, '1X2'), Group::none()], $figures->groupsIn(Dimension::Market));
        $this->assertEquals([new Group($arsenal->id, 'Arsenal'), new Group($chelsea->id, 'Chelsea'), Group::none()], $figures->groupsIn(Dimension::Team));
        $this->assertEquals([new Group($value->id, 'value')], $figures->groupsIn(Dimension::Tag));
        $this->assertEquals([Group::none()], $figures->groupsIn(Dimension::Tipster));
    }

    public function test_a_settled_bet_has_its_profit_and_settlement_date(): void
    {
        $bet = $this->settledBet(1000, placedAt: '2026-10-01 12:00', settledAt: '2026-10-02 18:00');

        [$figures] = $this->query->bets($this->user, new StatisticsFilter);

        $this->assertSame($bet->profit, $figures->profit);
        $this->assertSame('2026-10-02 18:00:00', $figures->settledAt?->toDateTimeString());
        $this->assertSame(Decimal::normalize($bet->total_odds), $figures->factor);
    }

    public function test_a_lost_free_bet_is_a_loss_and_a_void_one_is_not(): void
    {
        Bet::factory()->for($this->user)->freeBet()->settled(SelectionResult::Lost)->create(['stake' => 100]);
        Bet::factory()->for($this->user)->freeBet()->settled(SelectionResult::Void)->create(['stake' => 200]);

        $figures = $this->query->bets($this->user, new StatisticsFilter);
        usort($figures, fn (BetFigures $a, BetFigures $b): int => $a->stake <=> $b->stake);

        $this->assertSame([0, 0], [$figures[0]->profit, $figures[1]->profit]);
        $this->assertSame([true, false], [$figures[0]->isLoss(), $figures[1]->isLoss()]);
    }

    private function settledBet(int $stake, string $placedAt, string $settledAt): Bet
    {
        $bet = Bet::factory()->for($this->user)->settled(SelectionResult::Won)->create(['stake' => $stake, 'placed_at' => $placedAt]);
        $bet->forceFill(['settled_at' => $settledAt])->save();

        return $bet;
    }

    private function selection(Bet $bet, int $position): Selection
    {
        return $bet->selections()->where('position', $position)->firstOrFail();
    }

    /**
     * The stakes of the user's bets that pass the filter, smallest first: each test gives
     * its bets different stakes to tell them apart.
     *
     * @return list<int>
     */
    private function stakes(StatisticsFilter $filter): array
    {
        $stakes = array_map(fn (BetFigures $bet): int => $bet->stake, $this->query->bets($this->user, $filter));
        sort($stakes);

        return $stakes;
    }
}
