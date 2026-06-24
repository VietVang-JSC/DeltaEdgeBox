<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ResetDatabase extends Command
{
    protected $signature = 'db:reset
                            {--force : Skip confirmation prompt}
                            {--backup : Backup current database before reset}';

    protected $description = 'Reset edge box database — drop all tables and migrate fresh';

    public function handle(): int
    {
        $dbPath = database_path('database.sqlite');

        if (!File::exists($dbPath)) {
            $this->warn('Database does not exist. Will create fresh.');
        } else {
            // Backup first
            if ($this->option('backup')) {
                $backupDir = storage_path('app/backup');
                if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
                $backupPath = "$backupDir/pre-reset-" . date('Y-m-d-His') . '.sqlite';
                copy($dbPath, $backupPath);
                $this->info("Backed up to: $backupPath");
            }

            // Confirm
            if (!$this->option('force')) {
                $size = round(filesize($dbPath) / 1048576, 2);
                if (!$this->confirm("Reset database ($size MB)? ALL data will be lost.")) {
                    $this->info('Cancelled');
                    return 0;
                }
            }
        }

        // Drop all tables
        $this->line('Dropping all tables...');
        DB::statement('PRAGMA foreign_keys = OFF');
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        foreach ($tables as $table) {
            DB::statement("DROP TABLE IF EXISTS \"{$table->name}\"");
            $this->line("  Dropped: {$table->name}");
        }
        DB::statement('PRAGMA foreign_keys = ON');

        // Run migrations
        $this->line('Running migrations...');
        $this->call('migrate', ['--force' => true]);

        $this->info('Database reset complete.');

        // Show system status
        $tablesAfter = DB::select("SELECT COUNT(*) as c FROM sqlite_master WHERE type='table'");
        $this->line($tablesAfter[0]->c . ' tables created');

        return 0;
    }
}
