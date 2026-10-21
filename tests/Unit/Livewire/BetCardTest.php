<?php

namespace Tests\Unit\Livewire;

use App\Livewire\Bets\BetCard;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BetCardTest extends TestCase
{
    /**
     * Relative to Wednesday 21 October 2026, 15:00.
     *
     * @return array<string, array{string, string}>
     */
    public static function times(): array
    {
        return [
            'earlier today' => ['2026-10-21 00:05', 'today 00:05'],
            'later today' => ['2026-10-21 23:59', 'today 23:59'],
            'tomorrow' => ['2026-10-22 18:30', 'tomorrow 18:30'],
            'yesterday' => ['2026-10-20 09:00', 'yesterday 09:00'],
            'later this week' => ['2026-10-25 14:00', 'Sun 14:00'],
            'six days ago' => ['2026-10-15 11:05', 'Thu 11:05'],
            'a week ago' => ['2026-10-14 11:05', '14 Oct 11:05'],
            'in a week' => ['2026-10-28 20:00', '28 Oct 20:00'],
            'earlier this year' => ['2026-01-03 12:00', '3 Jan 12:00'],
            'last year' => ['2025-12-30 12:00', '30 Dec 2025'],
            'next year' => ['2027-01-02 12:00', '2 Jan 2027'],
        ];
    }

    #[DataProvider('times')]
    public function test_times_are_shown_relative_to_now(string $time, string $expected): void
    {
        $now = Carbon::parse('2026-10-21 15:00');

        $this->assertSame($expected, BetCard::formatTime(Carbon::parse($time), $now));
    }
}
