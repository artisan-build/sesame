<?php

namespace App\Sesame\Http;

use App\Sesame\Delivery\Sweeper;
use App\Sesame\Ingest\IngestBuffer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function Illuminate\Support\defer;

/**
 * What a scheduler would otherwise do, run after the response of any inbound
 * request, so Sesame can hibernate when nobody calls it.
 *
 * - Every request: drain this instance's ingest buffer, every row it can
 *   claim within the drain budget (IngestBuffer::drain()). The buffer is a
 *   local file, so only this instance can drain it.
 * - At most every `sesame.housekeeping_seconds` per instance: tell Postgres
 *   the buffer is alive, and sweep overdue events, in case a wakeup job was
 *   lost (Wakeups). A lease running out schedules its own wakeup too, so this
 *   is a second line, not the only one.
 * - At most daily across all instances: prune past the retention window.
 *
 * It runs after the response is sent, holding the worker but not the client.
 * Failures are reported and swallowed: they must not turn a served request
 * into an error, and the next request tries again.
 */
final class Housekeeping
{
    public function handle(Request $request, Closure $next): Response
    {
        defer(self::run(...), 'sesame-housekeeping');

        return $next($request);
    }

    public static function run(): void
    {
        try {
            IngestBuffer::drain();
            $every = (int) config('sesame.housekeeping_seconds');
            if ($every <= 0 || ! IngestBuffer::claimHousekeeping($every)) {
                return;
            }
            IngestBuffer::register();
            resolve(Sweeper::class)->sweep();
            if (Cache::add('sesame:pruned', true, 86_400)) {
                Artisan::call('sesame:prune');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
