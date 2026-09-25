<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\GradeAppeal;
use App\Models\User;
use App\Notifications\AppealFiled;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class E2eFixturesTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private const PASSWORD = 'a-test-password-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function seedFixtures(): void
    {
        $this->artisan('lms:e2e', ['action' => 'seed', '--password' => self::PASSWORD])->assertSuccessful();
    }

    private function e2eOffering(): CourseOffering
    {
        return CourseOffering::whereHas('course', fn ($q) => $q->where('code', 'E2E101'))->firstOrFail();
    }

    public function test_seed_creates_one_account_per_role_and_a_course_they_can_use(): void
    {
        $this->seedFixtures();

        foreach (['admin' => 'super-admin', 'lect' => 'lecturer', 'ta' => 'teaching-assistant', 'dept' => 'department-admin', 'reg' => 'registrar', 'stu1' => 'student', 'stu2' => 'student'] as $short => $role) {
            $user = User::where('email', "e2e-{$short}@example.test")->firstOrFail();
            $this->assertTrue($user->hasRole($role), "{$short} should be a {$role}");
            $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        }
        $offering = $this->e2eOffering();
        $this->assertTrue($offering->published);
        $this->assertSame(2, $offering->teachers()->count());
        $this->assertSame(2, $offering->enrolments()->where('status', 'active')->count());
    }

    public function test_seed_can_be_repeated_without_duplicating_anything_and_resets_the_password(): void
    {
        $this->seedFixtures();
        $this->artisan('lms:e2e', ['action' => 'seed', '--password' => 'a-different-password-9'])->assertSuccessful();

        $this->assertSame(7, User::where('email', 'like', 'e2e-%@example.test')->count());
        $this->assertSame(1, Course::where('code', 'E2E101')->count());
        $this->postJson('/api/login', ['email' => 'e2e-stu1@example.test', 'password' => 'a-different-password-9'])->assertOk();
    }

    public function test_seed_insists_on_a_real_password(): void
    {
        $this->artisan('lms:e2e', ['action' => 'seed', '--password' => 'short'])->assertFailed();
        $this->artisan('lms:e2e', ['action' => 'seed'])->assertFailed();
        $this->assertSame(0, User::where('email', 'like', 'e2e-%')->count());
    }

    public function test_it_refuses_to_run_in_production_and_rejects_unknown_actions(): void
    {
        $this->artisan('lms:e2e', ['action' => 'bogus'])->assertFailed();

        $this->app['env'] = 'production';
        $this->artisan('lms:e2e', ['action' => 'seed', '--password' => self::PASSWORD])->assertFailed();
        $this->artisan('lms:e2e', ['action' => 'clean'])->assertFailed();
        $this->assertSame(0, User::where('email', 'like', 'e2e-%')->count());
    }

    public function test_clean_removes_the_test_accounts_and_everything_they_produced_but_nothing_else(): void
    {
        Storage::fake('s3');
        $this->seedFixtures();

        // Work produced during a browser run: content, a graded and appealed submission, a quiz attempt, discussion, attendance.
        $offering = $this->e2eOffering();
        [$lect, $ada, $ben, $admin] = array_map(fn ($e) => User::where('email', "e2e-{$e}@example.test")->firstOrFail(), ['lect', 'stu1', 'stu2', 'admin']);
        $module = $offering->modules()->create(['title' => 'Week 1', 'published' => true]);
        $module->items()->create(['title' => 'Notes', 'type' => 'file', 'storage_path' => "course-files/{$offering->id}/notes.txt", 'published' => true]);
        Storage::disk('s3')->put("course-files/{$offering->id}/notes.txt", 'notes');
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 20, 'published' => true]);
        $criterion = $assignment->rubricCriteria()->create(['title' => 'Argument', 'max_points' => 20, 'position' => 0]);
        $criterion->levels()->create(['title' => 'Strong', 'points' => 20, 'position' => 0]);
        $submission = $assignment->submissions()->create(['user_id' => $ada->id, 'body' => 'x', 'storage_path' => "submissions/{$assignment->id}/{$ada->id}/a.txt", 'submitted_at' => now()]);
        Storage::disk('s3')->put($submission->storage_path, 'essay');
        $grade = $submission->gradeRecords()->create(['graded_by' => $lect->id, 'score' => 15, 'status' => 'published']);
        GradeAppeal::create(['submission_id' => $submission->id, 'user_id' => $ada->id, 'grade_record_id' => $grade->id, 'reason' => 'I think this deserves a higher mark.']);
        $quiz = $offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'max_attempts' => 1, 'published' => true]);
        $question = $quiz->questions()->create(['type' => 'single_choice', 'prompt' => 'Q?', 'points' => 1, 'position' => 0]);
        $attempt = $quiz->attempts()->create(['user_id' => $ada->id, 'started_at' => now(), 'submitted_at' => now(), 'score' => 1, 'max_score' => 1]);
        $attempt->answers()->create(['quiz_question_id' => $question->id, 'response' => ['option_ids' => []], 'is_correct' => false, 'points' => 0]);
        $thread = $offering->threads()->create(['user_id' => $ada->id, 'title' => 'Hi', 'body' => 'Hello']);
        $thread->posts()->create(['user_id' => $lect->id, 'body' => 'Reply']);
        $offering->announcements()->create(['user_id' => $lect->id, 'title' => 'News', 'body' => 'Hello']);
        $session = $offering->sessions()->create(['title' => 'Lecture', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
        $session->attendance()->create(['user_id' => $ada->id, 'status' => 'present', 'marked_by' => $lect->id, 'source' => 'staff']);
        $ben->createToken('api');
        $admin->notify(new AppealFiled(1, 1, 'Essay'));
        activity()->causedBy($lect)->log('grade recorded');

        // Real data that must survive: another course, a real student, and their work.
        $real = $this->userWithRole('student', ['name' => 'Real Student']);
        $realTeacher = $this->userWithRole('lecturer');
        $realOffering = $this->offering('R');
        $this->enrol($realOffering, $real);
        $this->teach($realOffering, $realTeacher);
        $realAssignment = Assignment::create(['course_offering_id' => $realOffering->id, 'title' => 'Real essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $realAssignment->submissions()->create(['user_id' => $real->id, 'body' => 'mine', 'submitted_at' => now()]);
        activity()->causedBy($realTeacher)->log('real thing');
        Storage::disk('s3')->put("submissions/{$realAssignment->id}/{$real->id}/mine.txt", 'keep');

        $this->artisan('lms:e2e', ['action' => 'clean'])->assertSuccessful();

        $this->assertSame(0, User::where('email', 'like', 'e2e-%')->count());
        $this->assertSame(0, Course::where('code', 'like', 'E2E%')->count());
        foreach (['course_offerings' => $offering->id, 'assignments' => $assignment->id, 'submissions' => $submission->id, 'quizzes' => $quiz->id] as $table => $id) {
            $this->assertFalse(DB::table($table)->where('id', $id)->exists(), "{$table} row {$id} should be gone");
        }
        $this->assertSame(0, DB::table('grade_records')->count());
        $this->assertSame(0, DB::table('grade_appeals')->count());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(0, DB::table('activity_log')->where('description', 'grade recorded')->count());
        $this->assertSame(0, DB::table('academic_terms')->where('name', 'E2E Term')->count());
        Storage::disk('s3')->assertMissing("course-files/{$offering->id}/notes.txt");
        Storage::disk('s3')->assertMissing($submission->storage_path);

        // ...and everything real is still there.
        $this->assertSame(2, User::whereKey([$real->id, $realTeacher->id])->count());
        $this->assertSame(1, DB::table('submissions')->where('user_id', $real->id)->count());
        $this->assertTrue(CourseOffering::whereKey($realOffering->id)->exists());
        $this->assertSame(1, DB::table('activity_log')->where('description', 'real thing')->count());
        Storage::disk('s3')->assertExists("submissions/{$realAssignment->id}/{$real->id}/mine.txt");
    }

    public function test_clean_removes_the_administration_leftovers_of_a_browser_run_and_keeps_real_ones(): void
    {
        Storage::fake('s3');
        $this->seedFixtures();
        $admin = User::where('email', 'e2e-admin@example.test')->firstOrFail();
        $real = $this->userWithRole('student');
        DB::table('departments')->insert([['code' => 'E2EDEP', 'name' => 'Test faculty', 'created_at' => now(), 'updated_at' => now()], ['code' => 'SCI', 'name' => 'Science', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('system_announcements')->insert([['title' => 'E2E notice', 'body' => 'x', 'severity' => 'info', 'created_at' => now(), 'updated_at' => now()], ['title' => 'Real notice', 'body' => 'x', 'severity' => 'info', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('security_events')->insert([['event' => 'login.success', 'user_id' => $admin->id, 'level' => 'info', 'created_at' => now()], ['event' => 'login.success', 'user_id' => $real->id, 'level' => 'info', 'created_at' => now()]]);

        $this->artisan('lms:e2e', ['action' => 'clean'])->assertSuccessful();

        $this->assertSame(['SCI'], DB::table('departments')->pluck('code')->all());
        $this->assertSame(['Real notice'], DB::table('system_announcements')->pluck('title')->all());
        $this->assertSame([$real->id], DB::table('security_events')->pluck('user_id')->all());
    }

    public function test_clean_also_removes_work_that_real_people_did_inside_the_test_course(): void
    {
        Storage::fake('s3');
        $this->seedFixtures();
        $offering = $this->e2eOffering();
        $real = $this->userWithRole('student');
        $this->enrol($offering, $real);
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 20, 'published' => true]);
        $assignment->submissions()->create(['user_id' => $real->id, 'body' => 'x', 'submitted_at' => now()]);

        $this->artisan('lms:e2e', ['action' => 'clean'])->assertSuccessful();

        $this->assertSame(0, DB::table('submissions')->count());
        $this->assertTrue(User::whereKey($real->id)->exists(), 'the real person is not deleted, only their work in the test course');
    }

    public function test_clean_still_removes_the_records_when_file_storage_cannot_be_reached(): void
    {
        $this->seedFixtures();
        Storage::shouldReceive('disk')->with('s3')->andThrow(new \RuntimeException('storage is down'));

        $this->artisan('lms:e2e', ['action' => 'clean'])->expectsOutputToContain('storage is down')->assertSuccessful();

        $this->assertSame(0, User::where('email', 'like', 'e2e-%')->count());
    }

    public function test_clean_with_nothing_to_remove_is_harmless(): void
    {
        $this->artisan('lms:e2e', ['action' => 'clean'])->assertSuccessful();
        $this->artisan('lms:e2e', ['action' => 'clean'])->assertSuccessful();
    }
}
