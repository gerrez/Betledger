<?php

namespace App\Livewire\Statistics;

use Carbon\CarbonImmutable;

/**
 * The date ranges the statistics page offers. The rolling ones end today and include it:
 * "30 days" is today and the 29 days before.
 */
enum DateRange: string
{
    case Last30Days = '30d';
    case Last90Days = '90d';
    case ThisYear = 'year';
    case AllTime = 'all';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Last30Days => '30 days',
            self::Last90Days => '90 days',
            self::ThisYear => 'This year',
            self::AllTime => 'All time',
            self::Custom => 'Custom…',
        };
    }

    /**
     * The first and last day of the range (null: no limit). A custom range has its
     * dates chosen by the user, so it has none here.
     *
     * @return array{CarbonImmutable|null, CarbonImmutable|null}
     */
    public function days(CarbonImmutable $today): array
    {
        $today = $today->startOfDay();

        return match ($this) {
            self::Last30Days => [$today->subDays(29), $today],
            self::Last90Days => [$today->subDays(89), $today],
            self::ThisYear => [$today->startOfYear(), $today],
            self::AllTime, self::Custom => [null, null],
        };
    }
}
