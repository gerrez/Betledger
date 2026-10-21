<?php

namespace App\Domain\Statistics;

use App\Domain\Decimal;
use App\Domain\Odds;

/**
 * Ranges of a bet's total odds. Total odds have four decimals, so each band runs up to
 * and including its upper bound: 1.5000 is in 1.01–1.50, 1.5001 in 1.51–2.00.
 */
enum OddsBand: string
{
    case UpTo150 = '1.01-1.50';
    case UpTo200 = '1.51-2.00';
    case UpTo300 = '2.01-3.00';
    case UpTo500 = '3.01-5.00';
    case UpTo1000 = '5.01-10.00';
    case Over1000 = '10.01+';

    public static function forOdds(string $totalOdds): self
    {
        Odds::assertValid($totalOdds);

        foreach (self::cases() as $band) {
            $upTo = $band->upTo();

            if ($upTo === null || Decimal::compare($totalOdds, $upTo) <= 0) {
                return $band;
            }
        }

        return self::Over1000;
    }

    /**
     * The band as shown to the user, with an en dash: "1.51–2.00", "10.01+".
     */
    public function label(): string
    {
        return str_replace('-', "\u{2013}", $this->value);
    }

    /**
     * The highest total odds in the band; null for the open-ended top band.
     */
    public function upTo(): ?string
    {
        return match ($this) {
            self::UpTo150 => '1.50',
            self::UpTo200 => '2.00',
            self::UpTo300 => '3.00',
            self::UpTo500 => '5.00',
            self::UpTo1000 => '10.00',
            self::Over1000 => null,
        };
    }
}
