<?php

namespace Tests\Feature\Lists;

use App\Models\Competition;
use App\Models\Market;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListEntriesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string<Competition|Team>}>
     */
    public static function perSportModels(): array
    {
        return [
            'competition' => [Competition::class],
            'team' => [Team::class],
        ];
    }

    /**
     * @param  class-string<Competition|Team>  $model
     */
    #[DataProvider('perSportModels')]
    public function test_an_entry_can_belong_to_the_users_own_sport(string $model): void
    {
        $user = User::factory()->create();
        $sport = Sport::factory()->for($user)->create();

        $entry = $model::factory()->for($user)->create(['sport_id' => $sport->id]);

        $this->assertTrue($entry->sport->is($sport));
    }

    /**
     * @param  class-string<Competition|Team>  $model
     */
    #[DataProvider('perSportModels')]
    public function test_an_entry_cannot_belong_to_another_users_sport(string $model): void
    {
        $user = User::factory()->create();
        $someoneElsesSport = Sport::factory()->create();

        $this->expectException(QueryException::class);

        $model::factory()->for($user)->create(['sport_id' => $someoneElsesSport->id]);
    }

    /**
     * @param  class-string<Competition|Team>  $model
     */
    #[DataProvider('perSportModels')]
    public function test_names_are_unique_within_a_sport(string $model): void
    {
        $user = User::factory()->create();
        $football = Sport::factory()->for($user)->create(['name' => 'Football']);
        $basketball = Sport::factory()->for($user)->create(['name' => 'Basketball']);
        $model::factory()->forSport($football)->create(['name' => 'Premier League']);

        $model::factory()->forSport($basketball)->create(['name' => 'Premier League']);
        $model::factory()->forSport(Sport::factory()->create(['name' => 'Football']))->create(['name' => 'Premier League']);

        $this->expectException(QueryException::class);

        $model::factory()->forSport($football)->create(['name' => 'Premier League']);
    }

    /**
     * @return array<string, array{class-string<Sport|Market|Tipster|Tag>}>
     */
    public static function perUserModels(): array
    {
        return [
            'sport' => [Sport::class],
            'market' => [Market::class],
            'tipster' => [Tipster::class],
            'tag' => [Tag::class],
        ];
    }

    /**
     * @param  class-string<Sport|Market|Tipster|Tag>  $model
     */
    #[DataProvider('perUserModels')]
    public function test_names_are_unique_per_user(string $model): void
    {
        $user = User::factory()->create();
        $model::factory()->for($user)->create(['name' => 'Value']);
        $model::factory()->create(['name' => 'Value']);

        $this->expectException(QueryException::class);

        $model::factory()->for($user)->create(['name' => 'Value']);
    }

    public function test_deleting_a_user_deletes_their_lists(): void
    {
        $user = User::factory()->create();
        $sport = Sport::factory()->for($user)->create();
        Competition::factory()->forSport($sport)->create();
        Team::factory()->forSport($sport)->create();
        Market::factory()->for($user)->create();
        Tipster::factory()->for($user)->create();
        Tag::factory()->for($user)->create();

        $user->delete();

        foreach (['sports', 'competitions', 'teams', 'markets', 'tipsters', 'tags'] as $table) {
            $this->assertDatabaseEmpty($table);
        }
    }

    /**
     * @return array<string, array{class-string<Sport|Competition|Team|Market|Tipster|Tag>}>
     */
    public static function allModels(): array
    {
        return self::perUserModels() + self::perSportModels();
    }

    /**
     * @param  class-string<Sport|Competition|Team|Market|Tipster|Tag>  $model
     */
    #[DataProvider('allModels')]
    public function test_only_the_owner_may_rename_or_delete_an_entry(string $model): void
    {
        $entry = $model::factory()->create();
        $owner = $entry->user;
        $otherUser = User::factory()->create();

        $this->assertTrue(Gate::forUser($owner)->allows('update', $entry));
        $this->assertTrue(Gate::forUser($owner)->allows('delete', $entry));
        $this->assertFalse(Gate::forUser($otherUser)->allows('update', $entry));
        $this->assertFalse(Gate::forUser($otherUser)->allows('delete', $entry));
    }

    public function test_a_sport_is_in_use_while_it_has_competitions_or_teams(): void
    {
        $unused = Sport::factory()->create();
        $withCompetition = Sport::factory()->create();
        Competition::factory()->forSport($withCompetition)->create();
        $withTeam = Sport::factory()->create();
        Team::factory()->forSport($withTeam)->create();

        $this->assertFalse($unused->isInUse());
        $this->assertTrue($withCompetition->isInUse());
        $this->assertTrue($withTeam->isInUse());
    }
}
