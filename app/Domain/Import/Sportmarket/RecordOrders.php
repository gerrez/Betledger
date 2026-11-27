<?php

namespace App\Domain\Import\Sportmarket;

use App\Domain\Bets\EventDate;
use App\Domain\Bets\ImportBatch;
use App\Domain\Bets\RecordBet;
use App\Domain\Bets\SettleBet;
use App\Models\Bookmaker;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
     * errors are left out. The settlement date and a manual payout come from the order;
     * the event date is the settlement date (the game date), as the order has no start time.
     * Every bet created gets the same new import batch id, so the import can be found
     * (and undone) as a whole.
     *
     * @param  list<ImportedOrder>  $orders  read for the bookmaker's currency
     * @return ImportBatch the batch, with the number of bets created
     */
    public function handle(User $user, Bookmaker $bookmaker, array $orders): ImportBatch
    {
        $batchId = (string) Str::ulid();

        $created = DB::transaction(function () use ($user, $bookmaker, $orders, $batchId): int {
            $created = 0;

            foreach ($orders as $order) {
                if ($order->bet === null) {
                    continue;
                }

                $this->record($user, $bookmaker, $order->bet, $batchId);
                $created++;
            }

            return $created;
        });

        return new ImportBatch($batchId, CarbonImmutable::now(), $created);
    }

    private function record(User $user, Bookmaker $bookmaker, ImportedBet $imported, string $batchId): void
    {
        $bet = $this->recordBet->handle($user, $imported->betData($bookmaker->id, $bookmaker->formattedExchangeRate()));

        $bet->external_ref = $imported->externalRef;
        $bet->import_batch = $batchId;
        $bet->settled_at = Carbon::instance($imported->settledAt);
        $bet->event_at = Carbon::instance(EventDate::forImport($bet->placed_at, $imported->settledAt));
        $bet->save();

        if ($imported->manualPayout !== null) {
            $this->settleBet->overridePayout($bet, $imported->manualPayout);
        }
    }
}
