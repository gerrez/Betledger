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
            ->assertSeeInOrder(['Strike rate', '50.0%', 'pushes excluded'])
            ->assertSeeInOrder(['Average odds', '2.05', 'settled bets'])
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

    public function test_odds_ranges_and_closing_line_value(): void
    {
        $this->seedBets();
        $this->firstSelection(Bet::query()->where('stake', 10000)->firstOrFail())->update(['closing_odds' => '2.00']);
        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->assertSeeInOrder(['Odds ranges', '1.51–2.00', '1 bet', '−100.0%', '2.01–3.00', '1 bet', '+110.0%', '10.01+', '0 bets', '—'])
            ->assertSeeInOrder(['Closing line value', '+5.0%', '100%'])
            ->assertSee('1 of 1 bet with closing odds beat the closing line. 1 settled bet has no closing odds.');
    }

    public function test_users_only_see_their_own_bets_and_lists(): void
    {
        $this->seedBets();
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
            ->assertDontSee(['Unibet', 'Football']);
    }

    /**
     * A won single at Unibet on football and a lost one at Bet365 (GBP) on tennis, both
     * settled this month; a won single settled in May; and an open bet placed yesterday.
     */
    private function seedBets(): void
    {
        $won = Bet::factory()->forBookmaker($this->unibet)->single('2.10')->settled()->create(['stake' => 10000, 'placed_at' => '2026-10-10 12:00']);
        $this->firstSelection($won)->update(['sport_id' => $this->football->id]);

        $lost = Bet::factory()->forBookmaker($this->bet365)->single('2.00')->settled(SelectionResult::Lost)->create(['stake' => 5000, 'placed_at' => '2026-10-12 12:00']);
        $this->firstSelection($lost)->update(['sport_id' => $this->tennis->id]);

        $old = Bet::factory()->forBookmaker($this->unibet)->single('3.00')->settled()->create(['stake' => 1000, 'placed_at' => '2026-05-01 12:00']);
        $this->firstSelection($old)->update(['sport_id' => $this->football->id]);

        $open = Bet::factory()->forBookmaker($this->unibet)->single('1.80')->create(['stake' => 2000, 'placed_at' => '2026-10-20 12:00']);
        $this->firstSelection($open)->update(['sport_id' => $this->football->id]);
    }

    private function firstSelection(Bet $bet): Selection
    {
        return $bet->selections()->orderBy('position')->firstOrFail();
    }
}
