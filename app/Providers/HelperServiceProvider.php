<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class HelperServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        foreach (glob(app_path('Helpers/*.php')) as $file) {
            require_once $file;
        }
    }
}
