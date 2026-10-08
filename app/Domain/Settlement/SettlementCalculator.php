<?php

namespace App\Domain\Settlement;

use App\Domain\Decimal;
use InvalidArgumentException;

/**
 * Settlement rules from docs/spec.md: selection factors, the bet factor, when a bet is
 * settled, and payout/profit for cash and free bets.
 */
final class SettlementCalculator
{
    /**
     * A bet is settled as soon as any selection is lost, or once every selection has a
     * result.
     *
     * @param  list<Leg>  $legs
     */
    public function isSettled(array $legs): bool
    {
        $this->assertHasLegs($legs);

        foreach ($legs as $leg) {
            if ($leg->result === SelectionResult::Lost) {
                return true;
            }
        }

        foreach ($legs as $leg) {
            if ($leg->isPending()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The bet factor: the product of the selection factors, or 0 when any selection
     * is lost. Null while the bet is not settled.
     *
     * @param  list<Leg>  $legs
     */
    public function factor(array $legs): ?string
    {
        if (! $this->isSettled($legs)) {
            return null;
        }

        $factor = '1';

        foreach ($legs as $leg) {
            if ($leg->result === SelectionResult::Lost) {
                return '0';
            }

            $factor = Decimal::multiply($factor, (string) $leg->factor());
        }

        return Decimal::normalize($factor);
    }

    /**
     * Settle a bet, or return null when it is not settled yet.
     *
     * @param  int  $stake  minor units
     * @param  list<Leg>  $legs
     */
    public function settle(int $stake, bool $isFreeBet, array $legs): ?Settlement
    {
        $this->assertValidStake($stake);
        $factor = $this->factor($legs);

        if ($factor === null) {
            return null;
        }

        $payout = $this->payout($stake, $factor, $isFreeBet);

        return new Settlement($factor, $payout, $this->profit($stake, $payout, $isFreeBet));
    }

    /**
     * Cash bet: stake × factor. Free bet (stake not returned): stake × max(factor − 1, 0).
     * Rounded half up to whole minor units, once.
     *
     * @param  int  $stake  minor units
     * @return int minor units
     */
    public function payout(int $stake, string $factor, bool $isFreeBet): int
    {
        $this->assertValidStake($stake);

        if (Decimal::compare($factor, '0') < 0) {
            throw new InvalidArgumentException("[{$factor}] is not a valid bet factor.");
        }

        $multiplier = $isFreeBet ? Decimal::max(Decimal::subtract($factor, '1'), '0') : $factor;

        return Decimal::toInteger(Decimal::multiply((string) $stake, $multiplier));
    }

    /**
     * Cash bet: payout − stake. Free bet: the payout itself. Also used when the user
     * replaces the calculated payout with the amount actually paid.
     *
     * @param  int  $stake  minor units
     * @param  int  $payout  minor units
     * @return int minor units
     */
    public function profit(int $stake, int $payout, bool $isFreeBet): int
    {
        $this->assertValidStake($stake);

        if ($payout < 0) {
            throw new InvalidArgumentException('The payout cannot be negative.');
        }

        return $isFreeBet ? $payout : $payout - $stake;
    }

    /**
     * @param  array<Leg>  $legs
     */
    private function assertHasLegs(array $legs): void
    {
        if ($legs === []) {
            throw new InvalidArgumentException('A bet needs at least one selection.');
        }
    }

    private function assertValidStake(int $stake): void
    {
        if ($stake <= 0) {
            throw new InvalidArgumentException('The stake must be greater than zero.');
        }
    }
}
