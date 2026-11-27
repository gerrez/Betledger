<?php

namespace Tests\Feature\Statistics;

use App\Domain\Currency;
use App\Domain\Settlement\SelectionResult;
use App\Livewire\Statistics\Index;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Selection;
use App\Models\Sport;
use App\Models\Tipster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class StatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bookmaker $unibet;

    private Bookmaker $bet365;

    private Sport $football;

    private Sport $tennis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-21 15:00:00');

        $this->user = User::factory()->create(['base_currency' => Currency::Eur]);
        $this->unibet = Bookmaker::factory()->for($this->user)->create(['name' => 'Unibet']);
        $this->bet365 = Bookmaker::factory()->for($this->user)->inCurrency(Currency::Gbp, '1.17')->create(['name' => 'Bet365']);
        $this->football = Sport::factory()->for($this->user)->create(['name' => 'Football']);
        $this->tennis = Sport::factory()->for($this->user)->create(['name' => 'Tennis']);
    }

    public function test_without_bets_the_page_invites_the_user_to_record_one(): void
    {
        $this->actingAs($this->user);

        $this->get(route('statistics'))
            ->assertOk()
            ->assertSee('No statistics yet')
            ->assertSee(route('bets.create'))
            ->assertDontSee('Breakdown');
    }

    public function test_the_kpi_tiles_show_the_last_90_days_in_the_base_currency(): void
    {
        $this->seedBets();
        $this->actingAs($this->user);

        // Won 100.00 @ 2.10 (+110.00) and lost £50.00 = €58.50 at 1.17; the open bet stakes €20.00.
        $this->get(route('statistics'))
            ->assertOk()
            ->assertSee('All amounts in your base currency, EUR')
            ->assertSeeInOrder(['Profit', '+51.50', '€ · 2 settled bets'])
            ->assertSeeInOrder(['Turnover', '158.50', '€ · cash stakes only'])
            ->assertSeeInOrder(['ROI', '+32.5%', 'profit ÷ turnover'])
            // (1/2.10 + 1/2.00) ÷ 2 = 48.8 %; median of 2.00 and 2.10
            ->assertSeeInOrder(['Strike rate', '50.0%', 'needs 48.8% to break even'])
            ->assertSeeInOrder(['Average odds', '2.05', 'median 2.05'])
            ->assertSeeInOrder(['Open', '1', '€20.00 at stake']);
    }

    public function test_the_date_range_decides_which_bets_count(): void
    {
        $this->seedBets();
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSee('2 settled bets')
            ->call('useRange', 'all')
            ->assertSet('range', 'all')
            ->assertSee(['3 settled bets', '+71.50'])
            ->call('useRange', '30d')
            ->assertSee('2 settled bets')
            ->call('useRange', 'year')
            ->assertSee('3 settled bets')
            ->call('useRange', '30d')
            ->call('useRange', 'custom')
            ->assertSet('from', '2026-09-22')
            ->assertSet('to', '2026-10-21')
            ->set('from', '2026-10-11')
            ->assertSee(['1 settled bet', '−58.50'])
            ->set('to', '2026-05-01')
            ->set('from', '2026-10-12')
            ->assertSee(['3 settled bets', '+71.50'])
            ->set('from', 'nonsense')
            ->set('to', '2026-04-30')
            ->assertSee('No bets in this date range');
    }

    public function test_filters_and_the_free_bets_toggle(): void
    {
        $this->seedBets();
        Bet::factory()->forBookmaker($this->unibet)->single('3.00')->freeBet()->settled()->create(['stake' => 1000, 'placed_at' => '2026-10-15 12:00']);
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSee(['+71.50', '3 settled bets', '+45.1%'])
            ->call('toggleFreeBets')
            ->assertSet('freeBets', 'exclude')
            ->assertSee(['+51.50', '2 settled bets', '+32.5%'])
            ->call('toggleFreeBets')
            ->set('bookmaker', (string) $this->bet365->id)
            ->assertSee(['−58.50', '1 settled bet', 'Clear filters'])
            ->set('sport', (string) $this->football->id)
            ->assertSee('No bets match these filters')
            ->call('clearFilters')
            ->assertSet('bookmaker', '')
            ->assertSet('sport', '')
            ->assertSee('3 settled bets')
            ->set('type', 'accumulator')
            ->assertSee('No bets match these filters');
    }

    public function test_the_breakdown_splits_the_bets_by_the_chosen_dimension(): void
    {
        $this->seedBets();
        $accumulator = Bet::factory()->forBookmaker($this->unibet)->accumulator(['1.50', '2.00'])->settled()->create(['stake' => 1000, 'placed_at' => '2026-10-16 12:00']);
        $accumulator->selections()->update(['sport_id' => $this->football->id]);
        $accumulator->selections()->where('position', 2)->update(['sport_id' => $this->tennis->id]);
        $tipster = Tipster::factory()->for($this->user)->create(['name' => 'Own model']);
        $accumulator->update(['tipster_id' => $tipster->id]);
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSet('dimension', 'sport')
            ->assertSeeInOrder(['Sport', 'Football', '2', '+130.00', 'Tennis', '2', '−38.50'])
            // Football: won @ 2.10 and the won accumulator @ 3.00; Tennis: lost @ 2.00 and the accumulator
            ->assertSeeInOrder(['Strike', 'Break-even', 'Football', '100.0%', '40.5%', 'Tennis', '50.0%', '41.7%'])
            ->assertSee('Accumulators count under every value their selections include')
            ->set('dimension', 'bookmaker')
            ->assertSeeInOrder(['Bookmaker', 'Unibet', '2', '110.00', 'Bet365', '1', '58.50'])
            ->assertDontSee('Accumulators count under every value')
            ->set('dimension', 'tipster')
            ->assertSeeInOrder(['Own model', '+20.00', 'No tipster', '+51.50'])
            ->set('dimension', 'type')
            ->assertSeeInOrder(['Singles', '+51.50', 'Accumulators', '+20.00'])
            ->set('dimension', 'tag')
            ->assertSee(['No tags', 'Bets with several tags count under each of them'])
            ->set('dimension', 'nonsense')
            ->assertSee('Football');
    }

    public function test_the_breakdown_by_month_and_weekday(): void
    {
        $this->seedBets();
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSeeInOrder(['More', 'Month', 'Weekday'])
            ->set('dimension', 'month')
            ->assertSeeInOrder(['Month', 'Oct 2026', '2', '+1 open', '+51.50'])
            ->assertSee('Bets count on their event date');

        // Lost on Monday 12 October, won on Saturday 10 October, open on Tuesday 20 October.
        Livewire::withQueryParams(['by' => 'weekday'])->test(Index::class)
            ->assertSeeInOrder(['Weekday', 'Monday', '−58.50', 'Tuesday', '+1 open', 'Saturday', '+110.00']);
    }

    public function test_the_breakdown_sorts_by_a_column_kept_in_the_url(): void
    {
        $this->seedBets(); // Football +110.00, Tennis −58.50: one settled bet each
        $this->actingAs($this->user);
        $labels = fn (Testable $page): array => array_column($page->viewData('breakdown'), 'label');

        $page = Livewire::test(Index::class)
            ->assertSet('sort', '')
            ->assertDontSeeHtml(['aria-sort="descending"', 'aria-sort="ascending"'])
            ->call('sortBreakdown', 'profit')
            ->assertSet('sort', 'profit')
            ->assertSet('direction', 'desc')
            ->assertSeeHtml('aria-sort="descending"');
        $this->assertSame(['Football', 'Tennis'], $labels($page));

        $page->call('sortBreakdown', 'profit')
            ->assertSet('direction', 'asc')
            ->assertSeeHtml('aria-sort="ascending"');
        $this->assertSame(['Tennis', 'Football'], $labels($page));

        $page->set('dimension', 'bookmaker')
            ->assertSet('sort', 'profit')
            ->set('dimension', 'sport');
        $this->assertSame(['Tennis', 'Football'], $labels($page), 'The sort stays when the dimension changes.');

        $page->call('sortBreakdown', 'profit')
            ->assertSet('sort', '')
            ->assertSet('direction', 'desc')
            ->assertDontSeeHtml(['aria-sort="descending"', 'aria-sort="ascending"']);
        $this->assertSame(['Football', 'Tennis'], $labels($page), 'Back to most settled bets, then by name.');

        $page->call('sortBreakdown', 'roi')
            ->call('sortBreakdown', 'nonsense')
            ->assertSet('sort', 'roi')
            ->assertSet('direction', 'desc');

        $this->assertSame(['Tennis', 'Football'], $labels(Livewire::withQueryParams(['sort' => 'profit', 'dir' => 'asc'])->test(Index::class)));
        $this->assertSame(['Tennis', 'Football'], $labels(Livewire::withQueryParams(['sort' => 'roi', 'dir' => 'asc'])->test(Index::class)));
        $this->assertSame(['Football', 'Tennis'], $labels(Livewire::withQueryParams(['sort' => 'roi', 'dir' => 'nonsense'])->test(Index::class)));
        $this->assertSame(['Football', 'Tennis'], $labels(Livewire::withQueryParams(['sort' => 'nonsense', 'dir' => 'asc'])->test(Index::class)));
    }

    public function test_odds_ranges_and_closing_line_value(): void
    {
        $this->seedBets();
        $this->firstSelection(Bet::query()->where('stake', 10000)->firstOrFail())->update(['closing_odds' => '2.00']);
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSeeInOrder(['Odds ranges', '1.51–2.00', '1 bet', '−100.0%', '0.0%', 'needs 50.0%', '2.01–3.00', '1 bet', '+110.0%', '100.0%', 'needs 47.6%', '10.01+', '0 bets', '—', '—', 'Closing line value'])
            ->assertSeeInOrder(['Closing line value', '+5.0%', '100%'])
            ->assertSee('1 of 1 bet with closing odds beat the closing line. 1 settled bet has no closing odds.');
    }

    public function test_the_profit_chart_groups_by_day_week_or_month_with_the_range(): void
    {
        $this->seedBets();
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSeeInOrder(['Profit over time', 'Cumulative, by week of the event', 'Cumulative profit over 14 weeks, ending at +€51.50', '20 Jul', '19 Oct', 'Breakdown'])
            ->call('useRange', '30d')
            ->assertSee(['Cumulative, by day of the event', 'Cumulative profit over 30 days, ending at +€51.50', '22 Sep', '21 Oct'])
            ->call('useRange', 'year')
            ->assertSee(['Cumulative, by month of the event', 'Cumulative profit over 10 months, ending at +€71.50', 'Jan 2026', 'Oct 2026'])
            ->call('useRange', 'custom')
            ->set('from', '2026-10-13')
            ->assertSee('The chart shows once bets in this range are settled.')
            ->assertDontSee('Cumulative profit over');
    }

    public function test_the_risk_panel_follows_the_range_and_filters_and_links_to_the_biggest_win_and_loss(): void
    {
        ['won' => $won, 'lost' => $lost] = $this->seedBets();
        $this->actingAs($this->user);

        // Last 90 days: +110.00 on 10 Oct, then −58.50 on 12 Oct; stakes 100.00 and 58.50.
        Livewire::test(Index::class)
            ->assertSeeInOrder(['Risk', 'Max drawdown', '−58.50', '10 Oct – 12 Oct 2026'])
            ->assertSeeInOrder(['Current drawdown', '−58.50', 'Since the peak on 10 Oct 2026'])
            ->assertSeeInOrder(['Longest winning streak', '1', 'Longest losing streak', '1', 'Current streak', '1 loss'])
            ->assertSeeInOrder(['Biggest win', '+110.00', route('bets.show', $won), 'View bet · 10 Oct 2026'])
            ->assertSeeInOrder(['Biggest loss', '−58.50', route('bets.show', $lost), 'View bet · 12 Oct 2026'])
            ->assertSeeInOrder(['Average stake', '79.25', 'settled cash bets'])
            ->call('useRange', 'all')
            ->assertSeeInOrder(['Longest winning streak', '2'])
            ->set('bookmaker', (string) $this->unibet->id)
            ->assertSeeInOrder(['Max drawdown', '0.00', 'Never below an earlier peak'])
            ->assertSeeInOrder(['Biggest loss', 'No losing bets']);
    }

    public function test_the_risk_panel_waits_for_settled_bets(): void
    {
        Bet::factory()->forBookmaker($this->unibet)->single('1.80')->create(['stake' => 2000, 'placed_at' => '2026-10-20 12:00']);
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSee('Risk figures show once bets in this range are settled.')
            ->assertDontSee('Max drawdown');
    }

    public function test_users_only_see_their_own_bets_and_lists(): void
    {
        ['won' => $ownWin, 'lost' => $ownLoss] = $this->seedBets();
        $other = User::factory()->create();
        $othersBookmaker = Bookmaker::factory()->for($other)->create(['name' => 'Someone Elses Bookie']);
        Bet::factory()->forBookmaker($othersBookmaker)->single('5.00')->settled()->create(['stake' => 99900, 'placed_at' => '2026-10-15 12:00']);
        Sport::factory()->for($other)->create(['name' => 'Curling']);

        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSee(['+51.50', '2 settled bets'])
            ->assertDontSee(['Someone Elses Bookie', 'Curling', '999.00'])
            ->set('bookmaker', (string) $othersBookmaker->id)
            ->assertSee('No bets match these filters');

        $this->actingAs($other);

        Livewire::test(Index::class)
            ->assertSee(['+3,996.00', '1 settled bet'])
            ->assertDontSee(['Unibet', 'Football', route('bets.show', $ownWin), route('bets.show', $ownLoss)]);
    }

    /**
     * A won single at Unibet on football and a lost one at Bet365 (GBP) on tennis, both
     * settled this month; a won single settled in May; and an open bet placed yesterday.
     *
     * @return array{won: Bet, lost: Bet}
     */
    private function seedBets(): array
    {
        $won = Bet::factory()->forBookmaker($this->unibet)->single('2.10')->settled()->create(['stake' => 10000, 'placed_at' => '2026-10-10 12:00']);
        $this->firstSelection($won)->update(['sport_id' => $this->football->id]);

        $lost = Bet::factory()->forBookmaker($this->bet365)->single('2.00')->settled(SelectionResult::Lost)->create(['stake' => 5000, 'placed_at' => '2026-10-12 12:00']);
        $this->firstSelection($lost)->update(['sport_id' => $this->tennis->id]);

        $old = Bet::factory()->forBookmaker($this->unibet)->single('3.00')->settled()->create(['stake' => 1000, 'placed_at' => '2026-05-01 12:00']);
        $this->firstSelection($old)->update(['sport_id' => $this->football->id]);

        $open = Bet::factory()->forBookmaker($this->unibet)->single('1.80')->create(['stake' => 2000, 'placed_at' => '2026-10-20 12:00']);
        $this->firstSelection($open)->update(['sport_id' => $this->football->id]);

        return ['won' => $won, 'lost' => $lost];
    }

    private function firstSelection(Bet $bet): Selection
    {
        return $bet->selections()->orderBy('position')->firstOrFail();
    }
}
