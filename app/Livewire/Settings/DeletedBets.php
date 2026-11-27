<?php

namespace App\Livewire\Settings;

use App\Livewire\Bets\BetCard;
use App\Models\Bet;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The user's recently deleted bets, newest deletion first: each can be restored or
 * deleted for good, and all of them can be deleted for good at once (e.g. after undoing
 * an import, so the file can be imported again). model:prune deletes them for good after
 * Bet::DAYS_KEPT_DELETED days.
 */
#[Title('Deleted bets')]
class DeletedBets extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    /** The bet just restored, linked from the confirmation until the user leaves the page. */
    #[Locked]
    public ?int $restoredBetId = null;

    /** What "Delete all for good" did ("16 bets deleted for good."), until the next action. */
    #[Locked]
    public ?string $notice = null;

    /**
     * Bring back one of the user's deleted bets, with its selections, results and tags.
     */
    public function restore(int $betId): void
    {
        $bet = $this->deletedBet($betId);

        Gate::authorize('restore', $bet);

        $bet->restore();
        $this->restoredBetId = $bet->id;
        $this->notice = null;
    }

    /**
     * Delete one of the user's deleted bets for good, with its selections and tags.
     */
    public function forceDelete(int $betId): void
    {
        $bet = $this->deletedBet($betId);

        Gate::authorize('forceDelete', $bet);

        $bet->forceDelete();
        $this->restoredBetId = null;
        $this->notice = null;
    }

    /**
     * Delete all of the user's deleted bets for good; their selections, selection teams
     * and tag links go with them (cascading foreign keys).
     */
    public function forceDeleteAll(): void
    {
        $deleted = (int) DB::transaction(fn (): mixed => $this->user()->bets()->onlyTrashed()->forceDelete());

        $this->restoredBetId = null;
        $this->notice = ($deleted === 1 ? '1 bet' : "{$deleted} bets").' deleted for good.';
        $this->resetPage();
    }

    public function render(): View
    {
        $user = $this->user();
        $localTime = $user->localTime();

        $bets = $user->bets()
            ->onlyTrashed()
            ->with(['bookmaker', 'selections.competition'])
            ->latest('deleted_at')
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.settings.deleted-bets', [
            'daysKept' => Bet::DAYS_KEPT_DELETED,
            'restoredBet' => $this->restoredBetId === null ? null : $user->bets()->find($this->restoredBetId),
            'paginator' => $bets,
            'bets' => $bets->getCollection()->map(function (Bet $bet) use ($localTime): array {
                $card = BetCard::fromBet($bet, $localTime);
                $deletedAt = $localTime->toLocal($bet->deleted_at ?? now());
                $prunedAt = $localTime->toLocal($bet->prunedAt() ?? now());

                return [
                    'id' => $bet->id,
                    'title' => $card->title,
                    'meta' => $card->meta,
                    'amounts' => $card->stake.' · '.($card->result === null ? 'Open' : $card->result['label'].' '.$card->result['profit']),
                    'deleted' => 'Deleted '.$deletedAt->format('j M Y').' · can be restored until '.$prunedAt->format('j M Y'),
                ];
            }),
        ]);
    }

    /**
     * One of the user's deleted bets; another user's, or a bet that isn't deleted, is
     * "not found".
     */
    private function deletedBet(int $betId): Bet
    {
        return $this->user()->bets()->onlyTrashed()->findOrFail($betId);
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
