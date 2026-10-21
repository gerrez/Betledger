<?php

namespace App\Livewire\Bets;

use App\Domain\BetStatus;
use App\Models\Bet;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The bets list: Open / Settled / All tabs, bookmaker and sport filters, sorting.
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
        $sort = $this->sortOption($tab);
        $now = now();

        $bets = $sort
            ->apply($this->filteredBets($user)->when($tab->status(), fn (Builder $query, BetStatus $status) => $query->where('status', $status)))
            ->with(['bookmaker', 'selections.competition'])
            ->paginate(self::PER_PAGE);

        $openCount = $this->filteredBets($user)->where('status', BetStatus::Open)->count();
        $isFiltered = $this->bookmakerId() !== null || $this->sportId() !== null;

        return view('livewire.bets.index', [
            'tabs' => collect(BetListTab::cases())->mapWithKeys(fn (BetListTab $option): array => [
                $option->value => $option === BetListTab::Open ? "Open · {$openCount}" : $option->label(),
            ]),
            'tab' => $tab,
            'sorts' => $tab->sorts(),
            'bets' => $bets,
            'cards' => $bets->getCollection()->map(fn (Bet $bet): BetCard => BetCard::fromBet($bet, $now)),
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
