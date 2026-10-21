<?php

namespace App\Domain\Statistics;

use App\Domain\BetType;
use App\Domain\ClosingLineValue;
use App\Domain\Money;
use App\Domain\Odds;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A bet as far as statistics are concerned, with its stake and profit converted to the
 * user's base currency with the bet's own exchange rate (rounded half up per amount).
 */
final readonly class BetFigures
{
    /** Stake in minor units of the base currency. */
    public int $stake;

    /** Profit in minor units of the base currency; null while the bet is open. */
    public ?int $profit;

    /** Closing line value as a fraction; null unless every selection has closing odds. */
    public ?string $clv;

    /**
     * @param  int  $stake  minor units of the bet's currency
     * @param  int|null  $profit  minor units of the bet's currency, null while open
     * @param  string  $exchangeRate  the bet's own rate: 1 unit of its currency = this many units of the base currency
     * @param  list<string|null>  $closingOdds  one per selection, null when it has none
     * @param  array<value-of<Dimension>, list<Group>>  $groups  what the bet counts under per dimension (bet type excepted, which follows from $type); a dimension left out counts it under none
     */
    public function __construct(
        int $stake,
        ?int $profit,
        string $exchangeRate,
        public BetType $type,
        public bool $isFreeBet,
        public string $totalOdds,
        public ?CarbonImmutable $settledAt,
        array $closingOdds = [],
        public array $groups = [],
    ) {
        if (($profit === null) !== ($settledAt === null)) {
            throw new InvalidArgumentException('A settled bet has a profit and a settlement date; an open bet has neither.');
        }

        Odds::assertValid($totalOdds);

        $this->stake = Money::convert($stake, $exchangeRate);
        $this->profit = $profit === null ? null : Money::convert($profit, $exchangeRate);
        $this->clv = ClosingLineValue::forBet($totalOdds, $closingOdds);
    }

    public function isSettled(): bool
    {
        return $this->settledAt !== null;
    }

    /**
     * What the bet counts under in a breakdown by the dimension, each value once.
     *
     * @return list<Group>
     */
    public function groupsIn(Dimension $dimension): array
    {
        if ($dimension === Dimension::BetType) {
            return [new Group($this->type->value, match ($this->type) {
                BetType::Single => 'Singles',
                BetType::Accumulator => 'Accumulators',
            })];
        }

        $unique = [];

        foreach ($this->groups[$dimension->value] ?? [] as $group) {
            $unique[$group->key ?? ''] ??= $group;
        }

        return $unique === [] ? [Group::none()] : array_values($unique);
    }
}
