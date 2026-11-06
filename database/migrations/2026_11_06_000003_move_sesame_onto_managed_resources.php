<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Sesame no longer keeps a write-ahead log on a persistent disk or runs a
 * long-lived data plane. Accepted sends are buffered per instance in SQLite
 * and drained here; sesame_buffers records how far each buffer was drained,
 * so a send lost before its drain is identifiable. A lease on each event
 * makes delivery restartable across stateless managed-queue workers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS sesame_accounts_notify ON sesame_accounts;
            DROP TRIGGER IF EXISTS sesame_runs_notify ON sesame_runs;
            DROP FUNCTION IF EXISTS sesame_notify_config();
        SQL);
        Schema::dropIfExists('sesame_wal_checkpoint');

        Schema::table('sesame_events', function (Blueprint $table): void {
            // Set when a sweep hands the event to a delivery job; only that job may settle it.
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_until', 3)->nullable();
        });

        Schema::create('sesame_buffers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('host');
            $table->timestampTz('opened_at', 3);
            // Every drain, and the scheduled drain each second even when the
            // buffer is empty, moves this forward. A buffer not seen for a
            // while is gone, and sends past drained_through_seq went with it.
            $table->timestampTz('last_seen_at', 3);
            $table->unsignedBigInteger('drained_through_seq')->default(0);
            $table->unsignedBigInteger('drained_sends')->default(0);
        });

        Schema::table('sesame_send_refusals', function (Blueprint $table): void {
            // Drains repeat after a crash; the buffer's own ID keeps them idempotent.
            $table->uuid('id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesame_buffers');
        Schema::table('sesame_send_refusals', function (Blueprint $table): void {
            $table->dropColumn('id');
        });
        Schema::table('sesame_events', function (Blueprint $table): void {
            $table->dropColumn(['lease_token', 'lease_until']);
        });
    }
};
