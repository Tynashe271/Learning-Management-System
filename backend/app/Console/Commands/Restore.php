<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Puts a backup back. Deliberately a command and not a button: it replaces everything, so it is done on purpose, at the
 * server, by someone who has read what it will do.
 */
class Restore extends Command
{
    protected $signature = 'lms:restore {backup? : The backup file name (see --list)} {--list : Show the available backups} {--force : Do not ask for confirmation} {--skip-files : Restore the database but not the uploaded files} {--no-safety-backup : Do not back up the current data first}';

    protected $description = 'Replace the database and uploaded files with the contents of a backup';

    public function handle(BackupService $backups): int
    {
        if ($this->option('list') || ! $this->argument('backup')) {
            $rows = collect($backups->list())->map(fn ($b) => [$b['name'], $b['created_at'], round($b['size'] / 1048576, 1).' MB', $b['rows'] ?? '?', $b['files'] ?? '?'])->all();
            $rows === [] ? $this->warn('There are no backups yet.') : $this->table(['Backup', 'Made', 'Size', 'Rows', 'Files'], $rows);

            return self::SUCCESS;
        }

        $name = (string) $this->argument('backup');
        $this->info("Checking {$name} ...");
        $check = $backups->verify($name);
        if (! $check['ok']) {
            $this->error('This backup is damaged and cannot be restored:');
            foreach ($check['problems'] as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }
        $s = $check['summary'];
        $this->line("Made {$s['created_at']}: {$s['rows']} rows in {$s['tables']} tables, {$s['files']} files.");
        $this->warn('Restoring REPLACES the current data with this backup. Anything changed since it was made is lost, and everyone will have to sign in again.');
        if (! $this->option('force') && (! $this->input->isInteractive() || $this->ask('Type RESTORE to continue') !== 'RESTORE')) {
            $this->error('Cancelled. Nothing was changed. (Use --force to run without being asked.)');

            return self::FAILURE;
        }

        if (! $this->option('no-safety-backup')) {
            $this->line('Backing up the current data first, in case you need to come back...');
            $safety = $backups->create(false);
            $this->line("  saved as {$safety['name']}");
        }

        $connection = config('database.default') === 'pgsql' && config('database.connections.pgsql_migrate') ? 'pgsql_migrate' : null;
        Artisan::call('down', ['--retry' => 60]);
        try {
            $result = $backups->restore($name, $connection, ! $this->option('skip-files'), fn (string $m) => $this->line("  {$m}"));
        } catch (Throwable $e) {
            $this->error('The restore failed and the database was left as it was: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            Artisan::call('up');
        }
        activity()->withProperties(['backup' => $name, 'rows' => $result['rows'], 'files' => $result['files']])->log('backup restored');
        $this->info("Restored {$result['rows']} rows in {$result['tables']} tables and {$result['files']} files.");

        return self::SUCCESS;
    }
}
