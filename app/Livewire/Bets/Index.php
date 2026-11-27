<?php

namespace App\Livewire\Bets;

use App\Domain\Bets\BetFilterQuery;
use App\Domain\Bets\BulkBetActions;
use App\Domain\Bets\ImportBatch;
use App\Domain\Bets\SettleBet;
use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Statistics\Dimension;
use App\Domain\Statistics\OddsBand;
use App\Domain\Statistics\StatisticsFilter;
use App\Models\Bet;
use App\Models\Competition;
use App\Models\Selection;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Flux\Flux;
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
 * are in the URL, so a filtered list can be linked to. A Select mode ticks bets (or every
 * bet that matches) to delete them or add or remove a tag at once; with the import batch
 * filter that undoes an import.
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
     * The filter properties (the search not included). Team, odds band and free bets are
     * set only by a link (from a statistics row), so they have a chip but no field.
     */
    public const FILTERS = ['bookmaker', 'sport', 'competition', 'team', 'market', 'tipster', 'tag', 'type', 'odds', 'freeBets', 'from', 'to', 'importBatch'];

    /**
     * The URL value of an entry filter that picks the bets without such an entry.
     */
    public const NONE = 'none';

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
     * Ids of the list entries to show bets with (sport, competition, team and market
     * match a bet when any of its selections does); empty for all. "none" (NONE) shows
     * the bets without one: no tipster, no tags, or a selection without a competition,
     * market or teams.
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
     * An OddsBand value: the bets whose total odds are in that band; empty for any.
     */
    #[Url]
    public string $odds = '';

    /**
     * "exclude" leaves free bets out, as on the statistics page; empty shows them.
     */
    #[Url(as: 'free')]
    public string $freeBets = '';

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
     * The id of an import (`bets.import_batch`): only the bets of that import; empty for
     * every bet. The import page links to the list with its new batch.
     */
    #[Url(as: 'batch')]
    public string $importBatch = '';

    /**
     * Whether the cards have checkboxes, for the bulk actions.
     */
    public bool $selecting = false;

    /**
     * Ids of the ticked bets, as the browser sent them; every action looks them up again
     * through the user's own bets. Checkbox values arrive as strings, but nothing stops a
     * request from sending anything else.
     *
     * @var array<int, mixed>
     */
    public array $selected = [];

    /**
     * Whether every bet that matches the tab, search and filters is selected (on every
     * page), instead of the ticked ones.
     */
    public bool $selectsAllMatching = false;

    /**
     * The id of the tag to add to or remove from the selected bets.
     */
    public string $bulkTag = '';

    /**
     * What the last bulk action did ("3 bets deleted."), shown until the next one.
     */
    #[Locked]
    public ?string $notice = null;

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
     * starts with its own default order. A new tab, search or filter changes which bets
     * there are to select, so it clears the selection.
     */
    public function updated(string $property): void
    {
        if (! in_array($property, ['status', 'sort', 'search', ...self::FILTERS], true)) {
            return;
        }

        if ($property === 'status') {
            $this->sort = '';
        }

        if ($property !== 'sort') {
            $this->clearSelection();
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
        $this->clearSelection();
        $this->resetPage();
    }

    /**
     * Clear every filter, keeping the search.
     */
    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->clearSelection();
        $this->resetPage();
    }

    /**
     * Clear the search and every filter.
     */
    public function clearAll(): void
    {
        $this->reset('search', ...self::FILTERS);
        $this->clearSelection();
        $this->resetPage();
    }

    /**
     * Show the checkboxes, with nothing ticked.
     */
    public function startSelecting(): void
    {
        $this->clearSelection();
        $this->selecting = true;
        $this->notice = null;
    }

    /**
     * Leave the Select mode, forgetting the selection.
     */
    public function stopSelecting(): void
    {
        $this->clearSelection();
        $this->selecting = false;
    }

    /**
     * Select every bet that matches the tab, search and filters, on every page.
     */
    public function selectAllMatching(): void
    {
        $this->selecting = true;
        $this->selectsAllMatching = true;
    }

    /**
     * Untick every bet, staying in the Select mode.
     */
    public function clearSelection(): void
    {
        $this->reset('selected', 'selectsAllMatching', 'bulkTag');
        $this->resetValidation('bulkTag');
    }

    /**
     * Delete the selected bets. An import batch filter whose bets are all gone is
     * cleared, as nothing is left of that import.
     */
    public function deleteSelected(BulkBetActions $actions): void
    {
        $user = $this->user();
        $deleted = $actions->delete($user, $this->selectedIds($user));

        if ($this->importBatch !== '' && $user->bets()->where('import_batch', $this->importBatch)->doesntExist()) {
            $this->reset('importBatch');
        }

        $this->finishBulkAction(Str::ucfirst(self::betCount($deleted)).' deleted.');
    }

    /**
     * Give the selected bets the chosen tag.
     */
    public function addTagToSelected(BulkBetActions $actions): void
    {
        $user = $this->user();
        $tag = $this->chosenTag($user);

        if ($tag === null) {
            return;
        }

        $changed = $actions->addTag($user, $tag, $this->selectedIds($user));

        Flux::modal('bulk-tag')->close();
        $this->finishBulkAction("Tag {$tag->name} added to ".self::betCount($changed).'.');
    }

    /**
     * Take the chosen tag off the selected bets.
     */
    public function removeTagFromSelected(BulkBetActions $actions): void
    {
        $user = $this->user();
        $tag = $this->chosenTag($user);

        if ($tag === null) {
            return;
        }

        $changed = $actions->removeTag($user, $tag, $this->selectedIds($user));

        Flux::modal('bulk-tag')->close();
        $this->finishBulkAction("Tag {$tag->name} removed from ".self::betCount($changed).'.');
    }

    public function render(): View
    {
        $user = $this->user();
        $tab = $this->tab();
        $localTime = $user->localTime();
        $bets = $this->bets();
        $shown = $this->pinned === [] ? $bets->getCollection() : $this->pinnedBets($user);

        $importBatches = app(BetFilterQuery::class)->importBatches($user);
        $chips = $this->chips($user, $importBatches);
        $tickedCount = count($this->tickedIds());
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
            'importBatches' => array_map(fn (ImportBatch $batch): array => [
                'id' => $batch->id,
                'label' => $localTime->toLocal($batch->importedAt)->format('j M Y H:i').' · '.self::betCount($batch->betCount),
            ], $importBatches),
            'hasSelection' => $this->selectsAllMatching || $tickedCount > 0,
            'selectedCount' => self::betCount($this->selectsAllMatching ? $bets->total() : $tickedCount),
            'matchingTotal' => $bets->total(),
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
            teamId: $this->entryId($this->team),
            marketId: $this->entryId($this->market),
            tipsterId: $this->entryId($this->tipster),
            tagId: $this->entryId($this->tag),
            type: BetType::tryFrom($this->type),
            includeFreeBets: $this->freeBets !== 'exclude',
            status: $status,
            oddsBand: OddsBand::tryFrom($this->odds),
        );

        return $query->inImportBatch($query->search($query->filtered($user, $filter), $this->searchTerm()), $this->importBatch);
    }

    /**
     * The URL parameters of the list showing the bets that pass the filter: its tab
     * (Settled or Open for a status, else All) and each filter in use.
     *
     * @return array<string, string>
     */
    public static function queryFor(StatisticsFilter $filter): array
    {
        $entry = fn (?int $id): ?string => match ($id) {
            null => null,
            StatisticsFilter::NONE => self::NONE,
            default => (string) $id,
        };

        return array_filter([
            'status' => match ($filter->status) {
                BetStatus::Open => BetListTab::Open->value,
                BetStatus::Settled => BetListTab::Settled->value,
                null => BetListTab::All->value,
            },
            'from' => $filter->from?->toDateString(),
            'to' => $filter->to?->toDateString(),
            'bookmaker' => $entry($filter->bookmakerId),
            'sport' => $entry($filter->sportId),
            'competition' => $entry($filter->competitionId),
            'team' => $entry($filter->teamId),
            'market' => $entry($filter->marketId),
            'tipster' => $entry($filter->tipsterId),
            'tag' => $entry($filter->tagId),
            'type' => $filter->type?->value,
            'odds' => $filter->oddsBand?->value,
            'free' => $filter->includeFreeBets ? null : 'exclude',
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * A removable chip for each filter in use, naming the entry ("No competition" for
     * none). An id that isn't one of the user's entries still filters (so no bets match)
     * and shows as "unknown", as does an import batch that isn't one of the user's.
     *
     * @param  list<ImportBatch>  $importBatches  the user's imports
     * @return list<array{filter: string, label: string}>
     */
    private function chips(User $user, array $importBatches): array
    {
        $entries = [
            'bookmaker' => [Dimension::Bookmaker, $user->bookmakers()],
            'sport' => [Dimension::Sport, $user->sports()],
            'competition' => [Dimension::Competition, $user->competitions()],
            'team' => [Dimension::Team, $user->teams()],
            'market' => [Dimension::Market, $user->markets()],
            'tipster' => [Dimension::Tipster, $user->tipsters()],
            'tag' => [Dimension::Tag, $user->tags()],
        ];
        $chips = [];

        foreach ($entries as $filter => [$dimension, $relation]) {
            $id = $this->entryId($this->{$filter});

            if ($id === StatisticsFilter::NONE && $dimension->hasNone()) {
                $chips[] = ['filter' => $filter, 'label' => $dimension->noneLabel()];
            } elseif ($id !== null) {
                $name = $id === StatisticsFilter::NONE ? null : $relation->whereKey($id)->value('name');
                $chips[] = ['filter' => $filter, 'label' => "{$dimension->label()}: ".(is_string($name) ? $name : 'unknown')];
            }
        }

        $type = BetType::tryFrom($this->type);

        if ($type !== null) {
            $chips[] = ['filter' => 'type', 'label' => self::typeLabels()[$type->value]];
        }

        $oddsBand = OddsBand::tryFrom($this->odds);

        if ($oddsBand !== null) {
            $chips[] = ['filter' => 'odds', 'label' => "Total odds {$oddsBand->label()}"];
        }

        if ($this->freeBets === 'exclude') {
            $chips[] = ['filter' => 'freeBets', 'label' => 'No free bets'];
        }

        [$from, $to] = $this->days();

        if ($from !== null && $to !== null) {
            $chips[] = ['filter' => 'dates', 'label' => $from->format('j M Y').' – '.$to->format('j M Y')];
        } elseif ($from !== null) {
            $chips[] = ['filter' => 'dates', 'label' => 'From '.$from->format('j M Y')];
        } elseif ($to !== null) {
            $chips[] = ['filter' => 'dates', 'label' => 'Until '.$to->format('j M Y')];
        }

        if ($this->importBatch !== '') {
            $batch = collect($importBatches)->first(fn (ImportBatch $batch): bool => $batch->id === $this->importBatch);
            $chips[] = ['filter' => 'importBatch', 'label' => $batch === null
                ? 'Import: unknown'
                : 'Imported '.$user->localTime()->toLocal($batch->importedAt)->format('j M Y H:i')];
        }

        return $chips;
    }

    /**
     * The ids of the bets to change: every bet that matches, or the ticked ones.
     *
     * @return list<int>
     */
    private function selectedIds(User $user): array
    {
        if (! $this->selectsAllMatching) {
            return $this->tickedIds();
        }

        return array_values(array_map(
            fn (mixed $id): int => (int) $id,
            $this->matchingBets($user, $this->tab()->status())->pluck('bets.id')->all(),
        ));
    }

    /**
     * The ticked ids that look like ids; whether they are the user's bets is up to the
     * query that uses them.
     *
     * @return list<int>
     */
    private function tickedIds(): array
    {
        $ids = array_filter($this->selected, fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)));

        return array_values(array_unique(array_map(fn (mixed $id): int => (int) $id, $ids)));
    }

    /**
     * The tag chosen for adding or removing, if it is one of the user's; else null, with
     * an error on the field.
     */
    private function chosenTag(User $user): ?Tag
    {
        $tag = ctype_digit($this->bulkTag) ? $user->tags()->find((int) $this->bulkTag) : null;

        if ($tag === null) {
            $this->addError('bulkTag', 'Choose one of your tags.');
        }

        return $tag;
    }

    /**
     * Leave the Select mode after a bulk action and say what it did. The page starts again
     * at page 1 and forgets what could be undone, as bets may have gone.
     */
    private function finishBulkAction(string $notice): void
    {
        $this->stopSelecting();
        $this->undoable = [];
        $this->pinned = [];
        $this->notice = $notice;
        $this->resetPage();
    }

    /**
     * "1 bet", "12 bets".
     */
    private static function betCount(int $count): string
    {
        return $count.' '.Str::plural('bet', $count);
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

    /**
     * The id in a filter's URL value: StatisticsFilter::NONE for "none", null for
     * anything that isn't an id.
     */
    private function entryId(string $value): ?int
    {
        return match (true) {
            $value === self::NONE => StatisticsFilter::NONE,
            ctype_digit($value) => (int) $value,
            default => null,
        };
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
