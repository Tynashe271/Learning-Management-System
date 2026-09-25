<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BackupService;
use App\Services\PruneService;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/** The technical side of running the system: versions, capacity, queues, failed jobs, housekeeping. For super administrators. */
class SystemController extends Controller
{
    /** Everything a person keeping the system running wants on one screen, and a list of what needs attention. */
    public function info(Request $request, BackupService $backups): JsonResponse
    {
        $this->allow($request);
        $warnings = [];
        $driver = DB::connection()->getDriverName();

        $started = microtime(true);
        $dbOk = true;
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $dbOk = false;
            $warnings[] = 'The database is not answering.';
        }
        $latency = round((microtime(true) - $started) * 1000, 1);
        $size = $driver === 'pgsql' ? (int) DB::selectOne('select pg_database_size(current_database()) as s')->s : ($driver === 'sqlite' && is_file((string) config('database.connections.sqlite.database')) ? (int) filesize((string) config('database.connections.sqlite.database')) : null);

        $tables = [];
        foreach (['users', 'courses', 'course_offerings', 'enrolments', 'assignments', 'submissions', 'grade_records', 'quiz_attempts', 'direct_messages', 'activity_log', 'security_events'] as $table) {
            $tables[$table] = DB::table($table)->count();
        }

        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
        $pending = array_values(array_diff($files, DB::table('migrations')->pluck('migration')->all()));
        if ($pending !== []) {
            $warnings[] = count($pending).' database update(s) have not been applied. Run: php artisan migrate --database=pgsql_migrate --force';
        }

        $failed = DB::table('failed_jobs')->count();
        if ($failed > 0) {
            $warnings[] = "{$failed} background job(s) have failed. See Failed jobs.";
        }
        $queue = ['driver' => config('queue.default'), 'waiting' => $this->queueSize('redis', 'default'), 'waiting_backups' => $this->queueSize('redis_long', 'backups'), 'failed' => $failed];
        if (($queue['waiting'] ?? 0) > 2000) {
            $warnings[] = 'A large number of background jobs are waiting; the worker may be stuck.';
        }

        $beat = Cache::get('lms:scheduler:heartbeat');
        $scheduler = ['last_beat_seconds_ago' => $beat ? time() - (int) $beat : null, 'ok' => $beat !== null && time() - (int) $beat <= 180];
        if (! $scheduler['ok']) {
            $warnings[] = 'The scheduler has not run recently, so reminders, summaries and backups are not being sent.';
        }

        $disk = ['free' => @disk_free_space(storage_path()) ?: null, 'total' => @disk_total_space(storage_path()) ?: null];
        if ($disk['free'] && $disk['total'] && $disk['free'] / $disk['total'] < 0.1) {
            $warnings[] = 'Less than 10% of the disk is free.';
        }

        $list = $backups->list();
        $last = $list[0] ?? null;
        $ageHours = $last ? round((time() - strtotime($last['created_at'])) / 3600, 1) : null;
        if (config('lms.backups.enabled') && ($last === null || $ageHours > 36)) {
            $warnings[] = $last === null ? 'No backup has been made yet.' : "The last backup is {$ageHours} hours old.";
        }
        if (config('app.debug') && config('app.env') === 'production') {
            $warnings[] = 'Debug mode is on in production. Turn it off (APP_DEBUG=false).';
        }
        if (config('lms.maintenance.enabled')) {
            $warnings[] = 'Maintenance mode is on: only super administrators can use the system.';
        }

        return response()->json([
            'warnings' => $warnings,
            'application' => ['name' => config('lms.institution.name'), 'version' => config('lms.version'), 'environment' => config('app.env'), 'debug' => (bool) config('app.debug'), 'timezone' => config('lms.institution.timezone'), 'php' => PHP_VERSION, 'laravel' => app()->version(), 'server_time' => now()->toIso8601String()],
            'database' => ['driver' => $driver, 'ok' => $dbOk, 'latency_ms' => $latency, 'size_bytes' => $size, 'tables' => $tables, 'pending_migrations' => $pending],
            'storage' => $this->storageUsage(),
            'disk' => $disk,
            'queue' => $queue,
            'scheduler' => $scheduler,
            'cache' => ['store' => config('cache.default')],
            'mail' => ['mailer' => config('mail.default'), 'from' => config('mail.from.address')],
            'backups' => ['count' => count($list), 'last' => $last, 'age_hours' => $ageHours, 'status' => $backups->status()],
        ]);
    }

    // ---- failed jobs --------------------------------------------------------------------------------------------------

    public function failedJobs(Request $request): JsonResponse
    {
        $this->allow($request);
        $page = DB::table('failed_jobs')->orderByDesc('id')->paginate(20);
        $page->getCollection()->transform(function ($job) {
            $payload = json_decode($job->payload, true) ?: [];

            return ['id' => $job->id, 'uuid' => $job->uuid, 'queue' => $job->queue, 'job' => $payload['displayName'] ?? 'Unknown job', 'error' => mb_substr(strtok((string) $job->exception, "\n") ?: '', 0, 300), 'failed_at' => $job->failed_at];
        });

        return response()->json($page);
    }

    public function retryFailedJob(Request $request, string $uuid): JsonResponse
    {
        $this->allow($request);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        activity()->causedBy($request->user())->withProperties(['job' => $uuid])->log('failed job retried');

        return response()->json(['message' => 'The job has been put back in the queue.']);
    }

    public function retryAllFailedJobs(Request $request): JsonResponse
    {
        $this->allow($request);
        $count = DB::table('failed_jobs')->count();
        Artisan::call('queue:retry', ['id' => ['all']]);
        activity()->causedBy($request->user())->withProperties(['jobs' => $count])->log('failed jobs retried');

        return response()->json(['retried' => $count]);
    }

    public function forgetFailedJob(Request $request, string $uuid): JsonResponse
    {
        $this->allow($request);
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->delete() > 0, 404);
        activity()->causedBy($request->user())->withProperties(['job' => $uuid])->log('failed job deleted');

        return response()->json(['message' => 'Deleted.']);
    }

    public function flushFailedJobs(Request $request): JsonResponse
    {
        $this->allow($request);
        $count = DB::table('failed_jobs')->delete();
        activity()->causedBy($request->user())->withProperties(['jobs' => $count])->log('failed jobs cleared');

        return response()->json(['deleted' => $count]);
    }

    // ---- housekeeping -------------------------------------------------------------------------------------------------

    /** Forgets cached copies of things that are recalculated on demand (reports, settings, permissions). Sign-in lockouts and rate limits are NOT touched. */
    public function refreshCaches(Request $request): JsonResponse
    {
        $this->allow($request);
        foreach (['reports:overview', 'settings:overrides', 'system:storage-usage'] as $key) {
            Cache::forget($key);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(Settings::class)->apply();
        activity()->causedBy($request->user())->log('caches refreshed');

        return response()->json(['message' => 'Cached reports, settings and permissions were refreshed.']);
    }

    /** `dry_run` counts what would be removed without removing it. */
    public function prune(Request $request, PruneService $prune): JsonResponse
    {
        $this->allow($request);
        $dry = $request->boolean('dry_run');
        $counts = $prune->run($dry);
        if (! $dry) {
            activity()->causedBy($request->user())->withProperties($counts)->log('old records removed');
        }

        return response()->json(['dry_run' => $dry, 'removed' => $counts]);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

    private function queueSize(string $connection, string $queue): ?int
    {
        if (config('queue.default') === 'sync') {
            return 0;
        }
        try {
            return (int) Queue::connection($connection)->size($queue);
        } catch (Throwable) {
            return null;
        }
    }

    /** Files held in storage, by kind, counted from the storage listing (cached for ten minutes: listing a big bucket is slow). @return array<string, mixed> */
    private function storageUsage(): array
    {
        return Cache::remember('system:storage-usage', 600, function () {
            $out = ['ok' => true, 'by_kind' => [], 'total_bytes' => 0, 'total_files' => 0, 'truncated' => false];
            try {
                $driver = Storage::disk('s3')->getDriver();
                foreach (['course-files' => 'Course files', 'submissions' => 'Submissions', 'message-attachments' => 'Message attachments'] as $prefix => $label) {
                    $files = 0;
                    $bytes = 0;
                    foreach ($driver->listContents($prefix, true) as $item) {
                        if (! $item->isFile()) {
                            continue;
                        }
                        $files++;
                        $bytes += (int) $item->fileSize();
                        if ($files >= 200000) {
                            $out['truncated'] = true;
                            break;
                        }
                    }
                    $out['by_kind'][$prefix] = ['label' => $label, 'files' => $files, 'bytes' => $bytes];
                    $out['total_files'] += $files;
                    $out['total_bytes'] += $bytes;
                }
            } catch (Throwable $e) {
                $out['ok'] = false;
                $out['error'] = 'File storage could not be listed.';
            }

            return $out;
        });
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('manage-system'), 403);
    }
}
