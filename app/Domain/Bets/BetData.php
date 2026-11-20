<?php

namespace App\Domain\Bets;

use Carbon\CarbonInterface;

/**
 * A validated bet as entered in the bet form. List entries (tipster, tags) are names:
 * RecordBet matches them to the user's own entries or adds new ones.
 */
final readonly class BetData
{
    /**
     * @param  int  $stake  minor units of the bookmaker's currency
     * @param  string  $exchangeRate  1 unit of the bookmaker's currency = this many units of the base currency
     * @param  list<string>  $tags
     * @param  list<SelectionData>  $selections
     * @param  string|null  $probability  a percentage, e.g. "55.5"
     * @param  string|null  $betValue  a percentage as entered; ignored when there is a probability, from which it is calculated
     */
    public function __construct(
        public int $bookmakerId,
        public CarbonInterface $placedAt,
        public int $stake,
        public string $exchangeRate,
        public bool $isFreeBet,
        public ?string $tipster,
        public array $tags,
        public ?string $notes,
        public array $selections,
        public ?string $probability = null,
        public ?string $betValue = null,
    ) {}
}
