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
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sport_id');
            $table->string('name');
            $table->timestamps();

            // Referencing the sport together with its owner means the database itself
            // refuses a team under another user's sport.
            $table->foreign(['sport_id', 'user_id'])
                ->references(['id', 'user_id'])
                ->on('sports')
                ->cascadeOnDelete();

            $table->unique(['user_id', 'sport_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
