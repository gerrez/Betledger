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
