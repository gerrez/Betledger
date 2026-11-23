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
        Schema::create('bankroll_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bookmaker_id');
            $table->string('type');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->date('occurred_on');
            $table->string('note')->nullable();
            $table->timestamps();

            // Referencing the bookmaker together with the owner means the database itself
            // refuses another user's bookmaker (see the bets table).
            $table->foreign(['bookmaker_id', 'user_id'])->references(['id', 'user_id'])->on('bookmakers');

            $table->index(['user_id', 'bookmaker_id', 'occurred_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bankroll_transactions');
    }
};
