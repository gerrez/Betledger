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
        Schema::create('bet_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('bet_id');
            $table->unsignedBigInteger('tag_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['bet_id', 'tag_id']);

            // The owner of both the bet and the tag, so a bet can't get another user's tag.
            $table->foreign(['bet_id', 'user_id'])->references(['id', 'user_id'])->on('bets')->cascadeOnDelete();
            $table->foreign(['tag_id', 'user_id'])->references(['id', 'user_id'])->on('tags');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bet_tag');
    }
};
