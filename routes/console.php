<?php

use App\Sesame\Delivery\Sweeper;
use App\Sesame\Ingest\IngestBuffer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sesame:prune')->daily()->onOneServer();

// Sub-minute: Laravel Cloud runs schedule:run each minute and Laravel keeps it
// alive for the minute to fire this every second. onOneServer() uses the
// cache store Cloud points the scheduler at, so replicas do not double-sweep;
// even if two did, SKIP LOCKED leases keep their claims disjoint.
Schedule::call(fn () => app(Sweeper::class)->sweep())->name('sesame:sweep')->everySecond()->onOneServer()->withoutOverlapping(1);

// Every replica drains its OWN ingest buffer, so no onOneServer() here. This
// catches what the after-response drain did not, such as the last sends
// before traffic stops, and tells Postgres the buffer is still alive (see
// IngestBuffer::lossFor()). The buffer's file lock keeps drains from overlapping.
Schedule::call(fn () => IngestBuffer::drain(heartbeat: true))->name('sesame:drain')->everySecond();
