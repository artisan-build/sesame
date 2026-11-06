<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * LOCAL CRM-COMPATIBLE HARNESS ONLY. These tables stand in for Growth CRM's
 * outbound message, contact and SNS receipt state so Sesame's loop can be
 * closed without touching the real CRM. They are not part of Sesame's
 * contract and are never created on production (see CrmHarnessServiceProvider).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harness_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
        });

        Schema::create('harness_contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->timestampTz('suppressed_at', 3)->nullable();
            $table->string('suppression_reason')->nullable();
            $table->string('suppression_event')->nullable();
        });

        Schema::create('harness_outbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('harness_contacts');
            $table->string('load_run');
            $table->string('state')->default('queued'); // queued | accepted | failed | skipped_suppressed
            $table->string('provider_message_id', 80)->nullable()->unique();
            $table->timestampTz('dispatched_at', 3);
            $table->timestampTz('started_at', 3)->nullable();
            $table->timestampTz('accepted_at', 3)->nullable();
            $table->unsignedInteger('throttled')->default(0);
            $table->text('failure')->nullable();
            // Order-tolerant delivery state, derived from every event received.
            $table->string('delivery_state')->default('unknown'); // unknown|sent|delayed|delivered|soft_bounced|bounced|complained
            $table->timestampTz('delivered_at', 3)->nullable();
            $table->string('bounce_type')->nullable();
            $table->string('bounce_subtype')->nullable();
            $table->boolean('delayed')->default(false);
            $table->boolean('complained')->default(false);
            $table->index(['load_run', 'state']);
        });

        Schema::create('harness_sns_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('sns_message_id')->unique(); // idempotency on the SNS MessageId
            $table->string('type');
            $table->text('raw');
            $table->timestampTz('received_at', 3);
            $table->timestampTz('processed_at', 3)->nullable();
        });

        Schema::create('harness_message_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('sns_message_id')->unique();
            $table->string('provider_message_id', 80)->index();
            $table->string('event_type');
            $table->string('recipient')->nullable();
            $table->string('bounce_type')->nullable();
            $table->string('bounce_subtype')->nullable();
            $table->jsonb('tags');
            $table->timestampTz('event_at', 3)->nullable();
            $table->timestampTz('received_at', 3);
            $table->timestampTz('processed_at', 3);
        });
    }

    public function down(): void
    {
        foreach (['harness_message_events', 'harness_sns_receipts', 'harness_outbound_messages', 'harness_contacts', 'harness_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
