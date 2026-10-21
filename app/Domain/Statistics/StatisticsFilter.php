<?php

namespace App\Domain\Statistics;

use App\Domain\BetStatus;
use App\Domain\BetType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Which bets statistics cover. Dates are whole days, both included: settled bets count by
 * their settlement date, open bets by the date they were placed. Selection-level filters
 * (sport, competition, team, market) match a bet when any of its selections matches.
 * A status limits the bets to open or settled ones. Null means no filter.
 */
final readonly class StatisticsFilter
{
    public ?CarbonImmutable $from;

    public ?CarbonImmutable $to;

    public function __construct(
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        public ?int $bookmakerId = null,
        public ?int $sportId = null,
        public ?int $competitionId = null,
        public ?int $teamId = null,
        public ?int $marketId = null,
        public ?int $tipsterId = null,
        public ?int $tagId = null,
        public ?BetType $type = null,
        public bool $includeFreeBets = true,
        public ?BetStatus $status = null,
    ) {
        $this->from = $from?->startOfDay();
        $this->to = $to?->startOfDay();

        if ($this->from !== null && $this->to !== null && $this->from->greaterThan($this->to)) {
            throw new InvalidArgumentException('The date range must not end before it starts.');
        }
    }
}
