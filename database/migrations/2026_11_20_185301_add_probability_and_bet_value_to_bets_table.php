<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            // The user's estimate of the chance the bet wins, as a percentage ("55.50" =
            // 55.5 %), and the bet's value as a percentage of the stake: total odds ×
            // probability when a probability is given ("107.25" = 107.25 %), else as
            // entered. Both optional.
            $table->decimal('probability', 5, 2)->nullable()->after('total_odds');
            $table->decimal('bet_value', 11, 2)->nullable()->after('probability');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropColumn(['probability', 'bet_value']);
        });
    }
};
