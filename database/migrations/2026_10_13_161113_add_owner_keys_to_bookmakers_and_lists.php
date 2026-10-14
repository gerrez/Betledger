<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that bets, selections and their pivots reference together with the owner.
     *
     * @var list<string>
     */
    private array $tables = ['bookmakers', 'competitions', 'teams', 'markets', 'tipsters', 'tags'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                // Always unique because `id` is (see the sports table): it lets bets and
                // selections reference (x_id, user_id), so the database itself refuses a
                // bookmaker or list entry of another user.
                $table->unique(['id', 'user_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['id', 'user_id']);
            });
        }
    }
};
