<?php

namespace App\Domain\Statistics;

use App\Domain\Decimal;
use Carbon\CarbonImmutable;

/**
 * Statistics rules from docs/spec.md over bets already reduced to BetFigures (in the
 * base currency, filtered): totals, breakdowns, odds bands and profit over time.
 */
final class StatisticsCalculator
{
    /**
     * @param  list<BetFigures>  $bets
     */
    public function totals(array $bets): Totals
    {
        $settled = array_values(array_filter($bets, fn (BetFigures $bet): bool => $bet->isSettled()));
        $open = array_values(array_filter($bets, fn (BetFigures $bet): bool => ! $bet->isSettled()));
        $clvs = array_values(array_filter(array_map(fn (BetFigures $bet): ?string => $bet->clv, $settled), fn (?string $clv): bool => $clv !== null));

        $turnover = $this->sumOfStakes(array_filter($settled, fn (BetFigures $bet): bool => ! $bet->isFreeBet));
        $profit = array_sum(array_map(fn (BetFigures $bet): int => $bet->profit ?? 0, $settled));
        $winners = count(array_filter($settled, fn (BetFigures $bet): bool => $bet->isWin()));
        $losers = count(array_filter($settled, fn (BetFigures $bet): bool => $bet->isLoss()));
        $beatClose = count(array_filter($clvs, fn (string $clv): bool => Decimal::compare($clv, '0') > 0));

        return new Totals(
            settledBets: count($settled),
            openBets: count($open),
            turnover: $turnover,
            profit: $profit,
            roi: $this->ratio($profit, $turnover),
            strikeRate: $this->ratio($winners, $winners + $losers),
            winningBets: $winners,
            losingBets: $losers,
            averageOdds: $this->mean(array_map(fn (BetFigures $bet): string => $bet->totalOdds, $settled)),
            averageClv: $this->mean($clvs),
            clvBets: count($clvs),
            beatCloseBets: $beatClose,
            beatCloseRate: $this->ratio($beatClose, count($clvs)),
            openStake: $this->sumOfStakes(array_filter($open, fn (BetFigures $bet): bool => ! $bet->isFreeBet)),
        );
    }

    /**
     * The totals per value of the dimension, most settled bets first (then by label);
     * bets without a value come last. An accumulator counts under every distinct value
     * its selections have, a bet with several tags under each tag.
     *
     * @param  list<BetFigures>  $bets
     * @return list<BreakdownRow>
     */
    public function breakdown(array $bets, Dimension $dimension): array
    {
        /** @var array<int|string, array{group: Group, bets: list<BetFigures>}> $groups */
        $groups = [];

        foreach ($bets as $bet) {
            foreach ($bet->groupsIn($dimension) as $group) {
                $groups[$group->key ?? ''] ??= ['group' => $group, 'bets' => []];
                $groups[$group->key ?? '']['bets'][] = $bet;
            }
        }

        $rows = array_map(fn (array $entry): BreakdownRow => new BreakdownRow($entry['group'], $this->totals($entry['bets'])), array_values($groups));

        usort($rows, fn (BreakdownRow $a, BreakdownRow $b): int => [$a->group->isNone(), $b->totals->settledBets, mb_strtolower($a->group->label ?? '')]
            <=> [$b->group->isNone(), $a->totals->settledBets, mb_strtolower($b->group->label ?? '')]);

        return $rows;
    }

    /**
     * The totals per band of total odds, every band in order, also the empty ones.
     *
     * @param  list<BetFigures>  $bets
     * @return list<OddsBandRow>
     */
    public function oddsBands(array $bets): array
    {
        return array_map(fn (OddsBand $band): OddsBandRow => new OddsBandRow($band, $this->totals(array_values(array_filter(
            $bets,
            fn (BetFigures $bet): bool => OddsBand::forOdds($bet->totalOdds) === $band,
        )))), OddsBand::cases());
    }

    /**
     * Cumulative profit of the settled bets by settlement date, one point per period
     * (empty ones included) from the start of the range, or the first settled bet, to
     * its end, or the last settled bet. The period follows from the range unless given.
     * No settled bets, no points.
     *
     * @param  list<BetFigures>  $bets
     */
    public function profitOverTime(array $bets, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, ?Period $period = null): ProfitSeries
    {
        /** @var array<string, int> $profitByDay profit per settlement day, earliest first */
        $profitByDay = [];

        foreach ($bets as $bet) {
            if ($bet->settledAt !== null) {
                $day = $bet->settledAt->toDateString();
                $profitByDay[$day] = ($profitByDay[$day] ?? 0) + ($bet->profit ?? 0);
            }
        }

        ksort($profitByDay);
        $days = array_keys($profitByDay);
        $from = $this->earliest($from, $days === [] ? null : CarbonImmutable::parse($days[0]));
        $to = $this->latest($to, $days === [] ? null : CarbonImmutable::parse($days[count($days) - 1]));
        $period ??= $from !== null && $to !== null ? Period::forRange($from, $to) : Period::Day;

        if ($from === null || $to === null || $days === []) {
            return new ProfitSeries($period, []);
        }

        $profitByPeriod = [];

        foreach ($profitByDay as $day => $profit) {
            $start = $period->start(CarbonImmutable::parse($day))->toDateString();
            $profitByPeriod[$start] = ($profitByPeriod[$start] ?? 0) + $profit;
        }

        $points = [];
        $cumulative = 0;

        for ($start = $period->start($from); $start->lessThanOrEqualTo($to); $start = $period->next($start)) {
            $profit = $profitByPeriod[$start->toDateString()] ?? 0;
            $cumulative += $profit;
            $points[] = new ProfitPoint($start, $profit, $cumulative);
        }

        return new ProfitSeries($period, $points);
    }

    /**
     * @param  iterable<BetFigures>  $bets
     */
    private function sumOfStakes(iterable $bets): int
    {
        $sum = 0;

        foreach ($bets as $bet) {
            $sum += $bet->stake;
        }

        return $sum;
    }

    private function ratio(int $numerator, int $denominator): ?string
    {
        return $denominator === 0 ? null : Decimal::divide((string) $numerator, (string) $denominator);
    }

    /**
     * @param  list<string>  $values  decimal strings
     */
    private function mean(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $sum = '0';

        foreach ($values as $value) {
            $sum = Decimal::add($sum, $value);
        }

        return Decimal::divide($sum, (string) count($values));
    }

    private function earliest(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        return $a === null || ($b !== null && $b->lessThan($a)) ? $b : $a;
    }

    private function latest(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        return $a === null || ($b !== null && $b->greaterThan($a)) ? $b : $a;
    }
}
