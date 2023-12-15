<?php

use App\Http\Controllers\AntaresController;
use App\Http\Controllers\MachineLearningController;
use App\Http\Controllers\SchedulerController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

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

Route::get('/schedule/run', function () {
    $exitCode = Artisan::call('schedule:run');
    return response('Scheduled commands executed: ' . $exitCode , 200);
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
    Route::post('/handle', [MachineLearningController::class, 'handleData'])->name('handle');
    Route::post('/fertilizer', [MachineLearningController::class, 'fertilizer'])->name('fertilizer');
    Route::post('/irrigation', [MachineLearningController::class, 'irrigation'])->name('irrigation');
    Route::post('/predict', [MachineLearningController::class, 'predict'])->name('predict');
});
