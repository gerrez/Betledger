<?php

namespace App\Providers;

use App\Notifications\Push\FcmClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FcmClient::class, fn (): FcmClient => FcmClient::fromConfig(config('services.fcm.credentials')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
