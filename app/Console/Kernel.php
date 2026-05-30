<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Send heartbeat every 30 seconds
        $schedule->command('heartbeat:send')->everyThirtySeconds();

        // Cleanup old sync logs daily at 2 AM
        $schedule->command('sync:cleanup')->dailyAt('02:00');

        // Database backup - Intraday (every 4 hours, overwrite latest.sqlite)
        $schedule->command('backup:database --type=intraday')
                 ->cron('0 */4 * * *'); // 00:00, 04:00, 08:00, 12:00, 16:00, 20:00

        // Database backup - Daily (at 23:59, keep last 7 days)
        $schedule->command('backup:database --type=daily --max=7')
                 ->dailyAt('23:59');

        // Optional: Run sync worker via scheduler (alternative to daemon mode)
        $schedule->command('sync:worker --batch=50')->everyTenSeconds()->withoutOverlapping();

        // Run master data sync from Cloud every minute in background
        $schedule->command('edge:sync-master')->everyMinute()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
