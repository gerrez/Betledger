<?php

namespace Tests\Feature\Bets;

use App\Livewire\Bets\Create;
use App\Livewire\Bets\Edit;
use App\Livewire\Bets\Index;
use App\Livewire\Bets\Show;
use App\Livewire\Overview\Index as Overview;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The bet form, bet cards and bet detail page enter and show times in the user's own
 * time zone, while the database keeps UTC instants.
 */
class BetTimesInUserZoneTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bookmaker $bookmaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'Europe/Copenhagen']);
        $this->bookmaker = Bookmaker::factory()->for($this->user)->create(['name' => 'Unibet']);
    }

    public function test_placed_and_starts_are_typed_in_local_time_and_stored_as_utc(): void
    {
        $this->actingAs($this->user);

        Livewire::test(Create::class)
            ->set('bookmaker_id', (string) $this->bookmaker->id)
            ->set('placed_date', '2026-07-01')
            ->set('placed_time', '00:30')
            ->set('selections.0.sport', 'Football')
            ->set('selections.0.event_name', 'Arsenal v Chelsea')
            ->set('selections.0.event_starts_date', '2026-12-01')
            ->set('selections.0.event_starts_time', '00:15')
            ->set('selections.0.pick', 'Arsenal')
            ->set('selections.0.odds', '2.00')
            ->set('stake', '10')
            ->call('save')
            ->assertHasNoErrors();

        $bet = Bet::sole();
        $this->assertSame('2026-06-30 22:30:00', $bet->getRawOriginal('placed_at'));
        $this->assertSame('2026-11-30 23:15:00', $bet->selections()->sole()->getRawOriginal('event_starts_at'));
    }

    public function test_a_new_bet_is_placed_at_the_users_local_now(): void
    {
        $this->travelTo('2026-11-22 23:30:00');
        $this->actingAs($this->user);

        Livewire::test(Create::class)
            ->assertSet('placed_date', '2026-11-23')
            ->assertSet('placed_time', '00:30');
    }

    public function test_the_edit_form_shows_local_times_and_saving_them_unchanged_moves_nothing(): void
    {
        $bet = Bet::factory()->forBookmaker($this->bookmaker)->single()->create(['placed_at' => '2026-06-30 22:30:00']);
        $bet->selections()->update(['event_starts_at' => '2026-11-30 23:15:00']);
        $bet->syncEventAt()->save();
        $this->actingAs($this->user);

        Livewire::test(Edit::class, ['bet' => $bet])
            ->assertSet('placed_date', '2026-07-01')
            ->assertSet('placed_time', '00:30')
            ->assertSet('selections.0.event_starts_date', '2026-12-01')
            ->assertSet('selections.0.event_starts_time', '00:15')
            ->call('save')
            ->assertHasNoErrors();

        $bet->refresh();
        $this->assertSame('2026-06-30 22:30:00', $bet->getRawOriginal('placed_at'));
        $this->assertSame('2026-11-30 23:15:00', $bet->selections()->sole()->getRawOriginal('event_starts_at'));
        $this->assertSame('2026-11-30 23:15:00', $bet->getRawOriginal('event_at'));
    }

    public function test_a_bet_card_says_today_and_yesterday_by_local_days(): void
    {
        $this->travelTo('2026-11-23 08:00:00'); // 09:00 in Copenhagen
        $this->openBet('Late kick-off', startsAt: '2026-11-22 23:30:00'); // 00:30 today in Copenhagen
        $this->openBet('Evening game', startsAt: '2026-11-22 19:00:00'); // 20:00 yesterday
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSee('today 00:30')
            ->assertSee('yesterday 20:00')
            ->assertDontSee('23:30');
    }

    public function test_the_home_preview_shows_starts_in_local_time(): void
    {
        $this->travelTo('2026-11-22 12:00:00');
        $this->openBet('Late kick-off', startsAt: '2026-11-22 23:30:00');
        $this->actingAs($this->user);

        Livewire::test(Overview::class)->assertSee('tomorrow 00:30');
    }

    public function test_two_users_see_the_same_instant_on_their_own_days(): void
    {
        $this->travelTo('2026-11-23 08:00:00');
        $other = User::factory()->create(['timezone' => 'UTC']);
        $othersBookmaker = Bookmaker::factory()->for($other)->create();
        $this->openBet('Late kick-off', startsAt: '2026-11-22 23:30:00');
        $this->openBet('Late kick-off', startsAt: '2026-11-22 23:30:00', bookmaker: $othersBookmaker);

        $this->actingAs($this->user);
        Livewire::test(Index::class)->assertSee('today 00:30')->assertDontSee('yesterday 23:30');

        $this->actingAs($other);
        Livewire::test(Index::class)->assertSee('yesterday 23:30')->assertDontSee('today 00:30');
    }

    public function test_the_bet_detail_page_shows_placed_settled_and_starts_in_local_time(): void
    {
        $bet = Bet::factory()->forBookmaker($this->bookmaker)->single()->settled()->create(['placed_at' => '2026-07-14 15:42:00']);
        $bet->selections()->update(['event_starts_at' => '2026-07-14 18:30:00']);
        $bet->syncEventAt()->forceFill(['settled_at' => '2026-12-31 23:05:00'])->save();
        $this->actingAs($this->user);

        Livewire::test(Show::class, ['bet' => $bet])
            ->assertSee('14 Jul 2026, 17:42') // placed, summer time
            ->assertSee('14 Jul 2026, 20:30') // starts
            ->assertSee('1 Jan 2027, 00:05'); // settled, winter time
    }

    private function openBet(string $event, string $startsAt, ?Bookmaker $bookmaker = null): Bet
    {
        $bet = Bet::factory()->forBookmaker($bookmaker ?? $this->bookmaker)->single()->create(['placed_at' => '2026-11-20 12:00:00']);
        $bet->selections()->update(['event_name' => $event, 'event_starts_at' => $startsAt]);
        $bet->syncEventAt()->save();

        return $bet;
    }
}
