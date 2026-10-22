<?php

namespace App\Domain\Import\Sportmarket;

use App\Domain\Currency;
use App\Domain\Decimal;
use App\Domain\Money;
use App\Domain\Odds;
use App\Domain\Settlement\Leg;
use App\Domain\Settlement\SelectionResult;
use App\Domain\Settlement\SettlementCalculator;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * Turns a Sportmarket orders export into bets, per "Import from Sportmarket" in
 * docs/spec.md: one settled single per order, made from the order's fills that didn't
 * fail, with its result worked out from the P&L Sportmarket reports. Orders that have
 * nothing to import yet are skipped, and orders that can't be imported are reported as
 * errors, each with its reason. Nothing is saved here.
 */
final class SportmarketImport
{
    public const EXTERNAL_REF_PREFIX = 'sportmarket:';

    public const IN_PLAY_TAG = 'In-play';

    /**
     * Sportmarket's football sport codes, with what the period adds to the market name.
     */
    private const FOOTBALL_PERIODS = [
        'fb' => '',
        'fb_ht' => ' – 1st half',
        'fb_et' => ' – extra time',
    ];

    private const MARKETS = [
        'ahou' => 'Asian total goals',
        'ou' => 'Total goals',
        'ah' => 'Asian handicap',
        '1x2' => 'Match result',
        'ml' => 'Draw no bet',
        'extot' => 'Exact total goals',
    ];

    /**
     * The results compared with the reported payout, in the order that wins a tie.
     */
    private const RESULTS = [
        SelectionResult::Won,
        SelectionResult::Lost,
        SelectionResult::Void,
        SelectionResult::HalfWon,
        SelectionResult::HalfLost,
    ];

    private const AMOUNT_PATTERN = '/^-?\d{1,12}(\.\d{1,2})?$/';

    public function __construct(private SettlementCalculator $calculator) {}

    /**
     * Read an export for a bookmaker in the given currency. Orders come in the order they
     * first appear in the file.
     *
     * @param  list<string>  $importedRefs  the `external_ref` of the user's bets, to skip orders imported before
     * @return list<ImportedOrder>
     *
     * @throws InvalidOrdersFile when the file isn't a Sportmarket orders export
     */
    public function read(string $contents, Currency $currency, array $importedRefs = []): array
    {
        $rowsByOrder = [];

        foreach (OrdersFile::parse($contents) as $row) {
            $rowsByOrder[$row->orderId][] = $row;
        }

        $imported = array_flip($importedRefs);
        $orders = [];

        foreach ($rowsByOrder as $orderId => $rows) {
            $orders[] = $this->order((string) $orderId, $rows, $currency, $imported);
        }

        return $orders;
    }

    /**
     * The `external_ref` of the bet made from an order.
     */
    public static function externalRef(string $orderId): string
    {
        return self::EXTERNAL_REF_PREFIX.$orderId;
    }

    /**
     * @param  non-empty-list<OrderRow>  $rows
     * @param  array<string, int>  $imported
     */
    private function order(string $orderId, array $rows, Currency $currency, array $imported): ImportedOrder
    {
        $fills = array_values(array_filter($rows, fn (OrderRow $row): bool => ! $row->hasFailed()));

        if ($fills === []) {
            return ImportedOrder::skipped($orderId, 'Every fill failed, so no bet was placed.');
        }

        if (isset($imported[self::externalRef($orderId)])) {
            return ImportedOrder::skipped($orderId, 'Imported before.');
        }

        foreach ($fills as $fill) {
            if (! $fill->isSettled()) {
                return ImportedOrder::skipped($orderId, "Not settled yet (a fill is \"{$fill->betStatus}\"). Import the file again once it is.");
            }
        }

        try {
            return ImportedOrder::bet($orderId, $this->bet($orderId, $fills, $currency));
        } catch (InvalidOrder $exception) {
            return ImportedOrder::error($orderId, $exception->getMessage());
        }
    }

    /**
     * @param  non-empty-list<OrderRow>  $fills
     *
     * @throws InvalidOrder
     */
    private function bet(string $orderId, array $fills, Currency $currency): ImportedBet
    {
        foreach ($fills as $fill) {
            if (strtoupper($fill->currency) !== $currency->value) {
                throw new InvalidOrder("Line {$fill->line}: the order is in {$fill->currency}, but the bookmaker is in {$currency->value}.");
            }
        }

        $stakes = array_map(fn (OrderRow $fill): int => $this->stake($fill), $fills);
        $stake = array_sum($stakes);

        if (! Money::isValidStake(Money::fromMinorUnits($stake))) {
            throw new InvalidOrder('The order\'s stake ('.Money::fromMinorUnits($stake).') can\'t be recorded: it must be above 0 and below 1,000,000,000.');
        }

        $odds = $this->averageOdds($fills, $stakes, $stake);
        $profitAndLoss = array_sum(array_map(fn (OrderRow $fill): int => $this->profitAndLoss($fill), $fills));
        $warning = $profitAndLoss < -$stake ? $this->excessLossWarning($stake, $profitAndLoss) : null;
        $payout = max(0, $stake + $profitAndLoss);

        $result = $this->nearestResult($stake, $odds, $payout);
        $calculatedPayout = $this->calculatedPayout($stake, $odds, $result);

        $main = $this->largestFill($fills, $stakes);
        $placedAt = $this->placedAt($fills);
        $settledAt = $this->gameDate($main)->setTime(23, 59);
        $teams = array_values(array_filter([$main->home, $main->away], fn (string $team): bool => $team !== ''));

        if ($teams === []) {
            throw new InvalidOrder("Line {$main->line}: the order has no Home or Away team.");
        }

        return new ImportedBet(
            externalRef: self::externalRef($orderId),
            placedAt: $placedAt,
            stake: $stake,
            odds: $odds,
            sport: $this->sport($main),
            competition: $main->competition === '' ? null : $main->competition,
            market: $this->market($main),
            teams: $teams,
            eventName: implode(' v ', $teams),
            pick: $main->betType !== '' ? $main->betType : throw new InvalidOrder("Line {$main->line}: the order has no Bet Type."),
            tags: $this->hasFillInRunning($fills) ? [self::IN_PLAY_TAG] : [],
            notes: $this->notes($orderId, $fills, $stakes).($warning === null ? '' : " · {$warning}"),
            result: $result,
            payout: $payout,
            manualPayout: $payout === $calculatedPayout ? null : $payout,
            settledAt: $settledAt->max($placedAt),
            warning: $warning,
        );
    }

    /**
     * A payout can't be negative, so an order whose reported loss is larger than its
     * stake is recorded as lost (payout 0) and says what Sportmarket reported.
     */
    private function excessLossWarning(int $stake, int $profitAndLoss): string
    {
        return 'Sportmarket reported a P&L of '.Money::formatWithoutSymbol($profitAndLoss)
            .' on a stake of '.Money::formatWithoutSymbol($stake).'; recorded as lost.';
    }

    /**
     * The stake-weighted average of the fills' prices, rounded half up to 3 decimals.
     *
     * @param  non-empty-list<OrderRow>  $fills
     * @param  non-empty-list<int>  $stakes
     *
     * @throws InvalidOrder
     */
    private function averageOdds(array $fills, array $stakes, int $stake): string
    {
        $weighted = '0';

        foreach ($fills as $index => $fill) {
            if (! Odds::isValid($fill->price)) {
                throw new InvalidOrder("Line {$fill->line}: the Price \"{$fill->price}\" is not decimal odds above 1.");
            }

            $weighted = Decimal::add($weighted, Decimal::multiply((string) $stakes[$index], $fill->price));
        }

        $odds = Decimal::normalize(Decimal::round(Decimal::divide($weighted, (string) $stake), 3));

        if (! Odds::isValidSelectionOdds($odds)) {
            throw new InvalidOrder("The order's average odds ({$odds}) can't be recorded: odds must be above 1 and below 10,000,000.");
        }

        return $odds;
    }

    /**
     * The result whose calculated payout is nearest to the reported one.
     */
    private function nearestResult(int $stake, string $odds, int $payout): SelectionResult
    {
        $distance = fn (SelectionResult $result): int => abs($this->calculatedPayout($stake, $odds, $result) - $payout);
        $nearest = self::RESULTS[0];

        foreach (self::RESULTS as $result) {
            if ($distance($result) < $distance($nearest)) {
                $nearest = $result;
            }
        }

        return $nearest;
    }

    private function calculatedPayout(int $stake, string $odds, SelectionResult $result): int
    {
        return $this->calculator->settle($stake, false, [new Leg($odds, $result)])->payout
            ?? throw new LogicException('A single with a result is settled.');
    }

    /**
     * The fill with the largest stake (the first of them on a tie), which gives the bet
     * its pick, market and event.
     *
     * @param  non-empty-list<OrderRow>  $fills
     * @param  non-empty-list<int>  $stakes
     */
    private function largestFill(array $fills, array $stakes): OrderRow
    {
        $largest = 0;

        foreach ($stakes as $index => $stake) {
            if ($stake > $stakes[$largest]) {
                $largest = $index;
            }
        }

        return $fills[$largest];
    }

    /**
     * The earliest time a fill was placed, in UTC without microseconds.
     *
     * @param  non-empty-list<OrderRow>  $fills
     *
     * @throws InvalidOrder
     */
    private function placedAt(array $fills): CarbonImmutable
    {
        $times = array_map(fn (OrderRow $fill): CarbonImmutable => $this->time($fill), $fills);

        return array_reduce($times, fn (?CarbonImmutable $earliest, CarbonImmutable $time): CarbonImmutable => $earliest?->min($time) ?? $time)
            ?? throw new LogicException('An order has fills.');
    }

    /**
     * @throws InvalidOrder
     */
    private function time(OrderRow $fill): CarbonImmutable
    {
        foreach (['Y-m-d H:i:s.uP', 'Y-m-d H:i:sP'] as $format) {
            $time = $this->fromFormat($format, $fill->orderPlaced);

            if ($time !== null) {
                return $time->utc()->startOfSecond();
            }
        }

        throw new InvalidOrder("Line {$fill->line}: the Order Placed \"{$fill->orderPlaced}\" is not a date and time.");
    }

    /**
     * @throws InvalidOrder
     */
    private function gameDate(OrderRow $fill): CarbonImmutable
    {
        return $this->fromFormat('Y-m-d', $fill->gameDate, 'UTC')
            ?? throw new InvalidOrder("Line {$fill->line}: the Game Date \"{$fill->gameDate}\" is not a date.");
    }

    /**
     * Parse a date strictly: null when it doesn't match the format exactly or isn't a
     * real date (e.g. 2026-02-30).
     */
    private function fromFormat(string $format, string $value, ?string $timezone = null): ?CarbonImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!'.$format, $value, $timezone === null ? null : new DateTimeZone($timezone));

        if ($time === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        return CarbonImmutable::instance($time);
    }

    /**
     * @throws InvalidOrder
     */
    private function sport(OrderRow $fill): string
    {
        if ($fill->sport === '') {
            throw new InvalidOrder("Line {$fill->line}: the order has no Sport.");
        }

        return array_key_exists(strtolower($fill->sport), self::FOOTBALL_PERIODS) ? 'Football' : $fill->sport;
    }

    private function market(OrderRow $fill): ?string
    {
        if ($fill->market === '') {
            return null;
        }

        return (self::MARKETS[strtolower($fill->market)] ?? $fill->market).(self::FOOTBALL_PERIODS[strtolower($fill->sport)] ?? '');
    }

    /**
     * @param  non-empty-list<OrderRow>  $fills
     */
    private function hasFillInRunning(array $fills): bool
    {
        foreach ($fills as $fill) {
            if ($fill->isInRunning()) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Sportmarket order 2384015187 · 6 fills: mbook ×4, molly, fair": the bookies by
     * number of fills, then by stake, most first.
     *
     * @param  non-empty-list<OrderRow>  $fills
     * @param  non-empty-list<int>  $stakes
     */
    private function notes(string $orderId, array $fills, array $stakes): string
    {
        /** @var array<string, array{name: string, fills: int, stake: int}> $bookies */
        $bookies = [];

        foreach ($fills as $index => $fill) {
            $bookies[$fill->bookie] ??= ['name' => $fill->bookie, 'fills' => 0, 'stake' => 0];
            $bookies[$fill->bookie]['fills']++;
            $bookies[$fill->bookie]['stake'] += $stakes[$index];
        }

        usort($bookies, fn (array $left, array $right): int => [$right['fills'], $right['stake']] <=> [$left['fills'], $left['stake']]);

        $names = array_map(fn (array $bookie): string => $bookie['fills'] > 1 ? "{$bookie['name']} ×{$bookie['fills']}" : $bookie['name'], $bookies);
        $count = count($fills);

        return "Sportmarket order {$orderId} · {$count} ".($count === 1 ? 'fill' : 'fills').': '.implode(', ', $names);
    }

    /**
     * @throws InvalidOrder
     */
    private function stake(OrderRow $fill): int
    {
        $stake = $this->amount($fill, 'Stake (User Currency)', $fill->stake);

        if ($stake < 0) {
            throw new InvalidOrder("Line {$fill->line}: the Stake (User Currency) \"{$fill->stake}\" is negative.");
        }

        return $stake;
    }

    /**
     * @throws InvalidOrder
     */
    private function profitAndLoss(OrderRow $fill): int
    {
        return $this->amount($fill, 'Reconciled P&L (User Currency)', $fill->profitAndLoss);
    }

    /**
     * An amount such as "148.57" or "-150.00" in minor units.
     *
     * @throws InvalidOrder
     */
    private function amount(OrderRow $fill, string $column, string $value): int
    {
        if (preg_match(self::AMOUNT_PATTERN, $value) !== 1) {
            throw new InvalidOrder("Line {$fill->line}: the {$column} \"{$value}\" is not an amount.");
        }

        return Decimal::toInteger(Decimal::multiply($value, '100'));
    }
}
