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
        Schema::create('selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bet_id');
            $table->unsignedSmallInteger('position');
            $table->unsignedBigInteger('sport_id');
            $table->unsignedBigInteger('competition_id')->nullable();
            $table->unsignedBigInteger('market_id')->nullable();
            $table->string('event_name');
            $table->dateTime('event_starts_at')->nullable();
            $table->string('pick');
            $table->decimal('odds', 10, 3);
            $table->decimal('closing_odds', 10, 3)->nullable();
            $table->string('result')->nullable();
            $table->timestamps();

            // The owner is the bet's owner. Referencing the bet and list entries together
            // with it means the database itself refuses another user's sport, competition
            // or market. A list entry can't be deleted while a selection uses it.
            $table->foreign(['bet_id', 'user_id'])->references(['id', 'user_id'])->on('bets')->cascadeOnDelete();
            $table->foreign(['sport_id', 'user_id'])->references(['id', 'user_id'])->on('sports');
            $table->foreign(['competition_id', 'user_id'])->references(['id', 'user_id'])->on('competitions');
            $table->foreign(['market_id', 'user_id'])->references(['id', 'user_id'])->on('markets');

            $table->unique(['bet_id', 'position']);

            // Lets selection_team reference (selection_id, user_id); see the sports table.
            $table->unique(['id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('selections');
    }
};
