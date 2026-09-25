<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

class Backup extends Command
{
    protected $signature = 'lms:backup {--no-files : Back up the database only} {--keep= : Keep this many backups (default: the setting)}';

    protected $description = 'Back up the database and uploaded files, then delete the oldest backups beyond the number to keep';

    public function handle(BackupService $backups): int
    {
        try {
            $summary = $backups->create(! $this->option('no-files'));
        } catch (Throwable $e) {
            $this->error('The backup failed: '.$e->getMessage());
            activity()->withProperties(['error' => $e->getMessage()])->log('backup failed');
            report($e);

            return self::FAILURE;
        }
        $removed = $backups->prune((int) ($this->option('keep') ?: config('lms.backups.keep')));
        activity()->withProperties($summary + ['removed_old' => count($removed)])->log('backup created');
        $this->line(json_encode($summary + ['removed_old' => $removed]));

        return self::SUCCESS;
    }
}
