<?php

namespace Tests\Feature\Statistics;

use App\Domain\BetStatus;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Statistics days and months are the user's own: a statistics range, profit-over-time
 * buckets and the Home month all follow the user's time zone (Europe/Copenhagen here:
 * +01:00 in winter, +02:00 in summer), while the stored instants stay UTC.
 */
class StatisticsInUserZoneTest extends TestCase
{
    use RefreshDatabase;

    private User $copenhagen;

    private User $utc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->copenhagen = User::factory()->create(['timezone' => 'Europe/Copenhagen']);
        $this->utc = User::factory()->create(['timezone' => 'UTC']);
    }

    public function test_profit_over_time_buckets_by_the_users_local_day(): void
    {
        foreach ([$this->copenhagen, $this->utc] as $user) {
            $this->settledBet($user, profitStake: 1000, eventAt: '2026-11-22 23:30:00');
        }

        $this->assertSame(['2026-11-23' => 1000], $this->profitByPeriod($this->copenhagen, Period::Day));
        $this->assertSame(['2026-11-22' => 1000], $this->profitByPeriod($this->utc, Period::Day));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function monthEdges(): array
    {
        return [
            // Winter (+01:00): only from 23:00 UTC is it the next local day.
            'last evening of November' => ['2026-11-30 22:30:00', '2026-11-01'],
            'first minutes of December' => ['2026-11-30 23:30:00', '2026-12-01'],
            // Summer (+02:00): from 22:00 UTC.
            'first minutes of September' => ['2026-08-31 22:30:00', '2026-09-01'],
            // The month in which the clocks go back, and the one after it.
            'last minutes of October, after the change' => ['2026-10-31 22:59:00', '2026-10-01'],
            'first minutes of November' => ['2026-10-31 23:00:00', '2026-11-01'],
            // The month in which the clocks go forward, and the one after it.
            'last minutes of March, after the change' => ['2026-03-31 21:59:00', '2026-03-01'],
            'first minutes of April' => ['2026-03-31 22:00:00', '2026-04-01'],
        ];
    }

    #[DataProvider('monthEdges')]
    public function test_profit_over_time_buckets_by_the_users_local_month(string $eventAt, string $month): void
    {
        $this->settledBet($this->copenhagen, profitStake: 1000, eventAt: $eventAt);

        $this->assertSame([$month => 1000], $this->profitByPeriod($this->copenhagen, Period::Month));
    }

    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public static function rangeEdges(): array
    {
        return [
            // From 2026-11-22 to 2026-11-23 in Copenhagen: 2026-11-21 23:00 UTC up to 2026-11-23 23:00 UTC.
            'one minute before the first day' => ['2026-11-22', '2026-11-23', '2026-11-21 22:59:00', false],
            'local midnight starting the first day' => ['2026-11-22', '2026-11-23', '2026-11-21 23:00:00', true],
            'local 23:59 on the last day' => ['2026-11-22', '2026-11-23', '2026-11-23 22:59:00', true],
            'local midnight after the last day' => ['2026-11-22', '2026-11-23', '2026-11-23 23:00:00', false],
            // The 25-hour day on which the clocks go back: 2026-10-24 22:00 UTC up to 2026-10-25 23:00 UTC.
            'before a 25-hour first day' => ['2026-10-25', '2026-10-25', '2026-10-24 21:59:00', false],
            'start of a 25-hour first day' => ['2026-10-25', '2026-10-25', '2026-10-24 22:00:00', true],
            'end of a 25-hour last day' => ['2026-10-25', '2026-10-25', '2026-10-25 22:59:00', true],
            'after a 25-hour last day' => ['2026-10-25', '2026-10-25', '2026-10-25 23:00:00', false],
            // The 23-hour day on which the clocks go forward: 2026-03-28 23:00 UTC up to 2026-03-29 22:00 UTC.
            'start of a 23-hour day' => ['2026-03-29', '2026-03-29', '2026-03-28 23:00:00', true],
            'end of a 23-hour day' => ['2026-03-29', '2026-03-29', '2026-03-29 21:59:00', true],
            'after a 23-hour day' => ['2026-03-29', '2026-03-29', '2026-03-29 22:00:00', false],
        ];
    }

    #[DataProvider('rangeEdges')]
    public function test_a_date_range_covers_whole_local_days(string $from, string $to, string $instant, bool $included): void
    {
        $this->settledBet($this->copenhagen, profitStake: 1000, eventAt: $instant);
        $this->openBet($this->copenhagen, placedAt: $instant);
        $filter = new StatisticsFilter(CarbonImmutable::parse($from), CarbonImmutable::parse($to));

        $bets = app(StatisticsQuery::class)->bets($this->copenhagen, $filter);

        $this->assertCount($included ? 2 : 0, $bets, 'settled by event date and open by placed date');
    }

    public function test_two_users_get_their_own_days_for_the_same_range(): void
    {
        $this->settledBet($this->copenhagen, profitStake: 1000, eventAt: '2026-11-22 23:30:00');
        $this->settledBet($this->utc, profitStake: 1000, eventAt: '2026-11-22 23:30:00');
        $filter = new StatisticsFilter(CarbonImmutable::parse('2026-11-23'), CarbonImmutable::parse('2026-11-23'), status: BetStatus::Settled);

        $this->assertCount(1, app(StatisticsQuery::class)->bets($this->copenhagen, $filter));
        $this->assertCount(0, app(StatisticsQuery::class)->bets($this->utc, $filter));
    }

    public function test_the_statistics_page_ranges_end_on_the_users_today(): void
    {
        $this->travelTo('2026-11-22 23:30:00'); // already 23 November in Copenhagen
        // 23:00 on 24 October in Copenhagen: one day before "30 days" there (25 Oct – 23 Nov),
        // but the first day of "30 days" in UTC (24 Oct – 22 Nov).
        $this->settledBet($this->copenhagen, profitStake: 1000, eventAt: '2026-10-24 21:00:00');
        $this->settledBet($this->utc, profitStake: 1000, eventAt: '2026-10-24 21:00:00');

        $this->actingAs($this->copenhagen);
        Livewire::test(Statistics::class)
            ->call('useRange', '30d')
            ->assertDontSee('+10.00')
            ->call('useRange', 'custom')
            ->assertSet('from', '2026-10-25')
            ->assertSet('to', '2026-11-23');

        $this->actingAs($this->utc);
        Livewire::test(Statistics::class)
            ->call('useRange', '30d')
            ->assertSee(['1 settled bet', '+10.00'])
            ->call('useRange', 'custom')
            ->assertSet('from', '2026-10-24')
            ->assertSet('to', '2026-11-22');
    }

    public function test_home_shows_the_users_local_month(): void
    {
        $this->travelTo('2026-12-05 10:00:00');
        // 00:30 on 1 December in Copenhagen, 23:30 on 30 November in UTC.
        $this->settledBet($this->copenhagen, profitStake: 1000, eventAt: '2026-11-30 23:30:00', placedAt: '2026-11-30 23:10:00');
        $this->settledBet($this->utc, profitStake: 1000, eventAt: '2026-11-30 23:30:00', placedAt: '2026-11-30 23:10:00');

        $this->actingAs($this->copenhagen);
        Livewire::test(Overview::class)
            ->assertSee(['Profit this month', '+10.00', '1 bet settled'])
            ->assertSee('December 2026')
            ->assertDontSee('November 2026');

        $this->actingAs($this->utc);
        Livewire::test(Overview::class)
            ->assertSee('No bets settled this month yet')
            ->assertSeeInOrder(['December 2026', 'November 2026'])
            ->set('month', '2026-11')
            ->assertSee(['Profit in November 2026', '+10.00']);
    }

    public function test_home_uses_the_users_current_month(): void
    {
        $this->travelTo('2026-11-30 23:30:00'); // already December in Copenhagen
        $this->settledBet($this->copenhagen, profitStake: 1000, eventAt: '2026-11-30 12:00:00');
        $this->actingAs($this->copenhagen);

        Livewire::test(Overview::class)
            ->assertSee(['No bets settled this month yet'])
            ->assertSeeInOrder(['December 2026', 'November 2026']);
    }

    /**
     * Profit per period start, leaving out empty periods.
     *
     * @return array<string, int>
     */
    private function profitByPeriod(User $user, Period $period): array
    {
        $points = app(StatisticsCalculator::class)->profitOverTime(app(StatisticsQuery::class)->bets($user, new StatisticsFilter), period: $period)->points;
        $profits = [];

        foreach ($points as $point) {
            if ($point->profit !== 0) {
                $profits[$point->start->toDateString()] = $point->profit;
            }
        }

        $this->assertContainsOnlyInstancesOf(ProfitPoint::class, $points);

        return $profits;
    }

    /**
     * A won bet at odds 2.00, so its profit equals its stake, decided at the instant (UTC).
     */
    private function settledBet(User $user, int $profitStake, string $eventAt, ?string $placedAt = null): Bet
    {
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $bet = Bet::factory()->forBookmaker($bookmaker)->single('2.00')->settled(SelectionResult::Won)->create([
            'stake' => $profitStake,
            'placed_at' => $placedAt ?? CarbonImmutable::parse($eventAt, 'UTC')->subDay(),
        ]);
        $bet->selections()->update(['event_starts_at' => $eventAt]);
        $bet->syncEventAt()->save();

        return $bet;
    }

    private function openBet(User $user, string $placedAt): Bet
    {
        $bookmaker = Bookmaker::factory()->for($user)->create();
        $bet = Bet::factory()->forBookmaker($bookmaker)->single()->create(['placed_at' => $placedAt]);
        $bet->syncEventAt()->save();

        return $bet;
    }
}
