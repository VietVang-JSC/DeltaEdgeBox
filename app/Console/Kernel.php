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
                 ->cron('0 */4 * * *');

        // Database backup - Daily (at 23:59, keep last 30 files)
        $schedule->command('backup:database --type=daily --max=30')
                 ->dailyAt('23:59');

        // Sync Box → Cloud — frequency controlled by sync_interval setting (e.g. 1m, 5m, 10m, 1h, 2h, 12h)
        $cron = $this->intervalToCron(config('edge_box.sync_interval', '1m'));
        $schedule->command('sync:worker --batch=50')->cron($cron)->withoutOverlapping();

        // No auto master sync — Cloud → Box is manual only (click Sync Data button in Edge Manager)
    }

    /**
     * Convert interval string (e.g. 1m, 5m, 10m, 1h, 2h, 12h, 30s) to cron expression.
     */
    private function intervalToCron(string $interval): string
    {
        if (preg_match('/^(\d+)\s*(m|min|h|d|s)$/i', trim($interval), $m)) {
            $num = max(1, (int)$m[1]);
            $unit = strtolower($m[2][0]);
            return match ($unit) {
                'm' => $num === 1 ? '* * * * *' : "*/$num * * * *",
                'h' => $num === 1 ? '0 * * * *' : "0 */$num * * *",
                'd' => $num === 1 ? '0 0 * * *' : "0 0 */$num * *",
                's' => $num < 60 ? '* * * * *' : $this->intervalToCron(($num / 60) . 'm'),
                default => '* * * * *',
            };
        }
        return '* * * * *';
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
