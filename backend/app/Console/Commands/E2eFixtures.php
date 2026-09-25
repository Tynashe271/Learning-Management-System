<?php

namespace App\Console\Commands;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Enrolment;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Accounts and a course for the browser tests in e2e/, and a way to remove them again.
 *
 * Everything this command creates is recognisable (emails like e2e-*@example.test, a course whose code starts with E2E), and
 * `clean` deletes only that, so it is safe to run against a development stack that also holds real data. It refuses to run in
 * production, where a well-known set of accounts must never exist.
 */
class E2eFixtures extends Command
{
    protected $signature = 'lms:e2e {action : seed or clean} {--password= : Password for the test accounts (seed only)}';

    protected $description = 'Create or remove the accounts and course used by the browser tests (never in production)';

    public const EMAIL_PATTERN = 'e2e-%@example.test';

    public const COURSE_PATTERN = 'E2E%';

    /** @var array<string, array{0: string, 1: string}> email => [name, role] */
    private const ACCOUNTS = [
        'e2e-admin@example.test' => ['E2E Admin', 'super-admin'],
        'e2e-lect@example.test' => ['Dr Lena Lecturer', 'lecturer'],
        'e2e-ta@example.test' => ['Tom Assistant', 'teaching-assistant'],
        'e2e-dept@example.test' => ['Dana Dept Admin', 'department-admin'],
        'e2e-reg@example.test' => ['Rita Registrar', 'registrar'],
        'e2e-stu1@example.test' => ['Ada Student', 'student'],
        'e2e-stu2@example.test' => ['Ben Student', 'student'],
    ];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('This command creates well-known accounts and is disabled in production.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'seed' => $this->seed(),
            'clean' => $this->clean(),
            default => $this->usage(),
        };
    }

    private function usage(): int
    {
        $this->error('The action must be "seed" or "clean".');

        return self::FAILURE;
    }

    private function seed(): int
    {
        $password = (string) $this->option('password');
        if (strlen($password) < 12) {
            $this->error('Give a password of at least 12 characters with --password.');

            return self::FAILURE;
        }

        foreach (self::ACCOUNTS as $email => [$name, $role]) {
            $user = User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => $password, 'is_active' => true]);
            $user->syncRoles([$role]);
        }

        $term = AcademicTerm::firstOrCreate(['name' => 'E2E Term'], ['starts_on' => '2026-09-01', 'ends_on' => '2027-01-31']);
        $course = Course::firstOrCreate(['code' => 'E2E101'], ['title' => 'Testing Foundations', 'description' => 'A course used to test the frontend.']);
        $offering = CourseOffering::firstOrCreate(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'A'], ['published' => true]);

        foreach (['e2e-lect@example.test', 'e2e-ta@example.test'] as $email) {
            TeachingAssignment::firstOrCreate(['course_offering_id' => $offering->id, 'user_id' => User::where('email', $email)->value('id')]);
        }
        foreach (['e2e-stu1@example.test', 'e2e-stu2@example.test'] as $email) {
            Enrolment::firstOrCreate(['course_offering_id' => $offering->id, 'user_id' => User::where('email', $email)->value('id')], ['status' => 'active']);
        }
        Cache::flush(); // forget sign-in lockouts and rate limits left by an earlier run

        $this->line(json_encode(['offering' => $offering->id]));

        return self::SUCCESS;
    }

    /**
     * Removes the test accounts and the test course with everything that hangs off them, in an order the database's
     * restrictive foreign keys allow (grades and submissions can never be deleted by a cascade).
     */
    private function clean(): int
    {
        $users = User::where('email', 'like', self::EMAIL_PATTERN)->pluck('id')->all();
        $offerings = CourseOffering::whereIn('course_id', Course::where('code', 'like', self::COURSE_PATTERN)->select('id'))->pluck('id')->all();
        $assignments = DB::table('assignments')->whereIn('course_offering_id', $offerings)->pluck('id')->all();
        $quizzes = DB::table('quizzes')->whereIn('course_offering_id', $offerings)->pluck('id')->all();
        $threads = DB::table('discussion_threads')->whereIn('course_offering_id', $offerings)->pluck('id')->all();
        $sessions = DB::table('class_sessions')->whereIn('course_offering_id', $offerings)->pluck('id')->all();
        $submissions = DB::table('submissions')->where(fn ($q) => $q->whereIn('user_id', $users)->orWhereIn('assignment_id', $assignments))->pluck('id')->all();

        DB::transaction(function () use ($users, $offerings, $quizzes, $threads, $sessions, $submissions) {
            DB::table('grade_appeals')->where(fn ($q) => $q->whereIn('submission_id', $submissions)->orWhereIn('user_id', $users)->orWhereIn('resolved_by', $users))->delete();
            DB::table('grade_records')->where(fn ($q) => $q->whereIn('submission_id', $submissions)->orWhereIn('graded_by', $users))->delete();
            DB::table('submissions')->whereIn('id', $submissions)->delete();

            $attempts = DB::table('quiz_attempts')->where(fn ($q) => $q->whereIn('user_id', $users)->orWhereIn('quiz_id', $quizzes))->pluck('id')->all();
            DB::table('quiz_answers')->whereIn('quiz_attempt_id', $attempts)->delete();
            DB::table('quiz_attempts')->whereIn('id', $attempts)->delete();

            DB::table('discussion_posts')->where(fn ($q) => $q->whereIn('user_id', $users)->orWhereIn('discussion_thread_id', $threads))->delete();
            DB::table('discussion_threads')->where(fn ($q) => $q->whereIn('user_id', $users)->orWhereIn('course_offering_id', $offerings))->delete();
            DB::table('announcements')->where(fn ($q) => $q->whereIn('user_id', $users)->orWhereIn('course_offering_id', $offerings))->delete();
            DB::table('attendance_records')->where(fn ($q) => $q->whereIn('user_id', $users)->orWhereIn('marked_by', $users)->orWhereIn('class_session_id', $sessions))->delete();

            DB::table('course_offerings')->whereIn('id', $offerings)->delete(); // the rest of the course goes with it
            Course::where('code', 'like', self::COURSE_PATTERN)->delete();
            AcademicTerm::where('name', 'like', 'E2E%')->whereNotIn('id', DB::table('course_offerings')->select('academic_term_id'))->delete();
            Department::where('code', 'like', self::COURSE_PATTERN)->whereNotIn('id', DB::table('courses')->whereNotNull('department_id')->select('department_id'))->delete();
            DB::table('system_announcements')->where('title', 'like', 'E2E%')->delete();
            DB::table('security_events')->whereIn('user_id', $users)->delete();

            DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $users)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $users)->delete();
            DB::table('password_reset_tokens')->where('email', 'like', self::EMAIL_PATTERN)->delete();
            DB::table('account_invitation_tokens')->where('email', 'like', self::EMAIL_PATTERN)->delete();
            DB::table('activity_log')->where(fn ($q) => $q->where('causer_type', User::class)->whereIn('causer_id', $users))
                ->orWhere(fn ($q) => $q->where('subject_type', User::class)->whereIn('subject_id', $users))->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $users)->delete();
            DB::table('users')->whereIn('id', $users)->delete();
        });

        // The records are already gone; a storage outage should not turn that into a failure, only leave files to tidy later.
        try {
            $disk = Storage::disk('s3');
            foreach ($offerings as $id) {
                $disk->deleteDirectory("course-files/{$id}");
            }
            foreach ($assignments as $id) {
                $disk->deleteDirectory("submissions/{$id}");
            }
        } catch (Throwable $e) {
            $this->warn('Uploaded test files could not be removed from storage: '.$e->getMessage());
        }
        Cache::flush();

        $this->line(json_encode(['users' => count($users), 'offerings' => count($offerings)]));

        return self::SUCCESS;
    }
}
