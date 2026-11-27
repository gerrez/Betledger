<?php

namespace Tests\Feature\Bets;

use App\Livewire\Bets\Index;
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
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BetsListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bookmaker $bookmaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-21 15:00:00');
        $this->user = User::factory()->create();
        $this->bookmaker = Bookmaker::factory()->for($this->user)->create();
    }

    public function test_the_search_finds_bets_and_says_how_many_match(): void
    {
        $this->bet('Arsenal v Chelsea');
        $this->bet('Everton v Arsenal');
        $this->bet('Leeds v Burnley', bet: ['notes' => 'Arsenal fan tip']);
        $this->bet('Celtic v Rangers');
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSee('Open · 4')
            ->set('search', 'ARSENAL')
            ->assertSee(['Open · 3', '3 bets', 'Arsenal v Chelsea', 'Everton v Arsenal', 'Leeds v Burnley', 'Clear search'])
            ->assertDontSee('Celtic v Rangers')
            ->set('search', 'arsenal v')
            ->assertSee(['1 bet', 'Arsenal v Chelsea'])
            ->assertDontSee(['Everton v Arsenal', 'Leeds v Burnley'])
            ->set('search', 'Tottenham')
            ->assertSee(['Open · 0', 'No open bets match this search', 'Clear search'])
            ->call('clearAll')
            ->assertSet('search', '')
            ->assertSee(['Open · 4', 'Celtic v Rangers'])
            ->assertDontSee('Clear search');
    }

    public function test_each_filter_narrows_the_list_and_shows_a_chip(): void
    {
        $football = Sport::factory()->for($this->user)->create(['name' => 'Football']);
        $premierLeague = Competition::factory()->for($this->user)->forSport($football)->create(['name' => 'Premier League']);
        $corners = Market::factory()->for($this->user)->create(['name' => 'Corners']);
        $tipster = Tipster::factory()->for($this->user)->create(['name' => 'Jens']);
        $tag = Tag::factory()->for($this->user)->create(['name' => 'Value']);

        $this->bet('Competition bet', ['sport_id' => $football->id, 'competition_id' => $premierLeague->id]);
        $this->bet('Market bet', ['market_id' => $corners->id]);
        $this->bet('Tipster bet', bet: ['tipster_id' => $tipster->id]);
        $this->bet('Tagged bet')->tags()->attach($tag);
        Bet::factory()->forBookmaker($this->bookmaker)->has(Selection::factory()->count(2)->state(new Sequence(
            ['position' => 1, 'event_name' => 'Accumulator leg one'],
            ['position' => 2, 'event_name' => 'Accumulator leg two'],
        )), 'selections')->create();
        $this->actingAs($this->user);

        $everyBet = ['Competition bet', 'Market bet', 'Tipster bet', 'Tagged bet', 'Accumulator leg one'];
        $filters = [
            ['competition', (string) $premierLeague->id, 'Competition: Premier League', 'Competition bet'],
            ['market', (string) $corners->id, 'Market: Corners', 'Market bet'],
            ['tipster', (string) $tipster->id, 'Tipster: Jens', 'Tipster bet'],
            ['tag', (string) $tag->id, 'Tag: Value', 'Tagged bet'],
            ['type', 'accumulator', 'Accumulators', 'Accumulator leg one'],
        ];

        foreach ($filters as [$property, $value, $chip, $match]) {
            Livewire::test(Index::class)
                ->set($property, $value)
                ->assertSee(['Open · 1', 'Filters · 1', $chip, $match, '1 bet', 'Clear filters'])
                ->assertDontSee(array_values(array_diff($everyBet, [$match])))
                ->call('removeFilter', $property)
                ->assertSet($property, '')
                ->assertSee(['Open · 5', ...$everyBet])
                ->assertDontSee("Remove filter {$chip}");
        }

        Livewire::test(Index::class)
            ->set('type', 'single')
            ->assertSee(['Open · 4', 'Singles'])
            ->assertDontSee('Accumulator leg one');
    }

    public function test_the_date_range_takes_settled_bets_by_event_date_and_open_bets_by_placed_date(): void
    {
        Bet::factory()->forBookmaker($this->bookmaker)
            ->has(Selection::factory()->state(['position' => 1, 'event_name' => 'Settled, event in range', 'event_starts_at' => '2026-10-05 18:00:00']), 'selections')
            ->settled()
            ->create(['placed_at' => '2026-09-30 12:00:00']);
        $this->bet('Open, placed in range', ['event_starts_at' => '2026-10-25 18:00:00'], ['placed_at' => '2026-10-10 12:00:00']);
        $this->bet('Open, placed after the range', ['event_starts_at' => '2026-10-25 18:00:00'], ['placed_at' => '2026-10-11 12:00:00']);
        $this->bet('Open, placed before the range', ['event_starts_at' => '2026-10-25 18:00:00'], ['placed_at' => '2026-10-04 12:00:00']);
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->set('status', 'all')
            ->set('from', '2026-10-05')
            ->set('to', '2026-10-10')
            ->assertSee(['Settled, event in range', 'Open, placed in range', '5 Oct 2026 – 10 Oct 2026', '2 bets'])
            ->assertDontSee(['Open, placed after the range', 'Open, placed before the range'])
            ->set('from', '2026-10-10')
            ->set('to', '2026-10-05')
            ->assertSee(['Settled, event in range', 'Open, placed in range', '5 Oct 2026 – 10 Oct 2026'])
            ->set('to', 'not a date')
            ->assertSee(['From 10 Oct 2026', 'Open, placed after the range', 'Open, placed in range'])
            ->assertDontSee(['Settled, event in range', 'Open, placed before the range'])
            ->set('from', '')
            ->set('to', '2026-10-04')
            ->assertSee(['Until 4 Oct 2026', 'Open, placed before the range'])
            ->assertDontSee(['Settled, event in range', 'Open, placed in range'])
            ->call('removeFilter', 'dates')
            ->assertSet('to', '')
            ->assertSee(['4 bets']);
    }

    public function test_the_search_and_filters_combine_with_the_tab(): void
    {
        $tipster = Tipster::factory()->for($this->user)->create(['name' => 'Jens']);
        $tag = Tag::factory()->for($this->user)->create(['name' => 'Value']);
        $this->bet('Brentford v Fulham', bet: ['tipster_id' => $tipster->id])->tags()->attach($tag);
        $this->bet('Brentford v Wolves', bet: ['tipster_id' => $tipster->id]);
        $this->bet('Brentford v Spurs');
        Bet::factory()->forBookmaker($this->bookmaker)->has(Selection::factory()->state(['position' => 1, 'event_name' => 'Brentford v Leeds']), 'selections')
            ->settled()->create(['tipster_id' => $tipster->id]);
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->set('search', 'brentford')
            ->set('tipster', (string) $tipster->id)
            ->assertSee(['Open · 2', 'Brentford v Fulham', 'Brentford v Wolves', 'Clear search and filters'])
            ->assertDontSee(['Brentford v Spurs', 'Brentford v Leeds'])
            ->set('tag', (string) $tag->id)
            ->assertSee(['Filters · 2', 'Brentford v Fulham'])
            ->assertDontSee('Brentford v Wolves')
            ->set('status', 'settled')
            ->assertSee('No settled bets match this search and these filters')
            ->call('removeFilter', 'tag')
            ->assertSee('Brentford v Leeds')
            ->call('clearFilters')
            ->assertSet('tipster', '')
            ->assertSet('search', 'brentford')
            ->assertSee(['Brentford v Leeds', 'Clear search']);
    }

    public function test_changing_the_search_or_a_filter_returns_to_the_first_page(): void
    {
        Bet::factory()->forBookmaker($this->bookmaker)->count(Index::PER_PAGE + 1)
            ->sequence(fn (Sequence $sequence) => ['placed_at' => now()->subDays($sequence->index + 1)])
            ->has(Selection::factory()->state(['position' => 1, 'event_name' => 'Batch event', 'event_starts_at' => null]), 'selections')
            ->create();
        $oldest = $this->user->bets()->oldest('placed_at')->firstOrFail();
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->call('nextPage')
            ->assertSee(route('bets.show', $oldest))
            ->set('search', 'batch')
            ->assertDontSee(route('bets.show', $oldest))
            ->call('nextPage')
            ->assertSee(route('bets.show', $oldest))
            ->set('type', 'single')
            ->assertDontSee(route('bets.show', $oldest))
            ->call('nextPage')
            ->call('removeFilter', 'type')
            ->assertDontSee(route('bets.show', $oldest))
            ->call('nextPage')
            ->call('clearAll')
            ->assertDontSee(route('bets.show', $oldest));
    }

    public function test_a_filtered_list_can_be_linked_to(): void
    {
        $tipster = Tipster::factory()->for($this->user)->create(['name' => 'Jens']);
        $this->bet('Linked bet', bet: ['tipster_id' => $tipster->id, 'notes' => 'derby']);
        $this->bet('Other bet', bet: ['tipster_id' => $tipster->id]);
        $this->actingAs($this->user);

        $this->get(route('bets.index', ['status' => 'all', 'search' => 'derby', 'tipster' => $tipster->id]))
            ->assertOk()
            ->assertSee(['Tipster: Jens', 'Linked bet', '1 bet'])
            ->assertDontSee('Other bet');
    }

    public function test_neither_the_search_nor_another_users_ids_reveal_their_bets(): void
    {
        $this->bet('My derby');
        $other = User::factory()->create();
        $theirSport = Sport::factory()->for($other)->create(['name' => 'Their sport']);
        $theirCompetition = Competition::factory()->for($other)->forSport($theirSport)->create(['name' => 'Their league']);
        $theirMarket = Market::factory()->for($other)->create(['name' => 'Their market']);
        $theirTipster = Tipster::factory()->for($other)->create(['name' => 'Their tipster']);
        $theirTag = Tag::factory()->for($other)->create(['name' => 'Their tag']);
        $theirBet = Bet::factory()->for($other)
            ->has(Selection::factory()->state(['position' => 1, 'event_name' => 'Their derby', 'sport_id' => $theirSport->id, 'competition_id' => $theirCompetition->id, 'market_id' => $theirMarket->id]), 'selections')
            ->create(['tipster_id' => $theirTipster->id, 'notes' => 'secret note']);
        $theirBet->tags()->attach($theirTag);
        $this->actingAs($this->user);

        $component = Livewire::test(Index::class)
            ->set('status', 'all')
            ->assertDontSee(['Their league', 'Their market', 'Their tipster', 'Their tag'])
            ->set('search', 'derby')
            ->assertSee('My derby')
            ->assertDontSee('Their derby')
            ->set('search', 'secret')
            ->assertSee('No bets match this search')
            ->assertDontSee(route('bets.show', $theirBet))
            ->set('search', '');

        $theirTeam = Team::factory()->for($other)->forSport($theirSport)->create(['name' => 'Their team']);
        $theirBet->selections[0]->teams()->attach($theirTeam);

        foreach (['competition' => $theirCompetition, 'team' => $theirTeam, 'market' => $theirMarket, 'tipster' => $theirTipster, 'tag' => $theirTag] as $filter => $entry) {
            $component
                ->set($filter, (string) $entry->id)
                ->assertSee([ucfirst($filter).': unknown', 'No bets match these filters'])
                ->assertDontSee(['Their derby', $entry->getAttribute('name')])
                ->call('removeFilter', $filter);
        }

        Bet::factory()->for($other)->has(Selection::factory()->state(['position' => 1, 'event_name' => 'Their bare bet']), 'selections')->create();

        $component
            ->set('competition', 'none')
            ->set('team', 'none')
            ->set('tipster', 'none')
            ->set('tag', 'none')
            ->assertSee(['My derby', '1 bet'])
            ->assertDontSee(['Their derby', 'Their bare bet']);
    }

    public function test_link_only_filters_none_values_and_odds_and_free_bets_show_as_chips(): void
    {
        $football = Sport::factory()->for($this->user)->create(['name' => 'Football']);
        $arsenal = Team::factory()->for($this->user)->forSport($football)->create(['name' => 'Arsenal']);
        $premierLeague = Competition::factory()->for($this->user)->forSport($football)->create(['name' => 'Premier League']);
        $corners = Market::factory()->for($this->user)->create(['name' => 'Corners']);
        $tipster = Tipster::factory()->for($this->user)->create(['name' => 'Jens']);
        $tag = Tag::factory()->for($this->user)->create(['name' => 'Value']);

        $full = $this->bet('Full bet', ['sport_id' => $football->id, 'competition_id' => $premierLeague->id, 'market_id' => $corners->id, 'odds' => '1.50'], ['tipster_id' => $tipster->id, 'is_free_bet' => true]);
        $full->selections[0]->teams()->attach($arsenal);
        $full->tags()->attach($tag);
        $this->bet('Bare bet', ['odds' => '1.501']);
        $this->actingAs($this->user);

        $filters = [
            ['competition', 'none', 'No competition', 'Bare bet'],
            ['team', 'none', 'No teams', 'Bare bet'],
            ['market', 'none', 'No market', 'Bare bet'],
            ['tipster', 'none', 'No tipster', 'Bare bet'],
            ['tag', 'none', 'No tags', 'Bare bet'],
            ['team', (string) $arsenal->id, 'Team: Arsenal', 'Full bet'],
            ['odds', '1.01-1.50', 'Total odds 1.01–1.50', 'Full bet'],
            ['odds', '1.51-2.00', 'Total odds 1.51–2.00', 'Bare bet'],
            ['freeBets', 'exclude', 'No free bets', 'Bare bet'],
        ];

        foreach ($filters as [$property, $value, $chip, $match]) {
            Livewire::test(Index::class)
                ->set($property, $value)
                ->assertSee(['Open · 1', 'Filters · 1', "Remove filter {$chip}", $match, '1 bet'])
                ->assertDontSee($match === 'Full bet' ? 'Bare bet' : 'Full bet')
                ->call('removeFilter', $property)
                ->assertSet($property, '')
                ->assertSee(['Open · 2', 'Full bet', 'Bare bet']);
        }

        $this->get(route('bets.index', ['team' => 'none', 'odds' => '1.51-2.00', 'free' => 'exclude']))
            ->assertOk()
            ->assertSee(['No teams', 'Total odds 1.51–2.00', 'No free bets', 'Bare bet'])
            ->assertDontSee('Full bet');

        Livewire::test(Index::class)
            ->set('odds', 'not a band')
            ->set('sport', 'none')
            ->assertSee(['Sport: unknown', 'No open bets match these filters'])
            ->assertDontSee('Total odds');
    }

    /**
     * An open single at the user's bookmaker.
     *
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $bet
     */
    private function bet(string $eventName, array $selection = [], array $bet = []): Bet
    {
        return Bet::factory()
            ->forBookmaker($this->bookmaker)
            ->has(Selection::factory()->state(['position' => 1, 'event_name' => $eventName, 'pick' => 'Home', ...$selection]), 'selections')
            ->create($bet);
    }
}
