<?php

namespace App\Domain\Statistics;

/**
 * What the breakdown table can split bets by. Sport, competition, team and market are
 * selection-level: an accumulator counts under every distinct value its selections have,
 * so rows can add up to more than the total. A bet with several tags counts under each
 * of them too.
 */
enum Dimension: string
{
    case Bookmaker = 'bookmaker';
    case Sport = 'sport';
    case Competition = 'competition';
    case Team = 'team';
    case Market = 'market';
    case Tipster = 'tipster';
    case Tag = 'tag';
    case BetType = 'type';
}
