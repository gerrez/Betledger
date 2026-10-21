<?php

namespace App\Livewire\Overview;

use App\Domain\BetStatus;
use App\Domain\Money;
use App\Domain\Statistics\Period;
use App\Domain\Statistics\StatisticsCalculator;
use App\Domain\Statistics\StatisticsFilter;
use App\Domain\Statistics\StatisticsQuery;
use App\Domain\Statistics\Totals;
use App\Livewire\Bets\BetSort;
use App\Livewire\Statistics\ProfitChart;
use App\Livewire\Statistics\TotalsDisplay;
use App\Models\Bet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The home page: a month's profit with a sparkline, KPI tiles, and a preview of the open
 * bets. The month's figures cover the bets settled in it; open stake and open bets are
 * always the ones open now.
 */
#[Title('Overview')]
class Index extends Component
{
    /** The most open bets the preview shows. */
    public const PREVIEW_SIZE = 3;

    /**
     * The month shown (Y-m); empty, unknown or in the future means the current month.
     */
    #[Url]
    public string $month = '';

    public function render(StatisticsQuery $query, StatisticsCalculator $calculator): View
    {
        $user = $this->user();
        $currency = $user->base_currency;
        $now = CarbonImmutable::now();
        $month = $this->selectedMonth($now);
        $isCurrentMonth = $month->equalTo($now->startOfMonth());
        $lastDay = $isCurrentMonth ? $now->startOfDay() : $month->endOfMonth()->startOfDay();

        $settled = $query->bets($user, new StatisticsFilter($month, $lastDay, status: BetStatus::Settled));
        $monthTotals = $calculator->totals($settled);
        $openTotals = $calculator->totals($query->bets($user, new StatisticsFilter(status: BetStatus::Open)));
        $totals = TotalsDisplay::fromTotals($monthTotals, $currency);
        $monthName = $month->format('F Y');

        return view('livewire.overview.index', [
            'hasBets' => $settled !== [] || $openTotals->openBets > 0 || $user->bets()->exists(),
            'months' => $this->months($user, $now),
            'monthName' => $monthName,
            'profitLabel' => $isCurrentMonth ? 'Profit this month' : "Profit in {$monthName}",
            'symbol' => $currency->symbol(),
            'totals' => $totals,
            'sparkline' => ProfitChart::fromSeries($calculator->profitOverTime($settled, $month, $lastDay, Period::Day), $currency),
            'summary' => $monthTotals->settledBets === 0
                ? ($isCurrentMonth ? 'No bets settled this month yet' : "No bets settled in {$monthName}")
                : "{$monthTotals->settledBets} ".Str::plural('bet', $monthTotals->settledBets).' settled · turnover '.Money::format($monthTotals->turnover, $currency),
            'kpis' => $this->kpis($monthTotals, $totals, TotalsDisplay::fromTotals($openTotals, $currency)),
            'openBets' => $openTotals->openBets,
            'previews' => BetSort::StartsSoonest->apply($user->bets()->getQuery()->where('status', BetStatus::Open))
                ->with(['bookmaker', 'selections'])
                ->limit(self::PREVIEW_SIZE)
                ->get()
                ->map(fn (Bet $bet): OpenBetRow => OpenBetRow::fromBet($bet, $now)),
        ]);
    }

    /**
     * The KPI tiles: strike rate, average odds and CLV of the month, and the stake of the
     * bets open now.
     *
     * @return list<array{label: string, value: string, tone: 'profit'|'loss'|'even', sub: string}>
     */
    private function kpis(Totals $month, TotalsDisplay $monthDisplay, TotalsDisplay $open): array
    {
        return [
            ['label' => 'Strike rate', 'value' => $monthDisplay->strikeRate, 'tone' => 'even', 'sub' => "{$month->winningBets} of ".($month->winningBets + $month->losingBets).' decided'],
            ['label' => 'Average odds', 'value' => $monthDisplay->averageOdds, 'tone' => 'even', 'sub' => 'settled bets'],
            ['label' => 'Closing line', 'value' => $monthDisplay->averageClv, 'tone' => $monthDisplay->clvTone, 'sub' => "CLV on {$month->clvBets} ".Str::plural('bet', $month->clvBets)],
            ['label' => 'Open stake', 'value' => $open->openStake, 'tone' => 'even', 'sub' => "{$open->openBets} ".Str::plural('bet', $open->openBets).' pending'],
        ];
    }

    /**
     * The months to choose from, newest first: the current month (an empty value) back to
     * the month of the user's first bet, placed or settled.
     *
     * @return list<array{value: string, label: string}>
     */
    private function months(User $user, CarbonImmutable $now): array
    {
        $current = $now->startOfMonth();
        $first = collect([$user->bets()->min('placed_at'), $user->bets()->min('settled_at')])
            ->filter()
            ->map(fn (string $date): CarbonImmutable => CarbonImmutable::parse($date)->startOfMonth())
            ->push($current)
            ->min();
        $months = [];

        for ($month = $current; $month->greaterThanOrEqualTo($first); $month = $month->subMonthNoOverflow()) {
            $months[] = ['value' => $month->equalTo($current) ? '' : $month->format('Y-m'), 'label' => $month->format('F Y')];
        }

        return $months;
    }

    /**
     * The first day of the month shown.
     */
    private function selectedMonth(CarbonImmutable $now): CarbonImmutable
    {
        $current = $now->startOfMonth();

        if (preg_match('/^\d{4}-\d{2}$/', $this->month) !== 1) {
            return $current;
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $this->month);

        return $month instanceof CarbonImmutable && $month->format('Y-m') === $this->month && $month->lessThan($current) ? $month : $current;
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
