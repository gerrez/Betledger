<?php

namespace Tests\Feature\Statistics;

use App\Domain\Settlement\SelectionResult;
use App\Domain\Statistics\StatisticsFilter;
use App\Domain\Statistics\StatisticsQuery;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Competition;
use App\Models\Market;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Statistics read a fixed number of queries however many bets there are, so a lazy
 * load per bet or selection shows up here before it shows up as a slow page.
 */
class StatisticsQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-21 15:00:00');

        $this->user = User::factory()->create();
    }

    public function test_the_statistics_query_reads_every_bet_in_six_queries(): void
    {
        $this->betsWithEverything(3);

        $queries = $this->queriesWhile(fn () => app(StatisticsQuery::class)->bets($this->user, new StatisticsFilter));

        // Bets, selections with their sport, competition and market, teams, tags,
        // bookmakers, tipsters.
        $this->assertSame(6, $queries);
    }

    public function test_the_statistics_page_runs_as_many_queries_for_many_bets_as_for_one(): void
    {
        $this->betsWithEverything(1);
        $this->actingAs($this->user);
        $forOne = $this->queriesWhile(fn () => $this->get(route('statistics'))->assertOk()->assertSee('Breakdown'));

        $this->betsWithEverything(5);

        $this->assertSame($forOne, $this->queriesWhile(fn () => $this->get(route('statistics'))->assertOk()->assertSee('6 settled bets')));
    }

    public function test_home_runs_as_many_queries_for_many_bets_as_for_one(): void
    {
        $this->betsWithEverything(1);
        $this->actingAs($this->user);
        $forOne = $this->queriesWhile(fn () => $this->get(route('dashboard'))->assertOk()->assertSee('1 bet pending'));

        $this->betsWithEverything(5);

        $this->assertSame($forOne, $this->queriesWhile(fn () => $this->get(route('dashboard'))->assertOk()->assertSee('6 bets pending')));
    }

    /**
     * For each count, a settled accumulator and an open single, with list entries of
     * their own (bookmaker, tipster, tag, sport, competition, market, teams), so loading
     * any of them per bet would add queries. All are in this month and the last 90 days.
     */
    private function betsWithEverything(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $bookmaker = Bookmaker::factory()->for($this->user)->create();
            $tipster = Tipster::factory()->for($this->user)->create();
            $tag = Tag::factory()->for($this->user)->create();
            $sport = Sport::factory()->for($this->user)->create();
            $competition = Competition::factory()->for($this->user)->for($sport)->create();
            $market = Market::factory()->for($this->user)->create();
            $teams = Team::factory()->for($this->user)->for($sport)->count(2)->create();

            $settled = Bet::factory()->forBookmaker($bookmaker)->accumulator(['1.50', '2.00'])->settled(SelectionResult::Won)
                ->create(['tipster_id' => $tipster->id, 'placed_at' => '2026-10-10 12:00']);
            $open = Bet::factory()->forBookmaker($bookmaker)->single('1.80')->create(['placed_at' => '2026-10-20 12:00']);

            foreach ([$settled, $open] as $bet) {
                $bet->tags()->attach($tag);

                foreach ($bet->selections as $selection) {
                    $selection->update(['sport_id' => $sport->id, 'competition_id' => $competition->id, 'market_id' => $market->id, 'closing_odds' => '1.70']);
                    $selection->teams()->attach($teams);
                }
            }
        }
    }

    private function queriesWhile(callable $action): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
