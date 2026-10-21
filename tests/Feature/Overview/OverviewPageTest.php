<?php

namespace Tests\Feature\Overview;

use App\Domain\Currency;
use App\Domain\Settlement\SelectionResult;
use App\Livewire\Overview\Index;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Selection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OverviewPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bookmaker $unibet;

    private Bookmaker $bet365;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-21 15:00:00');

        $this->user = User::factory()->create(['base_currency' => Currency::Eur]);
        $this->unibet = Bookmaker::factory()->for($this->user)->create(['name' => 'Unibet']);
        $this->bet365 = Bookmaker::factory()->for($this->user)->inCurrency(Currency::Gbp, '1.17')->create(['name' => 'Bet365']);
    }

    public function test_without_bets_the_page_invites_the_user_to_record_one(): void
    {
        $this->actingAs($this->user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(['Overview', 'No bets yet', route('bets.create')])
            ->assertDontSee(['Profit this month', 'Open bets']);
    }

    public function test_the_month_card_and_kpi_tiles_show_this_months_settled_bets_in_the_base_currency(): void
    {
        $this->seedBets();
        $this->actingAs($this->user);

        // Won 100.00 @ 2.10 (+110.00) and lost £50.00 = €58.50 at 1.17. Open cash stakes:
        // €20.00, £10.00 = €11.70 and €30.00; the free bet's stake isn't at risk.
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['October 2026', 'September 2026'])
            ->assertDontSee('August 2026')
            ->assertSeeInOrder(['Profit this month', 'ROI +32.5%', '+51.50', '€'])
            ->assertSee('Cumulative profit over 21 days, ending at +€51.50')
            ->assertSee('2 bets settled · turnover €158.50')
            ->assertSeeInOrder(['Strike rate', '50.0%', '1 of 2 decided'])
            ->assertSeeInOrder(['Average odds', '2.05', 'settled bets'])
            ->assertSeeInOrder(['Closing line', '+5.0%', 'CLV on 1 bet'])
            ->assertSeeInOrder(['Open stake', '€61.70', '4 bets pending']);
    }

    public function test_another_month_can_be_chosen_while_open_bets_stay_the_ones_open_now(): void
    {
        $this->seedBets();
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->set('month', '2026-09')
            ->assertSeeInOrder(['Profit in September 2026', 'ROI +200.0%', '+20.00'])
            ->assertSee(['Cumulative profit over 30 days, ending at +€20.00', '1 bet settled · turnover €10.00', '1 of 1 decided', 'CLV on 0 bets'])
            ->assertSeeInOrder(['Open stake', '61.70', '4 bets pending', 'Open bets', '4'])
            ->set('month', '2026-08')
            ->assertSee(['Profit in August 2026', '0.00', 'No bets settled in August 2026', '0 of 0 decided'])
            ->assertDontSee('ROI')
            ->set('month', '2026-11')
            ->assertSee(['Profit this month', '+51.50'])
            ->set('month', 'nonsense')
            ->assertSee(['Profit this month', '+51.50']);
    }

    public function test_the_month_without_settled_bets_says_so(): void
    {
        Bet::factory()->forBookmaker($this->unibet)->single('1.80')->create(['stake' => 2000, 'placed_at' => '2026-10-20 12:00']);
        $this->actingAs($this->user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(['Profit this month', 'No bets settled this month yet', 'Open bets', '1 bet pending'])
            ->assertDontSee(['ROI', 'Cumulative profit over']);
    }

    public function test_the_open_bets_preview_shows_the_bets_that_start_soonest(): void
    {
        $this->seedBets();
        $this->actingAs($this->user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Open bets', '· 4', 'Settle →',
                'Arsenal v Chelsea', 'Arsenal · Unibet · tomorrow 18:30', '€20.00', '@ 1.80',
                '3-fold accumulator', '1 of 3 won · Bet365', '£10.00', '@ 5.40',
                'Liverpool v Everton', 'Liverpool · Unibet · Sun 18:00 · free bet', '€5.00', '@ 3.00',
                'All 4 open bets',
            ])
            ->assertDontSee('Outright winner')
            ->assertSee(route('bets.index'));
    }

    public function test_an_accumulators_progress_counts_its_selections_with_a_result(): void
    {
        $open = Bet::factory()->forBookmaker($this->unibet)->accumulator(['1.50', '2.00'])->create(['placed_at' => '2026-10-20 12:00']);
        $partly = Bet::factory()->forBookmaker($this->unibet)->accumulator(['1.50', '2.00', '1.80'])->withResults(SelectionResult::Won, SelectionResult::Void)->create(['placed_at' => '2026-10-20 12:00']);
        $this->actingAs($this->user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(['2-fold accumulator', '2 of 3 settled · Unibet', route('bets.show', $open), route('bets.show', $partly)])
            ->assertDontSee('0 of 2');
    }

    public function test_users_only_see_their_own_bets(): void
    {
        $this->seedBets();
        $other = User::factory()->create();
        $othersBookmaker = Bookmaker::factory()->for($other)->create(['name' => 'Someone Elses Bookie']);
        Bet::factory()->forBookmaker($othersBookmaker)->single('5.00')->settled()->create(['stake' => 99900, 'placed_at' => '2026-10-15 12:00']);
        $othersOpen = Bet::factory()->forBookmaker($othersBookmaker)->single('1.50')->create(['stake' => 77700, 'placed_at' => '2026-10-15 12:00']);
        $this->firstSelection($othersOpen)->update(['event_name' => 'Someone Elses Match', 'event_starts_at' => '2026-10-21 16:00']);

        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSee(['+51.50', '2 bets settled', '€61.70', '4 bets pending'])
            ->assertDontSee(['Someone Elses Bookie', 'Someone Elses Match', '777.00', '3,996.00']);

        $this->actingAs($other);

        Livewire::test(Index::class)
            ->assertSee(['+3,996.00', '1 bet settled', 'Someone Elses Match', '1 bet pending'])
            ->assertDontSee(['Unibet', 'Arsenal v Chelsea', 'September 2026']);
    }

    /**
     * This month: a won single at Unibet with closing odds and a lost one at Bet365 (GBP).
     * In September: a won single. Open: a single tomorrow, an accumulator with its first
     * selection won, a free bet on Sunday and an outright without a start time.
     */
    private function seedBets(): void
    {
        $won = Bet::factory()->forBookmaker($this->unibet)->single('2.10')->settled()->create(['stake' => 10000, 'placed_at' => '2026-10-10 12:00']);
        $this->firstSelection($won)->update(['closing_odds' => '2.00']);
        Bet::factory()->forBookmaker($this->bet365)->single('2.00')->settled(SelectionResult::Lost)->create(['stake' => 5000, 'placed_at' => '2026-10-12 12:00']);
        Bet::factory()->forBookmaker($this->unibet)->single('3.00')->settled()->create(['stake' => 1000, 'placed_at' => '2026-09-15 12:00']);

        $single = Bet::factory()->forBookmaker($this->unibet)->single('1.80')->create(['stake' => 2000, 'placed_at' => '2026-10-20 12:00']);
        $this->firstSelection($single)->update(['event_name' => 'Arsenal v Chelsea', 'pick' => 'Arsenal', 'event_starts_at' => '2026-10-22 18:30']);

        $accumulator = Bet::factory()->forBookmaker($this->bet365)->accumulator(['1.50', '2.00', '1.80'])->withResults(SelectionResult::Won)->create(['stake' => 1000, 'placed_at' => '2026-10-19 12:00']);
        $accumulator->selections()->update(['event_starts_at' => '2026-10-23 20:00']);

        $free = Bet::factory()->forBookmaker($this->unibet)->single('3.00')->freeBet()->create(['stake' => 500, 'placed_at' => '2026-10-20 12:00']);
        $this->firstSelection($free)->update(['event_name' => 'Liverpool v Everton', 'pick' => 'Liverpool', 'event_starts_at' => '2026-10-25 18:00']);

        $outright = Bet::factory()->forBookmaker($this->unibet)->single('6.00')->create(['stake' => 3000, 'placed_at' => '2026-10-21 12:00']);
        $this->firstSelection($outright)->update(['event_name' => 'Outright winner', 'event_starts_at' => null]);
    }

    private function firstSelection(Bet $bet): Selection
    {
        return $bet->selections()->orderBy('position')->firstOrFail();
    }
}
