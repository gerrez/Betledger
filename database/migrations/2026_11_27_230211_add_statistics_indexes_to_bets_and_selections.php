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
            // Home's open stake and open bets, and every status filter.
            $table->index(['user_id', 'status']);
        });

        Schema::table('selections', function (Blueprint $table) {
            // The sport, competition and market filters of statistics and the bets list.
            $table->index(['user_id', 'sport_id']);
            $table->index(['user_id', 'competition_id']);
            $table->index(['user_id', 'market_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('selections', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'sport_id']);
            $table->dropIndex(['user_id', 'competition_id']);
            $table->dropIndex(['user_id', 'market_id']);
        });

        Schema::table('bets', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
        });
    }
};
