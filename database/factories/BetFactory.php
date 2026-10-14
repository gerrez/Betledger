<?php

namespace Database\Factories;

use App\Domain\BetStatus;
use App\Domain\BetType;
use App\Domain\Odds;
use App\Domain\Settlement\Leg;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Settlement\SettlementCalculator;
use App\Models\Bet;
use App\Models\Bookmaker;
use App\Models\Selection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * Bets come with their selections: an open single unless a state says otherwise. After
 * creating, the type, total odds and settlement columns are derived from the selections,
 * so they always agree with the settlement rules.
 *
 * @extends Factory<Bet>
 */
class BetFactory extends Factory
{
    /**
     * Define the model's default state: an open cash bet at a bookmaker of the same user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bookmaker_id' => fn (array $attributes) => Bookmaker::factory()->create(['user_id' => $attributes['user_id']])->id,
            'tipster_id' => null,
            'type' => BetType::Single,
            'placed_at' => fake()->dateTimeBetween('-60 days', '-1 day'),
            'stake' => fake()->numberBetween(1, 40) * 500,
            'currency' => fn (array $attributes) => Bookmaker::findOrFail($attributes['bookmaker_id'])->currency,
            'exchange_rate' => fn (array $attributes) => Bookmaker::findOrFail($attributes['bookmaker_id'])->exchange_rate,
            'is_free_bet' => false,
            'total_odds' => '1.0100', // replaced by the selections' total after creating
            'status' => BetStatus::Open,
            'settled_at' => null,
            'payout' => null,
            'payout_is_manual' => false,
            'profit' => null,
            'notes' => null,
        ];
    }

    /**
     * Give a bet without selections a single one, then derive the rest from them.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Bet $bet): void {
            if ($bet->selections()->doesntExist()) {
                Selection::factory()->for($bet)->create();
            }

            $this->syncWithSelections($bet);
        });
    }

    /**
     * A bet at the given bookmaker, owned by the bookmaker's user, in its currency and rate.
     */
    public function forBookmaker(Bookmaker $bookmaker): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $bookmaker->user_id,
            'bookmaker_id' => $bookmaker->id,
            'currency' => $bookmaker->currency,
            'exchange_rate' => $bookmaker->exchange_rate,
        ]);
    }

    /**
     * A single at the given odds.
     */
    public function single(string $odds = '2.10'): static
    {
        return $this->has(Selection::factory()->state(['position' => 1, 'odds' => $odds]), 'selections');
    }

    /**
     * An accumulator with one selection per given odds, in order.
     *
     * @param  list<string>  $odds
     */
    public function accumulator(array $odds = ['1.50', '2.00', '1.80']): static
    {
        $selections = Selection::factory()
            ->count(count($odds))
            ->sequence(fn (Sequence $sequence) => [
                'position' => $sequence->index + 1,
                'odds' => $odds[$sequence->index],
            ]);

        return $this->has($selections, 'selections');
    }

    /**
     * A free bet: the stake is not returned.
     */
    public function freeBet(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_free_bet' => true,
        ]);
    }

    /**
     * A settled bet: every selection gets the given result.
     */
    public function settled(SelectionResult $result = SelectionResult::Won): static
    {
        return $this->afterCreating(function (Bet $bet) use ($result): void {
            $bet->selections()->update(['result' => $result->value]);

            $this->syncWithSelections($bet);
        });
    }

    /**
     * Give the selections these results in order (null = pending). The bet is settled
     * when the settlement rules say so, e.g. as soon as one selection is lost.
     */
    public function withResults(?SelectionResult ...$results): static
    {
        return $this->afterCreating(function (Bet $bet) use ($results): void {
            foreach ($bet->selections()->get() as $index => $selection) {
                $selection->update(['result' => $results[$index] ?? null]);
            }

            $this->syncWithSelections($bet);
        });
    }

    /**
     * Derive the type, total odds, status, payout, profit and settlement date from the
     * bet's selections.
     */
    private function syncWithSelections(Bet $bet): void
    {
        $selections = $bet->selections()->get();
        $legs = $selections->map(fn (Selection $selection) => new Leg($selection->odds, $selection->result))->all();
        $settlement = (new SettlementCalculator)->settle($bet->stake, $bet->is_free_bet, $legs);

        $bet->type = BetType::forSelectionCount($selections->count());
        $bet->total_odds = Odds::total($selections->pluck('odds')->all());
        $bet->status = $settlement === null ? BetStatus::Open : BetStatus::Settled;
        $bet->settled_at = $settlement === null ? null : ($bet->settled_at ?? $bet->placed_at->copy()->addHours(3));
        $bet->payout = $settlement?->payout;
        $bet->profit = $settlement?->profit;
        $bet->payout_is_manual = false;
        $bet->save();
    }
}
