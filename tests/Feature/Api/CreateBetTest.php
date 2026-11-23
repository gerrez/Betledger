<?php

namespace Tests\Feature\Api;

use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Currency;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateBetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_without_a_token_is_refused_with_401(): void
    {
        $this->postJson(route('api.v1.bets.store'), [])->assertUnauthorized();

        $this->post(route('api.v1.bets.store'))->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_revoked_token_is_refused(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $token = $user->createToken('Script');
        $token->accessToken->delete();

        $this->withToken($token->plainTextToken)
            ->postJson(route('api.v1.bets.store'), $this->single($bookmaker))
            ->assertUnauthorized();

        $this->assertSame(0, Bet::query()->count());
    }

    public function test_a_personal_access_token_records_a_bet_for_its_user(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();

        $this->withToken($user->createToken('Script')->plainTextToken)
            ->postJson(route('api.v1.bets.store'), $this->single($bookmaker))
            ->assertCreated();

        $this->assertSame(1, $user->bets()->count());
    }

    public function test_a_single_is_recorded_with_every_field_of_the_form(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.1341')->create(['name' => 'Danske Spil']);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.bets.store'), [
            'bookmaker_id' => $bookmaker->id,
            'placed_at' => '2026-11-19T18:30:00+01:00',
            'stake' => '250,50',
            'exchange_rate' => '0.1342',
            'is_free_bet' => true,
            'tipster' => 'Pundit Pete',
            'tags' => ['Value', 'Live'],
            'notes' => 'Early price.',
            'probability' => '55,5',
            'selections' => [[
                'sport' => 'Football',
                'competition' => 'Premier League',
                'market' => 'Match result',
                'teams' => ['Arsenal', 'Chelsea'],
                'event_name' => 'Arsenal v Chelsea (derby)',
                'event_starts_at' => '2026-11-22T16:30:00Z',
                'pick' => 'Arsenal',
                'odds' => '1.95',
                'closing_odds' => '1.90',
            ]],
        ]);

        $bet = $user->bets()->sole();

        $response->assertCreated()->assertExactJson([
            'data' => [
                'id' => $bet->id,
                'url' => route('bets.show', $bet),
                'type' => 'single',
                'status' => 'open',
                'bookmaker' => ['id' => $bookmaker->id, 'name' => 'Danske Spil'],
                'placed_at' => '2026-11-19T17:30:00Z',
                'currency' => 'DKK',
                'stake' => '250.50',
                'exchange_rate' => '0.1342',
                'is_free_bet' => true,
                'total_odds' => '1.95',
                'probability' => '55.5',
                'bet_value' => '108.23',
                'tipster' => 'Pundit Pete',
                'tags' => ['Value', 'Live'],
                'notes' => 'Early price.',
                'settled_at' => null,
                'payout' => null,
                'profit' => null,
                'selections' => [[
                    'id' => $bet->selections->sole()->id,
                    'position' => 1,
                    'sport' => 'Football',
                    'competition' => 'Premier League',
                    'market' => 'Match result',
                    'teams' => ['Arsenal', 'Chelsea'],
                    'event_name' => 'Arsenal v Chelsea (derby)',
                    'event_starts_at' => '2026-11-22T16:30:00Z',
                    'pick' => 'Arsenal',
                    'odds' => '1.95',
                    'closing_odds' => '1.90',
                    'result' => null,
                ]],
            ],
        ]);

        $this->assertSame(BetType::Single, $bet->type);
        $this->assertSame(BetStatus::Open, $bet->status);
        $this->assertSame(25050, $bet->stake);
        $this->assertSame(Currency::Dkk, $bet->currency);
        $this->assertSame('0.13420000', $bet->exchange_rate);
        $this->assertTrue($bet->is_free_bet);
        $this->assertSame('2026-11-19 17:30:00', $bet->placed_at->format('Y-m-d H:i:s'));
        $this->assertSame('Pundit Pete', $bet->tipster?->name);
        $this->assertSame($user->id, $bet->tipster?->user_id);
        $this->assertEqualsCanonicalizing(['Value', 'Live'], $bet->tags->pluck('name')->all());
        $this->assertSame('Early price.', $bet->notes);
        $this->assertSame('55.50', $bet->probability);
        $this->assertSame('108.23', $bet->bet_value);

        $selection = $bet->selections->sole();
        $this->assertSame('Football', $selection->sport->name);
        $this->assertSame('Premier League', $selection->competition?->name);
        $this->assertSame('Match result', $selection->market?->name);
        $this->assertEqualsCanonicalizing(['Arsenal', 'Chelsea'], $selection->teams->pluck('name')->all());
        $this->assertSame('2026-11-22 16:30:00', $selection->event_starts_at?->format('Y-m-d H:i:s'));
        $this->assertSame('1.950', $selection->odds);
        $this->assertSame('1.900', $selection->closing_odds);
        $this->assertNull($selection->result);
    }

    public function test_an_accumulator_gets_the_total_odds_and_event_names_from_the_teams(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), [
            'bookmaker_id' => $bookmaker->id,
            'stake' => 10,
            'selections' => [
                ['sport' => 'Football', 'teams' => ['Arsenal', 'Chelsea'], 'pick' => 'Arsenal', 'odds' => '1.5'],
                ['sport' => 'Tennis', 'event_name' => 'Sinner v Alcaraz', 'pick' => 'Sinner', 'odds' => 2],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'accumulator')
            ->assertJsonPath('data.total_odds', '3.00')
            ->assertJsonPath('data.stake', '10.00')
            ->assertJsonPath('data.selections.0.event_name', 'Arsenal v Chelsea')
            ->assertJsonPath('data.selections.1.event_name', 'Sinner v Alcaraz');

        $bet = $user->bets()->sole();
        $this->assertSame(BetType::Accumulator, $bet->type);
        $this->assertSame('3.0000', $bet->total_odds);
        $this->assertSame(['Football', 'Tennis'], $bet->selections->map(fn ($selection) => $selection->sport->name)->all());
    }

    public function test_an_accumulators_bet_value_is_calculated_from_its_total_odds(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), [
            'bookmaker_id' => $bookmaker->id,
            'stake' => '10',
            'probability' => 40,
            'selections' => [
                ['sport' => 'Football', 'event_name' => 'Arsenal v Chelsea', 'pick' => 'Arsenal', 'odds' => '1.5'],
                ['sport' => 'Tennis', 'event_name' => 'Sinner v Alcaraz', 'pick' => 'Sinner', 'odds' => '1.85'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.total_odds', '2.775')
            ->assertJsonPath('data.probability', '40')
            ->assertJsonPath('data.bet_value', '111');
    }

    public function test_a_bet_value_can_be_sent_without_a_probability(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), [...$this->single($bookmaker), 'bet_value' => '104,5'])
            ->assertCreated()
            ->assertJsonPath('data.probability', null)
            ->assertJsonPath('data.bet_value', '104.5');

        $this->assertSame('104.50', $user->bets()->sole()->bet_value);
    }

    public function test_probability_and_bet_value_are_optional(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), [...$this->single($bookmaker), 'probability' => null, 'bet_value' => null])
            ->assertCreated()
            ->assertJsonPath('data.probability', null)
            ->assertJsonPath('data.bet_value', null);
    }

    public function test_the_bookmaker_can_be_sent_by_name_and_brings_its_exchange_rate(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->inCurrency(Currency::Dkk, '0.1341')->create(['name' => 'Danske Spil']);
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), [...$this->selectionPayload(), 'bookmaker' => 'danske spil', 'stake' => '100'])
            ->assertCreated()
            ->assertJsonPath('data.bookmaker.name', 'Danske Spil')
            ->assertJsonPath('data.exchange_rate', '0.1341');
    }

    public function test_a_bookmaker_in_the_base_currency_always_has_a_rate_of_1(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), [...$this->single($bookmaker), 'exchange_rate' => '7.46'])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate', '1');
    }

    public function test_the_bet_is_placed_now_when_no_time_is_sent_and_utc_when_no_offset_is_sent(): void
    {
        Carbon::setTestNow('2026-11-19 12:00:00');
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), $this->single($bookmaker))
            ->assertJsonPath('data.placed_at', '2026-11-19T12:00:00Z');

        $this->postJson(route('api.v1.bets.store'), [...$this->single($bookmaker), 'placed_at' => '2026-11-18T21:15'])
            ->assertJsonPath('data.placed_at', '2026-11-18T21:15:00Z');
    }

    public function test_names_are_matched_to_the_users_own_list_entries_ignoring_case(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $tipster = Tipster::factory()->for($user)->create(['name' => 'Pundit Pete']);
        $tag = Tag::factory()->for($user)->create(['name' => 'Value']);
        $sport = Sport::factory()->for($user)->create(['name' => 'Football']);
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), [
            ...$this->single($bookmaker),
            'tipster' => 'pundit pete',
            'tags' => ['VALUE', 'value'],
            'selections' => [['sport' => 'football', 'event_name' => 'Arsenal v Chelsea', 'pick' => 'Arsenal', 'odds' => '1.95']],
        ])->assertCreated();

        $bet = $user->bets()->sole();
        $this->assertSame($tipster->id, $bet->tipster_id);
        $this->assertSame([$tag->id], $bet->tags->modelKeys());
        $this->assertSame($sport->id, $bet->selections->sole()->sport_id);
        $this->assertSame(1, $user->tipsters()->count());
        $this->assertSame(1, $user->tags()->count());
        $this->assertSame(1, $user->sports()->count());
    }

    public function test_another_users_bookmaker_cannot_be_used_by_id_or_name(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create();
        $otherBookmaker = Bookmaker::factory()->create(['name' => 'Other Users Book']);
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), $this->single($otherBookmaker))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bookmaker_id' => 'Choose one of your active bookmakers.']);

        $this->postJson(route('api.v1.bets.store'), [...$this->selectionPayload(), 'bookmaker' => 'Other Users Book', 'stake' => '10'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bookmaker' => 'Choose one of your active bookmakers.']);

        $this->assertSame(0, Bet::query()->count());
    }

    public function test_an_inactive_bookmaker_cannot_be_used(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->inactive()->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), $this->single($bookmaker))
            ->assertJsonValidationErrors(['bookmaker_id' => 'Choose one of your active bookmakers.']);
    }

    public function test_the_bet_and_its_list_entries_belong_to_the_token_user_never_to_the_owner_of_same_named_entries(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $otherUser = User::factory()->create();
        Tipster::factory()->for($otherUser)->create(['name' => 'Pundit Pete']);
        Tag::factory()->for($otherUser)->create(['name' => 'Value']);
        Sport::factory()->for($otherUser)->create(['name' => 'Football']);
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), [
            ...$this->single($bookmaker),
            'user_id' => $otherUser->id,
            'status' => 'settled',
            'payout' => '1000',
            'tipster' => 'Pundit Pete',
            'tags' => ['Value'],
        ])->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.payout', null);

        $bet = Bet::query()->sole();
        $this->assertSame($user->id, $bet->user_id);
        $this->assertSame($user->id, $bet->tipster?->user_id);
        $this->assertSame([$user->id], $bet->tags->pluck('user_id')->all());
        $this->assertSame($user->id, $bet->selections->sole()->sport->user_id);
        $this->assertSame(0, $otherUser->bets()->count());
    }

    public function test_names_with_a_bar_are_rejected(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), [
            'bookmaker_id' => $bookmaker->id,
            'stake' => '25',
            'tipster' => 'Me | Model',
            'tags' => ['Value', 'Live | Value'],
            'selections' => [[
                'sport' => 'Football | Soccer',
                'competition' => 'EPL | Premier League',
                'market' => '1X2 | Match result',
                'teams' => ['Arsenal | Chelsea'],
                'pick' => 'Arsenal',
                'odds' => '1.95',
            ]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'tipster' => 'Names can’t contain “|”.',
                'tags.1' => 'Names can’t contain “|”.',
                'selections.0.sport' => 'Names can’t contain “|”.',
                'selections.0.competition' => 'Names can’t contain “|”.',
                'selections.0.market' => 'Names can’t contain “|”.',
                'selections.0.teams.0' => 'Names can’t contain “|”.',
            ])
            ->assertJsonMissingValidationErrors(['tags.0']);

        $this->assertSame(0, Bet::query()->count());
    }

    public function test_an_empty_request_names_the_required_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('api.v1.bets.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'bookmaker' => 'Send the bookmaker, by name (`bookmaker`) or id (`bookmaker_id`).',
                'bookmaker_id' => 'Send the bookmaker, by name (`bookmaker`) or id (`bookmaker_id`).',
                'stake' => 'Enter the stake.',
                'selections' => 'Add at least one selection.',
            ]);
    }

    public function test_a_selection_needs_sport_event_pick_and_odds(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), [...$this->single($bookmaker), 'selections' => [['teams' => []]]])
            ->assertJsonValidationErrors([
                'selections.0.sport' => 'Enter the sport.',
                'selections.0.event_name' => 'Enter the event, or add the teams taking part.',
                'selections.0.pick' => 'Enter what you backed.',
                'selections.0.odds' => 'Enter the odds.',
            ]);
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_rejected(array $change, string $field, string $message): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.bets.store'), array_replace_recursive($this->single($bookmaker), $change))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);

        $this->assertSame(0, Bet::query()->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidValues(): array
    {
        return [
            'odds as a JSON float' => [['selections' => [['odds' => 1.95]]], 'selections.0.odds', 'Send the odds as a string, like "1.95".'],
            'odds of 1' => [['selections' => [['odds' => '1']]], 'selections.0.odds', 'Enter odds above 1 as a string, like "1.95" (up to 3 decimals).'],
            'closing odds with 4 decimals' => [['selections' => [['closing_odds' => '1.9512']]], 'selections.0.closing_odds', 'Enter odds above 1 as a string, like "1.95" (up to 3 decimals).'],
            'stake as a JSON float' => [['stake' => 25.5], 'stake', 'Send the stake as a string, like "25.50".'],
            'stake of 0' => [['stake' => '0'], 'stake', 'Enter the stake as an amount above 0, like "25" or "25.50".'],
            'stake with 3 decimals' => [['stake' => '10.555'], 'stake', 'Enter the stake as an amount above 0, like "25" or "25.50".'],
            'rate of 0' => [['exchange_rate' => '0'], 'exchange_rate', 'Enter the rate as a number above 0, like "7.46" (up to 8 decimals).'],
            'a date that does not exist' => [['placed_at' => '2026-02-30T10:00:00Z'], 'placed_at', 'Enter an ISO 8601 date and time, like "2026-11-19T18:30:00+01:00".'],
            'a date without a time' => [['placed_at' => '2026-11-19'], 'placed_at', 'Enter an ISO 8601 date and time, like "2026-11-19T18:30:00+01:00".'],
            'a relative date' => [['selections' => [['event_starts_at' => 'tomorrow']]], 'selections.0.event_starts_at', 'Enter an ISO 8601 date and time, like "2026-11-19T18:30:00+01:00".'],
            'bookmaker by id and name' => [['bookmaker' => 'Unibet'], 'bookmaker_id', 'Send the bookmaker by name or by id, not both.'],
            'tags as a string' => [['tags' => 'Value'], 'tags', 'The tags field must be a list.'],
            'notes too long' => [['notes' => str_repeat('a', 5001)], 'notes', 'The notes field must not be greater than 5000 characters.'],
            'probability as a JSON float' => [['probability' => 55.5], 'probability', 'Send the probability as a string, like "55.5".'],
            'probability of 0' => [['probability' => '0'], 'probability', 'Enter the probability as a percentage above 0 and at most 100, like "55.5" (up to 2 decimals).'],
            'probability above 100' => [['probability' => '100.01'], 'probability', 'Enter the probability as a percentage above 0 and at most 100, like "55.5" (up to 2 decimals).'],
            'probability with 3 decimals' => [['probability' => '55.555'], 'probability', 'Enter the probability as a percentage above 0 and at most 100, like "55.5" (up to 2 decimals).'],
            'probability and bet value' => [['probability' => '55', 'bet_value' => '107'], 'probability', 'Send the probability or the bet value, not both: the bet value is calculated from the probability.'],
            'bet value of 0' => [['bet_value' => '0'], 'bet_value', 'Enter the bet value as a percentage above 0, like "104.5" (up to 2 decimals).'],
            'bet value as a JSON float' => [['bet_value' => 104.5], 'bet_value', 'Send the bet value as a string, like "104.5".'],
        ];
    }

    public function test_an_accumulator_has_at_most_20_selections_and_total_odds_that_fit(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        Sanctum::actingAs($user);
        $leg = ['sport' => 'Football', 'event_name' => 'Arsenal v Chelsea', 'pick' => 'Arsenal', 'odds' => '1.5'];

        $this->postJson(route('api.v1.bets.store'), [...$this->single($bookmaker), 'selections' => array_fill(0, 21, $leg)])
            ->assertJsonValidationErrors(['selections' => 'An accumulator can have at most 20 selections.']);

        $this->postJson(route('api.v1.bets.store'), [...$this->single($bookmaker), 'selections' => array_fill(0, 3, [...$leg, 'odds' => '1000'])])
            ->assertJsonValidationErrors(['selections' => 'The total odds can’t be above 9,999,999.999.']);
    }

    /**
     * A valid single at the bookmaker.
     *
     * @return array<string, mixed>
     */
    private function single(Bookmaker $bookmaker): array
    {
        return [...$this->selectionPayload(), 'bookmaker_id' => $bookmaker->id, 'stake' => '25'];
    }

    /**
     * @return array{selections: list<array<string, string>>}
     */
    private function selectionPayload(): array
    {
        return ['selections' => [['sport' => 'Football', 'event_name' => 'Arsenal v Chelsea', 'pick' => 'Arsenal', 'odds' => '1.95']]];
    }
}
