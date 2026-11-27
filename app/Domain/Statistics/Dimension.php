<?php

namespace App\Domain\Statistics;

/**
 * What the breakdown table can split bets by. Sport, competition, team and market are
 * selection-level: an accumulator counts under every distinct value its selections have,
 * so rows can add up to more than the total. A bet with several tags counts under each
 * of them too. Month and weekday follow from the bet's event date (`BetFigures::$eventAt`,
 * already in the user's time zone).
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
    case Month = 'month';
    case Weekday = 'weekday';

    public function label(): string
    {
        return match ($this) {
            self::BetType => 'Bet type',
            default => ucfirst($this->value),
        };
    }

    /**
     * Whether a bet can be without a value: no competition, teams, market, tipster or
     * tags. Every bet has a bookmaker, sport, bet type and event date.
     */
    public function hasNone(): bool
    {
        return ! in_array($this, [self::Bookmaker, self::Sport, self::BetType, self::Month, self::Weekday], true);
    }

    /**
     * What the row of bets without a value is called; bookmaker, sport, bet type, month
     * and weekday always have one.
     */
    public function noneLabel(): string
    {
        return match ($this) {
            self::Competition => 'No competition',
            self::Team => 'No teams',
            self::Market => 'No market',
            self::Tipster => 'No tipster',
            self::Tag => 'No tags',
            self::Bookmaker, self::Sport, self::BetType, self::Month, self::Weekday => 'None',
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

    /**
     * Whether the values are dates, whose own order the breakdown shows by default and
     * breaks ties with: months newest first, weekdays Monday to Sunday. The other
     * dimensions' values are names, ordered by label.
     */
    public function isCalendar(): bool
    {
        return in_array($this, [self::Month, self::Weekday], true);
    }
}
