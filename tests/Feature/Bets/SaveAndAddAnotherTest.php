<?php

namespace Tests\Feature\Bets;

use App\Livewire\Bets\Create;
use App\Livewire\Bets\Edit;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SaveAndAddAnotherTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bet_is_saved_and_the_form_keeps_what_the_next_bet_shares(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->assertSee('Save and add another')
            ->set('bookmaker_id', (string) $bookmaker->id)
            ->set('placed_date', '2026-11-20')
            ->set('placed_time', '19:15')
            ->set('stake', '50')
            ->set('is_free_bet', true)
            ->set('tipster', 'Own model')
            ->set('tags', ['value'])
            ->set('notes', 'Team news.')
            ->set('probability', '55')
            ->set('selections.0.sport', 'Football')
            ->set('selections.0.competition', 'Premier League')
            ->set('selections.0.market', 'Asian handicap')
            ->set('selections.0.team_name', 'Arsenal')
            ->call('addTeam', 0)
            ->set('selections.0.event_starts_date', '2026-11-20')
            ->set('selections.0.event_starts_time', '20:00')
            ->set('selections.0.pick', 'Arsenal −0.25')
            ->set('selections.0.odds', '1.95')
            ->set('selections.0.closing_odds', '1.90')
            ->call('saveAndAddAnother')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSee('Bet saved.')
            ->assertSet('bookmaker_id', (string) $bookmaker->id)
            ->assertSet('placed_date', '2026-11-20')
            ->assertSet('placed_time', '19:15')
            ->assertSet('tipster', 'Own model')
            ->assertSet('tags', ['value'])
            ->assertSet('selections.0.sport', 'Football')
            ->assertSet('selections.0.competition', 'Premier League')
            ->assertSet('selections.0.event_name', '')
            ->assertSet('selections.0.pick', '')
            ->assertSet('selections.0.odds', '')
            ->assertSet('selections.0.teams', [])
            ->assertSet('selections.0.market', '')
            ->assertSet('selections.0.event_starts_date', '')
            ->assertSet('selections.0.closing_odds', '')
            ->assertSet('stake', '')
            ->assertSet('is_free_bet', false)
            ->assertSet('notes', '')
            ->assertSet('probability', '');

        $bet = $user->bets()->with('selections')->sole();
        $this->assertSame(5000, $bet->stake);
        $this->assertSame('Arsenal −0.25', $bet->selections->sole()->pick);
    }

    public function test_the_next_bet_can_be_saved_from_the_kept_fields(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('bookmaker_id', (string) $bookmaker->id)
            ->set('stake', '50')
            ->set('selections.0.sport', 'Football')
            ->set('selections.0.event_name', 'Arsenal v Chelsea')
            ->set('selections.0.pick', 'Arsenal')
            ->set('selections.0.odds', '1.95')
            ->call('saveAndAddAnother')
            ->set('stake', '30')
            ->set('selections.0.event_name', 'Liverpool v Everton')
            ->set('selections.0.pick', 'Draw')
            ->set('selections.0.odds', '3.40')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('bets.index'));

        $this->assertSame([5000, 3000], $user->bets()->orderBy('id')->pluck('stake')->all());
        $this->assertSame(['Arsenal', 'Draw'], $user->selections()->orderBy('id')->pluck('pick')->all());
    }

    public function test_an_accumulator_keeps_the_sport_and_competition_of_each_selection(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $component = Livewire::test(Create::class)
            ->set('bookmaker_id', (string) $bookmaker->id)
            ->set('stake', '10')
            ->set('selections.0.sport', 'Football')
            ->set('selections.0.competition', 'Premier League')
            ->set('selections.0.event_name', 'Arsenal v Chelsea')
            ->set('selections.0.pick', 'Arsenal')
            ->set('selections.0.odds', '1.95')
            ->call('useAccumulator')
            ->set('selections.1.sport', 'Tennis')
            ->set('selections.1.event_name', 'Sinner v Alcaraz')
            ->set('selections.1.pick', 'Sinner')
            ->set('selections.1.odds', '2.10')
            ->call('saveAndAddAnother')
            ->assertHasNoErrors();

        $this->assertSame(['Football', 'Tennis'], array_column($component->get('selections'), 'sport'));
        $this->assertSame(['Premier League', ''], array_column($component->get('selections'), 'competition'));
        $this->assertSame(['', ''], array_column($component->get('selections'), 'pick'));
        $this->assertSame(1, $user->bets()->count());
    }

    public function test_an_invalid_bet_is_not_saved_and_the_form_keeps_its_values(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('stake', '50')
            ->set('selections.0.pick', 'Arsenal')
            ->call('saveAndAddAnother')
            ->assertHasErrors(['selections.0.odds', 'selections.0.event_name'])
            ->assertSet('stake', '50')
            ->assertSet('selections.0.pick', 'Arsenal')
            ->assertDontSee('Bet saved.');

        $this->assertSame(0, $user->bets()->count());
    }

    public function test_the_edit_form_has_no_save_and_add_another(): void
    {
        $bet = Bet::factory()->create();
        $this->actingAs($bet->user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->assertDontSee('Save and add another');
    }
}
