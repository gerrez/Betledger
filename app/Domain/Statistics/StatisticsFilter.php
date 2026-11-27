<?php

namespace App\Domain\Statistics;

use App\Domain\BetStatus;
use App\Domain\BetType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Which bets statistics cover. Dates are calendar days, both included, each meaning that
 * whole day in the user's time zone (StatisticsQuery turns them into UTC instants with
 * the user's LocalTime): settled bets count by their event date (`event_at`), open bets
 * by the date they were placed. Selection-level filters
 * (sport, competition, team, market) match a bet when any of its selections matches.
 * A status limits the bets to open or settled ones, an odds band to the bets whose total
 * odds are in it. Null means no filter.
 *
 * An id of NONE picks the bets without such an entry: no tipster, no tags, or a selection
 * without a competition, market or teams (the "none" rows of a breakdown).
 */
final readonly class StatisticsFilter
{
    /**
     * The id that stands for "none"; no entry has it, ids start at 1.
     */
    public const NONE = 0;

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
        public ?OddsBand $oddsBand = null,
    ) {
        $this->from = $from?->startOfDay();
        $this->to = $to?->startOfDay();

        if ($this->from !== null && $this->to !== null && $this->from->greaterThan($this->to)) {
            throw new InvalidArgumentException('The date range must not end before it starts.');
        }
    }
}
