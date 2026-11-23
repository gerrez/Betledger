<?php

use App\Domain\Bets\EventDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            // When the bet was decided, which statistics count it on (App\Domain\Bets\EventDate).
            $table->dateTime('event_at')->nullable()->after('placed_at');
        });

        $this->fillEventAt();

        Schema::table('bets', function (Blueprint $table) {
            $table->dateTime('event_at')->nullable(false)->change();
            $table->index(['user_id', 'event_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'event_at']);
            $table->dropColumn('event_at');
        });
    }

    /**
     * Give every existing bet its event date by the EventDate rule. The latest start is
     * read with a grouped subquery and the rule applied in PHP, so this works the same
     * on SQLite and Postgres. An imported bet was decided at its game date, which is its
     * settlement date.
     */
    private function fillEventAt(): void
    {
        $latestStarts = DB::table('selections')
            ->select('bet_id')
            ->selectRaw('max(event_starts_at) as latest_start')
            ->groupBy('bet_id');

        DB::table('bets')
            ->leftJoinSub($latestStarts, 'latest_starts', 'latest_starts.bet_id', '=', 'bets.id')
            ->select(['bets.id', 'bets.placed_at', 'bets.external_ref', 'bets.settled_at', 'latest_starts.latest_start'])
            ->chunkById(500, function (Collection $bets): void {
                foreach ($bets as $bet) {
                    $eventAt = EventDate::forBet(
                        $this->date($bet->placed_at),
                        [$bet->latest_start === null ? null : $this->date($bet->latest_start)],
                        $bet->external_ref === null || $bet->settled_at === null ? null : $this->date($bet->settled_at),
                    );

                    DB::table('bets')->where('id', $bet->id)->update(['event_at' => $eventAt->format('Y-m-d H:i:s')]);
                }
            }, 'bets.id', 'id');
    }

    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }
};
