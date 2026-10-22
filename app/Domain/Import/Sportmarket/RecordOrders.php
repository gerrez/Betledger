<?php

namespace App\Domain\Import\Sportmarket;

use App\Domain\Bets\RecordBet;
use App\Domain\Bets\SettleBet;
use App\Models\Bookmaker;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Save the bets of an import read with SportmarketImport, through the same services as
 * the bet form and settling, in one transaction: either every bet is saved or none is.
 */
final class RecordOrders
{
    public function __construct(
        private RecordBet $recordBet,
        private SettleBet $settleBet,
    ) {}

    /**
     * Create a settled bet at the bookmaker for every order to import; skipped orders and
     * errors are left out. The settlement date and a manual payout come from the order.
     *
     * @param  list<ImportedOrder>  $orders  read for the bookmaker's currency
     * @return int the number of bets created
     */
    public function handle(User $user, Bookmaker $bookmaker, array $orders): int
    {
        return DB::transaction(function () use ($user, $bookmaker, $orders): int {
            $created = 0;

            foreach ($orders as $order) {
                if ($order->bet === null) {
                    continue;
                }

                $this->record($user, $bookmaker, $order->bet);
                $created++;
            }

            return $created;
        });
    }

    private function record(User $user, Bookmaker $bookmaker, ImportedBet $imported): void
    {
        $bet = $this->recordBet->handle($user, $imported->betData($bookmaker->id, $bookmaker->formattedExchangeRate()));

        $bet->external_ref = $imported->externalRef;
        $bet->settled_at = Carbon::instance($imported->settledAt);
        $bet->save();

        if ($imported->manualPayout !== null) {
            $this->settleBet->overridePayout($bet, $imported->manualPayout);
        }
    }
}
