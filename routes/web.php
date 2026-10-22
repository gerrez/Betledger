<?php

use App\Livewire\Bets\Create as CreateBet;
use App\Livewire\Bets\Edit as EditBet;
use App\Livewire\Bets\Index as BetsIndex;
use App\Livewire\Bets\Show as ShowBet;
use App\Livewire\Bookmakers\Index as BookmakersIndex;
use App\Livewire\Lists\Index as ListsIndex;
use App\Livewire\Overview\Index as Overview;
use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\BaseCurrency;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use App\Livewire\Statistics\Index as StatisticsIndex;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(Auth::check() ? 'dashboard' : 'login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', Overview::class)->name('dashboard');

    Route::get('bets', BetsIndex::class)->name('bets.index');

    Route::get('bets/create', CreateBet::class)->name('bets.create');
    Route::get('bets/{bet}', ShowBet::class)->name('bets.show');
    Route::get('bets/{bet}/edit', EditBet::class)->name('bets.edit');

    Route::get('statistics', StatisticsIndex::class)->name('statistics');

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
