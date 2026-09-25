<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * For uptime monitors. Point the monitor at GET /api/health: it answers 200 when the app can serve requests and 503 when the
 * database, cache, or file storage is down. Background trouble (scheduler stopped, queue backed up, jobs failing) shows as
 * "degraded" with a 200, so it can be alerted on by looking for that word without paging anyone for a brief blip.
 * It reveals no versions, hostnames, or error text.
 */
class HealthController extends Controller
{
    private const SCHEDULER_STALE_SECONDS = 180;

    private const QUEUE_BACKLOG = 2000;

    private const FAILED_JOBS_LAST_HOUR = 10;

    public function show(): JsonResponse
    {
        $checks = [
            'database' => $this->probe('database', fn () => DB::select('select 1') !== []),
            'cache' => $this->probe('cache', function () {
                Cache::put('lms:health', 'ok', 10);

                return Cache::get('lms:health') === 'ok';
            }),
            'storage' => $this->probe('storage', function () {
                Storage::disk('s3')->exists('.health'); // an existence check; it throws only if storage cannot be reached

                return true;
            }),
            'scheduler' => $this->scheduler(),
            'queue' => $this->queue(),
        ];
        $down = collect(['database', 'cache', 'storage'])->contains(fn ($name) => $checks[$name] !== 'ok');
        $status = $down ? 'down' : (collect($checks)->contains(fn ($v) => $v !== 'ok') ? 'degraded' : 'ok');

        return response()->json(['status' => $status, 'checks' => $checks, 'time' => now()->toIso8601String()], $down ? 503 : 200, ['Cache-Control' => 'no-store']);
    }

    private function probe(string $name, callable $check): string
    {
        try {
            return $check() ? 'ok' : 'fail';
        } catch (Throwable $e) {
            Log::error("Health check failed: {$name}", ['error' => $e->getMessage()]);

            return 'fail';
        }
    }

    private function scheduler(): string
    {
        $beat = (int) Cache::get('lms:scheduler:heartbeat', 0);

        return $beat > 0 && time() - $beat <= self::SCHEDULER_STALE_SECONDS ? 'ok' : 'stale';
    }

    private function queue(): string
    {
        try {
            if (Queue::size() > self::QUEUE_BACKLOG) {
                return 'backlog';
            }
            if (DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count() >= self::FAILED_JOBS_LAST_HOUR) {
                return 'failing';
            }

            return 'ok';
        } catch (Throwable $e) {
            Log::error('Health check failed: queue', ['error' => $e->getMessage()]);

            return 'fail';
        }
    }
}
