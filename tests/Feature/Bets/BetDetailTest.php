<?php

namespace Tests\Feature\Bets;

use App\Domain\BetStatus;
use App\Domain\Currency;
use App\Domain\Settlement\SelectionResult;
use App\Livewire\Bets\Show;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Competition;
use App\Models\Market;
use App\Models\Selection;
use App\Models\Sport;
use App\Models\Tag;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Constraints\SeeInOrder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BetDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bookmaker $bookmaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-21 15:00:00');
        $this->user = User::factory()->create();
        $this->bookmaker = Bookmaker::factory()->for($this->user)->create(['name' => 'Unibet']);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $bet = $this->single();

        $this->get(route('bets.show', $bet))->assertRedirect(route('login'));
    }

    public function test_only_the_owner_can_open_the_page(): void
    {
        $bet = $this->single();

        $this->actingAs($this->user)->get(route('bets.show', $bet))
            ->assertOk()
            ->assertSee(['Single bet', route('bets.edit', $bet), route('bets.index')]);

        $this->actingAs(User::factory()->create())->get(route('bets.show', $bet))->assertForbidden();
    }

    public function test_a_settled_single_shows_profit_in_bet_and_base_currency_clv_and_facts(): void
    {
        $this->user->forceFill(['base_currency' => Currency::Dkk])->save();
        $pinnacle = Bookmaker::factory()->for($this->user)->inCurrency(Currency::Eur, '7.46')->create(['name' => 'Pinnacle']);
        $football = Sport::factory()->for($this->user)->create(['name' => 'Football']);
        $bet = Bet::factory()
            ->forBookmaker($pinnacle)
            ->has(Selection::factory()->state([
                'position' => 1,
                'sport_id' => $football->id,
                'competition_id' => Competition::factory()->for($this->user)->forSport($football)->create(['name' => 'Premier League'])->id,
                'market_id' => Market::factory()->for($this->user)->create(['name' => 'Asian handicap'])->id,
                'event_name' => 'Arsenal v Chelsea',
                'event_starts_at' => '2026-10-07 18:30:00',
                'pick' => 'Arsenal −0.25',
                'odds' => '1.95',
                'closing_odds' => '1.88',
            ]), 'selections')
            ->hasAttached([
                Tag::factory()->for($this->user)->create(['name' => 'value']),
                Tag::factory()->for($this->user)->create(['name' => 'pre-match']),
            ], ['user_id' => $this->user->id])
            ->settled(SelectionResult::Won)
            ->create([
                'stake' => 20000,
                'placed_at' => '2026-10-07 17:42:00',
                'tipster_id' => Tipster::factory()->for($this->user)->create(['name' => 'Own model'])->id,
                'notes' => 'Line moved from −0.25 at 2.02.',
            ]);

        $this->assertSeeInOrder($this->page($bet), [
            'Single bet',
            'Won', '+€190.00', '≈ +1,417.40 kr', 'Payout €390.00', 'calculated',
            'Arsenal −0.25', 'Arsenal v Chelsea · Premier League · Asian handicap', 'Starts 7 Oct 2026, 18:30', '1.95',
            'Closing odds 1.88', 'Beat the close +3.7%',
            'Clear result (back to open)',
            'Bookmaker paid a different amount?',
            'Bookmaker', 'Pinnacle (EUR, rate 7.46)',
            'Stake', '€200.00 ≈ 1,492.00 kr',
            'Placed', '7 Oct 2026, 17:42',
            'Settled', '7 Oct 2026, 20:42',
            'Sport', 'Football',
            'Tipster', 'Own model',
            'Tags', 'pre-match · value',
            'Notes', 'Line moved from −0.25 at 2.02.',
            'Delete bet',
        ])->assertDontSee(['Total odds', 'CLV']);
    }

    public function test_an_open_bet_shows_what_it_pays_if_it_wins(): void
    {
        $bet = $this->single(odds: '1.95', stake: 20000, closingOdds: '2.10');

        $this->assertSeeInOrder($this->page($bet), ['Open', '€390.00', 'Payout if it wins · profit +€190.00', 'Missed the close −7.1%'])
            ->assertDontSee(['Clear result', 'Bookmaker paid a different amount?', 'Settled']);
    }

    public function test_an_open_accumulator_counts_the_results_it_already_has(): void
    {
        $bet = Bet::factory()->forBookmaker($this->bookmaker)->accumulator(['1.50', '2.00', '1.80'])
            ->withResults(SelectionResult::Won, SelectionResult::Void, null)
            ->create(['stake' => 10000]);

        $this->assertSeeInOrder($this->page($bet), [
            '3-fold accumulator', 'Open', '€270.00', 'Payout if every open selection wins · profit +€170.00',
            'Clear result', 'Clear result', 'Total odds', '5.40',
        ]);
    }

    public function test_an_accumulator_with_closing_odds_on_every_selection_shows_its_clv(): void
    {
        $bet = Bet::factory()->forBookmaker($this->bookmaker)->has(Selection::factory()->count(2)->state(new Sequence(
            ['position' => 1, 'odds' => '2.00', 'closing_odds' => '1.90'],
            ['position' => 2, 'odds' => '1.50', 'closing_odds' => '1.50'],
        )), 'selections')->create();

        $this->assertSeeInOrder($this->page($bet), ['Beat the close +5.3%', 'Matched the close 0.0%', 'CLV', '+5.3%']);
    }

    public function test_results_set_on_the_page_settle_and_reopen_the_bet(): void
    {
        $bet = $this->single(odds: '1.95', stake: 10000);
        $selection = $bet->selections()->firstOrFail();
        $page = $this->page($bet);

        $this->assertSeeInOrder($page->call('setResult', $selection->id, 'half_won'), ['½ Won', '+€47.50', 'Payout €147.50']);
        $bet->refresh();
        $this->assertSame(BetStatus::Settled, $bet->status);
        $this->assertSame(14750, $bet->payout);
        $this->assertTrue($bet->settled_at?->equalTo(now()));

        $this->travel(1)->hour();
        $this->assertSeeInOrder($page->call('setResult', $selection->id, 'lost'), ['Lost', '−€100.00', 'Payout €0.00']);
        $bet->refresh();
        $this->assertSame(-10000, $bet->profit);
        $this->assertTrue($bet->settled_at?->equalTo(now()->subHour()));

        $page->call('clearResult', $selection->id)->assertSee('Payout if it wins');
        $bet->refresh();
        $this->assertSame(BetStatus::Open, $bet->status);
        $this->assertNull($bet->payout);
        $this->assertNull($selection->refresh()->result);
    }

    public function test_the_other_selections_of_an_accumulator_lost_by_one_leg_can_still_get_results(): void
    {
        $bet = Bet::factory()->forBookmaker($this->bookmaker)->accumulator(['1.50', '2.00'])
            ->withResults(SelectionResult::Lost, null)
            ->create(['stake' => 5000]);
        $settledAt = $bet->settled_at;

        $this->page($bet)->call('setResult', $bet->selections[1]->id, 'won');

        $bet->refresh();
        $this->assertSame(BetStatus::Settled, $bet->status);
        $this->assertSame(SelectionResult::Won, $bet->selections[1]->result);
        $this->assertTrue($bet->settled_at?->equalTo($settledAt));
    }

    public function test_the_payout_can_be_replaced_by_the_amount_actually_paid_and_back(): void
    {
        $bet = $this->single(odds: '1.95', stake: 20000, result: SelectionResult::Won);

        $page = $this->page($bet)
            ->call('editPayout')
            ->assertSet('payout', '390.00')
            ->assertSee('Calculated: €390.00. Changing a result later replaces this amount.')
            ->set('payout', '389,50')
            ->call('savePayout')
            ->assertHasNoErrors()
            ->assertSet('editingPayout', false);

        $this->assertSeeInOrder($page, ['+€189.50', 'Payout €389.50', 'entered manually', 'Payout entered manually (calculated €390.00)', 'Use calculated']);
        $bet->refresh();
        $this->assertSame(38950, $bet->payout);
        $this->assertSame(18950, $bet->profit);
        $this->assertTrue($bet->payout_is_manual);

        $page->call('useCalculatedPayout')->assertSee('Bookmaker paid a different amount?');
        $bet->refresh();
        $this->assertSame(39000, $bet->payout);
        $this->assertFalse($bet->payout_is_manual);
    }

    public function test_changing_a_result_warns_about_and_then_discards_a_manual_payout(): void
    {
        $bet = $this->single(odds: '1.95', stake: 20000, result: SelectionResult::Won);
        $page = $this->page($bet)->call('editPayout')->set('payout', '389.50')->call('savePayout');

        $page->assertSeeHtml('data-confirm="You entered this payout (€389.50) yourself. Changing a result replaces it with the calculated payout."');

        $page->call('setResult', $bet->selections()->firstOrFail()->id, 'half_won')
            ->assertDontSeeHtml('data-confirm="You entered');
        $bet->refresh();
        $this->assertFalse($bet->payout_is_manual);
        $this->assertSame(29500, $bet->payout);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPayouts(): array
    {
        return [
            'empty' => [''],
            'negative' => ['-5'],
            'three decimals' => ['389.505'],
            'thousands separator' => ['1,000.00'],
            'too large' => ['1000000000000'],
        ];
    }

    #[DataProvider('invalidPayouts')]
    public function test_an_invalid_amount_paid_is_refused(string $payout): void
    {
        $bet = $this->single(odds: '1.95', stake: 20000, result: SelectionResult::Won);

        $this->page($bet)->call('editPayout')->set('payout', $payout)->call('savePayout')->assertHasErrors('payout');

        $bet->refresh();
        $this->assertSame(39000, $bet->payout);
        $this->assertFalse($bet->payout_is_manual);
    }

    public function test_an_open_bet_has_no_manual_payout(): void
    {
        $bet = $this->single();

        $this->page($bet)
            ->call('editPayout')
            ->assertSet('editingPayout', false)
            ->set('payout', '50')
            ->call('savePayout');

        $bet->refresh();
        $this->assertNull($bet->payout);
        $this->assertFalse($bet->payout_is_manual);
    }

    public function test_a_free_bet_is_marked_and_its_stake_is_not_returned(): void
    {
        $bet = Bet::factory()->forBookmaker($this->bookmaker)->single('3.00')->freeBet()->settled(SelectionResult::Won)->create(['stake' => 10000]);

        $this->assertSeeInOrder($this->page($bet), ['Won', 'FREE BET', '+€200.00', 'Payout €200.00', 'Stake', '€100.00 · free bet']);
    }

    public function test_deleting_a_bet_removes_it_with_its_selections(): void
    {
        $bet = Bet::factory()->forBookmaker($this->bookmaker)->accumulator()->create();

        $this->page($bet)
            ->assertSeeHtml(['data-confirm-title="Delete this bet?"', 'data-confirm-danger'])
            ->call('delete')
            ->assertRedirect(route('bets.index'));

        $this->assertModelMissing($bet);
        $this->assertSame(0, Selection::query()->where('bet_id', $bet->id)->count());
        $this->assertSame('Bet deleted.', session('status'));
    }

    public function test_an_unknown_result_is_refused(): void
    {
        $bet = $this->single();

        $this->page($bet)->call('setResult', $bet->selections()->firstOrFail()->id, 'cashed_out')->assertStatus(422);

        $this->assertSame(BetStatus::Open, $bet->refresh()->status);
    }

    public function test_users_cannot_see_settle_or_delete_another_users_bet(): void
    {
        $theirBet = Bet::factory()->single('2.10')->create();
        $theirSelection = $theirBet->selections()->firstOrFail();
        $this->actingAs($this->user);

        Livewire::test(Show::class, ['bet' => $theirBet])->assertForbidden();

        $this->page($this->single())
            ->call('setResult', $theirSelection->id, 'won')
            ->assertNotFound();

        $this->assertNull($theirSelection->refresh()->result);
        $this->assertModelExists($theirBet);
    }

    /**
     * Livewire forwards assertSeeInOrder() after a call to the raw JSON response, so this
     * checks the rendered HTML instead.
     *
     * @param  list<string>  $values
     */
    private function assertSeeInOrder(Testable $page, array $values): Testable
    {
        $this->assertThat(array_map(e(...), $values), new SeeInOrder($page->html()));

        return $page;
    }

    private function page(Bet $bet): Testable
    {
        $this->actingAs($this->user);

        return Livewire::test(Show::class, ['bet' => $bet]);
    }

    private function single(string $odds = '2.10', int $stake = 10000, ?string $closingOdds = null, ?SelectionResult $result = null): Bet
    {
        return Bet::factory()
            ->forBookmaker($this->bookmaker)
            ->has(Selection::factory()->state([
                'position' => 1,
                'odds' => $odds,
                'closing_odds' => $closingOdds,
                'result' => $result,
            ]), 'selections')
            ->create(['stake' => $stake]);
    }
}
