<?php

namespace App\Livewire\Bets;

use App\Domain\Bets\BetFilterQuery;
use App\Domain\Bets\SettleBet;
use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Statistics\StatisticsFilter;
use App\Models\Bet;
use App\Models\Competition;
use App\Models\Selection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The bets list: a text search, Open / Settled / All tabs, filters (the statistics
 * page's conditions, through BetFilterQuery) shown as removable chips, sorting, and
 * result buttons that settle open bets in place, with Undo. The search and every filter
 * are in the URL, so a filtered list can be linked to.
 */
#[Title('Bets')]
class Index extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    /**
     * The longest search term that is used; the rest is ignored.
     */
    public const MAX_SEARCH_LENGTH = 100;

    /**
     * The filter properties (the search not included).
     */
    public const FILTERS = ['bookmaker', 'sport', 'competition', 'market', 'tipster', 'tag', 'type', 'from', 'to'];

    /**
     * The open tab, a BetListTab value.
     */
    #[Url]
    public string $status = 'open';

    /**
     * A BetSort value; empty (or one the tab doesn't offer) means the tab's default.
     */
    #[Url]
    public string $sort = '';

    /**
     * Text to look for in the notes and in the selections' event names, picks, teams and
     * competitions; empty for no search.
     */
    #[Url]
    public string $search = '';

    /**
     * Ids of the list entries to show bets with (sport, competition and market match a
     * bet when any of its selections does); empty for all.
     */
    #[Url]
    public string $bookmaker = '';

    #[Url]
    public string $sport = '';

    #[Url]
    public string $competition = '';

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
     * First and last day (Y-m-d, days in the user's time zone) of the date range, as on
     * the statistics page: settled bets by their event date, open bets by the day they
     * were placed. Empty for no limit.
     */
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /**
     * The latest result set from this page on each bet, which its card offers to undo:
     * bet id => the selection and the result it was given.
     *
     * @var array<int, array{selection: int, result: string}>
     */
    #[Locked]
    public array $undoable = [];

    /**
     * Ids of the bets on the page when the first result was set from it, in order: the
     * page keeps showing them there, so a bet that settles (or reopens on Undo) stays in
     * place instead of leaving the tab or moving in the order.
     *
     * @var list<int>
     */
    #[Locked]
    public array $pinned = [];

    /**
     * Every change of tab, search, filter or order starts again at page 1; a new tab also
     * starts with its own default order.
     */
    public function updated(string $property): void
    {
        if (! in_array($property, ['status', 'sort', 'search', ...self::FILTERS], true)) {
            return;
        }

        if ($property === 'status') {
            $this->sort = '';
        }

        $this->resetPage();
    }

    /**
     * A new page, which every change of tab, filter or order also leads to, shows the
     * bets as they are now and forgets what could be undone.
     */
    public function updatedPaginators(): void
    {
        $this->undoable = [];
        $this->pinned = [];
    }

    /**
     * Give a pending selection of an open bet a result, which settles the bet when the
     * settlement rules say so. A page that is out of date (the selection already has a
     * result, or its bet is settled) changes nothing.
     */
    public function settle(int $selectionId, string $result, SettleBet $settleBet): void
    {
        $selection = $this->user()->selections()->with('bet')->findOrFail($selectionId);
        $outcome = SelectionResult::tryFrom($result);

        Gate::authorize('update', $selection->bet);
        abort_if($outcome === null, 422);

        if ($selection->result !== null || $selection->bet->isSettled()) {
            return;
        }

        if ($this->pinned === []) {
            $this->pinned = array_values($this->bets()->getCollection()->map(fn (Bet $bet): int => $bet->id)->all());
        }

        $settleBet->setResult($selection, $outcome);
        $this->undoable[$selection->bet_id] = ['selection' => $selection->id, 'result' => $outcome->value];
    }

    /**
     * Take back the latest result set from this page on the bet, unless it has been
     * changed elsewhere since.
     */
    public function undo(int $betId, SettleBet $settleBet): void
    {
        $change = $this->undoable[$betId] ?? null;
        unset($this->undoable[$betId]);

        $selection = $change === null ? null : $this->user()->selections()->with('bet')->find($change['selection']);

        if ($selection === null || $selection->result?->value !== $change['result']) {
            return;
        }

        Gate::authorize('update', $selection->bet);

        $settleBet->setResult($selection, null);
    }

    /**
     * Remove one chip's filter: a filter property, or "dates" for the date range.
     */
    public function removeFilter(string $filter): void
    {
        $properties = $filter === 'dates' ? ['from', 'to'] : [$filter];

        if (array_diff($properties, self::FILTERS) !== []) {
            return;
        }

        $this->reset(...$properties);
        $this->resetPage();
    }

    /**
     * Clear every filter, keeping the search.
     */
    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->resetPage();
    }

    /**
     * Clear the search and every filter.
     */
    public function clearAll(): void
    {
        $this->reset('search', ...self::FILTERS);
        $this->resetPage();
    }

    public function render(): View
    {
        $user = $this->user();
        $tab = $this->tab();
        $localTime = $user->localTime();
        $bets = $this->bets();
        $shown = $this->pinned === [] ? $bets->getCollection() : $this->pinnedBets($user);

        $chips = $this->chips($user);
        $isSearching = $this->searchTerm() !== '';
        $isFiltered = $chips !== [];
        $openCount = $this->matchingBets($user, BetStatus::Open)->count();

        return view('livewire.bets.index', [
            'tabs' => collect(BetListTab::cases())->mapWithKeys(fn (BetListTab $option): array => [
                $option->value => $option === BetListTab::Open ? "Open · {$openCount}" : $option->label(),
            ]),
            'tab' => $tab,
            'sorts' => $tab->sorts(),
            'bets' => $bets,
            'results' => SelectionResult::cases(),
            'cards' => $shown->map(fn (Bet $bet): BetCard => BetCard::fromBet($bet, $localTime, $this->undoableSelection($bet))),
            'hasBets' => $user->bets()->exists(),
            'chips' => $chips,
            'isNarrowed' => $isSearching || $isFiltered,
            'matchCount' => $bets->total().' '.Str::plural('bet', $bets->total()),
            'emptyMessage' => match ($tab) {
                BetListTab::Open => 'No open bets',
                BetListTab::Settled => 'No settled bets',
                BetListTab::All => 'No bets',
            }.match (true) {
                $isSearching && $isFiltered => ' match this search and these filters',
                $isSearching => ' match this search',
                $isFiltered => ' match these filters',
                default => '',
            },
            'clearLabel' => match (true) {
                $isSearching && $isFiltered => 'Clear search and filters',
                $isSearching => 'Clear search',
                default => 'Clear filters',
            },
            'bookmakers' => $user->bookmakers()->orderBy('name')->get(['id', 'name']),
            'sports' => $user->sports()->orderBy('name')->get(['id', 'name']),
            'competitions' => $user->competitions()->with('sport')->get()
                ->sortBy([fn (Competition $a, Competition $b): int => strcasecmp($a->sport->name, $b->sport->name), fn (Competition $a, Competition $b): int => strcasecmp($a->name, $b->name)])
                ->groupBy(fn (Competition $competition): string => $competition->sport->name),
            'markets' => $user->markets()->orderBy('name')->get(['id', 'name']),
            'tipsters' => $user->tipsters()->orderBy('name')->get(['id', 'name']),
            'tags' => $user->tags()->orderBy('name')->get(['id', 'name']),
            'types' => self::typeLabels(),
        ]);
    }

    /**
     * The page of bets for the tab, search, filters and order.
     *
     * @return LengthAwarePaginator<int, Bet>
     */
    private function bets(): LengthAwarePaginator
    {
        $tab = $this->tab();

        return $this->sortOption($tab)
            ->apply($this->matchingBets($this->user(), $tab->status()))
            ->with(['bookmaker', 'selections.competition'])
            ->paginate(self::PER_PAGE);
    }

    /**
     * The pinned bets, in their pinned order.
     *
     * @return Collection<int, Bet>
     */
    private function pinnedBets(User $user): Collection
    {
        $order = array_flip($this->pinned);

        return $user->bets()
            ->whereIn('id', $this->pinned)
            ->with(['bookmaker', 'selections.competition'])
            ->get()
            ->sortBy(fn (Bet $bet): int => $order[$bet->id])
            ->values();
    }

    /**
     * The bet's selection whose result its card offers to undo, if it still has that result.
     */
    private function undoableSelection(Bet $bet): ?Selection
    {
        $change = $this->undoable[$bet->id] ?? null;

        return $change === null ? null : $bet->selections->first(
            fn (Selection $selection): bool => $selection->id === $change['selection'] && $selection->result?->value === $change['result'],
        );
    }

    /**
     * The user's bets with the status (null: any) that pass the filters and the search.
     *
     * @return Builder<Bet>
     */
    private function matchingBets(User $user, ?BetStatus $status): Builder
    {
        $query = app(BetFilterQuery::class);
        [$from, $to] = $this->days();

        $filter = new StatisticsFilter(
            from: $from,
            to: $to,
            bookmakerId: $this->entryId($this->bookmaker),
            sportId: $this->entryId($this->sport),
            competitionId: $this->entryId($this->competition),
            marketId: $this->entryId($this->market),
            tipsterId: $this->entryId($this->tipster),
            tagId: $this->entryId($this->tag),
            type: BetType::tryFrom($this->type),
            status: $status,
        );

        return $query->search($query->filtered($user, $filter), $this->searchTerm());
    }

    /**
     * A removable chip for each filter in use, naming the entry. An id that isn't one of
     * the user's entries still filters (so no bets match) and shows as "unknown".
     *
     * @return list<array{filter: string, label: string}>
     */
    private function chips(User $user): array
    {
        $entries = [
            'bookmaker' => ['Bookmaker', $user->bookmakers()],
            'sport' => ['Sport', $user->sports()],
            'competition' => ['Competition', $user->competitions()],
            'market' => ['Market', $user->markets()],
            'tipster' => ['Tipster', $user->tipsters()],
            'tag' => ['Tag', $user->tags()],
        ];
        $chips = [];

        foreach ($entries as $filter => [$label, $relation]) {
            $id = $this->entryId($this->{$filter});

            if ($id !== null) {
                $name = $relation->whereKey($id)->value('name');
                $chips[] = ['filter' => $filter, 'label' => "{$label}: ".(is_string($name) ? $name : 'unknown')];
            }
        }

        $type = BetType::tryFrom($this->type);

        if ($type !== null) {
            $chips[] = ['filter' => 'type', 'label' => self::typeLabels()[$type->value]];
        }

        [$from, $to] = $this->days();

        if ($from !== null && $to !== null) {
            $chips[] = ['filter' => 'dates', 'label' => $from->format('j M Y').' – '.$to->format('j M Y')];
        } elseif ($from !== null) {
            $chips[] = ['filter' => 'dates', 'label' => 'From '.$from->format('j M Y')];
        } elseif ($to !== null) {
            $chips[] = ['filter' => 'dates', 'label' => 'Until '.$to->format('j M Y')];
        }

        return $chips;
    }

    /**
     * @return array<string, string>
     */
    private static function typeLabels(): array
    {
        return [BetType::Single->value => 'Singles', BetType::Accumulator->value => 'Accumulators'];
    }

    /**
     * The first and last day of the date range (null: no limit). Dates entered the wrong
     * way round are read the right way round; an invalid date is no limit.
     *
     * @return array{CarbonImmutable|null, CarbonImmutable|null}
     */
    private function days(): array
    {
        $from = $this->date($this->from);
        $to = $this->date($this->to);

        return $from !== null && $to !== null && $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    private function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->toDateString() === $value ? $date : null;
    }

    private function searchTerm(): string
    {
        return trim(mb_substr($this->search, 0, self::MAX_SEARCH_LENGTH));
    }

    /**
     * The open tab; an unknown value in the URL falls back to Open.
     */
    private function tab(): BetListTab
    {
        return BetListTab::tryFrom($this->status) ?? BetListTab::Open;
    }

    private function sortOption(BetListTab $tab): BetSort
    {
        $sort = BetSort::tryFrom($this->sort);

        return in_array($sort, $tab->sorts(), true) ? $sort : $tab->sorts()[0];
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
