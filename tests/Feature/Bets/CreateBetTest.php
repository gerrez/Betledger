<?php

namespace Tests\Feature\Bets;

use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Currency;
use App\Livewire\Bets\Create;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Competition;
use App\Models\Market;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateBetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_offers_only_the_users_active_bookmakers(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create(['name' => 'Unibet']);
        Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.1341')->create(['name' => 'Danske Spil']);
        Bookmaker::factory()->for($user)->inactive()->create(['name' => 'Old Bookie']);
        Bookmaker::factory()->create(['name' => 'Someone Elses Book']);

        $this->actingAs($user)
            ->get(route('bets.create'))
            ->assertOk()
            ->assertSeeInOrder(['Danske Spil · DKK', 'Unibet · EUR'])
            ->assertDontSee('Old Bookie')
            ->assertDontSee('Someone Elses Book');
    }

    public function test_without_bookmakers_the_page_asks_to_add_one_first(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->inactive()->create();

        $this->actingAs($user)
            ->get(route('bets.create'))
            ->assertOk()
            ->assertSee('Add a bookmaker first')
            ->assertSee(route('bookmakers.index'))
            ->assertDontSee('Save bet');
    }

    public function test_suggestions_only_list_the_users_own_entries(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $football = Sport::factory()->for($user)->create(['name' => 'Football']);
        Team::factory()->for($user)->forSport($football)->create(['name' => 'Arsenal']);
        Market::factory()->for($user)->create(['name' => 'Asian handicap']);

        $otherSport = Sport::factory()->create(['name' => 'Other Users Sport']);
        Team::factory()->for($otherSport->user)->forSport($otherSport)->create(['name' => 'Other Users Team']);
        Market::factory()->for($otherSport->user)->create(['name' => 'Other Users Market']);
        Tag::factory()->for($otherSport->user)->create(['name' => 'Other Users Tag']);
        Tipster::factory()->for($otherSport->user)->create(['name' => 'Other Users Tipster']);
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('selections.0.sport', 'Football')
            ->set('showDetails', true)
            ->assertSee(['Football', 'Arsenal', 'Asian handicap'])
            ->assertDontSee(['Other Users Sport', 'Other Users Team', 'Other Users Market', 'Other Users Tag', 'Other Users Tipster']);
    }

    public function test_the_form_starts_now_at_the_bookmaker_and_sport_of_the_latest_bet(): void
    {
        $this->travelTo('2026-10-14 17:42:10');
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create(['name' => 'Aardvark Bets']);
        $danskeSpil = Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.13410000')->create(['name' => 'Danske Spil']);
        $bet = Bet::factory()->forBookmaker($danskeSpil)->create(['placed_at' => now()->subDay()]);
        $bet->selections()->sole()->sport->update(['name' => 'Tennis']);
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->assertSet('placed_at', '2026-10-14T17:42')
            ->assertSet('bookmaker_id', (string) $danskeSpil->id)
            ->assertSet('exchange_rate', '0.1341')
            ->assertSet('selections.0.sport', 'Tennis');
    }

    public function test_a_single_is_saved_with_the_bookmakers_currency_and_new_list_entries(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.1341')->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->set('placed_at', '2026-10-14T17:42')
            ->set('selections.0.event_starts_at', '2026-10-14T18:30')
            ->set('selections.0.competition', 'Premier League')
            ->set('selections.0.market', 'Asian handicap')
            ->set('selections.0.closing_odds', '1.90')
            ->set('tipster', 'Own model')
            ->set('tag_name', 'value')
            ->call('addTag')
            ->set('tag_name', 'pre-match')
            ->call('addTag')
            ->set('notes', 'Team news looked good.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('bets.index'));

        $bet = $user->bets()->with(['tipster', 'tags', 'selections.sport', 'selections.competition', 'selections.market', 'selections.teams'])->sole();
        $this->assertSame($bookmaker->id, $bet->bookmaker_id);
        $this->assertSame(BetType::Single, $bet->type);
        $this->assertSame(BetStatus::Open, $bet->status);
        $this->assertSame('2026-10-14 17:42:00', $bet->placed_at->toDateTimeString());
        $this->assertSame(20000, $bet->stake);
        $this->assertSame(Currency::Dkk, $bet->currency);
        $this->assertSame('0.13410000', $bet->exchange_rate);
        $this->assertFalse($bet->is_free_bet);
        $this->assertSame('1.9500', $bet->total_odds);
        $this->assertNull($bet->payout);
        $this->assertSame('Own model', $bet->tipster?->name);
        $this->assertSame(['value', 'pre-match'], $bet->tags->pluck('name')->all());
        $this->assertSame('Team news looked good.', $bet->notes);

        $selection = $bet->selections->sole();
        $this->assertSame(1, $selection->position);
        $this->assertSame('Football', $selection->sport->name);
        $this->assertSame('Premier League', $selection->competition?->name);
        $this->assertSame($selection->sport_id, $selection->competition?->sport_id);
        $this->assertSame('Asian handicap', $selection->market?->name);
        $this->assertSame(['Arsenal', 'Chelsea'], $selection->teams->pluck('name')->all());
        $this->assertSame([$selection->sport_id], $selection->teams->pluck('sport_id')->unique()->values()->all());
        $this->assertSame('Arsenal v Chelsea', $selection->event_name);
        $this->assertSame('2026-10-14 18:30:00', $selection->event_starts_at?->toDateTimeString());
        $this->assertSame('Arsenal −0.25', $selection->pick);
        $this->assertSame('1.950', $selection->odds);
        $this->assertSame('1.900', $selection->closing_odds);
        $this->assertNull($selection->result);
    }

    public function test_optional_details_can_be_left_empty(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('bookmaker_id', (string) $bookmaker->id)
            ->set('selections.0.sport', 'Tennis')
            ->set('selections.0.event_name', 'Wimbledon outright')
            ->set('selections.0.pick', 'Carlos Alcaraz')
            ->set('selections.0.odds', '3.5')
            ->set('stake', '10')
            ->call('save')
            ->assertHasNoErrors();

        $bet = $user->bets()->sole();
        $selection = $bet->selections()->sole();
        $this->assertNull($bet->tipster_id);
        $this->assertNull($bet->notes);
        $this->assertSame(0, $bet->tags()->count());
        $this->assertNull($selection->competition_id);
        $this->assertNull($selection->market_id);
        $this->assertNull($selection->event_starts_at);
        $this->assertNull($selection->closing_odds);
        $this->assertSame(0, $selection->teams()->count());
    }

    public function test_typed_names_reuse_the_users_entries_ignoring_case(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $football = Sport::factory()->for($user)->create(['name' => 'Football']);
        $arsenal = Team::factory()->for($user)->forSport($football)->create(['name' => 'Arsenal']);
        $premierLeague = Competition::factory()->for($user)->forSport($football)->create(['name' => 'Premier League']);
        $market = Market::factory()->for($user)->create(['name' => '1X2']);
        $tipster = Tipster::factory()->for($user)->create(['name' => 'Own model']);
        $tag = Tag::factory()->for($user)->create(['name' => 'Value']);
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('bookmaker_id', (string) $bookmaker->id)
            ->set('selections.0.sport', 'football')
            ->set('selections.0.team_name', 'ARSENAL')
            ->call('addTeam', 0)
            ->assertSet('selections.0.teams', ['Arsenal'])
            ->assertSet('selections.0.event_name', 'Arsenal')
            ->set('selections.0.competition', 'premier league')
            ->set('selections.0.market', '1x2')
            ->set('selections.0.pick', 'Arsenal')
            ->set('selections.0.odds', '2.10')
            ->set('stake', '20')
            ->set('tipster', 'own MODEL')
            ->set('tags', ['value'])
            ->call('save')
            ->assertHasNoErrors();

        $selection = $user->selections()->sole();
        $this->assertSame($football->id, $selection->sport_id);
        $this->assertSame($premierLeague->id, $selection->competition_id);
        $this->assertSame($market->id, $selection->market_id);
        $this->assertSame([$arsenal->id], $selection->teams()->pluck('teams.id')->all());
        $this->assertSame($tipster->id, $selection->bet->tipster_id);
        $this->assertSame([$tag->id], $selection->bet->tags()->pluck('tags.id')->all());
        $this->assertSame(1, $user->sports()->count());
        $this->assertSame(1, $user->teams()->count());
    }

    public function test_competitions_and_teams_are_kept_per_sport(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $football = Sport::factory()->for($user)->create(['name' => 'Football']);
        $footballTeam = Team::factory()->for($user)->forSport($football)->create(['name' => 'Brazil']);
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('bookmaker_id', (string) $bookmaker->id)
            ->set('selections.0.sport', 'Volleyball')
            ->set('selections.0.team_name', 'Brazil')
            ->call('addTeam', 0)
            ->set('selections.0.pick', 'Brazil')
            ->set('selections.0.odds', '1.40')
            ->set('stake', '20')
            ->call('save')
            ->assertHasNoErrors();

        $team = $user->selections()->sole()->teams()->sole();
        $this->assertNotSame($footballTeam->id, $team->id);
        $this->assertSame('Volleyball', $team->sport->name);
    }

    public function test_the_event_name_follows_the_teams_until_it_is_edited(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('selections.0.team_name', 'Arsenal')
            ->call('addTeam', 0)
            ->set('selections.0.team_name', 'Chelsea')
            ->call('addTeam', 0)
            ->assertSet('selections.0.event_name', 'Arsenal v Chelsea')
            ->call('removeTeam', 0, 0)
            ->assertSet('selections.0.teams', ['Chelsea'])
            ->assertSet('selections.0.event_name', 'Chelsea')
            ->set('selections.0.event_name', 'FA Cup final')
            ->set('selections.0.team_name', 'Arsenal')
            ->call('addTeam', 0)
            ->assertSet('selections.0.teams', ['Chelsea', 'Arsenal'])
            ->assertSet('selections.0.event_name', 'FA Cup final');
    }

    public function test_a_team_and_tag_still_being_typed_are_saved_too(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('bookmaker_id', (string) $bookmaker->id)
            ->set('selections.0.sport', 'Football')
            ->set('selections.0.team_name', 'Arsenal')
            ->set('selections.0.pick', 'Arsenal')
            ->set('selections.0.odds', '2')
            ->set('stake', '20')
            ->set('tag_name', 'value')
            ->call('save')
            ->assertHasNoErrors();

        $selection = $user->selections()->sole();
        $this->assertSame('Arsenal', $selection->event_name);
        $this->assertSame(['Arsenal'], $selection->teams()->pluck('name')->all());
        $this->assertSame(['value'], $selection->bet->tags()->pluck('name')->all());
    }

    public function test_the_preview_shows_payout_and_profit_if_the_bet_wins(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('selections.0.odds', '1,95')
            ->set('stake', '200')
            ->assertSee(['€390.00', '+€190.00'])
            ->set('is_free_bet', true)
            ->assertSee(['€190.00', '+€190.00'])
            ->assertDontSee('€390.00');
    }

    public function test_the_preview_waits_for_valid_stake_and_odds(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('selections.0.odds', '1')
            ->set('stake', '200')
            ->assertSee('Payout if it wins')
            ->assertDontSee('€');
    }

    public function test_choosing_a_bookmaker_copies_its_rate_and_shows_the_stake_in_base_currency(): void
    {
        $user = User::factory()->create(['base_currency' => Currency::Dkk]);
        $pinnacle = Bookmaker::factory()->for($user)->inCurrency(Currency::Eur, '7.46')->create(['name' => 'Pinnacle']);
        $danskeSpil = Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '1')->create(['name' => 'Danske Spil']);
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('bookmaker_id', (string) $pinnacle->id)
            ->assertSet('exchange_rate', '7.46')
            ->set('stake', '200')
            ->assertSee('≈ 1,492.00 kr at Pinnacle’s rate 7.46')
            ->set('exchange_rate', '7.5')
            ->assertSee('≈ 1,500.00 kr at Pinnacle’s rate 7.5')
            ->set('bookmaker_id', (string) $danskeSpil->id)
            ->assertSet('exchange_rate', '1')
            ->assertDontSee('≈');
    }

    public function test_the_rate_can_be_changed_for_the_bet_without_changing_the_bookmaker(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->inCurrency(Currency::Gbp, '1.17')->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->set('exchange_rate', '1,1825')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1.18250000', $user->bets()->sole()->exchange_rate);
        $this->assertSame('1.17000000', $bookmaker->refresh()->exchange_rate);
    }

    public function test_a_bet_in_the_base_currency_always_gets_a_rate_of_1(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->set('exchange_rate', '2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1.00000000', $user->bets()->sole()->exchange_rate);
    }

    public function test_a_free_bet_is_saved_as_free(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->set('is_free_bet', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($user->bets()->sole()->is_free_bet);
    }

    public function test_numbers_may_use_a_decimal_comma(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->set('selections.0.odds', '2,1')
            ->set('selections.0.closing_odds', ' 2,05 ')
            ->set('stake', '25,5')
            ->call('save')
            ->assertHasNoErrors();

        $bet = $user->bets()->sole();
        $this->assertSame(2550, $bet->stake);
        $this->assertSame('2.1000', $bet->total_odds);
        $this->assertSame('2.050', $bet->selections()->sole()->closing_odds);
    }

    public function test_required_fields(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('placed_at', '')
            ->call('save')
            ->assertHasErrors([
                'placed_at' => 'required',
                'stake' => 'required',
                'selections.0.sport' => 'required',
                'selections.0.event_name' => 'required',
                'selections.0.pick' => 'required',
                'selections.0.odds' => 'required',
            ])
            ->assertSee('Enter the event, or add the teams taking part.')
            ->assertSee('Enter what you backed.');

        $this->assertDatabaseCount('bets', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidOdds(): array
    {
        return [
            'exactly 1' => ['1.00'],
            'below 1' => ['0.95'],
            'more than three decimals' => ['1.9524'],
            'not a number' => ['evens'],
            'fraction' => ['5/2'],
        ];
    }

    #[DataProvider('invalidOdds')]
    public function test_odds_must_be_above_1(string $odds): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->set('selections.0.odds', $odds)
            ->call('save')
            ->assertHasErrors(['selections.0.odds'])
            ->assertSee('Enter odds above 1, like 1.95 (up to 3 decimals).');

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_invalid_closing_odds_open_the_details_to_show_the_error(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->set('selections.0.closing_odds', '1')
            ->call('save')
            ->assertHasErrors(['selections.0.closing_odds'])
            ->assertSet('showDetails', true);

        $this->assertDatabaseCount('bets', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidStakes(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-10'],
            'more than two decimals' => ['10.505'],
            'thousands separator' => ['1,000.00'],
            'absurdly large' => ['1000000000'],
        ];
    }

    #[DataProvider('invalidStakes')]
    public function test_the_stake_must_be_above_0(string $stake): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->set('stake', $stake)
            ->call('save')
            ->assertHasErrors(['stake'])
            ->assertSee('Enter the stake as an amount above 0, like 25 or 25.50.');

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_an_invalid_rate_opens_the_details_to_show_the_error(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->inCurrency(Currency::Gbp, '1.17')->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->set('exchange_rate', '0')
            ->call('save')
            ->assertHasErrors(['exchange_rate'])
            ->assertSet('showDetails', true);

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_a_bet_cannot_be_placed_at_an_inactive_bookmaker(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $inactive = Bookmaker::factory()->for($user)->inactive()->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $inactive)
            ->call('save')
            ->assertHasErrors(['bookmaker_id' => 'exists']);

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_a_bet_cannot_be_placed_at_another_users_bookmaker(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $someoneElses = Bookmaker::factory()->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $someoneElses)
            ->call('save')
            ->assertHasErrors(['bookmaker_id' => 'exists'])
            ->assertSee('Choose one of your bookmakers.');

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_another_users_list_entries_are_never_attached(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();

        $otherUser = User::factory()->create();
        $otherSport = Sport::factory()->for($otherUser)->create(['name' => 'Football']);
        Team::factory()->for($otherUser)->forSport($otherSport)->create(['name' => 'Arsenal']);
        Competition::factory()->for($otherUser)->forSport($otherSport)->create(['name' => 'Premier League']);
        Market::factory()->for($otherUser)->create(['name' => '1X2']);
        Tipster::factory()->for($otherUser)->create(['name' => 'Own model']);
        Tag::factory()->for($otherUser)->create(['name' => 'value']);
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->set('selections.0.competition', 'Premier League')
            ->set('selections.0.market', '1X2')
            ->set('tipster', 'Own model')
            ->set('tags', ['value'])
            ->call('save')
            ->assertHasNoErrors();

        $bet = $user->bets()->sole();
        $selection = $bet->selections()->sole();
        $this->assertSame($user->id, $selection->sport->user_id);
        $this->assertSame($user->id, $selection->competition?->user_id);
        $this->assertSame($user->id, $selection->market?->user_id);
        $this->assertSame($user->id, $bet->tipster?->user_id);
        $this->assertSame([$user->id], $selection->teams()->pluck('teams.user_id')->unique()->all());
        $this->assertSame([$user->id], $bet->tags()->pluck('tags.user_id')->all());

        $this->assertSame(1, $otherUser->teams()->count());
        $this->assertFalse($otherSport->selections()->exists());
    }

    public function test_an_accumulator_is_saved_with_its_selections_in_order_and_total_odds(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->call('useAccumulator')
            ->assertSet('selections.1.sport', 'Football')
            ->set('selections.1.team_name', 'Liverpool')
            ->call('addTeam', 1)
            ->set('selections.1.team_name', 'Man City')
            ->call('addTeam', 1)
            ->set('selections.1.pick', 'Over 2.5 goals')
            ->set('selections.1.odds', '2.10')
            ->set('stake', '200')
            ->assertSee(['Selection 1', 'Selection 2', 'Total odds (2 selections)', '4.095', '€819.00', '+€619.00'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('bets.index'));

        $bet = $user->bets()->with('selections.teams')->sole();
        $this->assertSame(BetType::Accumulator, $bet->type);
        $this->assertSame('4.0950', $bet->total_odds);
        $this->assertSame([1, 2], $bet->selections->pluck('position')->all());
        $this->assertSame(['Arsenal −0.25', 'Over 2.5 goals'], $bet->selections->pluck('pick')->all());
        $this->assertSame(['Liverpool', 'Man City'], $bet->selections[1]->teams->pluck('name')->all());
        $this->assertSame('Liverpool v Man City', $bet->selections[1]->event_name);
    }

    public function test_selections_can_be_added_and_removed_but_an_accumulator_keeps_two(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $component = Livewire::test(Create::class)
            ->set('selections.0.pick', 'First')
            ->call('useAccumulator')
            ->set('selections.1.pick', 'Second')
            ->call('removeSelection', 0)
            ->assertCount('selections', 2)
            ->call('addSelection')
            ->set('selections.2.pick', 'Third')
            ->call('removeSelection', 1)
            ->assertCount('selections', 2)
            ->assertSet('selections.0.pick', 'First')
            ->assertSet('selections.1.pick', 'Third');

        $component->call('useSingle')
            ->assertCount('selections', 1)
            ->assertSet('selections.0.pick', 'First')
            ->assertDontSee('Total odds');
    }

    public function test_an_accumulator_has_at_most_20_selections(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $component = Livewire::test(Create::class)->call('useAccumulator');

        for ($i = 0; $i < 25; $i++) {
            $component->call('addSelection');
        }

        $component->assertCount('selections', 20)->assertDontSee('Add selection');
    }

    public function test_every_selection_of_an_accumulator_is_validated(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->call('useAccumulator')
            ->set('selections.1.odds', '1')
            ->call('save')
            ->assertHasErrors([
                'selections.1.event_name' => 'required',
                'selections.1.pick' => 'required',
                'selections.1.odds',
            ])
            ->assertHasNoErrors(['selections.0.pick', 'selections.0.odds']);

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_the_total_odds_of_an_accumulator_must_fit(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->set('selections.0.odds', '5000')
            ->call('useAccumulator')
            ->set('selections.1.event_name', 'Wimbledon outright')
            ->set('selections.1.pick', 'Long shot')
            ->set('selections.1.odds', '2000.001')
            ->assertSee('Total odds (2 selections)')
            ->assertDontSee('€')
            ->call('save')
            ->assertHasErrors(['selections'])
            ->assertSee('The total odds can’t be above 9,999,999.999. Remove a selection.');

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_a_new_bet_cannot_take_over_an_existing_selection(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $existing = Bet::factory()->forBookmaker($bookmaker)->create();
        $this->actingAs($user);

        $this->fillSingle(Livewire::test(Create::class), $bookmaker)
            ->set('selections.0.id', $existing->selections()->sole()->id)
            ->call('save')
            ->assertHasErrors(['selections.0.id']);

        $this->assertSame(1, $user->bets()->count());
    }

    /**
     * Fill in the essentials of a valid single at the bookmaker.
     */
    private function fillSingle(Testable $component, Bookmaker $bookmaker): Testable
    {
        return $component
            ->set('bookmaker_id', (string) $bookmaker->id)
            ->set('selections.0.sport', 'Football')
            ->set('selections.0.team_name', 'Arsenal')
            ->call('addTeam', 0)
            ->set('selections.0.team_name', 'Chelsea')
            ->call('addTeam', 0)
            ->set('selections.0.pick', 'Arsenal −0.25')
            ->set('selections.0.odds', '1.95')
            ->set('stake', '200');
    }
}
