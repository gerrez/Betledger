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
            // Where an imported bet came from, e.g. "sportmarket:2384015187", so importing
            // the same file again creates nothing. Null for bets entered by hand, which
            // the unique index allows any number of.
            $table->string('external_ref')->nullable()->after('notes');
            $table->unique(['user_id', 'external_ref']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'external_ref']);
            $table->dropColumn('external_ref');
        });
    }
};
