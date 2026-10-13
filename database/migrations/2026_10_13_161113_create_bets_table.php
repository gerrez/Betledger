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
        Schema::create('bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bookmaker_id');
            $table->unsignedBigInteger('tipster_id')->nullable();
            $table->string('type');
            $table->dateTime('placed_at');
            $table->unsignedBigInteger('stake');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 8);
            $table->boolean('is_free_bet')->default(false);
            $table->decimal('total_odds', 14, 4);
            $table->string('status')->default('open');
            $table->dateTime('settled_at')->nullable();
            $table->unsignedBigInteger('payout')->nullable();
            $table->boolean('payout_is_manual')->default(false);
            $table->bigInteger('profit')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Referencing the bookmaker and tipster together with the owner means the
            // database itself refuses another user's. A list entry or bookmaker can't be
            // deleted while a bet uses it.
            $table->foreign(['bookmaker_id', 'user_id'])->references(['id', 'user_id'])->on('bookmakers');
            $table->foreign(['tipster_id', 'user_id'])->references(['id', 'user_id'])->on('tipsters');

            // Lets selections and tags reference (bet_id, user_id); see the sports table.
            $table->unique(['id', 'user_id']);

            $table->index(['user_id', 'placed_at']);
            $table->index(['user_id', 'settled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bets');
    }
};
