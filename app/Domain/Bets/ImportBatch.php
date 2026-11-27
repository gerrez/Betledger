<?php

namespace App\Domain\Bets;

use Carbon\CarbonImmutable;

/**
 * The bets of one confirmed import (`bets.import_batch`): its id, when it was imported
 * (UTC) and how many of its bets there are.
 */
final readonly class ImportBatch
{
    public function __construct(
        public string $id,
        public CarbonImmutable $importedAt,
        public int $betCount,
    ) {}
}
