<?php

namespace App\Livewire\Bets;

use App\Domain\Bets\SettleBet;
use App\Domain\Decimal;
use App\Domain\Money;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Settlement\SettlementCalculator;
use App\Models\Bet;
use App\Models\Selection;
use App\Models\User;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One bet in full: its result and profit, the selections with result buttons and CLV,
 * a manual payout override, the bet's facts, and deleting it.
 */
#[Title('Bet')]
class Show extends Component
{
    #[Locked]
    public Bet $bet;

    /**
     * Whether the form for the amount the bookmaker actually paid is open.
     */
    public bool $editingPayout = false;

    /**
     * The amount actually paid, as typed.
     */
    public string $payout = '';

    public function mount(Bet $bet): void
    {
        Gate::authorize('view', $bet);

        $this->bet = $bet;
    }

    /**
     * Give one of the bet's selections a result, which settles the bet again.
     */
    public function setResult(int $selectionId, string $result, SettleBet $settleBet): void
    {
        $outcome = SelectionResult::tryFrom($result);

        abort_if($outcome === null, 422);

        $settleBet->setResult($this->selection($selectionId), $outcome);
        $this->editingPayout = false;
    }

    /**
     * Make one of the bet's selections pending again.
     */
    public function clearResult(int $selectionId, SettleBet $settleBet): void
    {
        $settleBet->setResult($this->selection($selectionId), null);
        $this->editingPayout = false;
    }

    /**
     * Open the form for the amount the bookmaker actually paid, starting from the
     * current payout.
     */
    public function editPayout(): void
    {
        Gate::authorize('update', $this->bet);

        if (! $this->bet->isSettled() || $this->bet->payout === null) {
            return;
        }

        $this->payout = Money::fromMinorUnits($this->bet->payout);
        $this->resetValidation();
        $this->editingPayout = true;
    }

    public function cancelPayout(): void
    {
        $this->editingPayout = false;
        $this->resetValidation();
    }

    /**
     * Replace the calculated payout with the amount the bookmaker actually paid.
     */
    public function savePayout(SettleBet $settleBet): void
    {
        Gate::authorize('update', $this->bet);

        $this->payout = Decimal::fromInput($this->payout);

        $this->validate([
            'payout' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! Money::isValidPayout($value)) {
                        $fail('Enter the amount paid, like 389.50 (0 or more).');
                    }
                },
            ],
        ], [
            'payout.required' => 'Enter the amount the bookmaker paid.',
        ]);

        if (! $this->bet->isSettled()) {
            $this->editingPayout = false;

            return;
        }

        $settleBet->overridePayout($this->bet, Money::toMinorUnits($this->payout));
        $this->editingPayout = false;
    }

    /**
     * Go back from the amount entered by hand to the calculated payout.
     */
    public function useCalculatedPayout(SettleBet $settleBet): void
    {
        Gate::authorize('update', $this->bet);

        $settleBet->discardManualPayout($this->bet);
    }

    public function delete(): void
    {
        Gate::authorize('delete', $this->bet);

        $this->bet->delete();

        session()->flash('status', 'Bet deleted.');

        $this->redirectRoute('bets.index', navigate: true);
    }

    public function render(SettlementCalculator $calculator): View
    {
        $this->bet->load(['bookmaker', 'tipster', 'tags', 'selections.sport', 'selections.competition', 'selections.market']);

        return view('livewire.bets.show', [
            'detail' => BetDetail::fromBet($this->bet, $this->user()->base_currency, $calculator),
        ]);
    }

    /**
     * One of this bet's selections, once the user may change the bet. It refers to this
     * page's bet, so settling it updates the bet shown.
     */
    private function selection(int $selectionId): Selection
    {
        Gate::authorize('update', $this->bet);

        return $this->bet->selections()->findOrFail($selectionId)->setRelation('bet', $this->bet);
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
