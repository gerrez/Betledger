<?php

namespace Tests\Feature\Bets;

use App\Domain\Settlement\SelectionResult;
use App\Livewire\Bets\Create;
use App\Livewire\Bets\Edit;
use App\Livewire\Bets\Show;
use App\Livewire\Settings\Import;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `bets.event_at`, the date statistics count a bet on, is kept up to date wherever a bet
 * is recorded, edited or imported, and settling leaves it alone.
 */
class EventDateTest extends TestCase
{
    use RefreshDatabase;

    private const SPORTMARKET_FIXTURE = __DIR__.'/../../Fixtures/sportmarket/orders.csv';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-23 10:00:00');
    }

    public function test_a_bet_recorded_in_the_form_counts_on_its_last_start(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $this->actingAs($user);

        Livewire::test(Create::class)
            ->set('bookmaker_id', (string) $bookmaker->id)
            ->set('placed_date', '2026-10-14')
            ->set('placed_time', '17:42')
            ->set('stake', '20')
            ->set('selections.0.sport', 'Football')
            ->set('selections.0.event_name', 'Arsenal v Chelsea')
            ->set('selections.0.event_starts_date', '2026-10-14')
            ->set('selections.0.event_starts_time', '18:30')
            ->set('selections.0.pick', 'Arsenal')
            ->set('selections.0.odds', '1.95')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-10-14 18:30:00', $user->bets()->sole()->event_at->toDateTimeString());
    }

    public function test_a_bet_recorded_through_the_api_counts_on_its_last_start(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();

        $this->withToken($user->createToken('Script')->plainTextToken)
            ->postJson(route('api.v1.bets.store'), [
                'bookmaker_id' => $bookmaker->id,
                'placed_at' => '2026-10-14T12:00:00Z',
                'stake' => '25',
                'selections' => [
                    ['sport' => 'Football', 'event_name' => 'Arsenal v Chelsea', 'event_starts_at' => '2026-10-18T15:00:00Z', 'pick' => 'Arsenal', 'odds' => '1.95'],
                    ['sport' => 'Football', 'event_name' => 'Liverpool v Everton', 'event_starts_at' => '2026-10-19T17:30:00+02:00', 'pick' => 'Liverpool', 'odds' => '1.50'],
                    ['sport' => 'Football', 'event_name' => 'Outright', 'pick' => 'Arsenal', 'odds' => '4.00'],
                ],
            ])
            ->assertCreated()
            ->assertJsonMissingPath('data.event_at');

        $this->assertSame('2026-10-19 15:30:00', $user->bets()->sole()->event_at->toDateTimeString());
    }

    public function test_a_bet_without_start_times_counts_on_its_placed_date(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create();

        $this->withToken($user->createToken('Script')->plainTextToken)
            ->postJson(route('api.v1.bets.store'), [
                'bookmaker_id' => $bookmaker->id,
                'placed_at' => '2026-10-14T12:00:00Z',
                'stake' => '25',
                'selections' => [['sport' => 'Football', 'event_name' => 'Outright', 'pick' => 'Arsenal', 'odds' => '4.00']],
            ])
            ->assertCreated();

        $this->assertSame('2026-10-14 12:00:00', $user->bets()->sole()->event_at->toDateTimeString());
    }

    public function test_changing_a_start_time_in_the_edit_form_moves_the_bet(): void
    {
        $bet = Bet::factory()->settled()->create(['placed_at' => '2026-10-14 12:00:00']);
        $settledAt = $bet->settled_at?->toDateTimeString();
        $this->actingAs($bet->user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->set('selections.0.event_starts_date', '2026-10-31')
            ->set('selections.0.event_starts_time', '20:00')
            ->call('save')
            ->assertHasNoErrors();

        $bet->refresh();
        $this->assertSame('2026-10-31 20:00:00', $bet->event_at->toDateTimeString());
        $this->assertSame($settledAt, $bet->settled_at?->toDateTimeString());
    }

    public function test_settling_and_unsettling_leave_the_event_date_alone(): void
    {
        $bet = Bet::factory()->create(['placed_at' => '2026-10-14 12:00:00']);
        $selection = $bet->selections()->sole();
        $selection->update(['event_starts_at' => '2026-10-18 15:00:00']);
        $bet->syncEventAt()->save();
        $this->actingAs($bet->user);

        $show = Livewire::test(Show::class, ['bet' => $bet])->call('setResult', $selection->id, SelectionResult::Won->value);

        $this->assertTrue($bet->refresh()->isSettled());
        $this->assertSame('2026-11-23 10:00:00', $bet->settled_at?->toDateTimeString());
        $this->assertSame('2026-10-18 15:00:00', $bet->event_at->toDateTimeString());

        $show->call('clearResult', $selection->id);

        $this->assertFalse($bet->refresh()->isSettled());
        $this->assertSame('2026-10-18 15:00:00', $bet->event_at->toDateTimeString());
    }

    public function test_an_imported_bet_counts_on_its_game_date(): void
    {
        $bet = $this->importedBet();

        $this->assertSame('2026-10-21 23:59:00', $bet->settled_at?->toDateTimeString());
        $this->assertSame('2026-10-21 23:59:00', $bet->event_at->toDateTimeString());
    }

    public function test_editing_an_imported_bet_keeps_its_game_date(): void
    {
        $bet = $this->importedBet();

        Livewire::test(Edit::class, ['bet' => $bet])
            ->set('notes', 'Checked against the statement.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-10-21 23:59:00', $bet->refresh()->event_at->toDateTimeString());
    }

    public function test_an_unsettled_imported_bet_keeps_its_game_date_when_edited_and_settled_again(): void
    {
        $bet = $this->importedBet();
        $selection = $bet->selections()->sole();
        $show = Livewire::test(Show::class, ['bet' => $bet])->call('clearResult', $selection->id);

        Livewire::test(Edit::class, ['bet' => $bet->refresh()])
            ->set('notes', 'Waiting for the bookie.')
            ->call('save')
            ->assertHasNoErrors();
        $show->call('setResult', $selection->id, SelectionResult::Won->value);

        $bet->refresh();
        $this->assertSame('2026-11-23 10:00:00', $bet->settled_at?->toDateTimeString());
        $this->assertSame('2026-10-21 23:59:00', $bet->event_at->toDateTimeString());
    }

    public function test_a_start_time_given_to_an_imported_bet_replaces_its_game_date(): void
    {
        $bet = $this->importedBet();

        Livewire::test(Edit::class, ['bet' => $bet])
            ->set('selections.0.event_starts_date', '2026-10-21')
            ->set('selections.0.event_starts_time', '21:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('2026-10-21 21:00:00', $bet->refresh()->event_at->toDateTimeString());
    }

    /**
     * Order 2384017276 of the Sportmarket fixture: placed 2026-10-21 20:26, game date the
     * same day. The user is logged in afterwards.
     */
    private function importedBet(): Bet
    {
        $user = User::factory()->create();
        $sportmarket = Bookmaker::factory()->for($user)->create(['name' => 'Sportmarket']);
        $this->actingAs($user);

        Livewire::test(Import::class)
            ->set('bookmaker', (string) $sportmarket->id)
            ->set('file', UploadedFile::fake()->createWithContent('orders.csv', (string) file_get_contents(self::SPORTMARKET_FIXTURE)))
            ->call('preview')
            ->call('import')
            ->assertHasNoErrors();

        return $user->bets()->where('external_ref', 'sportmarket:2384017276')->sole();
    }
}
