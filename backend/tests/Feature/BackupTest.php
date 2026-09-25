<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackupService;
use App\Services\PruneService;
use App\Support\TarGz;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private BackupService $backups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('s3');
        Storage::fake('backups');
        $this->backups = app(BackupService::class);
    }

    /** A little institution: a course, a student with a submission and a grade, a message, and uploaded files. */
    private function populate(): array
    {
        $offering = $this->offering();
        $teacher = $this->userWithRole('lecturer', ['name' => 'Dr Backup']);
        $student = $this->userWithRole('student', ['name' => 'Ada Backup', 'email' => 'ada@example.test']);
        $this->teach($offering, $teacher);
        $this->enrol($offering, $student);
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 20, 'published' => true]);
        $submission = $assignment->submissions()->create(['user_id' => $student->id, 'body' => 'Unicode: café — 你好', 'storage_path' => "submissions/{$assignment->id}/{$student->id}/essay.txt", 'submitted_at' => now()]);
        Storage::disk('s3')->put($submission->storage_path, 'THE ESSAY FILE');
        Storage::disk('s3')->put("course-files/{$offering->id}/notes.pdf", str_repeat('x', 1000));
        $submission->gradeRecords()->create(['graded_by' => $teacher->id, 'score' => 17.5, 'status' => 'published', 'criteria_scores' => [['title' => 'Argument', 'points' => 12]]]);
        DB::table('direct_messages')->insert(['sender_id' => $student->id, 'recipient_id' => $teacher->id, 'body' => 'Hello', 'created_at' => now(), 'updated_at' => now()]);
        $student->createToken('api');
        DB::table('password_reset_tokens')->insert(['email' => 'ada@example.test', 'token' => 'secret', 'created_at' => now()]);

        return compact('offering', 'teacher', 'student', 'assignment', 'submission');
    }

    // ---- the archive format ------------------------------------------------------------------------------------------

    public function test_the_archive_round_trips_files_of_any_size_and_long_names(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tar');
        $long = 'files/'.str_repeat('folder/', 20).'a-rather-long-file-name.txt';
        $big = random_bytes(3 * 1048576 + 17);
        $tar = TarGz::create($path);
        $hashes = [
            'empty.txt' => $tar->addString('empty.txt', ''),
            'exact.bin' => $tar->addString('exact.bin', str_repeat('a', 512)),
            $long => $tar->addString($long, 'long name'),
            'big.bin' => $tar->addStream('big.bin', $this->stream($big), strlen($big)),
        ];
        $tar->close();

        $found = [];
        TarGz::read($path, function (string $name, int $size, $stream) use (&$found) {
            $found[$name] = [$size, hash('sha256', stream_get_contents($stream))];
        });

        $this->assertSame(array_keys($hashes), array_keys($found));
        foreach ($hashes as $name => $hash) {
            $this->assertSame($hash, $found[$name][1], $name);
        }
        $this->assertSame(strlen($big), $found['big.bin'][0]);
        @unlink($path);
    }

    private function stream(string $data)
    {
        $s = fopen('php://memory', 'w+b');
        fwrite($s, $data);
        rewind($s);

        return $s;
    }

    // ---- creating and checking ---------------------------------------------------------------------------------------

    public function test_a_backup_holds_every_table_and_the_uploaded_files_but_none_of_the_secrets(): void
    {
        $this->populate();

        $summary = $this->backups->create();

        $this->assertMatchesRegularExpression(BackupService::NAME_PATTERN, $summary['name']);
        Storage::disk('backups')->assertExists([$summary['name'], $summary['name'].'.json']);
        $this->assertSame(2, $summary['files']);
        $this->assertGreaterThan(20, $summary['rows']);
        $this->assertTrue($summary['include_files']);

        $entries = [];
        $manifest = null;
        TarGz::read(Storage::disk('backups')->path($summary['name']), function (string $name, int $size, $stream) use (&$entries, &$manifest) {
            $entries[] = $name;
            if ($name === 'manifest.json') {
                $manifest = json_decode(stream_get_contents($stream), true);
            }
        });
        $this->assertContains('data/users.ndjson', $entries);
        $this->assertContains('data/grade_records.ndjson', $entries);
        $this->assertContains('files/course-files/'.DB::table('course_offerings')->value('id').'/notes.pdf', $entries);
        foreach (['personal_access_tokens', 'password_reset_tokens', 'sessions', 'cache', 'jobs', 'migrations'] as $secret) {
            $this->assertNotContains("data/{$secret}.ndjson", $entries, "{$secret} must not be in a backup");
        }
        $this->assertSame(1, $manifest['tables']['direct_messages']['rows']);
        $this->assertNotEmpty($manifest['migrations']);
    }

    public function test_tables_are_written_parents_before_the_tables_that_refer_to_them(): void
    {
        $order = $this->backups->orderedTables();

        $this->assertLessThan(array_search('enrolments', $order), array_search('users', $order));
        $this->assertLessThan(array_search('course_offerings', $order), array_search('courses', $order));
        $this->assertLessThan(array_search('grade_records', $order), array_search('submissions', $order));
        $this->assertLessThan(array_search('grade_appeals', $order), array_search('grade_records', $order));
    }

    public function test_a_database_only_backup_leaves_the_files_out(): void
    {
        $this->populate();

        $summary = $this->backups->create(false);

        $this->assertFalse($summary['include_files']);
        $this->assertSame(0, $summary['files']);
    }

    public function test_backups_are_listed_newest_first_with_what_is_in_them(): void
    {
        $this->populate();
        $first = $this->backups->create();
        $this->travel(2)->seconds();
        $second = $this->backups->create(false);

        $list = $this->backups->list();

        $this->assertSame([$second['name'], $first['name']], array_column($list, 'name'));
        $this->assertSame(2, $list[1]['files']);
        $this->assertGreaterThan(0, $list[0]['size']);
        $this->assertSame(['running' => false, 'started_at' => null, 'last_error' => null], $this->backups->status());
    }

    public function test_only_one_backup_runs_at_a_time(): void
    {
        $lock = Cache::lock('lms:backup', 60);
        $lock->get();

        $this->expectExceptionMessage('already running');
        $this->backups->create();
    }

    public function test_a_good_backup_verifies_and_a_damaged_one_is_caught(): void
    {
        $this->populate();
        $name = $this->backups->create()['name'];

        $ok = $this->backups->verify($name);
        $this->assertTrue($ok['ok'], implode(' ', $ok['problems']));
        $this->assertGreaterThan(20, $ok['checked']);

        $path = Storage::disk('backups')->path($name);
        file_put_contents($path, substr((string) file_get_contents($path), 0, (int) (filesize($path) / 2))); // cut in half
        $bad = $this->backups->verify($name);
        $this->assertFalse($bad['ok']);
        $this->assertNotEmpty($bad['problems']);
    }

    public function test_a_backup_whose_content_no_longer_matches_its_checksum_is_caught(): void
    {
        $this->populate();
        $name = $this->backups->create()['name'];
        $this->rewrite($name, fn (string $entry, string $body) => $entry === 'data/users.ndjson' ? str_replace('Ada Backup', 'Eve Tampered', $body) : $body);

        $check = $this->backups->verify($name);

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('users is damaged', implode(' ', $check['problems']));
        $this->expectExceptionMessage('damaged');
        $this->backups->restore($name);
    }

    /** Rewrites a backup with each entry passed through $change (to simulate damage or another version). */
    private function rewrite(string $name, callable $change): void
    {
        $path = Storage::disk('backups')->path($name);
        $entries = [];
        TarGz::read($path, function (string $entry, int $size, $stream) use (&$entries) {
            $entries[$entry] = stream_get_contents($stream);
        });
        $tar = TarGz::create($path);
        foreach ($entries as $entry => $body) {
            $tar->addString($entry, $change($entry, $body));
        }
        $tar->close();
    }

    public function test_only_real_backup_names_are_accepted(): void
    {
        foreach (['../../.env', 'backup-1.tar.gz', 'anything.zip', 'backup-20260101-000000.tar.gz/../../x'] as $bad) {
            try {
                $this->backups->delete($bad);
                $this->fail("{$bad} should have been refused");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('not a backup file name', $e->getMessage());
            }
        }
    }

    public function test_the_oldest_backups_are_deleted_beyond_the_number_to_keep(): void
    {
        $names = [];
        foreach (range(1, 4) as $i) {
            $names[] = $this->backups->create(false)['name'];
            $this->travel(2)->seconds();
        }

        $removed = $this->backups->prune(2);

        $this->assertEqualsCanonicalizing([$names[0], $names[1]], $removed);
        Storage::disk('backups')->assertMissing([$names[0], $names[0].'.json']);
        Storage::disk('backups')->assertExists([$names[2], $names[3]]);
        $this->assertCount(2, $this->backups->list());
        $this->assertCount(1, $this->backups->prune(0), 'even asking to keep none keeps the newest');
        $this->assertCount(1, $this->backups->list());
    }

    // ---- restoring ---------------------------------------------------------------------------------------------------

    public function test_a_restore_puts_back_exactly_what_was_backed_up(): void
    {
        $data = $this->populate();
        $name = $this->backups->create()['name'];
        $studentId = $data['student']->id;

        // Afterwards: a message is deleted, a name and a grade change, someone is added, a file is lost, and a stray file appears.
        DB::table('direct_messages')->delete();
        DB::table('grade_records')->update(['score' => 1]);
        DB::table('submissions')->update(['body' => 'edited later']);
        DB::table('users')->where('id', $studentId)->update(['name' => 'Renamed later']);
        $intruder = $this->userWithRole('student', ['name' => 'Added later']);
        Storage::disk('s3')->delete($data['submission']->storage_path);
        Storage::disk('s3')->put('submissions/9/9/new-since.txt', 'keep me');

        $result = $this->backups->restore($name);

        $this->assertSame('Ada Backup', User::find($studentId)->name);
        $this->assertNull(User::find($intruder->id), 'someone added after the backup is gone');
        $this->assertSame('17.50', number_format((float) DB::table('grade_records')->value('score'), 2, '.', ''));
        $this->assertSame('Unicode: café — 你好', DB::table('submissions')->value('body'));
        $this->assertSame(1, DB::table('direct_messages')->count());
        $this->assertSame([['title' => 'Argument', 'points' => 12]], json_decode(DB::table('grade_records')->value('criteria_scores'), true));
        $this->assertTrue(User::find($studentId)->hasRole('student'), 'roles come back too');
        $this->assertSame('THE ESSAY FILE', Storage::disk('s3')->get($data['submission']->storage_path));
        Storage::disk('s3')->assertExists('submissions/9/9/new-since.txt');
        $this->assertSame(2, $result['files']);
        $this->assertGreaterThan(20, $result['rows']);
    }

    public function test_tokens_and_reset_links_are_not_restored_so_everyone_signs_in_again(): void
    {
        $data = $this->populate();
        $name = $this->backups->create()['name'];
        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        $this->backups->restore($name);

        $this->assertSame(1, DB::table('personal_access_tokens')->count(), 'sign-ins are left as they are: they are not part of a backup');
        $this->assertNotNull($data['student']->fresh());
    }

    public function test_a_restore_that_fails_leaves_the_database_exactly_as_it_was(): void
    {
        $this->populate();
        $name = $this->backups->create()['name'];
        $usersBefore = User::count();
        // A row that cannot be inserted back (a duplicate primary key) makes the load fail part-way through.
        $this->rewrite($name, fn (string $entry, string $body) => $entry === 'data/enrolments.ndjson' ? $body.$body : $body);
        $manifestFix = $this->fixChecksums($name);

        try {
            $this->backups->restore($name);
            $this->fail('the restore should have failed');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('damaged', $e->getMessage());
        }

        $this->assertSame($usersBefore, User::count());
        $this->assertSame(1, DB::table('grade_records')->count());
        $this->assertNotEmpty($manifestFix);
    }

    /** Recomputes the manifest's checksums after an entry was altered on purpose. */
    private function fixChecksums(string $name): array
    {
        $path = Storage::disk('backups')->path($name);
        $entries = [];
        TarGz::read($path, function (string $entry, int $size, $stream) use (&$entries) {
            $entries[$entry] = stream_get_contents($stream);
        });
        $manifest = json_decode($entries['manifest.json'], true);
        foreach ($manifest['tables'] as $table => $info) {
            $manifest['tables'][$table]['sha256'] = hash('sha256', $entries["data/{$table}.ndjson"]);
        }
        $entries['manifest.json'] = json_encode($manifest);
        $tar = TarGz::create($path);
        foreach ($entries as $entry => $body) {
            $tar->addString($entry, $body);
        }
        $tar->close();

        return $manifest;
    }

    public function test_a_backup_made_by_a_newer_version_is_refused(): void
    {
        $this->populate();
        $name = $this->backups->create()['name'];
        $this->rewrite($name, function (string $entry, string $body) {
            if ($entry !== 'manifest.json') {
                return $body;
            }
            $manifest = json_decode($body, true);
            $manifest['migrations'][] = '2099_01_01_000000_from_the_future';

            return json_encode($manifest);
        });

        $this->expectExceptionMessage('newer version');
        $this->backups->restore($name);
    }

    public function test_tables_that_no_longer_exist_are_skipped_and_unknown_columns_ignored(): void
    {
        $this->populate();
        $name = $this->backups->create()['name'];
        $this->rewrite($name, function (string $entry, string $body) {
            return $entry === 'data/departments.ndjson' ? "{\"id\":1,\"code\":\"X\",\"name\":\"Ghost\",\"a_column_from_the_past\":5}\n" : $body;
        });
        $this->fixChecksums($name);

        $this->backups->restore($name);

        $this->assertSame('Ghost', DB::table('departments')->value('name'));
    }

    // ---- the commands ------------------------------------------------------------------------------------------------

    public function test_the_backup_command_makes_a_backup_records_it_and_keeps_only_the_newest(): void
    {
        $this->populate();
        config(['lms.backups.keep' => 2]);
        foreach (range(1, 3) as $i) {
            $this->artisan('lms:backup', ['--no-files' => true])->assertSuccessful();
            $this->travel(2)->seconds();
        }

        $this->assertCount(2, $this->backups->list());
        $this->assertSame(3, DB::table('activity_log')->where('description', 'backup created')->count());
    }

    public function test_the_restore_command_asks_first_and_takes_a_safety_backup(): void
    {
        $data = $this->populate();
        $name = $this->backups->create()['name'];
        $this->travel(2)->seconds();
        DB::table('users')->where('id', $data['teacher']->id)->update(['name' => 'Changed since']);

        $this->artisan('lms:restore', ['backup' => $name])->expectsOutputToContain('REPLACES')->expectsQuestion('Type RESTORE to continue', 'no')->assertFailed();
        $this->assertSame('Changed since', User::find($data['teacher']->id)->name);

        $this->artisan('lms:restore', ['backup' => $name, '--force' => true])->assertSuccessful();

        $this->assertSame('Dr Backup', User::find($data['teacher']->id)->name);
        $this->assertCount(2, $this->backups->list(), 'the state before the restore was backed up too');
        $this->assertSame(1, DB::table('activity_log')->where('description', 'backup restored')->count());
        $this->assertFileDoesNotExist(storage_path('framework/down'), 'the system is back up afterwards');
    }

    public function test_the_restore_command_lists_backups_and_refuses_a_damaged_one(): void
    {
        $this->populate();
        $name = $this->backups->create()['name'];
        $this->artisan('lms:restore', ['--list' => true])->expectsOutputToContain($name)->assertSuccessful();

        $path = Storage::disk('backups')->path($name);
        file_put_contents($path, substr((string) file_get_contents($path), 0, 100));
        $this->artisan('lms:restore', ['backup' => $name, '--force' => true])->expectsOutputToContain('damaged')->assertFailed();
    }

    // ---- housekeeping ------------------------------------------------------------------------------------------------

    public function test_pruning_removes_only_what_has_expired_and_can_be_previewed(): void
    {
        config(['lms.retention.security_event_days' => 30, 'lms.retention.notification_days' => 30, 'sanctum.expiration' => 60]);
        $user = $this->userWithRole('student');
        $old = now()->subDays(60);
        DB::table('security_events')->insert([['event' => 'login.success', 'created_at' => $old], ['event' => 'login.success', 'created_at' => now()]]);
        DB::table('notifications')->insert([
            ['id' => 'a', 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => '{}', 'read_at' => $old, 'created_at' => $old, 'updated_at' => $old],
            ['id' => 'b', 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => '{}', 'read_at' => null, 'created_at' => $old, 'updated_at' => $old],
        ]);
        DB::table('password_reset_tokens')->insert([['email' => 'a@x.test', 'token' => 't', 'created_at' => now()->subDays(2)], ['email' => 'b@x.test', 'token' => 't', 'created_at' => now()]]);
        DB::table('personal_access_tokens')->insert([
            ['tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'api', 'token' => 'old', 'created_at' => now()->subDays(3), 'updated_at' => now()],
            ['tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'api', 'token' => 'new', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('activity_log')->insert(['description' => 'a grade changed long ago', 'created_at' => $old, 'updated_at' => $old]);

        $preview = app(PruneService::class)->run(true);
        $this->assertSame(['expired_sign_ins' => 1, 'security_events' => 1, 'read_notifications' => 1, 'password_reset_links' => 1, 'welcome_links' => 0, 'failed_jobs' => 0], $preview);
        $this->assertSame(2, DB::table('security_events')->count(), 'a preview deletes nothing');

        app(PruneService::class)->run();

        $this->assertSame(1, DB::table('security_events')->count());
        $this->assertSame(['b'], DB::table('notifications')->pluck('id')->all(), 'unread notifications are never pruned');
        $this->assertSame(['b@x.test'], DB::table('password_reset_tokens')->pluck('email')->all());
        $this->assertSame(['new'], DB::table('personal_access_tokens')->pluck('token')->all());
        $this->assertSame(1, DB::table('activity_log')->where('description', 'a grade changed long ago')->count(), 'the audit log is never pruned');
    }
}
