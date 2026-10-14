<?php

namespace Tests\Feature\Bets;

use App\Models\Bet;
use App\Models\Bookmaker;
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

/**
 * The database itself refuses to link a bet or selection to another user's records.
 */
class BetIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_owner_may_view_update_or_delete_a_bet(): void
    {
        $bet = Bet::factory()->create();
        $otherUser = User::factory()->create();

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($bet->user)->allows($ability, $bet));
            $this->assertFalse(Gate::forUser($otherUser)->allows($ability, $bet));
        }
    }

    public function test_a_users_bets_and_selections_are_only_their_own(): void
    {
        $user = User::factory()->create();
        Bet::factory()->for($user)->accumulator()->create();
        Bet::factory()->accumulator()->create();

        $this->assertSame(1, $user->bets()->count());
        $this->assertSame(3, $user->selections()->count());
    }

    public function test_a_bet_cannot_be_at_another_users_bookmaker(): void
    {
        $user = User::factory()->create();
        $someoneElses = Bookmaker::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');

        Bet::factory()->for($user)->create(['bookmaker_id' => $someoneElses->id]);
    }

    public function test_a_bet_cannot_follow_another_users_tipster(): void
    {
        $bet = Bet::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');

        $bet->update(['tipster_id' => Tipster::factory()->create()->id]);
    }

    public function test_a_bet_cannot_get_another_users_tag(): void
    {
        $bet = Bet::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');

        $bet->tags()->attach(Tag::factory()->create());
    }

    public function test_a_selection_cannot_belong_to_another_users_bet(): void
    {
        $bet = Bet::factory()->create();
        $selection = Bet::factory()->create()->selections[0];

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');

        $selection->forceFill(['bet_id' => $bet->id, 'position' => 2])->save();
    }

    /**
     * @return array<string, array{string, class-string<Sport|Competition|Market>}>
     */
    public static function selectionLists(): array
    {
        return [
            'sport' => ['sport_id', Sport::class],
            'competition' => ['competition_id', Competition::class],
            'market' => ['market_id', Market::class],
        ];
    }

    /**
     * @param  class-string<Sport|Competition|Market>  $model
     */
    #[DataProvider('selectionLists')]
    public function test_a_selection_cannot_use_another_users_list_entry(string $column, string $model): void
    {
        $selection = Bet::factory()->create()->selections[0];
        $someoneElses = $model::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');

        $selection->update([$column => $someoneElses->id]);
    }

    public function test_a_selection_cannot_get_another_users_team(): void
    {
        $selection = Bet::factory()->create()->selections[0];

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');

        $selection->teams()->attach(Team::factory()->create());
    }
}
