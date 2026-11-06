<?php

namespace App\CrmHarness;

/**
 * LOCAL CRM-COMPATIBLE HARNESS. Millisecond timestamps for the harness's own
 * metrics: the query builder formats Carbon values to whole seconds, which
 * makes send latency and event lag unmeasurable.
 */
final class Clock
{
    public static function now(): string
    {
        return now()->format('Y-m-d H:i:s.vP');
    }
}
