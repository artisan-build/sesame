<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The schema is the contract between the control plane and the data plane.
 * Postgres only: the data plane relies on COPY and LISTEN/NOTIFY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesame_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('access_key_id', 128)->unique();
            $table->string('truth_token_hash', 64);
            $table->string('preset')->default('healthy');
            $table->jsonb('rate_overrides')->nullable();
            $table->jsonb('timing_overrides')->nullable();
            $table->unsignedInteger('max_send_rate')->default(200);
            $table->boolean('sending_paused')->default(false);
            $table->string('aws_account_number', 12);
            $table->string('topic_arn');
            $table->text('webhook_url')->nullable();
            $table->string('subscription_status')->default('none'); // none | pending | confirmed
            $table->string('subscription_arn')->nullable();
            $table->string('subscription_token', 128)->nullable();
            $table->timestampTz('subscription_requested_at', 3)->nullable();
            $table->timestampTz('subscription_confirmed_at', 3)->nullable();
            $table->text('private_key'); // encrypted with APP_KEY
            $table->text('certificate');
            $table->uuid('current_run_id')->nullable();
            $table->timestampsTz();
        });

        Schema::create('sesame_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('sesame_accounts')->cascadeOnDelete();
            $table->bigInteger('seed');
            $table->string('preset');
            $table->string('label')->nullable();
            $table->timestampTz('started_at', 3);
            $table->timestampTz('ended_at', 3)->nullable();
            $table->jsonb('summary')->nullable(); // outlives raw data
            $table->timestampsTz();
        });

        Schema::create('sesame_messages', function (Blueprint $table): void {
            $table->string('message_id', 80)->primary();
            $table->uuid('account_id');
            $table->uuid('run_id')->nullable();
            $table->timestampTz('accepted_at', 3);
            $table->text('source')->nullable();
            $table->jsonb('destination');
            $table->string('configuration_set')->nullable();
            $table->string('tenant_name')->nullable();
            $table->jsonb('tags');
            $table->jsonb('headers');
            $table->jsonb('common_headers');
            $table->unsignedInteger('raw_bytes');
            $table->jsonb('outcomes');
            $table->index(['run_id', 'accepted_at']);
            $table->index('accepted_at');
        });

        Schema::create('sesame_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('sns_message_id');
            $table->uuid('account_id');
            $table->uuid('run_id')->nullable();
            $table->string('message_id', 80)->nullable();
            $table->string('type', 40);
            $table->text('recipient')->nullable();
            $table->uuid('duplicate_of')->nullable();
            $table->timestampTz('due_at', 3);
            $table->string('status', 16)->default('pending'); // pending | delivered | dropped
            $table->jsonb('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('first_attempt_at', 3)->nullable();
            $table->timestampTz('delivered_at', 3)->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->double('latency_ms')->nullable();
            $table->timestampTz('created_at', 3);
            $table->index(['run_id', 'message_id']);
            $table->index('message_id');
            $table->index('created_at');
        });
        DB::statement("CREATE INDEX sesame_events_pending ON sesame_events (due_at) WHERE status = 'pending'");

        Schema::create('sesame_delivery_attempts', function (Blueprint $table): void {
            $table->uuid('event_id')->index();
            $table->unsignedSmallInteger('attempt');
            $table->timestampTz('started_at', 3)->index();
            $table->double('duration_ms');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error')->nullable();
        });

        Schema::create('sesame_send_refusals', function (Blueprint $table): void {
            $table->uuid('account_id');
            $table->uuid('run_id')->nullable();
            $table->string('kind', 16); // throttled | rejected
            $table->timestampTz('at', 3)->index();
            $table->index(['run_id', 'kind']);
        });

        Schema::create('sesame_wal_checkpoint', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('segment');
            $table->unsignedBigInteger('offset');
            $table->timestampTz('updated_at', 3);
        });

        // Config changes reach the data plane through LISTEN/NOTIFY; it never polls config.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sesame_notify_config() RETURNS trigger AS $$
            BEGIN
                PERFORM pg_notify('sesame_config', TG_TABLE_NAME || ':' || NEW.id::text);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER sesame_accounts_notify AFTER INSERT OR UPDATE ON sesame_accounts
                FOR EACH ROW EXECUTE FUNCTION sesame_notify_config();
            CREATE TRIGGER sesame_runs_notify AFTER INSERT OR UPDATE ON sesame_runs
                FOR EACH ROW EXECUTE FUNCTION sesame_notify_config();
        SQL);
    }

    public function down(): void
    {
        foreach (['sesame_wal_checkpoint', 'sesame_send_refusals', 'sesame_delivery_attempts', 'sesame_events', 'sesame_messages', 'sesame_runs', 'sesame_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS sesame_notify_config() CASCADE');
    }
};
