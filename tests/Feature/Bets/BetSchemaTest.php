<?php

namespace Tests\Feature\Bets;

use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Currency;
use App\Domain\Settlement\SelectionResult;
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
use Tests\TestCase;

class BetSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bet_is_an_open_single_by_default(): void
    {
        $bet = Bet::factory()->create();

        $this->assertSame(BetType::Single, $bet->type);
        $this->assertSame(BetStatus::Open, $bet->status);
        $this->assertCount(1, $bet->selections);
        $this->assertSame(1, $bet->selections[0]->position);
        $this->assertSame($bet->user_id, $bet->selections[0]->user_id);
        $this->assertSame($bet->user_id, $bet->bookmaker->user_id);
        $this->assertSame($bet->user_id, $bet->selections[0]->sport->user_id);
        $this->assertNull($bet->settled_at);
        $this->assertNull($bet->payout);
        $this->assertNull($bet->profit);
    }

    public function test_a_bet_takes_the_currency_and_rate_of_its_bookmaker(): void
    {
        $bookmaker = Bookmaker::factory()->inCurrency(Currency::Dkk, '0.1341')->create();

        $bet = Bet::factory()->forBookmaker($bookmaker)->create();

        $this->assertSame($bookmaker->user_id, $bet->user_id);
        $this->assertSame(Currency::Dkk, $bet->currency);
        $this->assertSame('0.13410000', $bet->exchange_rate);
    }

    public function test_money_is_cast_to_integers_and_odds_to_decimal_strings(): void
    {
        $bet = Bet::factory()->single('2.1')->create(['stake' => 10000])->refresh();
        $selection = $bet->selections[0];
        $selection->update(['closing_odds' => '1.95']);

        $this->assertSame(10000, $bet->stake);
        $this->assertSame('2.1000', $bet->total_odds);
        $this->assertSame('2.100', $selection->odds);
        $this->assertSame('1.950', $selection->refresh()->closing_odds);
    }

    public function test_an_accumulator_keeps_its_selections_in_order_with_their_total_odds(): void
    {
        $bet = Bet::factory()->accumulator(['1.50', '2.00', '1.85'])->create();

        $this->assertSame(BetType::Accumulator, $bet->type);
        $this->assertSame(['1.500', '2.000', '1.850'], $bet->selections->pluck('odds')->all());
        $this->assertSame([1, 2, 3], $bet->selections->pluck('position')->all());
        $this->assertSame('5.5500', $bet->total_odds);
    }

    public function test_a_selection_added_to_a_bet_goes_after_its_other_selections(): void
    {
        $bet = Bet::factory()->accumulator(['1.50', '2.00'])->create();

        $selection = Selection::factory()->for($bet)->create();

        $this->assertSame(3, $selection->position);
        $this->assertSame($bet->user_id, $selection->user_id);
    }

    public function test_a_selection_takes_its_owner_from_the_bet(): void
    {
        $bet = Bet::factory()->create();
        $sport = Sport::factory()->for($bet->user)->create();

        $selection = $bet->selections()->create([
            'position' => 2,
            'sport_id' => $sport->id,
            'event_name' => 'Arsenal v Chelsea',
            'pick' => 'Arsenal',
            'odds' => '1.80',
        ]);

        $this->assertSame($bet->user_id, $selection->user_id);
    }

    public function test_a_settled_single_has_payout_and_profit(): void
    {
        $bet = Bet::factory()->single('2.10')->settled()->create(['stake' => 10000]);

        $this->assertSame(BetStatus::Settled, $bet->status);
        $this->assertTrue($bet->isSettled());
        $this->assertSame(SelectionResult::Won, $bet->selections[0]->result);
        $this->assertNotNull($bet->settled_at);
        $this->assertSame(21000, $bet->payout);
        $this->assertSame(11000, $bet->profit);
        $this->assertFalse($bet->payout_is_manual);
    }

    public function test_an_accumulator_with_a_void_leg_settles_on_the_other_legs(): void
    {
        $bet = Bet::factory()
            ->accumulator(['1.50', '2.00', '1.80'])
            ->withResults(SelectionResult::Won, SelectionResult::Void, SelectionResult::Won)
            ->create(['stake' => 10000]);

        $this->assertSame(BetStatus::Settled, $bet->status);
        $this->assertSame(27000, $bet->payout);
        $this->assertSame(17000, $bet->profit);
    }

    public function test_an_accumulator_is_settled_as_soon_as_a_leg_is_lost(): void
    {
        $lost = Bet::factory()->accumulator(['1.50', '2.00'])->withResults(SelectionResult::Lost, null)->create(['stake' => 10000]);
        $pending = Bet::factory()->accumulator(['1.50', '2.00'])->withResults(SelectionResult::Won, null)->create(['stake' => 10000]);

        $this->assertSame(BetStatus::Settled, $lost->status);
        $this->assertSame(0, $lost->payout);
        $this->assertSame(-10000, $lost->profit);
        $this->assertSame(BetStatus::Open, $pending->status);
        $this->assertNull($pending->payout);
    }

    public function test_a_won_free_bet_does_not_return_the_stake(): void
    {
        $bet = Bet::factory()->freeBet()->single('3.00')->settled()->create(['stake' => 10000]);

        $this->assertTrue($bet->is_free_bet);
        $this->assertSame(20000, $bet->payout);
        $this->assertSame(20000, $bet->profit);
    }

    public function test_bets_and_selections_have_their_classification(): void
    {
        $user = User::factory()->create();
        $sport = Sport::factory()->for($user)->create();
        $competition = Competition::factory()->forSport($sport)->create();
        $market = Market::factory()->for($user)->create();
        $tipster = Tipster::factory()->for($user)->create();
        $tags = Tag::factory()->for($user)->count(2)->create();
        $teams = Team::factory()->forSport($sport)->count(2)->create();

        $bet = Bet::factory()->for($user)->create(['tipster_id' => $tipster->id]);
        $bet->tags()->attach($tags);
        $selection = $bet->selections[0];
        $selection->update(['sport_id' => $sport->id, 'competition_id' => $competition->id, 'market_id' => $market->id]);
        $selection->teams()->sync($teams);

        $bet = Bet::query()->with(['tipster', 'tags', 'selections.sport', 'selections.competition', 'selections.market', 'selections.teams'])->findOrFail($bet->id);
        $this->assertTrue($bet->tipster?->is($tipster));
        $this->assertEqualsCanonicalizing($tags->modelKeys(), $bet->tags->modelKeys());
        $this->assertTrue($bet->selections[0]->sport->is($sport));
        $this->assertTrue($bet->selections[0]->competition?->is($competition));
        $this->assertTrue($bet->selections[0]->market?->is($market));
        $this->assertEqualsCanonicalizing($teams->modelKeys(), $bet->selections[0]->teams->modelKeys());
        $this->assertDatabaseHas('bet_tag', ['bet_id' => $bet->id, 'tag_id' => $tags[0]->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('selection_team', ['selection_id' => $selection->id, 'team_id' => $teams[0]->id, 'user_id' => $user->id]);
    }

    public function test_deleting_a_bet_deletes_its_selections_and_links(): void
    {
        $bet = Bet::factory()->accumulator()->create();
        $bet->tags()->attach(Tag::factory()->for($bet->user)->create());
        $bet->selections[0]->teams()->attach(Team::factory()->for($bet->user)->create());

        $bet->delete();

        $this->assertDatabaseEmpty('selections');
        $this->assertDatabaseEmpty('bet_tag');
        $this->assertDatabaseEmpty('selection_team');
    }

    public function test_deleting_a_user_deletes_their_bets(): void
    {
        $bet = Bet::factory()->accumulator()->create();
        $bet->tags()->attach(Tag::factory()->for($bet->user)->create());
        $bet->selections[0]->teams()->attach(Team::factory()->for($bet->user)->create());
        $someoneElses = Bet::factory()->create();

        $bet->user->delete();

        $this->assertModelMissing($bet);
        $this->assertSame(1, Selection::query()->count());
        $this->assertDatabaseEmpty('bet_tag');
        $this->assertDatabaseEmpty('selection_team');
        $this->assertModelExists($someoneElses);
    }

    public function test_a_tag_used_by_a_bet_cannot_be_deleted_from_the_database(): void
    {
        $bet = Bet::factory()->create();
        $tag = Tag::factory()->for($bet->user)->create();
        $bet->tags()->attach($tag);

        $this->expectConstraintViolation();

        $tag->delete();
    }
}
