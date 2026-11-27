<?php

namespace Tests\Feature\Bets;

use App\Livewire\Bets\BetForm;
use App\Livewire\Bets\Create;
use App\Livewire\Bets\Edit;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Competition;
use App\Models\Sport;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The team and competition comboboxes of the bet form: suggestions are fetched while
 * typing through `suggest()`, not rendered into the page.
 */
class BetFormSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestions_are_the_sports_names_containing_the_search_best_match_first(): void
    {
        [$user, $football] = $this->userWithSport('Football');
        foreach (['Arsenal', 'Real Madrid', 'Manchester United', 'Chelsea'] as $name) {
            Team::factory()->for($user)->forSport($football)->create(['name' => $name]);
        }
        Competition::factory()->for($user)->forSport($football)->create(['name' => 'Premier League']);
        Competition::factory()->for($user)->forSport($football)->create(['name' => 'Champions League']);
        $tennis = Sport::factory()->for($user)->create(['name' => 'Tennis']);
        Team::factory()->for($user)->forSport($tennis)->create(['name' => 'Rafael Nadal']);
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->call('suggest', 'teams', ' football ', 'MA')->assertReturned(['Manchester United', 'Real Madrid'])
            ->call('suggest', 'teams', 'Football', '')->assertReturned(['Arsenal', 'Chelsea', 'Manchester United', 'Real Madrid'])
            ->call('suggest', 'competitions', 'Football', 'league')->assertReturned(['Champions League', 'Premier League'])
            ->call('suggest', 'teams', 'Football', 'Barcelona')->assertReturned([])
            ->call('suggest', 'teams', 'Basketball', '')->assertReturned([])
            ->call('suggest', 'markets', 'Football', '')->assertReturned([]);
    }

    public function test_at_most_twenty_suggestions_are_returned(): void
    {
        [$user, $football] = $this->userWithSport('Football');
        Team::factory()->for($user)->forSport($football)->count(BetForm::MAX_SUGGESTIONS + 5)->sequence(fn ($sequence) => ['name' => sprintf('Team %02d', $sequence->index)])->create();
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->call('suggest', 'teams', 'Football', 'team')
            ->assertReturned(fn (array $names): bool => $names === array_map(fn (int $index): string => sprintf('Team %02d', $index), range(0, BetForm::MAX_SUGGESTIONS - 1)));
    }

    public function test_suggestions_never_include_another_users_entries(): void
    {
        [$user, $football] = $this->userWithSport('Football');
        Team::factory()->for($user)->forSport($football)->create(['name' => 'Arsenal']);
        [$otherUser, $otherFootball] = $this->userWithSport('Football');
        Team::factory()->for($otherUser)->forSport($otherFootball)->create(['name' => 'Aston Villa']);
        Competition::factory()->for($otherUser)->forSport($otherFootball)->create(['name' => 'Allsvenskan']);
        $otherCricket = Sport::factory()->for($otherUser)->create(['name' => 'Cricket']);
        Team::factory()->for($otherUser)->forSport($otherCricket)->create(['name' => 'Australia']);
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->call('suggest', 'teams', 'Football', 'a')->assertReturned(['Arsenal'])
            ->call('suggest', 'competitions', 'Football', 'a')->assertReturned([])
            ->call('suggest', 'teams', 'Cricket', '')->assertReturned([]);
    }

    public function test_teams_and_competitions_are_not_rendered_into_the_page(): void
    {
        [$user, $football] = $this->userWithSport('Football');
        Team::factory()->for($user)->forSport($football)->create(['name' => 'Tottenham Hotspur']);
        Competition::factory()->for($user)->forSport($football)->create(['name' => 'Eredivisie']);
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('selections.0.sport', 'Football')
            ->call('toggleSelectionDetails', 0)
            ->assertSeeHtml('role="combobox" aria-autocomplete="list" aria-controls="team-name-0-listbox"')
            ->assertSeeHtml('aria-controls="competition-0-listbox"')
            ->assertDontSee(['Tottenham Hotspur', 'Eredivisie']);
    }

    public function test_a_picked_team_is_added_as_saved_and_clears_the_input(): void
    {
        [$user, $football] = $this->userWithSport('Football');
        Team::factory()->for($user)->forSport($football)->create(['name' => 'Arsenal']);
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('selections.0.sport', 'football')
            ->set('selections.0.team_name', 'arsenal')
            ->call('addTeam', 0)
            ->set('selections.0.team_name', 'Brand New FC')
            ->call('addTeam', 0)
            ->assertSet('selections.0.teams', ['Arsenal', 'Brand New FC'])
            ->assertSet('selections.0.team_name', '')
            ->assertSet('selections.0.event_name', 'Arsenal v Brand New FC');
    }

    public function test_the_form_runs_as_many_queries_for_a_ten_leg_accumulator_as_for_a_single(): void
    {
        [$user, $football] = $this->userWithSport('Football');
        Team::factory()->for($user)->forSport($football)->count(30)->create();
        Competition::factory()->for($user)->forSport($football)->count(5)->create();
        $this->actingAs($user);

        $single = Livewire::test(Create::class)->set('selections.0.sport', 'Football');
        $accumulator = Livewire::test(Create::class)->set('selections.0.sport', 'Football');
        for ($leg = 1; $leg < 10; $leg++) {
            $accumulator->call('addSelection');
        }
        foreach ([$single, $accumulator] as $component) {
            foreach (array_keys($component->get('selections')) as $index) {
                $component->set("selections.{$index}.show_details", true);
            }
        }

        $this->assertCount(10, $accumulator->get('selections'));
        $this->assertSame($this->queriesToRender($single), $this->queriesToRender($accumulator));
    }

    public function test_editing_a_ten_leg_accumulator_runs_as_many_queries_as_editing_a_single(): void
    {
        [$user, $football] = $this->userWithSport('Football');
        $bookmaker = $user->bookmakers()->sole();
        $single = Bet::factory()->forBookmaker($bookmaker)->create();
        $accumulator = Bet::factory()->forBookmaker($bookmaker)->accumulator(array_fill(0, 10, '1.10'))->create();
        foreach ([$single, $accumulator] as $bet) {
            foreach ($bet->selections as $selection) {
                $selection->update(['sport_id' => $football->id, 'competition_id' => Competition::factory()->for($user)->forSport($football)->create()->id]);
                $selection->teams()->attach(Team::factory()->for($user)->forSport($football)->count(2)->create());
            }
        }
        $this->actingAs($user);

        $this->assertSame(
            $this->queriesToRender(Livewire::test(Edit::class, ['bet' => $single])),
            $this->queriesToRender(Livewire::test(Edit::class, ['bet' => $accumulator])),
        );
    }

    /**
     * @return array{User, Sport}
     */
    private function userWithSport(string $sportName): array
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();

        return [$user, Sport::factory()->for($user)->create(['name' => $sportName])];
    }

    /**
     * The queries of one more request of the form that only re-renders it.
     */
    private function queriesToRender(Testable $component): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->set('notes', 'Re-render.');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
