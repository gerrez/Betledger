<?php

namespace Tests\Feature\Statistics;

use App\Domain\Bankroll\BankrollQuery;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Statistics\Period;
use App\Domain\Statistics\ProfitPoint;
use App\Domain\Statistics\StatisticsCalculator;
use App\Domain\Statistics\StatisticsFilter;
use App\Domain\Statistics\StatisticsQuery;
use App\Livewire\Overview\Index as Overview;
use App\Livewire\Statistics\Index as Statistics;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Statistics and Home count a settled bet on its event date (`event_at`), not on the
 * day its result was recorded (`settled_at`); the bankroll counts it once it is settled.
 */
class StatisticsByEventDateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bookmaker $unibet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-05 10:00:00');

        $this->user = User::factory()->create();
        $this->unibet = Bookmaker::factory()->for($this->user)->create(['name' => 'Unibet']);
    }

    public function test_a_bet_settled_after_its_event_counts_in_the_events_month_on_the_statistics_page(): void
    {
        $this->seedBets();
        $this->actingAs($this->user);

        Livewire::test(Statistics::class)
            ->assertSee('Settled bets count on the date of their event.')
            ->call('useRange', 'custom')
            ->set('from', '2026-10-01')
            ->set('to', '2026-10-31')
            ->assertSee(['1 settled bet', '+10.00'])
            ->set('from', '2026-11-01')
            ->set('to', '2026-11-05')
            ->assertSee(['1 settled bet', '−5.00'])
            ->assertDontSee('+10.00');
    }

    public function test_a_bet_settled_after_its_event_counts_in_the_events_month_on_home(): void
    {
        $this->seedBets();
        $this->actingAs($this->user);

        Livewire::test(Overview::class)
            ->assertSeeInOrder(['November 2026', 'October 2026'])
            ->assertSee(['Profit this month', '1 bet settled · turnover €5.00'])
            ->assertDontSee('+10.00')
            ->set('month', '2026-10')
            ->assertSee(['Profit in October 2026', '+10.00', '1 bet settled · turnover €10.00']);
    }

    public function test_profit_over_time_puts_a_bet_on_its_event_day(): void
    {
        $this->seedBets();
        $bets = app(StatisticsQuery::class)->bets($this->user, new StatisticsFilter);

        $points = app(StatisticsCalculator::class)->profitOverTime($bets, CarbonImmutable::parse('2026-10-30'), CarbonImmutable::parse('2026-11-05'), Period::Day)->points;
        $profitByDay = array_combine(
            array_map(fn (ProfitPoint $point): string => $point->start->toDateString(), $points),
            array_map(fn (ProfitPoint $point): int => $point->profit, $points),
        );

        $this->assertSame(1000, $profitByDay['2026-10-31']);
        $this->assertSame(-500, $profitByDay['2026-11-02']);
        $this->assertSame(0, $profitByDay['2026-11-03']);
    }

    public function test_the_bankroll_counts_a_bet_once_it_is_settled_whatever_its_event_date(): void
    {
        $this->seedBets();
        // Settled today, although its event is next month (e.g. a bet settled early).
        $this->bet(stake: 2000, placedAt: '2026-11-04 12:00', startsAt: '2026-12-06 15:00', result: SelectionResult::Won, settledAt: '2026-11-05 09:00');

        $bankroll = app(BankrollQuery::class)->bankroll($this->user);

        $this->assertSame(3, $bankroll->total->bets);
        $this->assertSame(2500, $bankroll->total->profit); // +10.00 − 5.00 + 20.00
        $this->assertSame(-500, app(StatisticsCalculator::class)->totals(app(StatisticsQuery::class)->bets(
            $this->user,
            new StatisticsFilter(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-05')),
        ))->profit);
    }

    public function test_another_users_bets_never_count_by_event_date(): void
    {
        $this->seedBets();
        $other = User::factory()->create();
        $othersBookmaker = Bookmaker::factory()->for($other)->create(['name' => 'Someone Elses Bookie']);
        $othersBet = Bet::factory()->forBookmaker($othersBookmaker)->single('5.00')->settled()->create(['stake' => 99900, 'placed_at' => '2026-10-10 12:00']);
        $othersBet->selections()->update(['event_starts_at' => '2026-10-31 18:00']);
        $othersBet->syncEventAt()->save();
        $this->actingAs($this->user);

        Livewire::test(Overview::class)
            ->set('month', '2026-10')
            ->assertSee(['+10.00', '1 bet settled · turnover €10.00'])
            ->assertDontSee(['999.00', '3,996.00', 'Someone Elses Bookie']);

        Livewire::test(Statistics::class)
            ->call('useRange', 'custom')
            ->set('from', '2026-10-01')
            ->set('to', '2026-10-31')
            ->assertSee(['1 settled bet', '+10.00'])
            ->assertDontSee(['999.00', '3,996.00', 'Someone Elses Bookie']);
    }

    /**
     * A won €10.00 bet whose event was on 31 October but which was settled on 3 November,
     * and a lost €5.00 bet decided and settled on 2 November.
     */
    private function seedBets(): void
    {
        $this->bet(stake: 1000, placedAt: '2026-10-30 12:00', startsAt: '2026-10-31 18:00', result: SelectionResult::Won, settledAt: '2026-11-03 09:00');
        $this->bet(stake: 500, placedAt: '2026-11-02 12:00', startsAt: '2026-11-02 18:00', result: SelectionResult::Lost, settledAt: '2026-11-02 21:00');
    }

    private function bet(int $stake, string $placedAt, string $startsAt, SelectionResult $result, string $settledAt): Bet
    {
        $bet = Bet::factory()->forBookmaker($this->unibet)->single('2.00')->settled($result)->create(['stake' => $stake, 'placed_at' => $placedAt]);
        $bet->selections()->update(['event_starts_at' => $startsAt]);
        $bet->syncEventAt()->forceFill(['settled_at' => $settledAt])->save();

        return $bet;
    }
}
