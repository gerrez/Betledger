<?php

namespace Tests\Feature\Bets;

use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Currency;
use App\Domain\Settlement\SelectionResult;
use App\Livewire\Bets\Create;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DuplicateBetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bet_page_links_to_a_copy_of_the_bet(): void
    {
        $bet = Bet::factory()->create();

        $this->actingAs($bet->user)
            ->get(route('bets.show', $bet))
            ->assertOk()
            ->assertSee('Duplicate bet')
            ->assertSee(route('bets.create', ['from' => $bet]), escape: false);
    }

    public function test_every_field_is_copied_except_placed_results_and_closing_odds(): void
    {
        $this->travelTo('2026-11-27 19:05:00');
        $user = User::factory()->create(['timezone' => 'Europe/Copenhagen']);
        $bookmaker = Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.1341')->create();
        $football = Sport::factory()->for($user)->create(['name' => 'Football']);
        $bet = Bet::factory()
            ->forBookmaker($bookmaker)
            ->has(Selection::factory()->state([
                'sport_id' => $football->id,
                'competition_id' => Competition::factory()->for($user)->forSport($football)->create(['name' => 'Premier League'])->id,
                'market_id' => Market::factory()->for($user)->create(['name' => 'Asian handicap'])->id,
                'event_name' => 'Arsenal v Chelsea',
                'event_starts_at' => '2026-11-28 17:30:00',
                'pick' => 'Arsenal −0.25',
                'odds' => '1.95',
                'closing_odds' => '1.90',
            ]), 'selections')
            ->freeBet()
            ->settled(SelectionResult::Won)
            ->create([
                'tipster_id' => Tipster::factory()->for($user)->create(['name' => 'Own model'])->id,
                'placed_at' => '2026-11-20 10:00:00',
                'stake' => 20050,
                'exchange_rate' => '0.13',
                'notes' => 'Team news looked good.',
                'probability' => '55.5',
                'external_ref' => 'sportmarket:123',
            ]);
        $bet->tags()->attach(Tag::factory()->for($user)->create(['name' => 'value']));
        $bet->selections()->sole()->teams()->attach([
            Team::factory()->for($user)->forSport($football)->create(['name' => 'Arsenal'])->id,
            Team::factory()->for($user)->forSport($football)->create(['name' => 'Chelsea'])->id,
        ]);
        $this->actingAs($user);

        $component = Livewire::withQueryParams(['from' => $bet->id])->test(Create::class)
            ->assertSet('bookmaker_id', (string) $bookmaker->id)
            ->assertSet('placed_date', '2026-11-27')
            ->assertSet('placed_time', '20:05')
            ->assertSet('stake', '200.50')
            ->assertSet('exchange_rate', '0.13')
            ->assertSet('is_free_bet', true)
            ->assertSet('tipster', 'Own model')
            ->assertSet('tags', ['value'])
            ->assertSet('notes', 'Team news looked good.')
            ->assertSet('probability', '55.5')
            ->assertSet('showDetails', true)
            ->assertSet('selections', [[
                'id' => null,
                'sport' => 'Football',
                'competition' => 'Premier League',
                'market' => 'Asian handicap',
                'teams' => ['Arsenal', 'Chelsea'],
                'team_name' => '',
                'event_name' => 'Arsenal v Chelsea',
                'event_starts_date' => '2026-11-28',
                'event_starts_time' => '18:30',
                'pick' => 'Arsenal −0.25',
                'odds' => '1.95',
                'closing_odds' => '',
                'result' => '',
                'show_details' => true,
            ]])
            ->assertSee(['New bet', 'Save bet']);

        $component->call('save')->assertHasNoErrors()->assertRedirect(route('bets.index'));

        $copy = $user->bets()->whereKeyNot($bet->id)->with('selections')->sole();
        $this->assertSame(BetStatus::Open, $copy->status);
        $this->assertNull($copy->payout);
        $this->assertNull($copy->external_ref);
        $this->assertSame('2026-11-27 19:05:00', $copy->placed_at->format('Y-m-d H:i:s'));
        $this->assertNull($copy->selections->sole()->closing_odds);
        $this->assertSame(BetStatus::Settled, $bet->fresh()?->status);
        $this->assertSame('sportmarket:123', $bet->fresh()?->external_ref);
    }

    public function test_an_accumulator_is_copied_with_every_selection_in_order(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $bet = Bet::factory()->forBookmaker($bookmaker)->accumulator(['1.50', '2.00', '1.80'])
            ->withResults(SelectionResult::Won, SelectionResult::Lost, null)
            ->create();
        $picks = $bet->selections()->orderBy('position')->pluck('pick')->all();
        $this->actingAs($user);

        $component = Livewire::withQueryParams(['from' => $bet->id])->test(Create::class)
            ->assertSee('Total odds (3 selections)');

        $this->assertSame(['1.50', '2.00', '1.80'], array_column($component->get('selections'), 'odds'));
        $this->assertSame($picks, array_column($component->get('selections'), 'pick'));
        $this->assertSame(['', '', ''], array_column($component->get('selections'), 'result'));
        $this->assertSame([null, null, null], array_column($component->get('selections'), 'id'));

        $component->call('save')->assertHasNoErrors();

        $copy = $user->bets()->whereKeyNot($bet->id)->with('selections')->sole();
        $this->assertSame(BetType::Accumulator, $copy->type);
        $this->assertSame(BetStatus::Open, $copy->status);
        $this->assertSame('5.4000', $copy->total_odds);
        $this->assertSame($picks, $copy->selections->sortBy('position')->pluck('pick')->values()->all());
        $this->assertSame(3, $bet->selections()->count());
    }

    public function test_a_copy_of_a_bet_at_a_deactivated_bookmaker_uses_an_active_one(): void
    {
        $user = User::factory()->create();
        $active = Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.1341')->create();
        $deactivated = Bookmaker::factory()->for($user)->inactive()->create();
        $bet = Bet::factory()->forBookmaker($deactivated)->create();
        $this->actingAs($user);

        Livewire::withQueryParams(['from' => $bet->id])->test(Create::class)
            ->assertSet('bookmaker_id', (string) $active->id)
            ->assertSet('exchange_rate', '0.1341');
    }

    public function test_another_users_bet_cannot_be_copied(): void
    {
        $someoneElses = Bet::factory()->create(['notes' => 'Secret notes']);
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('bets.create', ['from' => $someoneElses->id]))
            ->assertNotFound();

        $this->assertSame(0, $user->bets()->count());
    }

    public function test_an_unknown_or_malformed_bet_id_is_not_found(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $this->get(route('bets.create', ['from' => 999]))->assertNotFound();
        $this->get(route('bets.create', ['from' => 'abc']))->assertNotFound();
        $this->get(route('bets.create'))->assertOk();
    }
}
