<?php

namespace App\Livewire\Bets;

use App\Domain\Bets\RecordBet;
use App\Models\Bet;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;

/**
 * The New bet form, empty or as a copy of one of the user's bets (`?from={bet}`).
 */
#[Title('New bet')]
class Create extends BetForm
{
    /**
     * Start from a copy of the bet in `?from`, else from the bookmaker and sport of the
     * user's latest bet; placed now in the user's time zone either way.
     */
    public function mount(): void
    {
        $sourceBet = $this->sourceBet();

        if ($sourceBet !== null) {
            $this->copyBet($sourceBet);
        } else {
            $user = $this->user();
            $latestBet = $user->bets()->with('selections.sport')->latest('placed_at')->latest('id')->first();
            $bookmakers = $this->availableBookmakers();

            $this->selectBookmaker($bookmakers->firstWhere('id', $latestBet?->bookmaker_id) ?? $bookmakers->first());
            $this->selections = [$this->emptySelection($latestBet?->selections->first()?->sport->name ?? '')];
        }

        $now = $this->user()->localTime()->now();

        $this->placed_date = $now->format(self::DATE);
        $this->placed_time = $now->format(self::TIME);
    }

    public function save(RecordBet $recordBet): void
    {
        $recordBet->handle($this->user(), $this->validatedBetData());

        session()->flash('status', 'Bet saved.');

        $this->redirectRoute('bets.index', navigate: true);
    }

    /**
     * Save the bet and stay on the form for the next one: bookmaker, placed date and
     * time, tipster, tags and each selection's sport and competition are kept; the rest
     * is cleared.
     */
    public function saveAndAddAnother(RecordBet $recordBet): void
    {
        $recordBet->handle($this->user(), $this->validatedBetData());

        $this->stake = '';
        $this->is_free_bet = false;
        $this->notes = '';
        $this->probability = '';
        $this->bet_value = '';
        $this->selections = array_map(fn (array $selection): array => [
            ...$this->emptySelection($selection['sport']),
            'competition' => $selection['competition'],
            'show_details' => $selection['competition'] !== '',
        ], $this->selections);

        session()->now('status', 'Bet saved.');

        $this->js('window.scrollTo(0, 0); history.replaceState(history.state, "", location.pathname)');
    }

    protected function editedBet(): ?Bet
    {
        return null;
    }

    /**
     * The bet named by `?from`, through the user's own bets: another user's bet, or one
     * that doesn't exist, is a 404.
     */
    private function sourceBet(): ?Bet
    {
        $from = request()->query('from');

        if ($from === null) {
            return null;
        }

        $betId = filter_var($from, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        abort_if($betId === false, 404);

        $bet = $this->user()->bets()->findOrFail($betId);

        Gate::authorize('view', $bet);

        return $bet;
    }

    /**
     * Fill the form with a copy of the bet: every field except the placed time, results
     * and closing odds (payout and the import reference aren't on the form). A bookmaker
     * that has been deactivated since gives way to the first active one and its rate.
     */
    private function copyBet(Bet $bet): void
    {
        $this->fillFromBet($bet);

        $this->selections = array_map(fn (array $selection): array => [
            ...$selection,
            'id' => null,
            'closing_odds' => '',
            'result' => '',
            'show_details' => $selection['competition'] !== '' || $selection['market'] !== '',
        ], $this->selections);

        $bookmakers = $this->availableBookmakers();

        if ($bookmakers->firstWhere('id', (int) $this->bookmaker_id) === null) {
            $this->selectBookmaker($bookmakers->first());
        }
    }
}
