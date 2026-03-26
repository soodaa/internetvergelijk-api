<?php

use App\Http\Controllers\PackageController;
use App\Http\Controllers\PostcodeController;
use App\Http\Controllers\SearchRequestController;
use App\Http\Controllers\SpeedcheckProxyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (zonder throttle)
|--------------------------------------------------------------------------
| 
| Speedcheck V2 gebruikt eigen caching, dus geen rate limiting nodig.
| Routes worden geladen via RouteServiceProvider met 'api' middleware group,
| maar wij willen GEEN throttle, dus definiëren we ze hier zonder.
|
*/

// Proxy endpoint om API token server-side te houden
Route::middleware(['throttle:60,1', 'cors'])->get('api/speedcheck', [SpeedcheckProxyController::class, 'proxy']);

// Speedcheck V2 routes - ZONDER throttle (heeft eigen caching + circuit breaker)
Route::middleware(['api.token', 'cors'])->group(function () {
    Route::get('packagesCheck', [PackageController::class, 'get']);
    Route::get('speedCheck', [PostcodeController::class, 'speedCheck']);
    Route::get('hgvt-raw', [PostcodeController::class, 'debugHgvtRaw'])->middleware('throttle:10,1');

    Route::get('searchresults/{id}', [SearchRequestController::class, 'getResults']);

});
