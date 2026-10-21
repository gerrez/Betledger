<?php

namespace Tests\Feature\Bets;

use App\Domain\BetStatus;
use App\Domain\Settlement\SelectionResult;
use App\Livewire\Bets\Index;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Selection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Constraints\SeeInOrder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class InlineSettleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bookmaker $bookmaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-21 15:00:00');
        $this->user = User::factory()->create();
        $this->bookmaker = Bookmaker::factory()->for($this->user)->create();
    }

    public function test_an_open_single_offers_the_five_results(): void
    {
        $this->single('Arsenal v Chelsea', '1.95');

        $this->bets()
            ->assertSeeInOrder(['Arsenal v Chelsea', 'How did it go?', 'Won', 'Lost', 'Void', '½ Won', '½ Lost'])
            ->assertDontSee('Undo');
    }

    public function test_settling_a_single_shows_the_result_in_place_until_undone(): void
    {
        $bet = $this->single('Arsenal v Chelsea', '1.95', stake: 20000);
        $this->single('Later match', '2.00', startsAt: '2026-10-22 20:00:00');

        $list = $this->bets()->call('settle', $this->selection($bet)->id, 'half_won');
        $this->assertSeeInOrder($list, ['Open · 1', 'Arsenal v Chelsea', 'Settled: ½ Won', 'Payout €295.00 · profit +€95.00', 'Undo', 'Later match', 'How did it go?']);

        $bet->refresh();
        $this->assertSame(BetStatus::Settled, $bet->status);
        $this->assertSame(29500, $bet->payout);
        $this->assertTrue($bet->settled_at?->equalTo(now()));

        $this->assertSeeInOrder($list->call('undo', $bet->id), ['Open · 2', 'Arsenal v Chelsea', 'How did it go?', 'Later match'])
            ->assertDontSee(['Settled:', 'Undo']);

        $bet->refresh();
        $this->assertSame(BetStatus::Open, $bet->status);
        $this->assertNull($bet->payout);
        $this->assertNull($bet->settled_at);
        $this->assertNull($this->selection($bet)->result);
    }

    public function test_an_accumulator_is_settled_one_selection_at_a_time_soonest_first(): void
    {
        $bet = Bet::factory()->forBookmaker($this->bookmaker)->has(Selection::factory()->count(3)->state(new Sequence(
            ['position' => 1, 'pick' => 'Brentford (draw no bet)', 'odds' => '1.80', 'event_starts_at' => '2026-10-21 20:00:00'],
            ['position' => 2, 'pick' => 'Over 2.5 goals', 'odds' => '1.82', 'event_starts_at' => null],
            ['position' => 3, 'pick' => 'Both teams to score', 'odds' => '1.65', 'event_starts_at' => '2026-10-21 18:00:00'],
        )), 'selections')->create(['stake' => 5000]);

        $list = $this->bets()->assertSee('Settle the next selection, Both teams to score:');

        $this->assertSeeInOrder($list->call('settle', $this->selection($bet, 3)->id, 'won'), ['Both teams to score: Won', 'Undo', 'Settle the next selection, Brentford (draw no bet):']);

        $this->assertSeeInOrder($list->call('settle', $this->selection($bet, 1)->id, 'won'), ['Brentford (draw no bet): Won', 'Undo', 'Settle the last selection, Over 2.5 goals:']);

        $this->assertSeeInOrder($list->call('settle', $this->selection($bet, 2)->id, 'won'), ['3-fold accumulator', 'Final leg won · bet settled', 'Payout €270.27 · profit +€220.27', 'Undo'])
            ->assertDontSee('Settle the');
        $this->assertSame(BetStatus::Settled, $bet->refresh()->status);

        $list->call('undo', $bet->id)
            ->assertSee('Settle the last selection, Over 2.5 goals:')
            ->assertDontSee(['bet settled', 'Undo']);
        $this->assertSame(BetStatus::Open, $bet->refresh()->status);
        $this->assertSame(SelectionResult::Won, $this->selection($bet, 1)->result);
    }

    public function test_a_lost_leg_settles_an_accumulator_with_selections_still_pending(): void
    {
        $bet = Bet::factory()->forBookmaker($this->bookmaker)->accumulator(['1.50', '2.00'])->create(['stake' => 5000]);

        $this->assertSeeInOrder($this->bets()->call('settle', $this->selection($bet, 1)->id, 'lost'), ['Leg lost · bet settled', 'Payout €0.00 · profit −€50.00', 'Undo'])
            ->assertDontSee('Settle the');

        $this->assertSame(BetStatus::Settled, $bet->refresh()->status);
    }

    public function test_settled_bets_have_no_result_buttons_or_undo(): void
    {
        $this->single('Settled earlier', '2.10', result: SelectionResult::Won);
        Bet::factory()->forBookmaker($this->bookmaker)->accumulator(['1.50', '2.00'])->withResults(SelectionResult::Lost, null)->create();

        $this->actingAs($this->user);

        Livewire::withQueryParams(['status' => 'all'])
            ->test(Index::class)
            ->assertSee(['Settled earlier', 'Settled: Won', '2-fold accumulator'])
            ->assertDontSee(['How did it go?', 'Settle the', 'Undo']);
    }

    public function test_a_new_tab_filter_order_or_page_lets_settled_bets_go_and_forgets_undo(): void
    {
        $bet = $this->single('Arsenal v Chelsea', '1.95');

        $this->bets()
            ->call('settle', $this->selection($bet)->id, 'won')
            ->assertSee(['Arsenal v Chelsea', 'Undo'])
            ->set('sort', 'newest')
            ->assertSee('No open bets')
            ->assertDontSee('Arsenal v Chelsea')
            ->set('status', 'settled')
            ->assertSee(['Arsenal v Chelsea', 'Settled: Won'])
            ->assertDontSee('Undo')
            ->assertSet('undoable', [])
            ->assertSet('pinned', []);
    }

    public function test_a_result_set_elsewhere_is_neither_overwritten_nor_undone(): void
    {
        $bet = $this->single('Arsenal v Chelsea', '1.95');
        $selection = $this->selection($bet);
        $list = $this->bets();

        $selection->update(['result' => SelectionResult::Lost]);
        $list->call('settle', $selection->id, 'won');
        $this->assertSame(SelectionResult::Lost, $selection->refresh()->result);

        $selection->update(['result' => null]);
        $list->call('settle', $selection->id, 'won');
        $selection->update(['result' => SelectionResult::Void]);
        $list->call('undo', $bet->id)->assertDontSee('Undo');
        $this->assertSame(SelectionResult::Void, $selection->refresh()->result);
    }

    public function test_an_unknown_result_is_refused(): void
    {
        $bet = $this->single('Arsenal v Chelsea', '1.95');

        $this->bets()->call('settle', $this->selection($bet)->id, 'cashed_out')->assertStatus(422);

        $this->assertNull($this->selection($bet)->result);
    }

    public function test_users_cannot_settle_another_users_bets(): void
    {
        $theirBet = Bet::factory()->single('2.10')->create();
        $theirSelection = $this->selection($theirBet);
        $this->single('My match', '1.95');

        $this->bets()
            ->call('settle', $theirSelection->id, 'won')
            ->assertNotFound();

        $this->bets()->call('undo', $theirBet->id);

        $this->assertNull($theirSelection->refresh()->result);
        $this->assertSame(BetStatus::Open, $theirBet->refresh()->status);
    }

    /**
     * Livewire forwards assertSeeInOrder() after a call to the raw JSON response, so this
     * checks the rendered HTML instead.
     *
     * @param  list<string>  $values
     */
    private function assertSeeInOrder(Testable $list, array $values): Testable
    {
        $this->assertThat(array_map(e(...), $values), new SeeInOrder($list->html()));

        return $list;
    }

    private function bets(): Testable
    {
        $this->actingAs($this->user);

        return Livewire::test(Index::class);
    }

    private function single(string $eventName, string $odds, int $stake = 10000, ?string $startsAt = '2026-10-21 18:30:00', ?SelectionResult $result = null): Bet
    {
        return Bet::factory()
            ->forBookmaker($this->bookmaker)
            ->has(Selection::factory()->state([
                'position' => 1,
                'event_name' => $eventName,
                'event_starts_at' => $startsAt,
                'odds' => $odds,
                'result' => $result,
            ]), 'selections')
            ->create(['stake' => $stake]);
    }

    private function selection(Bet $bet, int $position = 1): Selection
    {
        return $bet->selections()->where('position', $position)->firstOrFail();
    }
}
