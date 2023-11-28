<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\APIController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::group([
    'prefix' => 'antares',
    'as' => 'antares.'
], function () {
    Route::post('/webhook', [APIController::class, 'handleAntaresWebhook'])->name('webhook');
    Route::post('/downlink', [APIController::class, 'handleAntaresDownlink'])->name('downlink');

});

Route::group([
    'prefix' => 'ml',
    'as' => 'ml.'
], function () {
    Route::post('/fertilizer', [APIController::class, 'fertilizer'])->name('fertilizer');
    Route::post('/irrigation', [APIController::class, 'irrigation'])->name('irrigation');
});
