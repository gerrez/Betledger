<?php

namespace Tests\Unit\Livewire;

use App\Domain\BetType;
use App\Domain\Statistics\BetFigures;
use App\Domain\Statistics\Drawdown;
use App\Domain\Statistics\RiskFigures;
use App\Domain\Statistics\Streak;
use App\Livewire\Statistics\RiskDisplay;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class RiskDisplayTest extends TestCase
{
    public function test_shows_falls_as_negative_amounts_and_links_the_biggest_results_to_their_bets(): void
    {
        $tiles = RiskDisplay::tiles(new RiskFigures(
            maxDrawdown: new Drawdown(134100, CarbonImmutable::parse('2026-10-05 23:30'), CarbonImmutable::parse('2026-10-08 15:00')),
            currentDrawdown: new Drawdown(40000, CarbonImmutable::parse('2025-12-30'), CarbonImmutable::parse('2026-01-04')),
            longestWinningStreak: 5,
            longestLosingStreak: 3,
            currentStreak: new Streak(false, 2),
            biggestWin: $this->bet(25000, '2026-10-21 18:00', 7),
            biggestLoss: $this->bet(-1341, '2026-10-08 15:00', 9),
            averageStake: 206157,
        ));

        $this->assertSame([
            ['Max drawdown', '−1,341.00', 'loss', '5 Oct – 8 Oct 2026', null],
            ['Current drawdown', '−400.00', 'loss', 'Since the peak on 30 Dec 2025', null],
            ['Longest winning streak', '5', 'even', 'wins in a row', null],
            ['Longest losing streak', '3', 'even', 'losses in a row', null],
            ['Current streak', '2 losses', 'even', 'pushes skipped', null],
            ['Biggest win', '+250.00', 'profit', '21 Oct 2026', 7],
            ['Biggest loss', '−13.41', 'loss', '8 Oct 2026', 9],
            ['Average stake', '2,061.57', 'even', 'settled cash bets', null],
        ], array_map(fn (array $tile): array => array_values($tile), $tiles));
    }

    public function test_a_drawdown_from_the_start_and_a_period_across_years(): void
    {
        $tiles = RiskDisplay::tiles($this->risk(
            maxDrawdown: new Drawdown(500, CarbonImmutable::parse('2025-12-30'), CarbonImmutable::parse('2026-01-02')),
            currentDrawdown: new Drawdown(300, null, CarbonImmutable::parse('2026-01-03')),
            currentStreak: new Streak(true, 1),
        ));

        $this->assertSame('30 Dec 2025 – 2 Jan 2026', $tiles[0]['sub']);
        $this->assertSame('Below the start', $tiles[1]['sub']);
        $this->assertSame('1 win', $tiles[4]['value']);
    }

    public function test_figures_with_nothing_to_show(): void
    {
        $tiles = RiskDisplay::tiles($this->risk());

        $this->assertSame(['0.00', 'even', 'Never below an earlier peak'], [$tiles[0]['value'], $tiles[0]['tone'], $tiles[0]['sub']]);
        $this->assertSame(['0.00', 'At the peak'], [$tiles[1]['value'], $tiles[1]['sub']]);
        $this->assertSame(['0', '0', '—'], [$tiles[2]['value'], $tiles[3]['value'], $tiles[4]['value']]);
        $this->assertSame(['—', 'No winning bets', null], [$tiles[5]['value'], $tiles[5]['sub'], $tiles[5]['betId']]);
        $this->assertSame(['—', 'No losing bets'], [$tiles[6]['value'], $tiles[6]['sub']]);
        $this->assertSame('—', $tiles[7]['value']);
    }

    private function risk(?Drawdown $maxDrawdown = null, ?Drawdown $currentDrawdown = null, ?Streak $currentStreak = null): RiskFigures
    {
        return new RiskFigures($maxDrawdown, $currentDrawdown, 0, 0, $currentStreak, null, null, null);
    }

    private function bet(int $profit, string $eventAt, int $betId): BetFigures
    {
        return new BetFigures(
            stake: 1000,
            profit: $profit,
            factor: $profit > 0 ? '2' : '0',
            exchangeRate: '1',
            type: BetType::Single,
            isFreeBet: false,
            totalOdds: '2.0000',
            eventAt: CarbonImmutable::parse($eventAt),
            betId: $betId,
        );
    }
}
