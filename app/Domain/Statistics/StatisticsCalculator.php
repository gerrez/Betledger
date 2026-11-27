<?php

namespace App\Domain\Statistics;

use App\Domain\Decimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Statistics rules from docs/spec.md over bets already reduced to BetFigures (in the
 * base currency, filtered): totals, breakdowns, odds bands, profit over time and risk
 * figures.
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
        $decided = array_values(array_filter($settled, fn (BetFigures $bet): bool => $bet->isWin() || $bet->isLoss()));
        $winners = count(array_filter($decided, fn (BetFigures $bet): bool => $bet->isWin()));
        $losers = count($decided) - $winners;
        $settledOdds = array_map(fn (BetFigures $bet): string => $bet->totalOdds, $settled);
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
            breakEvenStrikeRate: $this->mean(array_map(fn (BetFigures $bet): string => Decimal::divide('1', $bet->totalOdds), $decided)),
            averageOdds: $this->mean($settledOdds),
            medianOdds: $this->median($settledOdds),
            averageClv: $this->mean($clvs),
            clvBets: count($clvs),
            beatCloseBets: $beatClose,
            beatCloseRate: $this->ratio($beatClose, count($clvs)),
            openStake: $this->sumOfStakes(array_filter($open, fn (BetFigures $bet): bool => ! $bet->isFreeBet)),
        );
    }

    /**
     * The totals per value of the dimension. An accumulator counts under every distinct
     * value its selections have, a bet with several tags under each tag.
     *
     * Unsorted, rows come in the dimension's own order: months newest first, weekdays
     * Monday to Sunday, other dimensions most settled bets first (then by label), with
     * the bets without a value last. Sorted by a column, rows go by its value, ascending
     * or descending, the "none" row among them; rows without a value for the column come
     * last, and ties keep the dimension's order of values (label, month, weekday).
     *
     * @param  list<BetFigures>  $bets
     * @return list<BreakdownRow>
     */
    public function breakdown(array $bets, Dimension $dimension, ?BreakdownSort $sort = null, bool $ascending = false): array
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

        usort($rows, fn (BreakdownRow $a, BreakdownRow $b): int => match (true) {
            $sort !== null => $this->bySortedColumn($a, $b, $sort, $ascending) ?: $this->byValue($a->group, $b->group, $dimension),
            $dimension->isCalendar() => $this->byValue($a->group, $b->group, $dimension),
            default => [$a->group->isNone(), $b->totals->settledBets] <=> [$b->group->isNone(), $a->totals->settledBets]
                ?: $this->byValue($a->group, $b->group, $dimension),
        });

        return $rows;
    }

    /**
     * Rows by the column's value in the direction; a row without a value after one with.
     */
    private function bySortedColumn(BreakdownRow $a, BreakdownRow $b, BreakdownSort $sort, bool $ascending): int
    {
        $first = $sort->valueOf($a->totals);
        $second = $sort->valueOf($b->totals);

        if ($first === null || $second === null) {
            return ($first === null) <=> ($second === null);
        }

        return $ascending ? $sort->compare($first, $second) : $sort->compare($second, $first);
    }

    /**
     * The dimension's own order of values: months newest first, weekdays Monday to
     * Sunday, names by label ignoring case; none last.
     */
    private function byValue(Group $a, Group $b, Dimension $dimension): int
    {
        return match ($dimension) {
            Dimension::Month => [$a->isNone(), $b->key] <=> [$b->isNone(), $a->key],
            Dimension::Weekday => [$a->isNone(), $a->key] <=> [$b->isNone(), $b->key],
            default => [$a->isNone(), mb_strtolower($a->label ?? '')] <=> [$b->isNone(), mb_strtolower($b->label ?? '')],
        };
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
     * Cumulative profit of the settled bets by event date (`BetFigures::$eventAt`), one
     * point per period (empty ones included) from the start of the range, or the first
     * settled bet, to its end, or the last settled bet. The period follows from the range unless given.
     * No settled bets, no points.
     *
     * @param  list<BetFigures>  $bets
     */
    public function profitOverTime(array $bets, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, ?Period $period = null): ProfitSeries
    {
        /** @var array<string, int> $profitByDay profit per event day, earliest first */
        $profitByDay = [];

        foreach ($bets as $bet) {
            if ($bet->isSettled()) {
                $day = ($bet->eventAt ?? throw new InvalidArgumentException('Profit over time needs the event date of every settled bet.'))->toDateString();
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
     * Drawdowns, streaks, the biggest win and loss and the average stake of the settled
     * bets, taken in event order (`BetFigures::$eventAt`, ties by bet id). Free bets
     * count when they are in the set; open bets don't count.
     *
     * @param  list<BetFigures>  $bets
     */
    public function risk(array $bets): RiskFigures
    {
        $settled = $this->inEventOrder(array_values(array_filter($bets, fn (BetFigures $bet): bool => $bet->isSettled())));

        $cumulative = 0;
        $peak = 0;
        $peakAt = null;
        $maxDrawdown = null;
        $run = 0;
        $longestWinningStreak = 0;
        $longestLosingStreak = 0;
        $biggestWin = null;
        $biggestLoss = null;
        $lastAt = null;

        foreach ($settled as $bet) {
            $profit = $bet->profit ?? 0;
            $lastAt = $bet->eventAt ?? throw new InvalidArgumentException('Risk figures need the event date of every settled bet.');
            $cumulative += $profit;

            if ($cumulative >= $peak) {
                $peak = $cumulative;
                $peakAt = $lastAt;
            } elseif ($maxDrawdown === null || $peak - $cumulative > $maxDrawdown->amount) {
                $maxDrawdown = new Drawdown($peak - $cumulative, $peakAt, $lastAt);
            }

            if ($bet->isWin()) {
                $run = $run > 0 ? $run + 1 : 1;
                $longestWinningStreak = max($longestWinningStreak, $run);
            } elseif ($bet->isLoss()) {
                $run = $run < 0 ? $run - 1 : -1;
                $longestLosingStreak = max($longestLosingStreak, -$run);
            }

            if ($profit > 0 && ($biggestWin === null || $profit > $biggestWin->profit)) {
                $biggestWin = $bet;
            }

            if ($profit < 0 && ($biggestLoss === null || $profit < $biggestLoss->profit)) {
                $biggestLoss = $bet;
            }
        }

        $cashBets = array_filter($settled, fn (BetFigures $bet): bool => ! $bet->isFreeBet);
        $cashBetCount = count($cashBets);

        return new RiskFigures(
            maxDrawdown: $maxDrawdown,
            currentDrawdown: $lastAt !== null && $cumulative < $peak ? new Drawdown($peak - $cumulative, $peakAt, $lastAt) : null,
            longestWinningStreak: $longestWinningStreak,
            longestLosingStreak: $longestLosingStreak,
            currentStreak: $run === 0 ? null : new Streak($run > 0, abs($run)),
            biggestWin: $biggestWin,
            biggestLoss: $biggestLoss,
            averageStake: $cashBetCount === 0 ? null : intdiv(2 * $this->sumOfStakes($cashBets) + $cashBetCount, 2 * $cashBetCount),
        );
    }

    /**
     * The bets by event time, then by bet id; bets with neither keep their order.
     *
     * @param  list<BetFigures>  $bets
     * @return list<BetFigures>
     */
    private function inEventOrder(array $bets): array
    {
        usort($bets, fn (BetFigures $a, BetFigures $b): int => [$a->eventAt?->getTimestamp(), $a->betId] <=> [$b->eventAt?->getTimestamp(), $b->betId]);

        return $bets;
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

    /**
     * The middle value, or the mean of the middle two for an even number of values.
     *
     * @param  list<string>  $values  decimal strings
     */
    private function median(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        usort($values, fn (string $a, string $b): int => Decimal::compare($a, $b));
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : $this->mean([$values[$middle - 1], $values[$middle]]);
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
