<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Makes a backup in the background, for an administrator who asked for one in the app. */
class CreateBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public bool $withFiles, public ?int $requestedBy = null) {}

    /** Sends the job to the worker built for long jobs (or runs it straight away when queues are switched off, as in tests). */
    public static function start(bool $withFiles, ?int $requestedBy): void
    {
        $pending = self::dispatch($withFiles, $requestedBy);
        if (config('queue.default') !== 'sync') {
            $pending->onConnection('redis_long')->onQueue('backups');
        }
    }

    public function handle(BackupService $backups): void
    {
        $summary = $backups->create($this->withFiles);
        $backups->prune((int) config('lms.backups.keep'));
        activity()->causedBy($this->requestedBy ? User::find($this->requestedBy) : null)->withProperties($summary)->log('backup created');
    }

    public function failed(Throwable $e): void
    {
        activity()->causedBy($this->requestedBy ? User::find($this->requestedBy) : null)->withProperties(['error' => $e->getMessage()])->log('backup failed');
    }
}
