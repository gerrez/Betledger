<?php

namespace App\Domain\Import\Sportmarket;

use App\Domain\Bets\BetData;
use App\Domain\Bets\SelectionData;
use App\Domain\Settlement\SelectionResult;
use Carbon\CarbonImmutable;

/**
 * The settled single a Sportmarket order becomes. List entries (sport, competition,
 * market, teams, tags) are names, as in the bet form. `payout` is what Sportmarket
 * reports (stake + P&L, but never below 0) and `manualPayout` is that same amount when it differs from the
 * payout calculated for `result`, else null. Amounts are minor units of the order's
 * currency. `warning` tells the user when the bet differs from what Sportmarket reported
 * (a loss larger than the stake is recorded as lost); it is also in the notes.
 */
final readonly class ImportedBet
{
    /**
     * @param  list<string>  $teams
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $externalRef,
        public CarbonImmutable $placedAt,
        public int $stake,
        public string $odds,
        public string $sport,
        public ?string $competition,
        public ?string $market,
        public array $teams,
        public string $eventName,
        public string $pick,
        public array $tags,
        public string $notes,
        public SelectionResult $result,
        public int $payout,
        public ?int $manualPayout,
        public CarbonImmutable $settledAt,
        public ?string $warning = null,
    ) {}

    /**
     * The bet as the bet form would enter it, at the bookmaker the user chose for the
     * import, with its result.
     *
     * @param  string  $exchangeRate  the bookmaker's rate
     */
    public function betData(int $bookmakerId, string $exchangeRate): BetData
    {
        return new BetData(
            bookmakerId: $bookmakerId,
            placedAt: $this->placedAt,
            stake: $this->stake,
            exchangeRate: $exchangeRate,
            isFreeBet: false,
            tipster: null,
            tags: $this->tags,
            notes: $this->notes,
            selections: [new SelectionData(
                sport: $this->sport,
                competition: $this->competition,
                market: $this->market,
                teams: $this->teams,
                eventName: $this->eventName,
                eventStartsAt: null,
                pick: $this->pick,
                odds: $this->odds,
                closingOdds: null,
                result: $this->result,
            )],
        );
    }
}
