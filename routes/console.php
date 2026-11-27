<?php

use App\Models\Bet;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Deletes bets for good once they have been deleted for Bet::DAYS_KEPT_DELETED days.
Schedule::command('model:prune', ['--model' => [Bet::class]])->daily();
