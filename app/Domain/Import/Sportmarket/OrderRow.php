<?php

namespace App\Domain\Import\Sportmarket;

/**
 * One row (fill) of a Sportmarket orders export, with the columns the import uses as
 * they were written (trimmed). `line` is the row's line in the file, the header being
 * line 1.
 */
final readonly class OrderRow
{
    public function __construct(
        public int $line,
        public string $orderId,
        public string $orderPlaced,
        public string $competition,
        public string $gameDate,
        public string $sport,
        public string $home,
        public string $away,
        public string $betType,
        public string $betStatus,
        public string $bookie,
        public string $currency,
        public string $stake,
        public string $price,
        public string $profitAndLoss,
        public string $inRunning,
        public string $market,
    ) {}

    public function hasFailed(): bool
    {
        return strtolower($this->betStatus) === 'failed';
    }

    /**
     * Whether the fill has its final P&L: settled, or void (stake returned).
     */
    public function isSettled(): bool
    {
        return in_array(strtolower($this->betStatus), ['settled', 'void'], true);
    }

    public function isInRunning(): bool
    {
        return strtolower($this->inRunning) === 'yes';
    }
}
