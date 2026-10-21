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

    public function label(): string
    {
        return match ($this) {
            self::BetType => 'Bet type',
            default => ucfirst($this->value),
        };
    }

    /**
     * What the row of bets without a value is called; bookmaker, sport and bet type
     * always have one.
     */
    public function noneLabel(): string
    {
        return match ($this) {
            self::Competition => 'No competition',
            self::Team => 'No teams',
            self::Market => 'No market',
            self::Tipster => 'No tipster',
            self::Tag => 'No tags',
            self::Bookmaker, self::Sport, self::BetType => 'None',
        };
    }

    /**
     * Whether a bet can count under several values, so rows can add up to more than
     * the total: the selection-level dimensions and tags.
     */
    public function countsBetsMoreThanOnce(): bool
    {
        return in_array($this, [self::Sport, self::Competition, self::Team, self::Market, self::Tag], true);
    }
}
