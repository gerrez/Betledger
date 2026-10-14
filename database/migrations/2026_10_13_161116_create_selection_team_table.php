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
        Schema::create('selection_team', function (Blueprint $table) {
            $table->unsignedBigInteger('selection_id');
            $table->unsignedBigInteger('team_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['selection_id', 'team_id']);

            // The owner of both the selection and the team, so a selection can't get
            // another user's team.
            $table->foreign(['selection_id', 'user_id'])->references(['id', 'user_id'])->on('selections')->cascadeOnDelete();
            $table->foreign(['team_id', 'user_id'])->references(['id', 'user_id'])->on('teams');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('selection_team');
    }
};
