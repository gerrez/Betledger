<?php

namespace App\Domain\Import\Sportmarket;

/**
 * What the import does with one Sportmarket order: create `bet`, or skip it or report
 * an error, both with a reason for the user.
 */
final readonly class ImportedOrder
{
    private function __construct(
        public string $orderId,
        public OrderOutcome $outcome,
        public ?ImportedBet $bet,
        public ?string $reason,
    ) {}

    public static function bet(string $orderId, ImportedBet $bet): self
    {
        return new self($orderId, OrderOutcome::Import, $bet, null);
    }

    public static function skipped(string $orderId, string $reason): self
    {
        return new self($orderId, OrderOutcome::Skip, null, $reason);
    }

    public static function error(string $orderId, string $reason): self
    {
        return new self($orderId, OrderOutcome::Error, null, $reason);
    }
}
