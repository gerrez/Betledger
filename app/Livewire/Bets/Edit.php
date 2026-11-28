<?php

namespace App\Livewire\Bets;

use App\Domain\Bets\RecordBet;
use App\Models\Bet;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

/**
 * The bet form for an existing bet, with result buttons per selection. Saving settles
 * the bet again when its stake, odds, results or selections change.
 */
#[Title('Edit bet')]
class Edit extends BetForm
{
    #[Locked]
    public Bet $bet;

    public function mount(Bet $bet): void
    {
        Gate::authorize('update', $bet);

        $this->bet = $bet;
        $this->fillFromBet($bet);
    }

    /**
     * Livewire reloads the bet without its "not deleted" scope, so a bet deleted in
     * another tab would still be found: this form must not save it.
     */
    public function hydrate(): void
    {
        abort_if($this->bet->trashed(), 404);
    }

    public function save(RecordBet $recordBet): void
    {
        Gate::authorize('update', $this->bet);

        $recordBet->update($this->bet, $this->validatedBetData());

        session()->flash('status', 'Bet updated.');

        $this->redirectRoute('bets.show', $this->bet, navigate: true);
    }

    protected function editedBet(): Bet
    {
        return $this->bet;
    }
}
