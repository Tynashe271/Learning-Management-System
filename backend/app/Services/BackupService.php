<?php

namespace App\Services;

use App\Support\TarGz;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Backs the institution's data up and puts it back.
 *
 * A backup is one .tar.gz holding every table as one JSON line per row, the uploaded files (course files, submissions), and a
 * manifest with a checksum for each part. It leaves out things that are only temporary or secret and
 * that are safer to lose than to copy: cache, queued and failed jobs, sessions, sign-in tokens and reset links. After a
 * restore everyone simply signs in again.
 */
class BackupService
{
    public const NAME_PATTERN = '/^backup-\d{8}-\d{6}\.tar\.gz$/';

    /** Tables that are not backed up (temporary, or secrets that should not sit in a backup file). */
    public const EXCLUDED = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens', 'password_reset_tokens', 'account_invitation_tokens', 'migrations'];

    private const FILE_PREFIXES = ['course-files', 'submissions'];

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('lms.backups.disk'));
    }

    /** Whether a backup is being made right now, and when the last one failed. @return array{running: bool, started_at: ?string, last_error: ?string} */
    public function status(): array
    {
        return ['running' => Cache::has('lms:backup:running'), 'started_at' => Cache::get('lms:backup:running'), 'last_error' => Cache::get('lms:backup:error')];
    }

    // ---- create -------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the manifest of the new backup */
    public function create(bool $withFiles = true): array
    {
        $lock = Cache::lock('lms:backup', 7200);
        if (! $lock->get()) {
            throw new RuntimeException('A backup is already running.');
        }
        Cache::put('lms:backup:running', now()->toIso8601String(), 7200);
        Cache::forget('lms:backup:error');
        $tmp = tempnam(sys_get_temp_dir(), 'lmsb');
        try {
            $name = 'backup-'.now('UTC')->format('Ymd-His').'.tar.gz';
            $tar = TarGz::create($tmp);
            $manifest = [
                'format' => 1, 'created_at' => now()->toIso8601String(), 'app' => config('lms.institution.name'), 'php' => PHP_VERSION, 'laravel' => app()->version(),
                'database' => DB::connection()->getDriverName(), 'migrations' => $this->ranMigrations(), 'include_files' => $withFiles, 'tables' => [], 'files' => [], 'missing_files' => [],
            ];

            foreach ($this->orderedTables(config('database.default')) as $table) {
                $buffer = fopen('php://temp/maxmemory:8388608', 'w+b');
                $rows = 0;
                foreach ($this->rows($table) as $row) {
                    fwrite($buffer, json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION)."\n");
                    $rows++;
                }
                $size = ftell($buffer);
                rewind($buffer);
                $manifest['tables'][$table] = ['rows' => $rows, 'sha256' => $tar->addStream("data/{$table}.ndjson", $buffer, (int) $size)];
                fclose($buffer);
            }

            $bytes = 0;
            if ($withFiles) {
                $files = Storage::disk('s3');
                foreach (self::FILE_PREFIXES as $prefix) {
                    foreach ($files->allFiles($prefix) as $path) {
                        try {
                            $size = (int) $files->size($path);
                            $stream = $files->readStream($path);
                            $manifest['files'][$path] = $tar->addStream("files/{$path}", $stream, $size);
                            $bytes += $size;
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                        } catch (Throwable $e) {
                            report($e);
                            $manifest['missing_files'][] = $path;
                        }
                    }
                }
            }
            $manifest['file_bytes'] = $bytes;
            $tar->addString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $tar->close();

            $handle = fopen($tmp, 'rb');
            $this->disk()->writeStream($name, $handle);
            fclose($handle);
            $summary = ['name' => $name] + $this->summarise($manifest) + ['size' => filesize($tmp)];
            $this->disk()->put($name.'.json', json_encode($summary));

            return $summary;
        } catch (Throwable $e) {
            Cache::put('lms:backup:error', $e->getMessage(), 86400);
            throw $e;
        } finally {
            @unlink($tmp);
            Cache::forget('lms:backup:running');
            $lock->release();
        }
    }

    // ---- list, verify, delete, prune -------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> newest first */
    public function list(): array
    {
        $out = [];
        foreach ($this->disk()->files() as $file) {
            if (! preg_match(self::NAME_PATTERN, $file)) {
                continue;
            }
            $summary = [];
            if ($this->disk()->exists($file.'.json')) {
                $summary = json_decode((string) $this->disk()->get($file.'.json'), true) ?: [];
            }
            $out[] = ['name' => $file, 'size' => $this->disk()->size($file), 'created_at' => date('c', $this->disk()->lastModified($file))] + $summary;
        }
        usort($out, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $out;
    }

    /**
     * Reads the whole backup and checks every part against its checksum, so a damaged or truncated file is found now and not on
     * the day it is needed.
     *
     * @return array{ok: bool, problems: list<string>, manifest: ?array<string, mixed>, summary: ?array<string, mixed>, checked: int}
     */
    public function verify(string $name): array
    {
        [$path, $cleanup] = $this->localCopy($name);
        $problems = [];
        $seen = [];
        $manifest = null;
        try {
            TarGz::read($path, function (string $entry, int $size, $stream) use (&$seen, &$manifest) {
                $seen[$entry] = hash_init('sha256');
                while (! feof($stream)) {
                    hash_update($seen[$entry], (string) fread($stream, 1048576));
                }
                $seen[$entry] = hash_final($seen[$entry]);
                if ($entry === 'manifest.json') {
                    rewind($stream);
                    $manifest = json_decode((string) stream_get_contents($stream), true);
                }
            });
        } catch (Throwable $e) {
            $problems[] = 'The file could not be read to the end: '.$e->getMessage();
        } finally {
            $cleanup();
        }
        if ($manifest === null) {
            $problems[] = 'The backup has no readable manifest, so it is incomplete.';
        } else {
            foreach ($manifest['tables'] as $table => $info) {
                $key = "data/{$table}.ndjson";
                if (! isset($seen[$key])) {
                    $problems[] = "The data for {$table} is missing.";
                } elseif ($seen[$key] !== $info['sha256']) {
                    $problems[] = "The data for {$table} is damaged (checksum differs).";
                }
            }
            foreach ($manifest['files'] as $file => $sha) {
                if (! isset($seen["files/{$file}"])) {
                    $problems[] = "The file {$file} is missing.";
                } elseif ($seen["files/{$file}"] !== $sha) {
                    $problems[] = "The file {$file} is damaged (checksum differs).";
                }
            }
        }

        return ['ok' => $problems === [], 'problems' => $problems, 'manifest' => $manifest, 'summary' => $manifest ? $this->summarise($manifest) : null, 'checked' => count($seen)];
    }

    public function delete(string $name): void
    {
        $this->assertName($name);
        $this->disk()->delete([$name, $name.'.json']);
    }

    /** Keeps the newest $keep backups and deletes the rest. @return list<string> the names deleted */
    public function prune(int $keep): array
    {
        $old = array_slice($this->list(), max(1, $keep));
        foreach ($old as $backup) {
            $this->delete($backup['name']);
        }

        return array_column($old, 'name');
    }

    /** A readable copy of a backup on this machine, and how to remove it afterwards. @return array{0: string, 1: callable} */
    public function localCopy(string $name): array
    {
        $this->assertName($name);
        if (! $this->disk()->exists($name)) {
            throw new RuntimeException("There is no backup called {$name}.");
        }
        $config = config('filesystems.disks.'.config('lms.backups.disk'));
        if (($config['driver'] ?? null) === 'local') {
            return [$this->disk()->path($name), fn () => null];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'lmsr');
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($this->disk()->readStream($name), $out);
        fclose($out);

        return [$tmp, fn () => @unlink($tmp)];
    }

    // ---- restore ------------------------------------------------------------------------------------------------------

    /**
     * Replaces the database's contents (and, unless told not to, the uploaded files) with what is in the backup. The database
     * part is one transaction: it either completes or leaves everything as it was. Files are copied back afterwards; files
     * uploaded since the backup are left alone.
     *
     * @return array<string, mixed>
     */
    public function restore(string $name, ?string $connection = null, bool $withFiles = true, ?callable $log = null): array
    {
        $log ??= fn (string $m) => null;
        $connection ??= (string) config('database.default');
        $check = $this->verify($name);
        if (! $check['ok']) {
            throw new RuntimeException('The backup is damaged and was not restored: '.implode(' ', $check['problems']));
        }
        $manifest = $check['manifest'];
        $unknown = array_diff($manifest['migrations'] ?? [], $this->migrationFiles());
        if ($unknown !== []) {
            throw new RuntimeException('This backup was made by a newer version of the system (it knows migrations this one does not: '.implode(', ', array_slice($unknown, 0, 3)).'). Update the system first.');
        }

        [$path, $cleanup] = $this->localCopy($name);
        $tables = array_keys($manifest['tables']);
        $existing = collect(Schema::connection($connection)->getTables())->pluck('name')->all();
        $restorable = array_values(array_filter($tables, fn ($t) => in_array($t, $existing, true)));
        $counts = [];
        try {
            $log('Replacing the database contents...');
            DB::connection($connection)->transaction(function () use ($path, $restorable, $connection, &$counts) {
                foreach (array_reverse($restorable) as $table) {
                    DB::connection($connection)->table($table)->delete();
                }
                TarGz::read($path, function (string $entry, int $size, $stream) use ($restorable, $connection, &$counts) {
                    if (! preg_match('#^data/(.+)\.ndjson$#', $entry, $m) || ! in_array($m[1], $restorable, true)) {
                        return;
                    }
                    $columns = Schema::connection($connection)->getColumnListing($m[1]);
                    $batch = [];
                    $counts[$m[1]] = 0;
                    while (($line = fgets($stream)) !== false) {
                        $row = json_decode($line, true);
                        $batch[] = array_intersect_key($row, array_flip($columns));
                        $counts[$m[1]]++;
                        if (count($batch) >= 200) {
                            DB::connection($connection)->table($m[1])->insert($batch);
                            $batch = [];
                        }
                    }
                    if ($batch !== []) {
                        DB::connection($connection)->table($m[1])->insert($batch);
                    }
                });
                $this->resetSequences($connection, $restorable);
            });

            $files = 0;
            if ($withFiles && ($manifest['include_files'] ?? false)) {
                $log('Copying the uploaded files back...');
                $disk = Storage::disk('s3');
                TarGz::read($path, function (string $entry, int $size, $stream) use ($disk, &$files) {
                    if (str_starts_with($entry, 'files/')) {
                        $disk->writeStream(substr($entry, 6), $stream);
                        $files++;
                    }
                });
            }
        } finally {
            $cleanup();
        }

        $log('Applying any newer migrations...');
        Artisan::call('migrate', ['--force' => true, '--database' => $connection]);
        Cache::flush();

        return ['tables' => count($counts), 'rows' => array_sum($counts), 'files' => $files, 'backup' => $check['summary']];
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

    /** @return list<string> the tables to back up, parents before the tables that refer to them */
    public function orderedTables(?string $connection = null): array
    {
        $schema = Schema::connection($connection);
        $all = collect($schema->getTables())->pluck('name')->reject(fn ($t) => in_array($t, self::EXCLUDED, true))->sort()->values()->all();
        $deps = [];
        foreach ($all as $table) {
            $deps[$table] = collect($schema->getForeignKeys($table))->pluck('foreign_table')->unique()->filter(fn ($p) => $p !== $table && in_array($p, $all, true))->values()->all();
        }
        $ordered = [];
        while ($deps !== []) {
            $ready = array_keys(array_filter($deps, fn ($d) => array_diff($d, $ordered) === []));
            if ($ready === []) {
                throw new RuntimeException('The tables refer to each other in a circle ('.implode(', ', array_keys($deps)).'), so they cannot be ordered.');
            }
            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($deps[$table]);
            }
        }

        return $ordered;
    }

    /** @return iterable<object> */
    private function rows(string $table): iterable
    {
        $query = DB::table($table);
        if (Schema::hasColumn($table, 'id')) {
            return $query->lazyById(1000);
        }

        return $query->cursor();
    }

    /** @param  list<string>  $tables */
    private function resetSequences(string $connection, array $tables): void
    {
        $db = DB::connection($connection);
        if ($db->getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($tables as $table) {
            // Only tables whose id is counted by a sequence (notifications, for one, use UUIDs and have none).
            if (! Schema::connection($connection)->hasColumn($table, 'id') || $db->selectOne("select pg_get_serial_sequence('\"{$table}\"', 'id') as seq")->seq === null) {
                continue;
            }
            $db->statement("select setval(pg_get_serial_sequence('\"{$table}\"', 'id'), coalesce(max(id), 1), max(id) is not null) from \"{$table}\"");
        }
    }

    /** @return list<string> */
    private function ranMigrations(): array
    {
        return DB::table('migrations')->orderBy('id')->pluck('migration')->all();
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $migrator = app('migrator');

        return array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
    }

    /** @param  array<string, mixed>  $manifest @return array<string, mixed> */
    public function summarise(array $manifest): array
    {
        return [
            'created_at' => $manifest['created_at'], 'include_files' => $manifest['include_files'], 'tables' => count($manifest['tables']),
            'rows' => array_sum(array_column($manifest['tables'], 'rows')), 'files' => count($manifest['files']), 'file_bytes' => $manifest['file_bytes'] ?? 0,
            'missing_files' => count($manifest['missing_files'] ?? []), 'database' => $manifest['database'], 'migrations' => count($manifest['migrations']),
        ];
    }

    private function assertName(string $name): void
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            throw new RuntimeException('That is not a backup file name.');
        }
    }
}
