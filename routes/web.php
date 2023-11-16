<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebhookController;

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
    'prefix' => 'webhook',
    'as' => 'webhook.'
], function () {
    Route::post('/antares', [WebhookController::class, 'handleAntaresWebhook'])->name('antares');
    Route::get('/antares', [WebhookController::class, 'handleAntaresWebhook'])->name('antares');
});
