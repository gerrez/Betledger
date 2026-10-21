<?php

namespace Tests\Feature\Bets;

use App\Domain\Currency;
use App\Domain\Settlement\SelectionResult;
use App\Livewire\Bets\Index;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Competition;
use App\Models\Selection;
use App\Models\Sport;
use App\Models\User;
use Database\Factories\SelectionFactory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BetsListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-21 15:00:00');
    }

    public function test_without_bets_the_page_invites_the_user_to_record_one(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('bets.index'))
            ->assertOk()
            ->assertSee('No bets yet')
            ->assertSee(route('bets.create'));
    }

    public function test_the_tabs_show_open_settled_or_all_bets(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->openSingle($bookmaker, 'Open One v Two');
        $this->openSingle($bookmaker, 'Open Three v Four');
        Bet::factory()->forBookmaker($bookmaker)->has($this->selection(['event_name' => 'Settled One v Two']), 'selections')->settled()->create();
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->assertSee(['Open · 2', 'Open One v Two', 'Open Three v Four'])
            ->assertDontSee('Settled One v Two')
            ->set('status', 'settled')
            ->assertSee('Settled One v Two')
            ->assertDontSee(['Open One v Two', 'Open Three v Four'])
            ->set('status', 'all')
            ->assertSee(['Settled One v Two', 'Open One v Two', 'Open Three v Four'])
            ->set('status', 'nonsense')
            ->assertSee('Open One v Two')
            ->assertDontSee('Settled One v Two');
    }

    public function test_a_single_shows_its_event_bookmaker_start_stake_odds_and_pick(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.1341')->create(['name' => 'Danske Spil']);
        $football = Sport::factory()->for($user)->create();
        $bet = Bet::factory()
            ->forBookmaker($bookmaker)
            ->has($this->selection([
                'sport_id' => $football->id,
                'competition_id' => Competition::factory()->for($user)->forSport($football)->create(['name' => 'Premier League'])->id,
                'event_name' => 'Arsenal v Chelsea',
                'event_starts_at' => '2026-10-21 18:30:00',
                'pick' => 'Arsenal −0.25',
                'odds' => '1.95',
            ]), 'selections')
            ->freeBet()
            ->create(['stake' => 150000]);
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->assertSeeInOrder([
                route('bets.edit', $bet),
                'Arsenal v Chelsea',
                'Premier League · Danske Spil · today 18:30',
                '1,500.00 kr',
                '@ 1.95',
                'FREE BET',
                'Arsenal −0.25',
                '1.95',
            ]);
    }

    public function test_an_accumulator_lists_its_selections_with_their_results(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->inCurrency(Currency::Gbp, '1.17')->create(['name' => 'Bet365']);
        Bet::factory()
            ->forBookmaker($bookmaker)
            ->has(Selection::factory()->count(3)->state(new Sequence(
                ['position' => 1, 'pick' => 'Brentford (draw no bet)', 'event_name' => 'Brentford v Fulham', 'odds' => '1.80', 'result' => SelectionResult::Won],
                ['position' => 2, 'pick' => 'Both teams to score', 'event_name' => 'Celtic v Rangers', 'odds' => '1.65', 'result' => SelectionResult::HalfWon],
                ['position' => 3, 'pick' => 'Over 2.5 goals', 'event_name' => 'Bodø/Glimt v Molde', 'event_starts_at' => '2026-10-21 20:00:00', 'odds' => '1.82', 'result' => null],
            )), 'selections')
            ->create(['stake' => 5000, 'placed_at' => '2026-10-17 11:05:00']);
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->assertSeeInOrder([
                '3-fold accumulator',
                'Bet365 · placed Sat 11:05',
                '£50.00',
                '@ 5.4054',
                'Brentford (draw no bet)', 'Brentford v Fulham', '1.80', 'Won',
                'Both teams to score', 'Celtic v Rangers', '1.65', '½ Won',
                'Over 2.5 goals', 'Bodø/Glimt v Molde · today 20:00', '1.82', 'Pending',
            ])
            ->assertDontSee('Payout');
    }

    public function test_a_settled_bet_shows_its_result_payout_and_profit(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->settledAt('2026-10-20 10:00:00', Bet::factory()->forBookmaker($bookmaker)->has($this->selection(['event_name' => 'Half won', 'odds' => '1.95']), 'selections')
            ->settled(SelectionResult::HalfWon)->create(['stake' => 10000]));
        $this->settledAt('2026-10-19 10:00:00', Bet::factory()->forBookmaker($bookmaker)->has($this->selection(['event_name' => 'Lost one', 'odds' => '2.10']), 'selections')
            ->settled(SelectionResult::Lost)->create(['stake' => 20000]));
        $this->settledAt('2026-10-18 10:00:00', Bet::factory()->forBookmaker($bookmaker)->accumulator(['1.50', '2.00'])
            ->settled(SelectionResult::Void)->create(['stake' => 5000]));
        $this->actingAs($user);

        Livewire::withQueryParams(['status' => 'settled'])
            ->test(Index::class)
            ->assertSeeInOrder([
                'Half won', 'Settled: ½ Won', 'Payout €147.50 · profit +€47.50',
                'Lost one', 'Settled: Lost', 'Payout €0.00 · profit −€200.00',
                '2-fold accumulator', 'Void', 'Void', 'Settled', 'Payout €50.00 · profit €0.00',
            ]);
    }

    public function test_bets_can_be_filtered_by_bookmaker_and_sport(): void
    {
        $user = User::factory()->create();
        $unibet = Bookmaker::factory()->for($user)->create(['name' => 'Unibet']);
        $bet365 = Bookmaker::factory()->for($user)->create(['name' => 'Bet365']);
        $football = Sport::factory()->for($user)->create(['name' => 'Football']);
        $tennis = Sport::factory()->for($user)->create(['name' => 'Tennis']);
        $this->openSingle($unibet, 'Unibet football', ['sport_id' => $football->id]);
        $this->openSingle($bet365, 'Bet365 tennis', ['sport_id' => $tennis->id]);
        Bet::factory()->forBookmaker($bet365)->has(Selection::factory()->count(2)->state(new Sequence(
            ['position' => 1, 'sport_id' => $tennis->id, 'pick' => 'Acca tennis leg'],
            ['position' => 2, 'sport_id' => $football->id, 'pick' => 'Acca football leg'],
        )), 'selections')->create();
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->assertSee(['Open · 3', 'Unibet', 'Bet365', 'Football', 'Tennis'])
            ->set('bookmaker', (string) $bet365->id)
            ->assertSee(['Open · 2', 'Bet365 tennis', 'Acca tennis leg'])
            ->assertDontSee('Unibet football')
            ->set('sport', (string) $football->id)
            ->assertSee(['Open · 1', 'Acca football leg'])
            ->assertDontSee(['Unibet football', 'Bet365 tennis'])
            ->set('bookmaker', (string) $unibet->id)
            ->assertSee('Unibet football')
            ->set('sport', (string) $tennis->id)
            ->assertSee(['Open · 0', 'No open bets match these filters', 'Clear filters'])
            ->call('clearFilters')
            ->assertSet('bookmaker', '')
            ->assertSet('sport', '')
            ->assertSee(['Open · 3', 'Unibet football', 'Bet365 tennis', 'Acca tennis leg']);
    }

    public function test_open_bets_start_soonest_first_by_default(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->openSingle($bookmaker, 'No start time', ['event_starts_at' => null], ['placed_at' => '2026-10-21 12:00:00']);
        $this->openSingle($bookmaker, 'Starts Friday', ['event_starts_at' => '2026-10-23 20:00:00'], ['placed_at' => '2026-10-19 12:00:00']);
        $this->openSingle($bookmaker, 'Starts tonight', ['event_starts_at' => '2026-10-21 20:00:00'], ['placed_at' => '2026-10-18 12:00:00']);
        Bet::factory()->forBookmaker($bookmaker)->has(Selection::factory()->count(2)->state(new Sequence(
            ['position' => 1, 'pick' => 'Finished leg', 'event_starts_at' => '2026-10-20 18:00:00', 'result' => SelectionResult::Won],
            ['position' => 2, 'pick' => 'Acca leg on Thursday', 'event_starts_at' => '2026-10-22 18:00:00'],
        )), 'selections')->create(['placed_at' => '2026-10-20 12:00:00']);
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->assertSeeInOrder(['Starts soonest', 'Starts tonight', 'Acca leg on Thursday', 'Starts Friday', 'No start time'])
            ->set('sort', 'newest')
            ->assertSeeInOrder(['No start time', 'Acca leg on Thursday', 'Starts Friday', 'Starts tonight'])
            ->set('sort', 'oldest')
            ->assertSeeInOrder(['Starts tonight', 'Starts Friday', 'Acca leg on Thursday', 'No start time']);
    }

    public function test_settled_bets_show_the_latest_settled_first_and_a_new_tab_starts_with_its_own_order(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->settledAt('2026-10-20 12:00:00', Bet::factory()->forBookmaker($bookmaker)->has($this->selection(['event_name' => 'Placed first, settled last']), 'selections')
            ->settled()->create(['placed_at' => '2026-10-01 12:00:00']));
        $this->settledAt('2026-10-06 12:00:00', Bet::factory()->forBookmaker($bookmaker)->has($this->selection(['event_name' => 'Placed last, settled first']), 'selections')
            ->settled()->create(['placed_at' => '2026-10-05 12:00:00']));
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->set('sort', 'oldest')
            ->set('status', 'settled')
            ->assertSet('sort', '')
            ->assertSeeInOrder(['Recently settled', 'Placed first, settled last', 'Placed last, settled first'])
            ->set('sort', 'newest')
            ->assertSeeInOrder(['Placed last, settled first', 'Placed first, settled last'])
            ->set('sort', 'starts')
            ->assertSeeInOrder(['Placed first, settled last', 'Placed last, settled first']);
    }

    public function test_bets_are_paginated_and_changing_the_view_returns_to_the_first_page(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        Bet::factory()->forBookmaker($bookmaker)->count(Index::PER_PAGE + 1)
            ->sequence(fn (Sequence $sequence) => ['placed_at' => now()->subDays($sequence->index + 1)])
            ->has($this->selection(['event_starts_at' => null]), 'selections')
            ->create();
        $oldest = $user->bets()->oldest('placed_at')->firstOrFail();
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->assertDontSee(route('bets.edit', $oldest))
            ->call('nextPage')
            ->assertSee(route('bets.edit', $oldest))
            ->set('status', 'all')
            ->assertDontSee(route('bets.edit', $oldest))
            ->call('nextPage')
            ->set('sort', 'oldest')
            ->assertSee(route('bets.edit', $oldest));
    }

    public function test_the_message_from_saving_a_bet_is_shown(): void
    {
        $bet = Bet::factory()->create();

        $this->actingAs($bet->user)
            ->withSession(['status' => 'Bet saved.'])
            ->get(route('bets.index'))
            ->assertOk()
            ->assertSee('Bet saved.');
    }

    public function test_users_only_see_their_own_bets_bookmakers_and_sports(): void
    {
        $user = User::factory()->create();
        $this->openSingle(Bookmaker::factory()->for($user)->create(['name' => 'My bookie']), 'My event');

        $otherBookmaker = Bookmaker::factory()->create(['name' => 'Their bookie']);
        $otherSport = Sport::factory()->for($otherBookmaker->user)->create(['name' => 'Their sport']);
        $theirBet = $this->openSingle($otherBookmaker, 'Their event', ['sport_id' => $otherSport->id]);
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->set('status', 'all')
            ->assertSee(['My bookie', 'My event', 'Open · 1'])
            ->assertDontSee(['Their bookie', 'Their sport', 'Their event', route('bets.edit', $theirBet)])
            ->set('bookmaker', (string) $otherBookmaker->id)
            ->assertSee('No bets match these filters')
            ->assertDontSee('Their event')
            ->set('bookmaker', '')
            ->set('sport', (string) $otherSport->id)
            ->assertSee('No bets match these filters')
            ->assertDontSee('Their event');
    }

    /**
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $bet
     */
    private function openSingle(Bookmaker $bookmaker, string $eventName, array $selection = [], array $bet = []): Bet
    {
        return Bet::factory()
            ->forBookmaker($bookmaker)
            ->has($this->selection(['event_name' => $eventName, ...$selection]), 'selections')
            ->create($bet);
    }

    private function settledAt(string $time, Bet $bet): void
    {
        $bet->forceFill(['settled_at' => $time])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function selection(array $attributes): SelectionFactory
    {
        return Selection::factory()->state(['position' => 1, ...$attributes]);
    }
}
