<?php

namespace Tests\Feature\Bankroll;

use App\Domain\Bankroll\Bankroll;
use App\Domain\Currency;
use App\Domain\Money;
use App\Domain\Percent;
use App\Domain\Settlement\SelectionResult;
use App\Livewire\Bankroll\Index;
use App\Livewire\Statistics\Index as StatisticsIndex;
use App\Livewire\Statistics\TotalsDisplay;
use App\Models\BankrollTransaction;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BankrollPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Base EUR.
     * - Bet365 (GBP, 1.17): deposit £50, lost £10 → balance £40.00, turnover £10.00,
     *   profit −£10.00, ROI −100.0 %.
     * - Unibet (EUR): deposit €100, withdrawal €30, won €20 @ 2.10 (+€22), open €10 →
     *   balance €82.00, 2 bets · 1 open, turnover €20.00, profit +€22.00, ROI +110.0 %.
     * Total: balance 82.00 + 40 × 1.17 = €128.80; turnover 20 + 11.70 = €31.70; profit
     * 22 − 11.70 = +€10.30; ROI 10.30 / 31.70 = +32.5 %; open stake €10.00.
     */
    public function test_it_shows_each_bookmaker_and_the_total(): void
    {
        $user = $this->knownDataSet();

        $this->actingAs($user)
            ->get(route('bankroll.index'))
            ->assertOk()
            ->assertSee('<title>Bankroll · '.config('app.name').'</title>', escape: false)
            ->assertSeeInOrder([
                'Total balance · EUR', '€128.80',
                'Bets', '3 · 1 open', 'In open bets', '€10.00', 'Turnover', '€31.70', 'ROI', '+32.5%', 'Profit', '+€10.30',
                'Bet365', 'GBP', '£40.00',
                'Bets', '1', 'In open bets', '£0.00', 'Turnover', '£10.00', 'ROI', Money::MINUS.'100.0%', 'Profit', Money::MINUS.'£10.00',
                'Deposited £50.00 · Withdrawn £0.00',
                'Unibet', 'EUR', '€82.00',
                'Bets', '2 · 1 open', 'In open bets', '€10.00', 'Turnover', '€20.00', 'ROI', '+110.0%', 'Profit', '+€22.00',
                'Deposited €100.00 · Withdrawn €30.00',
            ]);
    }

    public function test_the_totals_equal_the_statistics_pages_all_time_figures_with_free_bets(): void
    {
        $user = $this->knownDataSet();
        $bet365 = $user->bookmakers()->where('name', 'Bet365')->sole();
        Bet::factory()->forBookmaker($bet365)->freeBet()->single('3.00')->settled(SelectionResult::Won)->create(['stake' => 1000]);
        // A bet copied an older rate; the total uses each bet's own rate, as statistics do.
        Bet::factory()->forBookmaker($bet365)->single('1.80')->settled(SelectionResult::HalfWon)->create(['stake' => 2500, 'exchange_rate' => '1.15']);
        $this->actingAs($user);

        $statistics = Livewire::withQueryParams(['range' => 'all', 'free' => 'include'])->test(StatisticsIndex::class)->viewData('totals');
        $bankroll = Livewire::test(Index::class)->viewData('bankroll');

        $this->assertInstanceOf(TotalsDisplay::class, $statistics);
        $this->assertInstanceOf(Bankroll::class, $bankroll);
        $this->assertNotNull($bankroll->total->roi);
        $this->assertSame($statistics->turnover, Money::formatWithoutSymbol($bankroll->total->turnover));
        $this->assertSame($statistics->profit, Money::formatWithoutSymbol($bankroll->total->profit, signed: true));
        $this->assertSame($statistics->roi, Percent::format($bankroll->total->roi, signed: true));
        $this->assertSame($statistics->openStake, Money::format($bankroll->total->openStake, Currency::Eur));
    }

    public function test_without_bookmakers_it_links_to_the_bookmakers_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('bankroll.index'))
            ->assertOk()
            ->assertSee('No bookmakers yet')
            ->assertSee(route('bookmakers.index'));
    }

    public function test_bookmakers_without_activity_show_zeros_and_a_hint(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create(['name' => 'Unibet']);

        $this->actingAs($user)
            ->get(route('bankroll.index'))
            ->assertOk()
            ->assertSee('Add your first deposit')
            ->assertSeeInOrder(['Unibet', '€0.00', 'Bets', '0', 'ROI', TotalsDisplay::NONE, 'Deposited €0.00 · Withdrawn €0.00', 'Add deposit', 'Add withdrawal']);
    }

    public function test_inactive_bookmakers_are_shown_only_with_activity(): void
    {
        $user = User::factory()->create();
        Bookmaker::factory()->for($user)->create(['name' => 'Active Book']);
        Bookmaker::factory()->for($user)->inactive()->create(['name' => 'Quiet Old Book']);
        $withBets = Bookmaker::factory()->for($user)->inactive()->create(['name' => 'Betting Old Book']);
        Bet::factory()->forBookmaker($withBets)->single()->create();
        $withTransactions = Bookmaker::factory()->for($user)->inactive()->create(['name' => 'Funded Old Book']);
        BankrollTransaction::factory()->forBookmaker($withTransactions)->deposit()->create();

        $this->actingAs($user)
            ->get(route('bankroll.index'))
            ->assertOk()
            ->assertSeeInOrder(['Active Book', 'Add deposit', 'Betting Old Book', '(inactive)', 'Funded Old Book', '(inactive)'])
            ->assertDontSee('Quiet Old Book');
    }

    public function test_a_bookmaker_with_bets_in_two_currencies_is_shown_in_the_base_currency(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->inCurrency(Currency::Gbp, '1.20')->create(['name' => 'Bet365']);
        Bet::factory()->forBookmaker($bookmaker)->single()->create(['stake' => 1000]);
        Bet::factory()->forBookmaker($bookmaker)->single()->create(['stake' => 2000, 'currency' => Currency::Eur, 'exchange_rate' => '1']);

        // Open £10 at 1.20 (€12) and €20: balance −€32.00.
        $this->actingAs($user)
            ->get(route('bankroll.index'))
            ->assertOk()
            ->assertSeeInOrder(['Bet365', 'EUR', Money::MINUS.'€32.00', 'Bets in more than one currency, shown in EUR.']);
    }

    public function test_a_bookmakers_transactions_are_listed_newest_first_when_expanded(): void
    {
        $user = User::factory()->create();
        $bookmaker = Bookmaker::factory()->for($user)->create(['name' => 'Unibet']);
        BankrollTransaction::factory()->forBookmaker($bookmaker)->deposit(10000)->create(['occurred_on' => '2026-11-01', 'note' => 'First deposit']);
        BankrollTransaction::factory()->forBookmaker($bookmaker)->withdrawal(2550)->create(['occurred_on' => '2026-11-20', 'note' => 'Cashed out']);
        BankrollTransaction::factory()->forBookmaker($bookmaker)->deposit(500)->create(['occurred_on' => '2026-11-20', 'note' => 'Later the same day']);
        $this->actingAs($user);

        Livewire::test(Index::class)
            ->assertDontSee('First deposit')
            ->call('toggleTransactions', $bookmaker->id)
            ->assertSet('bookmaker', (string) $bookmaker->id)
            ->assertSeeInOrder(['Later the same day', 'Cashed out', 'First deposit'])
            ->call('toggleTransactions', $bookmaker->id)
            ->assertSet('bookmaker', '')
            ->assertDontSee('First deposit');

        $this->get(route('bankroll.index', ['bookmaker' => $bookmaker->id]))
            ->assertOk()
            ->assertSeeInOrder([
                '20 Nov 2026 · Later the same day', '+€5.00',
                '20 Nov 2026 · Cashed out', Money::MINUS.'€25.50',
                '1 Nov 2026 · First deposit', '+€100.00',
            ]);
    }

    private function knownDataSet(): User
    {
        $user = User::factory()->create();
        $unibet = Bookmaker::factory()->for($user)->create(['name' => 'Unibet']);
        $bet365 = Bookmaker::factory()->for($user)->inCurrency(Currency::Gbp, '1.17')->create(['name' => 'Bet365']);

        BankrollTransaction::factory()->forBookmaker($unibet)->deposit(10000)->create();
        BankrollTransaction::factory()->forBookmaker($unibet)->withdrawal(3000)->create();
        BankrollTransaction::factory()->forBookmaker($bet365)->deposit(5000)->create();

        Bet::factory()->forBookmaker($unibet)->single('2.10')->settled(SelectionResult::Won)->create(['stake' => 2000]);
        Bet::factory()->forBookmaker($unibet)->single('2.00')->create(['stake' => 1000]);
        Bet::factory()->forBookmaker($bet365)->single('2.50')->settled(SelectionResult::Lost)->create(['stake' => 1000]);

        return $user;
    }
}
