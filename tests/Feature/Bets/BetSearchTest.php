<?php

namespace Tests\Feature\Bets;

use App\Domain\Bets\BetFilterQuery;
use App\Domain\Statistics\StatisticsFilter;
use App\Models\Bet;
use App\Models\Competition;
use App\Models\Selection;
use App\Models\Sport;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BetSearchTest extends TestCase
{
    use RefreshDatabase;

    private BetFilterQuery $query;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = app(BetFilterQuery::class);
        $this->user = User::factory()->create();
    }

    public function test_the_search_looks_in_notes_events_picks_competitions_and_teams(): void
    {
        $sport = Sport::factory()->for($this->user)->create();
        $notes = $this->bet(notes: 'Tipped by the Copenhagen pod');
        $event = $this->bet(event: 'Aalborg v Brøndby');
        $pick = $this->bet(pick: 'Vejle to win');
        $competition = $this->bet();
        $competition->selections()->update(['sport_id' => $sport->id, 'competition_id' => Competition::factory()->for($this->user)->forSport($sport)->create(['name' => 'Superliga'])->id]);
        $team = $this->bet();
        $team->selections()->firstOrFail()->teams()->attach(Team::factory()->for($this->user)->for($sport)->create(['name' => 'Silkeborg IF']));
        $this->bet();

        $this->assertSame([$notes->id], $this->search('copenhagen'));
        $this->assertSame([$event->id], $this->search('aalborg v'));
        $this->assertSame([$pick->id], $this->search('VEJLE'));
        $this->assertSame([$competition->id], $this->search('superLIGA'));
        $this->assertSame([$team->id], $this->search('silkeborg'));
    }

    public function test_an_accumulator_matches_once_when_any_selection_matches(): void
    {
        $accumulator = Bet::factory()->for($this->user)->accumulator(['1.50', '2.00'])->create();
        $accumulator->selections()->update(['event_name' => 'Odense v Randers']);
        $this->bet();

        $this->assertSame([$accumulator->id], $this->search('Odense'));
    }

    public function test_percent_underscore_and_backslash_are_matched_literally(): void
    {
        $percent = $this->bet(pick: 'Over 50% possession');
        $underscore = $this->bet(notes: 'ref_42');
        $backslash = $this->bet(notes: 'Home\\Away');
        $this->bet(pick: 'Over 500 corners', notes: 'refs 42');

        $this->assertSame([$percent->id], $this->search('50%'));
        $this->assertSame([$underscore->id], $this->search('ref_'));
        $this->assertSame([$backslash->id], $this->search('e\\a'));
        $this->assertSame([], $this->search('%%'));
    }

    public function test_a_blank_term_changes_nothing(): void
    {
        $first = $this->bet();
        $second = $this->bet();

        $this->assertSame([$first->id, $second->id], $this->search('   '));
    }

    public function test_only_the_users_own_bets_are_searched(): void
    {
        $mine = $this->bet(event: 'Midtjylland v Nordsjælland');
        Bet::factory()->has(Selection::factory()->state(['position' => 1, 'event_name' => 'Midtjylland v Viborg']), 'selections')->create();

        $this->assertSame([$mine->id], $this->search('midtjylland'));
    }

    private function bet(?string $event = null, ?string $pick = null, ?string $notes = null): Bet
    {
        return Bet::factory()->for($this->user)
            ->has(Selection::factory()->state(['position' => 1, 'event_name' => $event ?? 'Team A v Team B', 'pick' => $pick ?? 'Home']), 'selections')
            ->create(['notes' => $notes]);
    }

    /**
     * @return list<int>
     */
    private function search(string $term): array
    {
        return array_values($this->query->search($this->query->filtered($this->user, new StatisticsFilter), $term)
            ->orderBy('id')
            ->pluck('id')
            ->all());
    }
}
