<?php

namespace Tests\Feature\Bets;

use App\Domain\Settlement\SelectionResult;
use App\Models\Bet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration that adds `bets.event_at` fills it for the bets that already exist.
 */
class EventAtMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_bets_get_their_event_date(): void
    {
        $withStart = $this->bet('2026-10-14 12:00:00', ['2026-10-14 18:30:00']);
        $withoutStart = $this->bet('2026-10-14 12:00:00', [null]);
        $accumulator = $this->bet('2026-10-14 12:00:00', ['2026-10-18 15:00:00', null, '2026-10-19 20:45:00', '2026-10-17 12:00:00']);
        $startBeforePlaced = $this->bet('2026-10-14 12:00:00', ['2026-10-14 11:00:00']);
        $imported = $this->bet('2026-10-21 20:26:44', [null], externalRef: 'sportmarket:2384017276', settledAt: '2026-10-21 23:59:00');
        $importedUnsettled = $this->bet('2026-10-21 20:26:44', [null], externalRef: 'sportmarket:2384017277', settledAt: null);
        $migration = $this->migration();

        $migration->down();
        $this->assertFalse(Schema::hasColumn('bets', 'event_at'));
        $migration->up();

        $this->assertSame([
            '2026-10-14 18:30:00',
            '2026-10-14 12:00:00',
            '2026-10-19 20:45:00',
            '2026-10-14 12:00:00',
            '2026-10-21 23:59:00',
            '2026-10-21 20:26:44',
        ], array_map(fn (Bet $bet): string => $bet->refresh()->event_at->toDateTimeString(), [
            $withStart, $withoutStart, $accumulator, $startBeforePlaced, $imported, $importedUnsettled,
        ]));
    }

    public function test_the_event_date_is_required_after_the_migration(): void
    {
        $this->expectConstraintViolation();

        $bet = Bet::factory()->create();
        $bet->forceFill(['event_at' => null])->save();
    }

    /**
     * A bet whose selections start at the given times (null = no start), with the other
     * columns as an older version of the app left them.
     *
     * @param  list<string|null>  $startTimes
     */
    private function bet(string $placedAt, array $startTimes, ?string $externalRef = null, ?string $settledAt = '2026-11-01 09:00:00'): Bet
    {
        $bet = Bet::factory()->accumulator(array_fill(0, count($startTimes), '1.50'))->create(['placed_at' => $placedAt, 'external_ref' => $externalRef]);

        foreach ($bet->selections as $index => $selection) {
            $selection->update(['event_starts_at' => $startTimes[$index], 'result' => $settledAt === null ? null : SelectionResult::Won]);
        }

        $bet->forceFill(['settled_at' => $settledAt, 'status' => $settledAt === null ? 'open' : 'settled'])->save();

        return $bet;
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_23_224507_add_event_at_to_bets_table.php');
    }
}
