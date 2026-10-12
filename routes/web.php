<?php

use App\Livewire\Bookmakers\Index as BookmakersIndex;
use App\Livewire\Lists\Index as ListsIndex;
use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\BaseCurrency;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use Illuminate\Support\Facades\Route;

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

    Route::get('bookmakers', BookmakersIndex::class)->name('bookmakers.index');

    Route::get('lists', ListsIndex::class)->name('lists.index');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', Profile::class)->name('settings.profile');
    Route::get('settings/currency', BaseCurrency::class)->name('settings.currency');
    Route::get('settings/password', Password::class)->name('settings.password');
    Route::get('settings/appearance', Appearance::class)->name('settings.appearance');
});

require __DIR__.'/auth.php';
