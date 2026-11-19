<?php

use App\Http\Controllers\Api\BetController;
use Illuminate\Support\Facades\Route;

/*
 * The API, under /api/v1. The contract is docs/api/openapi.yaml: change it together with
 * the code (OpenApiContractTest fails when they disagree). Requests authenticate with a
 * personal access token created on Settings → API tokens, sent as
 * `Authorization: Bearer <token>`.
 */
Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'verified', 'throttle:60,1'])->group(function () {
    Route::post('bets', [BetController::class, 'store'])->name('bets.store');
});
