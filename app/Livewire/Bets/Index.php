<?php

namespace App\Livewire\Bets;

use App\Domain\Bets\SettleBet;
use App\Domain\BetStatus;
use App\Domain\Settlement\SelectionResult;
use App\Models\Bet;
use App\Models\Selection;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The bets list: Open / Settled / All tabs, bookmaker and sport filters, sorting, and
 * result buttons that settle open bets in place, with Undo.
 */
#[Title('Bets')]
class Index extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

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
     * Id of the bookmaker to show bets from; empty for all.
     */
    #[Url]
    public string $bookmaker = '';

    /**
     * Id of the sport to show bets on (a bet with any selection in it); empty for all.
     */
    #[Url]
    public string $sport = '';

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
     * Every change of tab, filter or order starts again at page 1; a new tab also
     * starts with its own default order.
     */
    public function updated(string $property): void
    {
        if (! in_array($property, ['status', 'sort', 'bookmaker', 'sport'], true)) {
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

    public function clearFilters(): void
    {
        $this->bookmaker = '';
        $this->sport = '';
        $this->resetPage();
    }

    public function render(): View
    {
        $user = $this->user();
        $tab = $this->tab();
        $now = now();
        $bets = $this->bets();
        $shown = $this->pinned === [] ? $bets->getCollection() : $this->pinnedBets($user);

        $openCount = $this->filteredBets($user)->where('status', BetStatus::Open)->count();
        $isFiltered = $this->bookmakerId() !== null || $this->sportId() !== null;

        return view('livewire.bets.index', [
            'tabs' => collect(BetListTab::cases())->mapWithKeys(fn (BetListTab $option): array => [
                $option->value => $option === BetListTab::Open ? "Open · {$openCount}" : $option->label(),
            ]),
            'tab' => $tab,
            'sorts' => $tab->sorts(),
            'bets' => $bets,
            'results' => SelectionResult::cases(),
            'cards' => $shown->map(fn (Bet $bet): BetCard => BetCard::fromBet($bet, $now, $this->undoableSelection($bet))),
            'hasBets' => $user->bets()->exists(),
            'isFiltered' => $isFiltered,
            'emptyMessage' => match ($tab) {
                BetListTab::Open => 'No open bets',
                BetListTab::Settled => 'No settled bets',
                BetListTab::All => 'No bets',
            }.($isFiltered ? ' match these filters' : ''),
            'bookmakers' => $user->bookmakers()->orderBy('name')->get(['id', 'name']),
            'sports' => $user->sports()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The page of bets for the tab, filters and order.
     *
     * @return LengthAwarePaginator<int, Bet>
     */
    private function bets(): LengthAwarePaginator
    {
        $tab = $this->tab();

        return $this->sortOption($tab)
            ->apply($this->filteredBets($this->user())->when($tab->status(), fn (Builder $query, BetStatus $status) => $query->where('status', $status)))
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
     * The user's bets that pass the bookmaker and sport filters.
     *
     * @return Builder<Bet>
     */
    private function filteredBets(User $user): Builder
    {
        return $user->bets()->getQuery()
            ->when($this->bookmakerId(), fn (Builder $query, int $id) => $query->where('bookmaker_id', $id))
            ->when($this->sportId(), fn (Builder $query, int $id) => $query->whereHas('selections', fn (Builder $selections) => $selections->where('sport_id', $id)));
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

    private function bookmakerId(): ?int
    {
        return ctype_digit($this->bookmaker) ? (int) $this->bookmaker : null;
    }

    private function sportId(): ?int
    {
        return ctype_digit($this->sport) ? (int) $this->sport : null;
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
