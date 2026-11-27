<?php

namespace App\Livewire\Statistics;

use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Currency;
use App\Domain\Statistics\BreakdownRow;
use App\Domain\Statistics\Dimension;
use App\Domain\Statistics\OddsBandRow;
use App\Domain\Statistics\StatisticsCalculator;
use App\Domain\Statistics\StatisticsFilter;
use App\Domain\Statistics\StatisticsQuery;
use App\Livewire\Bets\Index as BetsIndex;
use App\Models\Competition;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The statistics page: date range, filters and the free bets toggle, KPI tiles, risk
 * figures, the profit-over-time chart, the breakdown table by dimension, odds ranges and closing line
 * value. Each breakdown row and odds range with settled bets links to those bets on the
 * bets list.
 */
#[Title('Statistics')]
class Index extends Component
{
    /**
     * The breakdown table's dimensions that have their own tab; the others are under "More".
     */
    public const MAIN_DIMENSIONS = [Dimension::Sport, Dimension::Bookmaker, Dimension::Tipster, Dimension::BetType];

    /**
     * A DateRange value; an unknown one means the default, 90 days.
     */
    #[Url]
    public string $range = '90d';

    /**
     * First day of a custom range (Y-m-d); empty for no limit.
     */
    #[Url]
    public string $from = '';

    /**
     * Last day of a custom range (Y-m-d); empty for no limit.
     */
    #[Url]
    public string $to = '';

    /**
     * Ids of the list entries to filter by; empty for all.
     */
    #[Url]
    public string $bookmaker = '';

    #[Url]
    public string $sport = '';

    #[Url]
    public string $competition = '';

    #[Url]
    public string $team = '';

    #[Url]
    public string $market = '';

    #[Url]
    public string $tipster = '';

    #[Url]
    public string $tag = '';

    /**
     * A BetType value; empty for both.
     */
    #[Url]
    public string $type = '';

    /**
     * "exclude" leaves free bets out; anything else includes them.
     */
    #[Url(as: 'free')]
    public string $freeBets = 'include';

    /**
     * The Dimension the breakdown table splits bets by.
     */
    #[Url(as: 'by')]
    public string $dimension = 'sport';

    /**
     * Choose a date range. A custom range starts out as the range shown until then.
     */
    public function useRange(string $range): void
    {
        $chosen = DateRange::tryFrom($range);

        if ($chosen === null) {
            return;
        }

        if ($chosen === DateRange::Custom && $this->dateRange() !== DateRange::Custom) {
            [$from, $to] = $this->days();
            $this->from = $from?->toDateString() ?? '';
            $this->to = $to?->toDateString() ?? '';
        }

        $this->range = $chosen->value;
    }

    public function toggleFreeBets(): void
    {
        $this->freeBets = $this->includesFreeBets() ? 'exclude' : 'include';
    }

    public function clearFilters(): void
    {
        $this->reset('bookmaker', 'sport', 'competition', 'team', 'market', 'tipster', 'tag', 'type');
    }

    public function render(StatisticsQuery $query, StatisticsCalculator $calculator): View
    {
        $user = $this->user();
        $currency = $user->base_currency;
        $dimension = $this->breakdownDimension();
        $bets = $query->bets($user, $this->filter());
        $settledBetsQuery = BetsIndex::queryFor($this->filter(BetStatus::Settled));
        $display = function (BreakdownRow|OddsBandRow $row) use ($dimension, $currency, $settledBetsQuery): array {
            $totals = TotalsDisplay::fromTotals($row->totals, $currency);

            return [
                'label' => $row instanceof BreakdownRow ? ($row->group->label ?? $dimension->noneLabel()) : $row->band->label(),
                'isNone' => $row instanceof BreakdownRow && $row->group->isNone(),
                'totals' => $totals,
                'breakEven' => $this->breakEvenNote($totals),
                'href' => $row->totals->settledBets === 0 ? null : $this->settledBetsLink($settledBetsQuery, $row instanceof BreakdownRow
                    ? [$dimension->value => $row->group->isNone() ? BetsIndex::NONE : (string) $row->group->key]
                    : ['odds' => $row->band->value]),
            ];
        };
        $totals = TotalsDisplay::fromTotals($calculator->totals($bets), $currency);
        $moreDimensions = array_values(array_filter(Dimension::cases(), fn (Dimension $option): bool => ! in_array($option, self::MAIN_DIMENSIONS, true)));

        return view('livewire.statistics.index', [
            'currency' => $currency,
            'hasBets' => $bets !== [] || $user->bets()->exists(),
            'hasMatchingBets' => $bets !== [],
            'isFiltered' => $this->isFiltered(),
            'ranges' => DateRange::cases(),
            'selectedRange' => $this->dateRange(),
            'isCustomRange' => $this->dateRange() === DateRange::Custom,
            'includesFreeBets' => $this->includesFreeBets(),
            'totals' => $totals,
            'kpis' => $this->kpis($totals, $currency),
            'riskTiles' => RiskDisplay::tiles($calculator->risk($bets)),
            'profitChart' => ProfitChart::fromSeries($calculator->profitOverTime($bets, ...$this->days()), $currency),
            'clvSummary' => $this->clvSummary($totals),
            'breakdownBy' => $dimension,
            'mainDimensions' => self::MAIN_DIMENSIONS,
            'moreDimensions' => $moreDimensions,
            'isMoreDimension' => in_array($dimension, $moreDimensions, true),
            'breakdown' => array_map($display, $calculator->breakdown($bets, $dimension)),
            'breakdownNote' => match (true) {
                $dimension === Dimension::Tag => 'Bets with several tags count under each of them, so rows can add up to more than the total.',
                $dimension->countsBetsMoreThanOnce() => 'Accumulators count under every value their selections include, so rows can add up to more than the total.',
                default => null,
            },
            'oddsBands' => array_map($display, $calculator->oddsBands($bets)),
            'bookmakers' => $user->bookmakers()->orderBy('name')->get(['id', 'name']),
            'sports' => $user->sports()->orderBy('name')->get(['id', 'name']),
            'competitions' => $this->bySport($user->competitions()->with('sport')->get()),
            'teams' => $this->bySport($user->teams()->with('sport')->get()),
            'markets' => $user->markets()->orderBy('name')->get(['id', 'name']),
            'tipsters' => $user->tipsters()->orderBy('name')->get(['id', 'name']),
            'tags' => $user->tags()->orderBy('name')->get(['id', 'name']),
            'types' => [BetType::Single->value => 'Singles', BetType::Accumulator->value => 'Accumulators'],
        ]);
    }

    /**
     * The KPI tiles: profit, turnover, ROI, strike rate, average odds and open bets.
     *
     * @return list<array{label: string, value: string, tone: 'profit'|'loss'|'even', sub: string}>
     */
    private function kpis(TotalsDisplay $totals, Currency $currency): array
    {
        $symbol = $currency->symbol();

        return [
            ['label' => 'Profit', 'value' => $totals->profit, 'tone' => $totals->profitTone, 'sub' => "{$symbol} · {$totals->settledBets} settled ".Str::plural('bet', $totals->settledBets)],
            ['label' => 'Turnover', 'value' => $totals->turnover, 'tone' => 'even', 'sub' => "{$symbol} · cash stakes only"],
            ['label' => 'ROI', 'value' => $totals->roi, 'tone' => $totals->roiTone, 'sub' => 'profit ÷ turnover'],
            ['label' => 'Strike rate', 'value' => $totals->strikeRate, 'tone' => 'even', 'sub' => $this->breakEvenNote($totals, ' to break even') ?? 'pushes excluded'],
            ['label' => 'Average odds', 'value' => $totals->averageOdds, 'tone' => 'even', 'sub' => $totals->medianOdds === TotalsDisplay::NONE ? 'settled bets' : "median {$totals->medianOdds}"],
            ['label' => 'Open', 'value' => (string) $totals->openBets, 'tone' => 'even', 'sub' => "{$totals->openStake} at stake"],
        ];
    }

    /**
     * The bets list's Settled tab with the page's filters plus a row's value, so it shows
     * the row's settled bets. Null when the page already filters that dimension by another
     * value: an accumulator can count under both, and the list filters by one value.
     *
     * @param  array<string, string>  $pageQuery  the list's URL parameters for the page's filters
     * @param  array<string, string>  $rowQuery  the row's own parameter
     */
    private function settledBetsLink(array $pageQuery, array $rowQuery): ?string
    {
        foreach ($rowQuery as $parameter => $value) {
            if (($pageQuery[$parameter] ?? $value) !== $value) {
                return null;
            }
        }

        return route('bets.index', [...$pageQuery, ...$rowQuery]);
    }

    /**
     * "needs 51.3%" (plus the suffix): the strike rate the bets need to break even; null
     * while none of them is a win or a loss.
     */
    private function breakEvenNote(TotalsDisplay $totals, string $suffix = ''): ?string
    {
        return $totals->breakEvenStrikeRate === TotalsDisplay::NONE ? null : "needs {$totals->breakEvenStrikeRate}{$suffix}";
    }

    /**
     * How many settled bets the CLV covers, and how many of them beat the closing line.
     */
    private function clvSummary(TotalsDisplay $totals): string
    {
        $withoutClosingOdds = $totals->settledBets - $totals->clvBets;

        if ($totals->clvBets === 0) {
            return $totals->settledBets === 0
                ? 'CLV shows once bets with closing odds are settled.'
                : 'None of these settled bets have closing odds yet. Add closing odds to a bet to see its CLV.';
        }

        return "{$totals->beatCloseBets} of {$totals->clvBets} ".Str::plural('bet', $totals->clvBets).' with closing odds beat the closing line.'
            .($withoutClosingOdds === 0 ? '' : " {$withoutClosingOdds} settled ".($withoutClosingOdds === 1 ? 'bet has' : 'bets have').' no closing odds.');
    }

    /**
     * Competitions or teams grouped by sport name, both in alphabetical order, for the
     * filter's option groups (names repeat across sports).
     *
     * @template TEntry of Competition|Team
     *
     * @param  Collection<int, TEntry>  $entries
     * @return array<string, list<TEntry>>
     */
    private function bySport(Collection $entries): array
    {
        $grouped = [];

        foreach ($entries->sortBy([fn ($a, $b): int => strcasecmp($a->sport->name, $b->sport->name), fn ($a, $b): int => strcasecmp($a->name, $b->name)]) as $entry) {
            $grouped[$entry->sport->name][] = $entry;
        }

        return $grouped;
    }

    /**
     * The page's filters, limited to bets with the status if one is given.
     */
    private function filter(?BetStatus $status = null): StatisticsFilter
    {
        [$from, $to] = $this->days();

        return new StatisticsFilter(
            from: $from,
            to: $to,
            bookmakerId: $this->entryId($this->bookmaker),
            sportId: $this->entryId($this->sport),
            competitionId: $this->entryId($this->competition),
            teamId: $this->entryId($this->team),
            marketId: $this->entryId($this->market),
            tipsterId: $this->entryId($this->tipster),
            tagId: $this->entryId($this->tag),
            type: BetType::tryFrom($this->type),
            includeFreeBets: $this->includesFreeBets(),
            status: $status,
        );
    }

    /**
     * The first and last day of the chosen range (null: no limit). A custom range whose
     * dates were entered the wrong way round is read the right way round.
     *
     * @return array{CarbonImmutable|null, CarbonImmutable|null}
     */
    private function days(): array
    {
        $range = $this->dateRange();

        if ($range !== DateRange::Custom) {
            return $range->days($this->user()->localTime()->today());
        }

        $from = $this->date($this->from);
        $to = $this->date($this->to);

        return $from !== null && $to !== null && $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    private function dateRange(): DateRange
    {
        return DateRange::tryFrom($this->range) ?? DateRange::Last90Days;
    }

    private function breakdownDimension(): Dimension
    {
        return Dimension::tryFrom($this->dimension) ?? Dimension::Sport;
    }

    private function includesFreeBets(): bool
    {
        return $this->freeBets !== 'exclude';
    }

    private function isFiltered(): bool
    {
        return array_filter([$this->bookmaker, $this->sport, $this->competition, $this->team, $this->market, $this->tipster, $this->tag, $this->type], fn (string $value): bool => $value !== '') !== [];
    }

    private function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->toDateString() === $value ? $date : null;
    }

    private function entryId(string $value): ?int
    {
        return ctype_digit($value) ? (int) $value : null;
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
