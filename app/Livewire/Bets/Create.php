<?php

namespace App\Livewire\Bets;

use App\Domain\Bets\RecordBet;
use App\Models\Bet;
use Livewire\Attributes\Title;

/**
 * The New bet form.
 */
#[Title('New bet')]
class Create extends BetForm
{
    /**
     * Start from the bookmaker and sport of the user's latest bet, placed now.
     */
    public function mount(): void
    {
        $user = $this->user();
        $latestBet = $user->bets()->with('selections.sport')->latest('placed_at')->latest('id')->first();
        $bookmakers = $this->availableBookmakers();

        $bookmaker = $bookmakers->firstWhere('id', $latestBet?->bookmaker_id) ?? $bookmakers->first();

        $this->placed_date = now()->format(self::DATE);
        $this->placed_time = now()->format(self::TIME);
        $this->selectBookmaker($bookmaker);
        $this->selections = [$this->emptySelection($latestBet?->selections->first()?->sport->name ?? '')];
    }

    public function save(RecordBet $recordBet): void
    {
        $recordBet->handle($this->user(), $this->validatedBetData());

        session()->flash('status', 'Bet saved.');

        $this->redirectRoute('bets.index', navigate: true);
    }

    protected function editedBet(): ?Bet
    {
        return null;
    }
}
