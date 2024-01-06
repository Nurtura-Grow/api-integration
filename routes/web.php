<?php

use App\Http\Controllers\AntaresController;
use App\Http\Controllers\MachineLearningController;
use Illuminate\Support\Facades\Route;

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
    'prefix' => 'scheduler',
    'as' => 'scheduler.'
], function () {
    Route::get('/irrigation', 'App\Http\Controllers\SchedulerController@scheduleIrrigation')->name('irrigation');
    Route::get('/fertilizer', 'App\Http\Controllers\SchedulerController@scheduleFertilizer')->name('fertilizer');
    Route::get('/1hour', 'App\Http\Controllers\SchedulerController@schedule1Hour')->name('1hour');
});

Route::group([
    'prefix' => 'antares',
    'as' => 'antares.'
], function () {
    Route::post('/webhook', [AntaresController::class, 'handleAntaresWebhook'])->name('webhook');
    Route::post('/downlink', [AntaresController::class, 'handleAntaresDownlink'])->name('downlink');
});

Route::group([
    'prefix' => 'ml',
    'as' => 'ml.'
], function () {
    Route::post('/fertilizer', [MachineLearningController::class, 'fertilizer'])->name('fertilizer');
    Route::post('/irrigation', [MachineLearningController::class, 'irrigation'])->name('irrigation');
    Route::post('/predict', [MachineLearningController::class, 'predict'])->name('predict');
});
