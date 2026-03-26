<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, etc.
     */
    public function boot(): void
    {
        $this->configureRoutes();
    }

    protected function configureRoutes(): void
    {
        $this->routes(function () {
            $webPrefix = App::environment('production') ? (string) env('APP_DIR', '') : '';

            // API routes ZONDER de 'api' middleware group
            // (want die bevat throttle:api die we niet willen)
            // De routes definieren hun eigen middleware in routes/api.php
            Route::group([], base_path('routes/api.php'));

            $webRouteRegistrar = Route::middleware('web');

            if ($webPrefix !== '') {
                $webRouteRegistrar->prefix($webPrefix);
            }

            $webRouteRegistrar->group(base_path('routes/web.php'));
        });
    }
}
