<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// No scheduled tasks, on purpose: a schedule wakes a hibernating environment.
// Draining, sweeping and pruning ride on requests (App\Sesame\Http\Housekeeping)
// and delayed queue jobs (App\Sesame\Delivery\Wakeups).
