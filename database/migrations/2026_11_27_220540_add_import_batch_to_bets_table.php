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
            // The import a bet came from: one ULID shared by every bet of one confirmed
            // import, so the bets list can show (and undo) a whole import. Null for bets
            // entered by hand and for bets imported before this column existed.
            $table->string('import_batch', 26)->nullable()->after('external_ref');
            $table->index(['user_id', 'import_batch']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'import_batch']);
            $table->dropColumn('import_batch');
        });
    }
};
