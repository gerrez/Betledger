<?php

namespace App\Domain\Export;

use App\Domain\Currency;
use App\Domain\Decimal;
use App\Domain\Money;
use App\Models\Bet;
use App\Models\Selection;
use App\Models\Tag;
use App\Models\Team;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Turns bets into the rows of BetLedger's CSV export: one row per selection, with the
 * bet's fields repeated on every row of the bet ("Export" in docs/spec.md). The format is
 * a contract, since importing it again (roadmap 4.6) reads the same columns.
 *
 * Bets must come with their bookmaker, tipster, tags and selections (with sport,
 * competition, market and teams) already loaded; nothing here queries the database.
 */
final class BetsCsv
{
    /** Joins the names of a bet's tags and of a selection's teams. */
    public const LIST_SEPARATOR = ' | ';

    public const YES = 'yes';

    public const NO = 'no';

    /** The columns, in order: the bet's fields first, then the selection's. */
    public const COLUMNS = [
        'bet_id',
        'external_ref',
        'type',
        'placed_at',
        'bookmaker',
        'currency',
        'base_currency',
        'exchange_rate',
        'stake',
        'is_free_bet',
        'total_odds',
        'probability',
        'bet_value',
        'status',
        'settled_at',
        'payout',
        'payout_is_manual',
        'profit',
        'tipster',
        'tags',
        'notes',
        'selection_position',
        'sport',
        'competition',
        'market',
        'teams',
        'event_name',
        'event_starts_at',
        'pick',
        'odds',
        'closing_odds',
        'result',
    ];

    /**
     * @param  Currency  $baseCurrency  the owner's base currency, which the bets' exchange rates convert to
     */
    public function __construct(private readonly Currency $baseCurrency) {}

    /**
     * @return list<string>
     */
    public static function header(): array
    {
        return self::COLUMNS;
    }

    /**
     * One row per selection of the bet, in the order of the selections. Each row has a
     * value for every column in COLUMNS, in the same order; empty strings stand for null.
     *
     * @return list<list<string>>
     */
    public function rows(Bet $bet): array
    {
        $betFields = $this->betFields($bet);

        return array_values($bet->selections->map(
            fn (Selection $selection): array => array_values([...$betFields, ...$this->selectionFields($selection)]),
        )->all());
    }

    /**
     * Minor units as a plain amount in major units: 1250 → "12.50", -5 → "-0.05".
     */
    public static function money(?int $minorUnits): string
    {
        return $minorUnits === null ? '' : Money::fromMinorUnits($minorUnits);
    }

    /**
     * A stored decimal (odds, rate, percentage) without trailing zeros, so the text is
     * the same whatever the database returns: "1.850" → "1.85", "1.00000000" → "1".
     */
    public static function decimal(?string $value): string
    {
        return $value === null ? '' : Decimal::normalize($value);
    }

    /**
     * An instant in UTC, ISO 8601 with a "Z" offset: "2026-11-23T14:05:00Z".
     */
    public static function dateTime(?DateTimeInterface $value): string
    {
        return $value === null ? '' : Carbon::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function boolean(bool $value): string
    {
        return $value ? self::YES : self::NO;
    }

    /**
     * Names joined with LIST_SEPARATOR, sorted by byte value so the order is the same on
     * every database.
     *
     * @param  list<string>  $names
     */
    public static function names(array $names): string
    {
        sort($names, SORT_STRING);

        return implode(self::LIST_SEPARATOR, $names);
    }

    /**
     * @return array<string, string>
     */
    private function betFields(Bet $bet): array
    {
        return [
            'bet_id' => (string) $bet->id,
            'external_ref' => $bet->external_ref ?? '',
            'type' => $bet->type->value,
            'placed_at' => self::dateTime($bet->placed_at),
            'bookmaker' => $bet->bookmaker->name,
            'currency' => $bet->currency->value,
            'base_currency' => $this->baseCurrency->value,
            'exchange_rate' => self::decimal($bet->exchange_rate),
            'stake' => self::money($bet->stake),
            'is_free_bet' => self::boolean($bet->is_free_bet),
            'total_odds' => self::decimal($bet->total_odds),
            'probability' => self::decimal($bet->probability),
            'bet_value' => self::decimal($bet->bet_value),
            'status' => $bet->status->value,
            'settled_at' => self::dateTime($bet->settled_at),
            'payout' => self::money($bet->payout),
            'payout_is_manual' => self::boolean($bet->payout_is_manual),
            'profit' => self::money($bet->profit),
            'tipster' => $bet->tipster->name ?? '',
            'tags' => self::names(array_values($bet->tags->map(fn (Tag $tag): string => $tag->name)->all())),
            'notes' => $bet->notes ?? '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function selectionFields(Selection $selection): array
    {
        return [
            'selection_position' => (string) $selection->position,
            'sport' => $selection->sport->name,
            'competition' => $selection->competition->name ?? '',
            'market' => $selection->market->name ?? '',
            'teams' => self::names(array_values($selection->teams->map(fn (Team $team): string => $team->name)->all())),
            'event_name' => $selection->event_name,
            'event_starts_at' => self::dateTime($selection->event_starts_at),
            'pick' => $selection->pick,
            'odds' => self::decimal($selection->odds),
            'closing_odds' => self::decimal($selection->closing_odds),
            'result' => $selection->result->value ?? '',
        ];
    }
}
