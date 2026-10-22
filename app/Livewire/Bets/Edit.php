<?php

namespace App\Livewire\Bets;

use App\Domain\Bets\RecordBet;
use App\Domain\Decimal;
use App\Domain\Money;
use App\Domain\Odds;
use App\Models\Bet;
use App\Models\Selection;
use App\Models\Tag;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

/**
 * The bet form for an existing bet. Selections keep their results; saving a settled bet
 * settles it again when its stake, odds or selections change.
 */
#[Title('Edit bet')]
class Edit extends BetForm
{
    #[Locked]
    public Bet $bet;

    public function mount(Bet $bet): void
    {
        Gate::authorize('update', $bet);

        $bet->load(['tipster', 'tags', 'selections.sport', 'selections.competition', 'selections.market', 'selections.teams']);

        $this->bet = $bet;
        $this->bookmaker_id = (string) $bet->bookmaker_id;
        $this->placed_date = $bet->placed_at->format(self::DATE);
        $this->placed_time = $bet->placed_at->format(self::TIME);
        $this->stake = Money::fromMinorUnits($bet->stake);
        $this->exchange_rate = Decimal::normalize($bet->exchange_rate);
        $this->is_free_bet = $bet->is_free_bet;
        $this->tipster = $bet->tipster->name ?? '';
        $this->tags = array_values($bet->tags->map(fn (Tag $tag): string => $tag->name)->all());
        $this->notes = $bet->notes ?? '';
        $this->selections = array_values($bet->selections->map(fn (Selection $selection): array => [
            'id' => $selection->id,
            'sport' => $selection->sport->name,
            'competition' => $selection->competition->name ?? '',
            'market' => $selection->market->name ?? '',
            'teams' => array_values($selection->teams->map(fn (Team $team): string => $team->name)->all()),
            'team_name' => '',
            'event_name' => $selection->event_name,
            'event_starts_date' => $selection->event_starts_at?->format(self::DATE) ?? '',
            'event_starts_time' => $selection->event_starts_at?->format(self::TIME) ?? '',
            'pick' => $selection->pick,
            'odds' => Odds::format($selection->odds),
            'closing_odds' => $selection->closing_odds === null ? '' : Odds::format($selection->closing_odds),
            'show_details' => $selection->competition_id !== null || $selection->market_id !== null || $selection->closing_odds !== null,
        ])->all());

        $this->showDetails = $this->tipster !== '' || $this->tags !== [] || $this->notes !== '';
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
