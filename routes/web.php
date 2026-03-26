<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\PostcodeController;
use App\Models\Postcode;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('check/test', function() {
    return Artisan::call('health:check');
});

Route::middleware('monitoring.auth')->get('monitor', [MonitoringController::class, 'health'])
    ->name('monitoring.health');
Route::middleware('monitoring.auth')->get('monitor/lookup', [MonitoringController::class, 'lookup'])
    ->name('monitoring.lookup');

Route::get('test', function() {

    $postcode = '3201AA';
    $number = '7';
    $extension = null;

    $post = Postcode::where(function($query) use ($postcode, $number, $extension) {
        return $query->where('postcode', $postcode)
            ->where('house_number', $number)
            ->where('house_nr_add', $extension)
            ->where('supplier_id', 20);
    })->first();

    dd($post);
});

Route::get('loginredirect', function() {
    return redirect('login');
})->name('login');

