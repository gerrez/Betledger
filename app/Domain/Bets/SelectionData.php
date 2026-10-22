<?php

namespace App\Domain\Bets;

use App\Domain\Settlement\SelectionResult;
use Carbon\CarbonInterface;

/**
 * A validated selection as entered in the bet form. Sport, competition, market and
 * teams are names; competition and teams are looked up within the sport. When editing a
 * bet, `id` is the selection it replaces (null for a new one). `result` is null while
 * the selection is pending.
 */
final readonly class SelectionData
{
    /**
     * @param  list<string>  $teams
     */
    public function __construct(
        public string $sport,
        public ?string $competition,
        public ?string $market,
        public array $teams,
        public string $eventName,
        public ?CarbonInterface $eventStartsAt,
        public string $pick,
        public string $odds,
        public ?string $closingOdds,
        public ?int $id = null,
        public ?SelectionResult $result = null,
    ) {}
}
