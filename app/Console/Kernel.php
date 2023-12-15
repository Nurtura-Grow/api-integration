<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Http\Controllers\SchedulerController;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call('App\Http\Controllers\SchedulerController@scheduleIrrigation')->everyMinute();
        $schedule->call('App\Http\Controllers\SchedulerController@scheduleFertilizer')->everyMinute();
        $schedule->call([SchedulerController::class, 'schedule1Hour'])->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
