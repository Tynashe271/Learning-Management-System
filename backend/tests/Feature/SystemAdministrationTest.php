<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Enrolment;
use App\Models\SystemAnnouncement;
use App\Models\User;
use App\Services\BackupService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

/** The technical and reporting side of administration: monitoring, failed jobs, backups, announcements, reports. */
class SystemAdministrationTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private User $root;

    private User $uniAdmin;

    private User $registrar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('s3');
        Storage::fake('backups');
        $this->root = $this->userWithRole('super-admin');
        $this->uniAdmin = $this->userWithRole('university-admin');
        $this->registrar = $this->userWithRole('registrar');
    }

    private function as(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function failedJob(string $uuid = 'aaaaaaaa-0000-0000-0000-000000000001'): void
    {
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\Something']), 'exception' => "RuntimeException: boom\n#0 stack trace", 'failed_at' => now()]);
    }

    // ---- who may do what -----------------------------------------------------------------------------------------------

    public function test_technical_screens_are_for_super_administrators_only(): void
    {
        foreach ([['getJson', '/api/system'], ['getJson', '/api/system/failed-jobs'], ['postJson', '/api/system/refresh'], ['postJson', '/api/system/prune'], ['getJson', '/api/backups'], ['postJson', '/api/backups']] as [$method, $url]) {
            foreach ([$this->uniAdmin, $this->registrar, $this->userWithRole('lecturer'), $this->userWithRole('student')] as $person) {
                $this->as($person)->{$method}($url)->assertForbidden();
            }
            $this->as($this->root)->{$method}($url)->assertSuccessful();
        }
    }

    public function test_announcements_are_for_people_with_the_settings_permission(): void
    {
        $this->as($this->registrar)->postJson('/api/system-announcements', ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->as($this->userWithRole('student'))->getJson('/api/system-announcements')->assertForbidden();
    }

    // ---- monitoring ---------------------------------------------------------------------------------------------------

    public function test_the_system_screen_reports_state_and_lists_what_needs_attention(): void
    {
        $this->failedJob();

        $response = $this->as($this->root)->getJson('/api/system')->assertOk()
            ->assertJsonPath('application.version', config('lms.version'))->assertJsonPath('database.ok', true)
            ->assertJsonPath('queue.failed', 1)->assertJsonPath('scheduler.ok', false)->assertJsonPath('database.tables.users', 3);

        $warnings = implode(' | ', $response->json('warnings'));
        $this->assertStringContainsString('1 background job(s) have failed', $warnings);
        $this->assertStringContainsString('scheduler has not run', $warnings);

        Cache::put('lms:scheduler:heartbeat', time());
        $this->as($this->root)->getJson('/api/system')->assertJsonPath('scheduler.ok', true);
    }

    public function test_maintenance_mode_is_flagged_on_the_system_screen(): void
    {
        config(['lms.maintenance.enabled' => true]);

        $this->as($this->root)->getJson('/api/system')->assertOk();
        $this->assertContains('Maintenance mode is on: only super administrators can use the system.', $this->as($this->root)->getJson('/api/system')->json('warnings'));
    }

    // ---- failed jobs --------------------------------------------------------------------------------------------------

    public function test_failed_jobs_can_be_listed_and_removed_and_each_action_is_recorded(): void
    {
        $this->failedJob('aaaaaaaa-0000-0000-0000-000000000001');
        $this->failedJob('aaaaaaaa-0000-0000-0000-000000000002');

        $this->as($this->root)->getJson('/api/system/failed-jobs')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.job', 'App\\Jobs\\Something')->assertJsonPath('data.0.error', 'RuntimeException: boom');

        $this->as($this->root)->deleteJson('/api/system/failed-jobs/aaaaaaaa-0000-0000-0000-000000000001')->assertOk();
        $this->as($this->root)->deleteJson('/api/system/failed-jobs/aaaaaaaa-0000-0000-0000-000000000001')->assertNotFound();
        $this->as($this->root)->postJson('/api/system/failed-jobs/no-such-job/retry')->assertNotFound();
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $this->as($this->root)->deleteJson('/api/system/failed-jobs')->assertOk()->assertJsonPath('deleted', 1);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('activity_log')->where('description', 'failed job deleted')->count());
        $this->assertSame(1, DB::table('activity_log')->where('description', 'failed jobs cleared')->count());
    }

    // ---- housekeeping -------------------------------------------------------------------------------------------------

    public function test_refreshing_caches_forgets_reports_but_not_sign_in_lockouts(): void
    {
        Cache::put('reports:overview', ['stale' => true], 600);
        Cache::put('login-lockout:someone', 5, 600);

        $this->as($this->root)->postJson('/api/system/refresh')->assertOk();

        $this->assertNull(Cache::get('reports:overview'));
        $this->assertSame(5, Cache::get('login-lockout:someone'));
        $this->assertSame(1, DB::table('activity_log')->where('description', 'caches refreshed')->count());
    }

    public function test_pruning_can_be_previewed_first_and_then_run(): void
    {
        DB::table('security_events')->insert(['event' => 'login.failed', 'level' => 'info', 'created_at' => now()->subYears(5)]);
        config(['lms.retention.security_events_days' => 30]);

        $this->as($this->root)->postJson('/api/system/prune', ['dry_run' => true])->assertOk()->assertJsonPath('dry_run', true);
        $this->assertSame(1, DB::table('security_events')->count());
        $this->assertSame(0, DB::table('activity_log')->where('description', 'old records removed')->count());

        $this->as($this->root)->postJson('/api/system/prune')->assertOk()->assertJsonPath('dry_run', false);
        $this->assertSame(0, DB::table('security_events')->where('created_at', '<', now()->subYear())->count());
        $this->assertSame(1, DB::table('activity_log')->where('description', 'old records removed')->count());
    }

    // ---- backups through the API ----------------------------------------------------------------------------------------

    public function test_a_backup_is_made_listed_verified_downloaded_and_deleted_through_the_api(): void
    {
        $this->as($this->root)->postJson('/api/backups', ['include_files' => false])->assertStatus(202);

        $list = $this->as($this->root)->getJson('/api/backups')->assertOk()->assertJsonPath('restore_command', 'php artisan lms:restore <backup file name>');
        $this->assertCount(1, $list->json('backups'));
        $name = $list->json('backups.0.name');
        $this->assertMatchesRegularExpression(BackupService::NAME_PATTERN, $name);

        $this->as($this->root)->postJson("/api/backups/{$name}/verify")->assertOk()->assertJsonPath('ok', true)->assertJsonPath('problems', []);
        $this->as($this->root)->get("/api/backups/{$name}/download")->assertOk();
        $this->assertSame(1, DB::table('activity_log')->where('description', 'backup downloaded')->count());
        $this->assertSame(1, DB::table('security_events')->where('event', 'backup.downloaded')->count());

        $this->as($this->root)->deleteJson("/api/backups/{$name}")->assertOk();
        $this->as($this->root)->getJson('/api/backups')->assertJsonCount(0, 'backups');
    }

    public function test_backup_names_are_checked_before_anything_is_touched(): void
    {
        $this->as($this->root)->postJson('/api/backups/not-a-backup/verify')->assertNotFound();
        $this->as($this->root)->get('/api/backups/not-a-backup/download')->assertNotFound();
        $this->as($this->root)->deleteJson('/api/backups/not-a-backup')->assertNotFound();
        $this->as($this->root)->get('/api/backups/..%2F..%2F.env/download')->assertNotFound();
        $this->as($this->root)->get('/api/backups/backup-20260101-000000.tar.gz/download')->assertNotFound();
    }

    // ---- system announcements -------------------------------------------------------------------------------------------

    public function test_an_announcement_shows_only_to_its_audience_and_only_while_current(): void
    {
        $student = $this->userWithRole('student');
        $lecturer = $this->userWithRole('lecturer');

        $this->as($this->uniAdmin)->postJson('/api/system-announcements', ['title' => 'Everyone', 'body' => 'Hello all', 'severity' => 'info'])->assertCreated();
        $this->as($this->uniAdmin)->postJson('/api/system-announcements', ['title' => 'Staff only', 'body' => 'Marks due', 'severity' => 'warning', 'audience' => ['lecturer']])->assertCreated();
        $this->as($this->uniAdmin)->postJson('/api/system-announcements', ['title' => 'Next week', 'body' => 'Not yet', 'starts_at' => now()->addWeek()->toIso8601String()])->assertCreated();
        $this->as($this->uniAdmin)->postJson('/api/system-announcements', ['title' => 'Old news', 'body' => 'Gone', 'starts_at' => now()->subWeek()->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()])->assertCreated();

        $this->assertSame(['Everyone'], collect($this->as($student)->getJson('/api/system-announcements/active')->assertOk()->json())->pluck('title')->all());
        $this->assertEqualsCanonicalizing(['Everyone', 'Staff only'], collect($this->as($lecturer)->getJson('/api/system-announcements/active')->json())->pluck('title')->all());
        $this->as($this->uniAdmin)->getJson('/api/system-announcements')->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_announcements_are_validated_and_can_be_changed_and_removed(): void
    {
        $this->as($this->uniAdmin)->postJson('/api/system-announcements', ['title' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->as($this->uniAdmin)->postJson('/api/system-announcements', ['title' => 'x', 'body' => 'y', 'severity' => 'loud'])->assertUnprocessable()->assertJsonValidationErrors('severity');
        $this->as($this->uniAdmin)->postJson('/api/system-announcements', ['title' => 'x', 'body' => 'y', 'audience' => ['wizard']])->assertUnprocessable();
        $this->as($this->uniAdmin)->postJson('/api/system-announcements', ['title' => 'x', 'body' => 'y', 'starts_at' => '2026-05-02', 'ends_at' => '2026-05-01'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');

        $id = $this->as($this->uniAdmin)->postJson('/api/system-announcements', ['title' => 'Maintenance', 'body' => 'Saturday night'])->json('id');
        $this->as($this->uniAdmin)->patchJson("/api/system-announcements/{$id}", ['severity' => 'critical'])->assertOk()->assertJsonPath('severity', 'critical');
        $this->as($this->uniAdmin)->deleteJson("/api/system-announcements/{$id}")->assertOk();
        $this->assertSame(0, SystemAnnouncement::count());
        $this->assertSame(1, DB::table('activity_log')->where('description', 'system announcement removed')->count());
    }

    public function test_notifying_puts_the_announcement_in_the_notifications_of_the_audience_only(): void
    {
        $student = $this->userWithRole('student');
        $lecturer = $this->userWithRole('lecturer');
        $gone = $this->userWithRole('lecturer', ['is_active' => false]);

        $this->as($this->uniAdmin)->postJson('/api/system-announcements', ['title' => 'Staff meeting', 'body' => 'Thursday', 'audience' => ['lecturer'], 'notify' => true])->assertCreated();

        $this->assertSame(1, $lecturer->notifications()->count());
        $this->assertSame('Staff meeting', $lecturer->notifications()->first()->data['title']);
        $this->assertSame(0, $student->notifications()->count());
        $this->assertSame(0, $gone->notifications()->count());
    }

    // ---- reports ------------------------------------------------------------------------------------------------------

    public function test_the_audit_log_can_be_filtered_by_date_and_person_and_downloaded(): void
    {
        $person = $this->userWithRole('student');
        activity()->causedBy($this->uniAdmin)->log('grade changed');
        activity()->causedBy($person)->log('assignment submitted');
        DB::table('activity_log')->where('description', 'grade changed')->update(['created_at' => now()->subDays(10)]);

        $this->as($this->uniAdmin)->getJson('/api/audit-log?from='.now()->subDay()->toDateString())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'assignment submitted');
        $this->as($this->uniAdmin)->getJson('/api/audit-log?to='.now()->subDays(5)->toDateString())->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'grade changed');
        $this->as($this->uniAdmin)->getJson("/api/audit-log?causer_id={$person->id}")->assertJsonCount(1, 'data');
        $this->as($this->uniAdmin)->getJson('/api/audit-log?q=GRADE')->assertJsonCount(1, 'data');
        $this->as($this->uniAdmin)->getJson('/api/audit-log?from=nonsense')->assertUnprocessable();

        $csv = $this->as($this->uniAdmin)->get('/api/audit-log?format=csv&q=grade')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('When (UTC)', $body);
        $this->assertStringContainsString('grade changed', $body);
        $this->assertStringNotContainsString('assignment submitted', $body);
        $this->assertSame(1, DB::table('activity_log')->where('description', 'audit log exported')->count());
    }

    public function test_the_audit_log_is_for_administrators_who_manage_accounts(): void
    {
        $this->as($this->registrar)->getJson('/api/audit-log')->assertForbidden();
        $this->as($this->userWithRole('lecturer'))->getJson('/api/audit-log?format=csv')->assertForbidden();
    }

    public function test_the_audit_csv_neutralises_spreadsheet_formulas(): void
    {
        activity()->causedBy($this->uniAdmin)->log('=HYPERLINK("http://evil.test","x")');

        $body = $this->as($this->uniAdmin)->get('/api/audit-log?format=csv&q=hyperlink')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertStringNotContainsString(',=HYPERLINK', $body);
    }

    public function test_usage_counts_each_day_and_distinct_active_people(): void
    {
        $ada = $this->userWithRole('student');
        $ben = $this->userWithRole('student');
        foreach ([$ada, $ada, $ben] as $person) {
            DB::table('security_events')->insert(['event' => 'login.success', 'user_id' => $person->id, 'level' => 'info', 'created_at' => now()]);
        }
        DB::table('security_events')->insert(['event' => 'login.success', 'user_id' => $ada->id, 'level' => 'info', 'created_at' => now()->subDays(20)]);
        DB::table('security_events')->insert(['event' => 'login.failed', 'user_id' => $ben->id, 'level' => 'info', 'created_at' => now()]);

        $this->as($this->registrar)->getJson('/api/reports/usage')->assertForbidden();
        $usage = $this->as($this->uniAdmin)->getJson('/api/reports/usage')->assertOk();

        $this->assertCount(30, $usage->json('sign_ins_per_day'));
        $this->assertSame(now()->toDateString(), $usage->json('sign_ins_per_day.29.date'));
        $this->assertSame(3, $usage->json('sign_ins_per_day.29.count'));
        $this->assertSame(2, $usage->json('active_people.last_7_days'));
        $this->assertSame(2, $usage->json('active_people.last_30_days'));
        $this->assertGreaterThanOrEqual(2, $usage->json('new_accounts_per_day.29.count'));
        $this->as($this->userWithRole('student'))->getJson('/api/reports/usage')->assertForbidden();
    }

    public function test_the_enrolment_report_groups_by_term_and_department_and_shows_how_full_courses_are(): void
    {
        $science = Department::create(['code' => 'SCI', 'name' => 'Science']);
        $term = AcademicTerm::create(['name' => 'Semester 1', 'starts_on' => '2026-02-01', 'ends_on' => '2026-06-01', 'academic_year' => '2026']);
        $csc = Course::create(['code' => 'CSC101', 'title' => 'Computing', 'department_id' => $science->id]);
        $art = Course::create(['code' => 'ART101', 'title' => 'Drawing']);
        $small = CourseOffering::create(['course_id' => $csc->id, 'academic_term_id' => $term->id, 'section' => 'A', 'published' => true, 'capacity' => 2]);
        $open = CourseOffering::create(['course_id' => $art->id, 'academic_term_id' => $term->id, 'section' => 'A', 'published' => false]);
        foreach ([1, 2] as $i) {
            Enrolment::create(['course_offering_id' => $small->id, 'user_id' => $this->userWithRole('student')->id, 'status' => 'active']);
        }
        Enrolment::create(['course_offering_id' => $open->id, 'user_id' => $this->userWithRole('student')->id, 'status' => 'active']);
        Enrolment::create(['course_offering_id' => $open->id, 'user_id' => $this->userWithRole('student')->id, 'status' => 'dropped']);

        $report = $this->as($this->registrar)->getJson('/api/reports/enrolments')->assertOk();

        $this->assertSame(3, $report->json('total_enrolled'));
        $this->assertEquals(['name' => 'Semester 1', 'offerings' => 2, 'published' => 1, 'enrolled' => 3, 'capacity' => 2, 'fill_percent' => 100.0], collect($report->json('terms.0'))->only(['name', 'offerings', 'published', 'enrolled', 'capacity', 'fill_percent'])->all());
        $departments = collect($report->json('departments'))->keyBy('name');
        $this->assertSame(2, $departments['Science']['enrolled']);
        $this->assertSame(1, $departments['No department']['enrolled']);
        $this->assertSame('CSC101 Computing', $report->json('fullest.0.course'));

        $this->as($this->registrar)->getJson('/api/reports/enrolments?term_id=999999')->assertOk()->assertJsonPath('total_enrolled', 0);

        $csv = $this->as($this->registrar)->get('/api/reports/enrolments?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Places left', $csv);
        $this->assertStringContainsString('"Semester 1",2026,Science,CSC101,Computing,A,2,2,0,yes,no', str_replace('"Semester 1"', '"Semester 1"', $csv));
    }
}
