<?php

namespace Tests\Feature\Bets;

use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Currency;
use App\Domain\Settlement\SelectionResult;
use App\Livewire\Bets\Edit;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Competition;
use App\Models\Selection;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditBetTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $bet = Bet::factory()->create();

        $this->get(route('bets.edit', $bet))->assertRedirect(route('login'));
    }

    public function test_only_the_owner_can_open_or_save_the_form(): void
    {
        $bet = Bet::factory()->create();

        $this->actingAs($bet->user)->get(route('bets.edit', $bet))->assertOk()->assertSee('Edit bet');

        $this->actingAs(User::factory()->create());

        $this->get(route('bets.edit', $bet))->assertForbidden();
    }

    public function test_the_form_is_filled_in_from_the_bet(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.1341')->create();
        $football = Sport::factory()->for($user)->create(['name' => 'Football']);
        $bet = Bet::factory()
            ->forBookmaker($bookmaker)
            ->has(Selection::factory()->state([
                'sport_id' => $football->id,
                'competition_id' => Competition::factory()->for($user)->forSport($football)->create(['name' => 'Premier League'])->id,
                'event_name' => 'Arsenal v Chelsea',
                'event_starts_at' => '2026-10-14 18:30:00',
                'pick' => 'Arsenal −0.25',
                'odds' => '1.95',
                'closing_odds' => '1.9',
            ]), 'selections')
            ->freeBet()
            ->create([
                'tipster_id' => Tipster::factory()->for($user)->create(['name' => 'Own model'])->id,
                'placed_at' => '2026-10-14 17:42:00',
                'stake' => 20050,
                'exchange_rate' => '0.13',
                'notes' => 'Team news looked good.',
            ]);
        $bet->tags()->attach(Tag::factory()->for($user)->create(['name' => 'value']));
        $selection = $bet->selections()->sole();
        $selection->teams()->attach([
            Team::factory()->for($user)->forSport($football)->create(['name' => 'Arsenal'])->id,
            Team::factory()->for($user)->forSport($football)->create(['name' => 'Chelsea'])->id,
        ]);
        $this->actingAs($user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->assertSet('bookmaker_id', (string) $bookmaker->id)
            ->assertSet('placed_date', '2026-10-14')
            ->assertSet('placed_time', '17:42')
            ->assertSet('stake', '200.50')
            ->assertSet('exchange_rate', '0.13')
            ->assertSet('is_free_bet', true)
            ->assertSet('tipster', 'Own model')
            ->assertSet('tags', ['value'])
            ->assertSet('notes', 'Team news looked good.')
            ->assertSet('showDetails', true)
            ->assertSet('selections', [[
                'id' => $selection->id,
                'sport' => 'Football',
                'competition' => 'Premier League',
                'market' => '',
                'teams' => ['Arsenal', 'Chelsea'],
                'team_name' => '',
                'event_name' => 'Arsenal v Chelsea',
                'event_starts_date' => '2026-10-14',
                'event_starts_time' => '18:30',
                'pick' => 'Arsenal −0.25',
                'odds' => '1.95',
                'closing_odds' => '1.90',
                'show_details' => true,
            ]])
            ->assertSee(['Edit bet', 'Save changes'])
            ->assertDontSee('Settled');
    }

    public function test_an_open_bet_is_updated_in_place(): void
    {
        $user = User::factory()->create();
        $unibet = Bookmaker::factory()->for($user)->create();
        $danskeSpil = Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.1341')->create();
        $bet = Bet::factory()->forBookmaker($unibet)->single('1.95')->create(['stake' => 10000]);
        $selectionId = $bet->selections()->sole()->id;

        $otherUser = User::factory()->create();
        $otherTennis = Sport::factory()->for($otherUser)->create(['name' => 'Tennis']);
        Team::factory()->for($otherUser)->forSport($otherTennis)->create(['name' => 'Alcaraz']);
        Tag::factory()->for($otherUser)->create(['name' => 'value']);
        $this->actingAs($user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->set('bookmaker_id', (string) $danskeSpil->id)
            ->assertSet('exchange_rate', '0.1341')
            ->set('stake', '250')
            ->set('selections.0.sport', 'Tennis')
            ->set('selections.0.team_name', 'Alcaraz')
            ->call('addTeam', 0)
            ->set('selections.0.event_name', 'Alcaraz v Sinner')
            ->set('selections.0.pick', 'Alcaraz')
            ->set('selections.0.odds', '2,2')
            ->set('tags', ['value'])
            ->set('notes', 'Changed my mind.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('bets.show', $bet));

        $bet->refresh();
        $this->assertSame($danskeSpil->id, $bet->bookmaker_id);
        $this->assertSame(Currency::Dkk, $bet->currency);
        $this->assertSame('0.13410000', $bet->exchange_rate);
        $this->assertSame(25000, $bet->stake);
        $this->assertSame('2.2000', $bet->total_odds);
        $this->assertSame(BetType::Single, $bet->type);
        $this->assertSame(BetStatus::Open, $bet->status);
        $this->assertSame('Changed my mind.', $bet->notes);
        $this->assertSame([$user->id], $bet->tags()->pluck('tags.user_id')->all());

        $selection = $bet->selections()->sole();
        $this->assertSame($selectionId, $selection->id);
        $this->assertSame('Tennis', $selection->sport->name);
        $this->assertSame($user->id, $selection->sport->user_id);
        $this->assertSame('Alcaraz v Sinner', $selection->event_name);
        $this->assertSame('Alcaraz', $selection->pick);
        $this->assertSame('2.200', $selection->odds);
        $this->assertSame([$selection->sport_id], $selection->teams()->pluck('sport_id')->all());
        $this->assertSame([$user->id], $selection->teams()->pluck('teams.user_id')->all());

        $this->assertSame(1, $otherUser->teams()->count());
        $this->assertSame(1, $otherUser->tags()->count());
    }

    public function test_a_single_can_become_an_accumulator_and_back(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $bet = Bet::factory()->forBookmaker($bookmaker)->single('1.95')->create();
        $firstId = $bet->selections()->sole()->id;
        $this->actingAs($user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->call('useAccumulator')
            ->set('selections.1.event_name', 'Liverpool v Man City')
            ->set('selections.1.pick', 'Over 2.5 goals')
            ->set('selections.1.odds', '2.10')
            ->call('save')
            ->assertHasNoErrors();

        $bet->refresh();
        $this->assertSame(BetType::Accumulator, $bet->type);
        $this->assertSame('4.0950', $bet->total_odds);
        $this->assertSame($firstId, $bet->selections[0]->id);
        $this->assertSame([1, 2], $bet->selections->pluck('position')->all());
        $this->assertSame(['1.950', '2.100'], $bet->selections->pluck('odds')->all());
        $secondSelection = $bet->selections[1];

        Livewire::test(Edit::class, ['bet' => $bet])
            ->call('useSingle')
            ->call('save')
            ->assertHasNoErrors();

        $bet->refresh();
        $this->assertSame(BetType::Single, $bet->type);
        $this->assertSame('1.9500', $bet->total_odds);
        $this->assertSame($firstId, $bet->selections()->sole()->id);
        $this->assertModelMissing($secondSelection);
    }

    public function test_selections_keep_their_results_when_others_are_removed(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $bet = Bet::factory()->forBookmaker($bookmaker)
            ->accumulator(['1.50', '2.00', '1.80'])
            ->withResults(SelectionResult::Won, null, null)
            ->create();
        [$first, $second, $third] = $bet->selections()->get()->all();
        $this->actingAs($user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->assertSee('Won')
            ->call('removeSelection', 1)
            ->call('save')
            ->assertHasNoErrors();

        $selections = $bet->selections()->get();
        $this->assertSame([$first->id, $third->id], $selections->modelKeys());
        $this->assertSame([1, 2], $selections->pluck('position')->all());
        $this->assertSame([SelectionResult::Won, null], $selections->pluck('result')->all());
        $this->assertModelMissing($second);

        $bet->refresh();
        $this->assertSame(BetStatus::Open, $bet->status);
        $this->assertSame('2.7000', $bet->total_odds);
    }

    public function test_changing_the_stake_of_a_settled_bet_settles_it_again(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $bet = Bet::factory()->forBookmaker($bookmaker)->single('2.10')->settled()->create(['stake' => 10000]);
        $settledAt = $bet->settled_at?->toDateTimeString();
        $this->actingAs($user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->assertSee(['Settled · payout €210.00 · profit +€110.00', 'Won'])
            ->set('stake', '200')
            ->call('save')
            ->assertHasNoErrors();

        $bet->refresh();
        $this->assertSame(BetStatus::Settled, $bet->status);
        $this->assertSame(42000, $bet->payout);
        $this->assertSame(22000, $bet->profit);
        $this->assertSame($settledAt, $bet->settled_at?->toDateTimeString());
        $this->assertSame(SelectionResult::Won, $bet->selections()->sole()->result);
    }

    public function test_adding_a_pending_selection_reopens_a_settled_bet(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $bet = Bet::factory()->forBookmaker($bookmaker)->accumulator(['1.50', '2.00'])->settled()->create();
        $this->actingAs($user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->call('addSelection')
            ->set('selections.2.event_name', 'Liverpool v Man City')
            ->set('selections.2.pick', 'Over 2.5 goals')
            ->set('selections.2.odds', '1.80')
            ->call('save')
            ->assertHasNoErrors();

        $bet->refresh();
        $this->assertSame(BetStatus::Open, $bet->status);
        $this->assertNull($bet->payout);
        $this->assertNull($bet->profit);
        $this->assertNull($bet->settled_at);
        $this->assertSame('5.4000', $bet->total_odds);
        $this->assertSame([SelectionResult::Won, SelectionResult::Won, null], $bet->selections->pluck('result')->all());
    }

    public function test_removing_a_lost_selection_reopens_the_bet(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $bet = Bet::factory()->forBookmaker($bookmaker)
            ->accumulator(['1.50', '2.00', '1.80'])
            ->withResults(SelectionResult::Won, SelectionResult::Lost, null)
            ->create();
        $this->assertTrue($bet->isSettled());
        $this->actingAs($user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->call('removeSelection', 1)
            ->call('save')
            ->assertHasNoErrors();

        $bet->refresh();
        $this->assertSame(BetStatus::Open, $bet->status);
        $this->assertNull($bet->payout);
        $this->assertNull($bet->settled_at);
    }

    public function test_removing_the_last_pending_selection_settles_the_bet(): void
    {
        $this->travelTo('2026-10-21 12:00:00');
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $bet = Bet::factory()->forBookmaker($bookmaker)
            ->accumulator(['1.50', '2.00', '1.80'])
            ->withResults(SelectionResult::Won, SelectionResult::Void, null)
            ->create(['stake' => 10000]);
        $this->assertFalse($bet->isSettled());
        $this->actingAs($user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->call('removeSelection', 2)
            ->call('save')
            ->assertHasNoErrors();

        $bet->refresh();
        $this->assertSame(BetStatus::Settled, $bet->status);
        $this->assertSame(15000, $bet->payout);
        $this->assertSame(5000, $bet->profit);
        $this->assertSame('2026-10-21 12:00:00', $bet->settled_at?->toDateTimeString());
    }

    public function test_a_manual_payout_is_only_replaced_when_the_settlement_changes(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $bet = Bet::factory()->forBookmaker($bookmaker)->single('2.10')->settled()->create(['stake' => 10000]);
        $bet->forceFill(['payout' => 20500, 'profit' => 10500, 'payout_is_manual' => true])->save();
        $this->actingAs($user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->assertSee('You entered this payout yourself.')
            ->set('notes', 'Paid out 205.00 after a max payout cap.')
            ->call('save')
            ->assertHasNoErrors();

        $bet->refresh();
        $this->assertSame(20500, $bet->payout);
        $this->assertSame(10500, $bet->profit);
        $this->assertTrue($bet->payout_is_manual);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->set('selections.0.odds', '2.2')
            ->call('save')
            ->assertHasNoErrors();

        $bet->refresh();
        $this->assertSame(22000, $bet->payout);
        $this->assertSame(12000, $bet->profit);
        $this->assertFalse($bet->payout_is_manual);
    }

    public function test_the_bets_own_bookmaker_stays_available_after_it_is_deactivated(): void
    {
        $user = User::factory()->create();
        $oldBookie = Bookmaker::factory()->for($user)->create(['name' => 'Old Bookie']);
        $closedBookie = Bookmaker::factory()->for($user)->inactive()->create(['name' => 'Closed Bookie']);
        $bet = Bet::factory()->forBookmaker($oldBookie)->create();
        $oldBookie->update(['is_active' => false]);
        $this->actingAs($user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->assertSee('Old Bookie · EUR (inactive)')
            ->assertDontSee('Closed Bookie')
            ->set('stake', '50')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(5000, $bet->refresh()->stake);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->set('bookmaker_id', (string) $closedBookie->id)
            ->call('save')
            ->assertHasErrors(['bookmaker_id' => 'exists']);

        $this->assertSame($oldBookie->id, $bet->refresh()->bookmaker_id);
    }

    public function test_a_bet_cannot_be_moved_to_another_users_bookmaker(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $bet = Bet::factory()->forBookmaker($bookmaker)->create();
        $someoneElses = Bookmaker::factory()->create();
        $this->actingAs($user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->set('bookmaker_id', (string) $someoneElses->id)
            ->call('save')
            ->assertHasErrors(['bookmaker_id' => 'exists']);

        $this->assertSame($bookmaker->id, $bet->refresh()->bookmaker_id);
    }

    public function test_selections_of_other_bets_cannot_be_edited_through_this_one(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $bet = Bet::factory()->forBookmaker($bookmaker)->single()->create();
        $otherBetSelection = Bet::factory()->forBookmaker($bookmaker)->single()->create()->selections()->sole();
        $otherUsersSelection = Bet::factory()->create()->selections()->sole();
        $this->actingAs($user);

        foreach ([$otherBetSelection, $otherUsersSelection] as $selection) {
            Livewire::test(Edit::class, ['bet' => $bet])
                ->set('selections.0.id', $selection->id)
                ->set('selections.0.pick', 'Hijacked')
                ->call('save')
                ->assertHasErrors(['selections.0.id']);

            $this->assertNotSame('Hijacked', $selection->refresh()->pick);
            $this->assertNotSame($bet->id, $selection->bet_id);
        }
    }
}
