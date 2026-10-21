<?php

namespace App\Domain\Bets;

use App\Domain\BetStatus;
use App\Domain\Settlement\Leg;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Settlement\SettlementCalculator;
use App\Models\Bet;
use App\Models\Selection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Apply selection results to a bet by the settlement rules in docs/spec.md: status,
 * payout, profit and settlement date follow from the results, settling is reversible,
 * and a settled bet's payout can be replaced with the amount the bookmaker actually
 * paid until a result changes. Callers authorize the user first (BetPolicy::update).
 */
final class SettleBet
{
    public function __construct(private SettlementCalculator $calculator) {}

    /**
     * Give a selection a result, or clear it (null = pending), and settle its bet again.
     * Changing a result discards a manual payout; setting the result it already has
     * changes nothing.
     */
    public function setResult(Selection $selection, ?SelectionResult $result): Bet
    {
        return DB::transaction(function () use ($selection, $result): Bet {
            if ($selection->result === $result) {
                return $selection->bet;
            }

            $selection->update(['result' => $result]);

            return $this->recalculate($selection->bet);
        });
    }

    /**
     * Clear the result of every selection, which makes the bet open again.
     */
    public function clearResults(Bet $bet): Bet
    {
        return DB::transaction(function () use ($bet): Bet {
            if ($bet->selections()->whereNotNull('result')->doesntExist()) {
                return $bet;
            }

            $bet->selections()->update(['result' => null]);

            return $this->recalculate($bet);
        });
    }

    /**
     * Replace a settled bet's calculated payout with the amount the bookmaker actually
     * paid (rounding, max-payout caps). Profit follows from it as for a calculated payout.
     *
     * @param  int  $payout  minor units of the bet's currency
     */
    public function overridePayout(Bet $bet, int $payout): Bet
    {
        if (! $bet->isSettled()) {
            throw new InvalidArgumentException('Only a settled bet can have a manual payout.');
        }

        $bet->profit = $this->calculator->profit($bet->stake, $payout, $bet->is_free_bet);
        $bet->payout = $payout;
        $bet->payout_is_manual = true;
        $bet->save();

        return $bet;
    }

    /**
     * Go back from a manual payout to the calculated one.
     */
    public function discardManualPayout(Bet $bet): Bet
    {
        return $bet->payout_is_manual ? $this->recalculate($bet) : $bet;
    }

    /**
     * Status, payout, profit and settlement date from the selections' results, discarding
     * a manual payout. A bet that becomes settled is settled now; one that was already
     * settled keeps its settlement date; one that is no longer settled is open again.
     */
    public function recalculate(Bet $bet): Bet
    {
        $legs = $bet->load('selections')->selections
            ->map(fn (Selection $selection): Leg => new Leg($selection->odds, $selection->result))
            ->all();
        $settlement = $this->calculator->settle($bet->stake, $bet->is_free_bet, array_values($legs));

        $bet->status = $settlement === null ? BetStatus::Open : BetStatus::Settled;
        $bet->settled_at = $settlement === null ? null : ($bet->settled_at ?? now());
        $bet->payout = $settlement?->payout;
        $bet->profit = $settlement?->profit;
        $bet->payout_is_manual = false;
        $bet->save();

        return $bet;
    }
}
