<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A deleted bet keeps its row and its selections until it is
     * restored, deleted for good, or pruned (see Bet::prunable()).
     */
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
