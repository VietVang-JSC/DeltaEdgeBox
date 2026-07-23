<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sync_conflicts')) {
            return;
        }

        $this->ensureSqlite();
        $this->rebuildTable(true);
    }

    public function down(): void
    {
        if (! Schema::hasTable('sync_conflicts')) {
            return;
        }

        $this->ensureSqlite();

        $hasExpandedValues = DB::table('sync_conflicts')
            ->whereNotIn('resolution_strategy', ['last-write-wins', 'cloud-wins', 'manual'])
            ->orWhereNotIn('resolution_status', ['unresolved', 'resolved'])
            ->exists();

        if ($hasExpandedValues) {
            throw new RuntimeException('Cannot restore the old sync_conflicts constraints while expanded resolution values exist.');
        }

        $this->rebuildTable(false);
    }

    private function ensureSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('This EdgeBox migration only supports SQLite.');
        }
    }

    private function rebuildTable(bool $expanded): void
    {
        $strategies = $expanded
            ? "'last-write-wins', 'cloud-wins', 'manual', 'keep_local', 'keep_cloud', 'skip'"
            : "'last-write-wins', 'cloud-wins', 'manual'";
        $statuses = $expanded
            ? "'unresolved', 'resolved', 'skipped'"
            : "'unresolved', 'resolved'";

        DB::transaction(function () use ($strategies, $statuses): void {
            DB::statement('DROP TABLE IF EXISTS "sync_conflicts_rebuild"');
            DB::statement(<<<SQL
                CREATE TABLE "sync_conflicts_rebuild" (
                    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    "store_id" INTEGER NOT NULL,
                    "table_name" VARCHAR(255) NOT NULL,
                    "record_id" INTEGER NOT NULL,
                    "local_data" CLOB NOT NULL,
                    "cloud_data" CLOB NOT NULL,
                    "resolution_strategy" VARCHAR(50) NOT NULL DEFAULT 'manual'
                        CHECK ("resolution_strategy" IN ({$strategies})),
                    "resolution_status" VARCHAR(50) NOT NULL DEFAULT 'unresolved'
                        CHECK ("resolution_status" IN ({$statuses})),
                    "resolved_by" INTEGER NULL,
                    "resolved_at" DATETIME NULL,
                    "created_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    "sync_queue_id" INTEGER NULL,
                    "cloud_conflict_id" INTEGER NULL,
                    "operation_type" VARCHAR(255) NULL,
                    "error_message" CLOB NULL,
                    "updated_at" DATETIME NULL
                )
                SQL);

            DB::statement(<<<'SQL'
                INSERT INTO "sync_conflicts_rebuild" (
                    "id", "store_id", "table_name", "record_id", "local_data", "cloud_data",
                    "resolution_strategy", "resolution_status", "resolved_by", "resolved_at",
                    "created_at", "sync_queue_id", "cloud_conflict_id", "operation_type",
                    "error_message", "updated_at"
                )
                SELECT
                    "id", "store_id", "table_name", "record_id", "local_data", "cloud_data",
                    "resolution_strategy", "resolution_status", "resolved_by", "resolved_at",
                    "created_at", "sync_queue_id", "cloud_conflict_id", "operation_type",
                    "error_message", "updated_at"
                FROM "sync_conflicts"
                SQL);

            DB::statement('DROP TABLE "sync_conflicts"');
            DB::statement('ALTER TABLE "sync_conflicts_rebuild" RENAME TO "sync_conflicts"');
            DB::statement('CREATE INDEX "sync_conflicts_queue_id_index" ON "sync_conflicts" ("sync_queue_id")');
            DB::statement('CREATE INDEX "sync_conflicts_store_id_resolution_status_index" ON "sync_conflicts" ("store_id", "resolution_status")');
        });
    }
};
