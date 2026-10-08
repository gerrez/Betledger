<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'coming-soon', [
        'title' => 'Overview',
        'description' => 'Your month at a glance: profit, key numbers and open bets.',
    ])->name('dashboard');

    Route::view('bets', 'coming-soon', [
        'title' => 'Bets',
        'description' => 'All your bets, open and settled, with settling right from the list.',
    ])->name('bets.index');

    Route::view('bets/create', 'coming-soon', [
        'title' => 'New bet',
        'description' => 'Record a single or an accumulator in a few taps.',
    ])->name('bets.create');

    Route::view('statistics', 'coming-soon', [
        'title' => 'Statistics',
        'description' => 'Profit, ROI, strike rate and closing line value, broken down every way.',
    ])->name('statistics');

    Route::view('bookmakers', 'coming-soon', [
        'title' => 'Bookmakers',
        'description' => 'The bookmakers you bet with, their currency and exchange rate.',
    ])->name('bookmakers.index');

    Route::view('lists', 'coming-soon', [
        'title' => 'Lists',
        'description' => 'Sports, competitions, teams, markets, tipsters and tags.',
    ])->name('lists.index');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
