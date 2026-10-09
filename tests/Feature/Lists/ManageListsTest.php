<?php

namespace Tests\Feature\Lists;

use App\Livewire\Lists\Index;
use App\Models\Competition;
use App\Models\Market;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManageListsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every list: its tab and model.
     *
     * @return array<string, array{string, class-string<Sport|Competition|Team|Market|Tipster|Tag>}>
     */
    public static function lists(): array
    {
        return [
            'sports' => ['sports', Sport::class],
            'competitions' => ['competitions', Competition::class],
            'teams' => ['teams', Team::class],
            'markets' => ['markets', Market::class],
            'tipsters' => ['tipsters', Tipster::class],
            'tags' => ['tags', Tag::class],
        ];
    }

    public function test_the_page_opens_on_sports_and_lists_them_by_name(): void
    {
        $user = User::factory()->create();
        Sport::factory()->for($user)->create(['name' => 'Tennis']);
        Sport::factory()->for($user)->create(['name' => 'Football']);

        $this->actingAs($user)
            ->get(route('lists.index'))
            ->assertOk()
            ->assertSeeInOrder(['Football', 'Tennis']);
    }

    /**
     * @param  class-string<Sport|Competition|Team|Market|Tipster|Tag>  $model
     */
    #[DataProvider('lists')]
    public function test_each_list_shows_only_the_users_own_entries(string $list, string $model): void
    {
        $user = User::factory()->create();
        $model::factory()->for($user)->create(['name' => 'Mine']);
        $model::factory()->create(['name' => 'Someone elses']);

        $this->actingAs($user)
            ->get(route('lists.index', ['list' => $list]))
            ->assertOk()
            ->assertSee('Mine')
            ->assertDontSee('Someone elses');
    }

    public function test_competitions_are_grouped_by_sport(): void
    {
        $user = User::factory()->create();
        $tennis = Sport::factory()->for($user)->create(['name' => 'Tennis']);
        $football = Sport::factory()->for($user)->create(['name' => 'Football']);
        Competition::factory()->forSport($tennis)->create(['name' => 'Wimbledon']);
        Competition::factory()->forSport($football)->create(['name' => 'Serie A']);
        Competition::factory()->forSport($football)->create(['name' => 'Premier League']);

        $this->actingAs($user)
            ->get(route('lists.index', ['list' => 'competitions']))
            ->assertOk()
            ->assertSeeInOrder(['Football', 'Premier League', 'Serie A', 'Tennis', 'Wimbledon']);
    }

    public function test_an_empty_list_explains_where_entries_come_from(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('lists.index', ['list' => 'tipsters']))
            ->assertOk()
            ->assertSee('No tipsters yet')
            ->assertSee('They are added as you record bets');
    }

    public function test_an_unknown_list_falls_back_to_sports(): void
    {
        $user = User::factory()->create();
        Sport::factory()->for($user)->create(['name' => 'Football']);

        $this->actingAs($user)
            ->get(route('lists.index', ['list' => 'users']))
            ->assertOk()
            ->assertSee('Football');
    }

    public function test_entry_names_are_escaped(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => '<script>alert("x")</script>']);

        $this->actingAs($user)
            ->get(route('lists.index', ['list' => 'tags']))
            ->assertOk()
            ->assertSee('&lt;script&gt;', escape: false)
            ->assertDontSee('<script>alert("x")</script>', escape: false);
    }

    /**
     * @param  class-string<Sport|Competition|Team|Market|Tipster|Tag>  $model
     */
    #[DataProvider('lists')]
    public function test_an_entry_can_be_renamed(string $list, string $model): void
    {
        $user = User::factory()->create();
        $entry = $model::factory()->for($user)->create(['name' => 'Old name']);
        $this->actingAs($user);

        Livewire::test(Index::class, ['list' => $list])
            ->call('rename', $entry->id)
            ->assertSet('showRename', true)
            ->assertSet('name', 'Old name')
            ->set('name', '  New name  ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showRename', false)
            ->assertSee('New name');

        $this->assertSame('New name', $entry->refresh()->name);
    }

    public function test_the_new_name_is_required(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'Value']);
        $this->actingAs($user);

        Livewire::test(Index::class, ['list' => 'tags'])
            ->call('rename', $tag->id)
            ->set('name', '   ')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);

        $this->assertSame('Value', $tag->refresh()->name);
    }

    public function test_the_new_name_must_be_unused_in_the_users_list(): void
    {
        $user = User::factory()->create();
        Market::factory()->for($user)->create(['name' => '1X2']);
        Market::factory()->create(['name' => 'Over/Under']);
        $market = Market::factory()->for($user)->create(['name' => 'Asian handicap']);
        $this->actingAs($user);

        Livewire::test(Index::class, ['list' => 'markets'])
            ->call('rename', $market->id)
            ->set('name', '1X2')
            ->call('save')
            ->assertHasErrors(['name' => 'unique'])
            ->assertSee('You already have a market with this name.')
            ->set('name', 'Over/Under')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Over/Under', $market->refresh()->name);
    }

    public function test_team_names_must_be_unused_within_the_teams_sport_only(): void
    {
        $user = User::factory()->create();
        $football = Sport::factory()->for($user)->create(['name' => 'Football']);
        $basketball = Sport::factory()->for($user)->create(['name' => 'Basketball']);
        Team::factory()->forSport($football)->create(['name' => 'Barcelona']);
        Team::factory()->forSport($basketball)->create(['name' => 'Real Madrid']);
        $team = Team::factory()->forSport($football)->create(['name' => 'Barca']);
        $this->actingAs($user);

        Livewire::test(Index::class, ['list' => 'teams'])
            ->call('rename', $team->id)
            ->set('name', 'Barcelona')
            ->call('save')
            ->assertHasErrors(['name' => 'unique'])
            ->assertSee('You already have a team with this name in Football.')
            ->set('name', 'Real Madrid')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Real Madrid', $team->refresh()->name);
    }

    /**
     * @param  class-string<Sport|Competition|Team|Market|Tipster|Tag>  $model
     */
    #[DataProvider('lists')]
    public function test_an_unused_entry_can_be_deleted(string $list, string $model): void
    {
        $user = User::factory()->create();
        $entry = $model::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Index::class, ['list' => $list])
            ->call('delete', $entry->id)
            ->assertHasNoErrors();

        $this->assertModelMissing($entry);
    }

    public function test_a_sport_in_use_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $sport = Sport::factory()->for($user)->create(['name' => 'Football']);
        Team::factory()->forSport($sport)->create();
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->assertSee('In use')
            ->call('delete', $sport->id)
            ->assertHasErrors(['delete'])
            ->assertSee('“Football” is in use, so it can’t be deleted.');

        $this->assertModelExists($sport);
    }

    /**
     * @param  class-string<Sport|Competition|Team|Market|Tipster|Tag>  $model
     */
    #[DataProvider('lists')]
    public function test_users_cannot_rename_another_users_entry(string $list, string $model): void
    {
        $entry = $model::factory()->create(['name' => 'Theirs']);
        $this->actingAs(User::factory()->create());

        Livewire::test(Index::class, ['list' => $list])
            ->call('rename', $entry->id)
            ->assertNotFound()
            ->assertSet('showRename', false);

        Livewire::test(Index::class, ['list' => $list])
            ->set('renamingId', $entry->id)
            ->set('name', 'Hijacked')
            ->call('save')
            ->assertNotFound();

        $this->assertSame('Theirs', $entry->refresh()->name);
    }

    /**
     * @param  class-string<Sport|Competition|Team|Market|Tipster|Tag>  $model
     */
    #[DataProvider('lists')]
    public function test_users_cannot_delete_another_users_entry(string $list, string $model): void
    {
        $entry = $model::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(Index::class, ['list' => $list])
            ->call('delete', $entry->id)
            ->assertNotFound();

        $this->assertModelExists($entry);
    }

    public function test_switching_lists_closes_the_rename_form(): void
    {
        $user = User::factory()->create();
        $sport = Sport::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->call('rename', $sport->id)
            ->assertSet('showRename', true)
            ->set('list', 'tags')
            ->assertSet('showRename', false)
            ->assertSet('renamingId', null);
    }
}
