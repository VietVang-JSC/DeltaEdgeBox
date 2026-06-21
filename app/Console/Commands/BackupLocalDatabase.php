<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BackupLocalDatabase extends Command
{
    protected $signature = 'db:backup-local
                            {--filename= : Custom backup filename (without .sqlite extension)}';

    protected $description = 'Backup SQLite database to local storage/app/backup/';

    public function handle(): int
    {
        $dbPath = database_path('database.sqlite');

        if (!File::exists($dbPath)) {
            $this->error("Database not found: $dbPath");
            return 1;
        }

        $backupDir = storage_path('app/backup');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename = $this->option('filename') ?? 'delta-pos-' . date('Y-m-d-His');
        $backupPath = "$backupDir/$filename.sqlite";

        if (!copy($dbPath, $backupPath)) {
            $this->error('Backup failed');
            return 1;
        }

        $size = round(filesize($backupPath) / 1048576, 2);
        $this->info("Backup created: $backupPath ($size MB)");

        // List existing backups
        $backups = File::glob("$backupDir/*.sqlite");
        $this->line(count($backups) . ' local backup(s) total');

        return 0;
    }
}
