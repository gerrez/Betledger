<?php

namespace App\Domain\Statistics;

use App\Domain\Money;
use InvalidArgumentException;

/**
 * The bets open now and their stake in minor units of the base currency, the same
 * figures as Totals::$openBets and Totals::$openStake, computed from bets grouped by
 * stake and exchange rate instead of one BetFigures per bet: every bet's stake is still
 * converted (and rounded) on its own, as BetFigures does, and free bet stakes, which
 * aren't money at risk, are left out of the stake but counted as bets.
 */
final readonly class OpenStake
{
    public function __construct(
        public int $bets,
        public int $stake,
    ) {}

    /**
     * @param  iterable<array{stake: int, exchangeRate: string, isFreeBet: bool, bets: int}>  $groups  open bets with the same stake (minor units of their currency), exchange rate and free-bet flag, and how many there are
     */
    public static function fromGroups(iterable $groups): self
    {
        $bets = 0;
        $stake = 0;

        foreach ($groups as $group) {
            if ($group['bets'] < 1) {
                throw new InvalidArgumentException('A group has at least one bet.');
            }

            $bets += $group['bets'];

            if (! $group['isFreeBet']) {
                $stake += $group['bets'] * Money::convert($group['stake'], $group['exchangeRate']);
            }
        }

        return new self($bets, $stake);
    }
}
