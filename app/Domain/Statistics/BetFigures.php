<?php

namespace App\Domain\Statistics;

use App\Domain\BetType;
use App\Domain\ClosingLineValue;
use App\Domain\Decimal;
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
     * @param  string|null  $factor  the bet factor by the settlement rules, null while open
     * @param  string  $exchangeRate  the bet's own rate: 1 unit of its currency = this many units of the base currency
     * @param  list<string|null>  $closingOdds  one per selection, null when it has none
     * @param  array<value-of<Dimension>, list<Group>>  $groups  what the bet counts under per dimension (bet type excepted, which follows from $type); a dimension left out counts it under none
     */
    public function __construct(
        int $stake,
        ?int $profit,
        public ?string $factor,
        string $exchangeRate,
        public BetType $type,
        public bool $isFreeBet,
        public string $totalOdds,
        public ?CarbonImmutable $settledAt,
        array $closingOdds = [],
        public array $groups = [],
    ) {
        if (($profit === null) !== ($settledAt === null) || ($factor === null) !== ($settledAt === null)) {
            throw new InvalidArgumentException('A settled bet has a profit, a factor and a settlement date; an open bet has none of them.');
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
     * Whether the bet counts as a win in the strike rate: it made a profit.
     */
    public function isWin(): bool
    {
        return $this->profit > 0;
    }

    /**
     * Whether the bet counts as a loss in the strike rate: it lost money, or it is a
     * free bet that came back with nothing because it lost (bet factor below 1, e.g. a
     * lost or ½ lost free bet). A void bet is neither a win nor a loss.
     */
    public function isLoss(): bool
    {
        return $this->profit < 0
            || ($this->isFreeBet && $this->profit === 0 && $this->factor !== null && Decimal::compare($this->factor, '1') < 0);
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
