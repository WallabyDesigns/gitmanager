<?php

namespace App\Providers;

use App\Services\SettingsService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            app(SettingsService::class)->applyMailConfig();
        } catch (\Throwable $exception) {
            // Ignore settings bootstrap failures during early boot.
        }
    }
}
