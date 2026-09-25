<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assignment;
use App\Models\ClassSession;
use App\Models\CourseOffering;
use App\Models\GradeAppeal;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

/**
 * Who can do what, checked through the real API for every role. The README's summary of the roles is written from this test,
 * so if a permission changes, this fails until the documentation is updated to match.
 */
class RoleCapabilitiesTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $ada;

    private User $enrolTarget;

    private Submission $submission;

    private GradeAppeal $appeal;

    private ClassSession $session;

    private AcademicTerm $nextTerm;

    private Assignment $essay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->nextTerm = AcademicTerm::create(['name' => 'Next term', 'starts_on' => '2027-01-01', 'ends_on' => '2027-06-01']);
        $this->ada = $this->userWithRole('student', ['name' => 'Ada']);
        $this->enrolTarget = $this->userWithRole('student');
        $this->enrol($this->offering, $this->ada);
        $this->essay = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
        $this->submission = $this->essay->submissions()->create(['user_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now()]);
        $grade = $this->submission->gradeRecords()->create(['graded_by' => $this->userWithRole('super-admin')->id, 'score' => 60, 'status' => 'published']);
        $this->appeal = GradeAppeal::create(['submission_id' => $this->submission->id, 'user_id' => $this->ada->id, 'grade_record_id' => $grade->id, 'reason' => 'I think this deserves a higher mark.']);
        $this->session = $this->offering->sessions()->create(['title' => 'Lecture', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function roles(): array
    {
        return [
            'student' => ['student', ['submit_work']],
            'lecturer' => ['lecturer', ['build_content', 'post_announcement', 'mark_attendance', 'grade_work', 'decide_appeals']],
            // An assistant manages the courses they are assigned to and grades, but cannot decide appeals.
            'teaching assistant' => ['teaching-assistant', ['build_content', 'post_announcement', 'mark_attendance', 'grade_work']],
            'registrar' => ['registrar', ['enrol_student']],
            // A department admin runs courses and enrolments, but cannot create accounts, import users, or read the audit log.
            'department admin' => ['department-admin', ['create_term', 'copy_course', 'enrol_student', 'build_content', 'post_announcement', 'mark_attendance', 'decide_appeals']],
            'university admin' => ['university-admin', ['create_term', 'copy_course', 'enrol_student', 'build_content', 'post_announcement', 'mark_attendance', 'decide_appeals', 'create_user', 'import_users', 'view_audit_log']],
            // A super-admin can do everything except act as an enrolled student.
            'super admin' => ['super-admin', ['create_term', 'copy_course', 'enrol_student', 'build_content', 'post_announcement', 'mark_attendance', 'decide_appeals', 'create_user', 'import_users', 'view_audit_log', 'grade_work']],
        ];
    }

    /** Each capability is one real API call that returns whether it succeeded (a 2xx). */
    private function capabilities(User $actor): array
    {
        $api = fn () => $this->actingAs($actor);
        $ok = fn ($response) => $response->status() >= 200 && $response->status() < 300;
        $o = '/api/offerings/'.$this->offering->id;

        return [
            'create_term' => fn () => $ok($api()->postJson('/api/terms', ['name' => 'Term X', 'starts_on' => '2028-01-01', 'ends_on' => '2028-06-01'])),
            'create_user' => fn () => $ok($api()->postJson('/api/users', ['name' => 'New', 'email' => 'new@uni.test', 'password' => 'a-long-password-1', 'role' => 'student'])),
            'view_audit_log' => fn () => $ok($api()->getJson('/api/audit-log')),
            'import_users' => fn () => $ok($api()->post('/api/users/import', ['dry_run' => '1', 'file' => UploadedFile::fake()->createWithContent('u.csv', "name,email,role\nX,x@uni.test,student\n")], ['Accept' => 'application/json'])),
            'enrol_student' => fn () => $ok($api()->postJson($o.'/enrolments', ['user_id' => $this->enrolTarget->id, 'status' => 'active'])),
            'build_content' => fn () => $ok($api()->postJson($o.'/modules', ['title' => 'Week 1'])),
            'post_announcement' => fn () => $ok($api()->postJson($o.'/announcements', ['title' => 'Hello', 'body' => 'World'])),
            'decide_appeals' => fn () => $ok($api()->postJson('/api/appeals/'.$this->appeal->id.'/resolve', ['outcome' => 'rejected', 'response' => 'Reviewed against the rubric.'])),
            'grade_work' => fn () => $ok($api()->postJson('/api/submissions/'.$this->submission->id.'/grades', ['status' => 'draft', 'score' => 50, 'change_reason' => 'Second look'])),
            'mark_attendance' => fn () => $ok($api()->putJson('/api/sessions/'.$this->session->id.'/attendance', ['records' => [['user_id' => $this->ada->id, 'status' => 'present']]])),
            'copy_course' => fn () => $ok($api()->postJson($o.'/copy', ['academic_term_id' => $this->nextTerm->id, 'section' => 'Z'])),
            'submit_work' => fn () => $ok($api()->postJson('/api/assignments/'.$this->essay->id.'/submissions', ['body' => 'My answer'])),
        ];
    }

    /**
     * @dataProvider roles
     *
     * @param  list<string>  $allowed
     */
    #[DataProvider('roles')]
    public function test_each_role_can_do_exactly_what_it_should(string $role, array $allowed): void
    {
        $actor = $this->userWithRole($role);
        match ($role) {
            'lecturer', 'teaching-assistant' => $this->teach($this->offering, $actor),
            'student' => $this->enrol($this->offering, $actor),
            default => null,
        };

        $results = [];
        foreach ($this->capabilities($actor) as $name => $attempt) {
            $results[$name] = $attempt();
        }
        $can = array_keys(array_filter($results));
        sort($can);
        $expected = $allowed;
        sort($expected);

        $this->assertSame($expected, $can, "A {$role} should be able to do exactly: ".implode(', ', $expected));
    }

    public function test_a_lecturer_cannot_manage_a_course_they_are_not_assigned_to(): void
    {
        $stranger = $this->userWithRole('lecturer');
        $api = fn () => $this->actingAs($stranger);
        $o = '/api/offerings/'.$this->offering->id;

        $api()->postJson($o.'/modules', ['title' => 'Nope'])->assertForbidden();
        $api()->postJson($o.'/announcements', ['title' => 'Nope', 'body' => 'x'])->assertForbidden();
        $api()->postJson('/api/submissions/'.$this->submission->id.'/grades', ['status' => 'draft', 'score' => 1, 'change_reason' => 'x'])->assertForbidden();
        $api()->postJson('/api/appeals/'.$this->appeal->id.'/resolve', ['outcome' => 'rejected', 'response' => 'Not my course to decide.'])->assertForbidden();
    }

    public function test_a_super_admin_cannot_submit_work_or_take_quizzes_without_being_enrolled(): void
    {
        $root = $this->userWithRole('super-admin');
        $quiz = $this->offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'published' => true, 'max_attempts' => 1]);
        $quiz->questions()->create(['type' => 'true_false', 'prompt' => 'q', 'points' => 1])->options()->createMany([['text' => 'True', 'is_correct' => true], ['text' => 'False', 'is_correct' => false]]);

        $this->actingAs($root)->postJson('/api/assignments/'.$this->essay->id.'/submissions', ['body' => 'x'])->assertForbidden();
        $this->actingAs($root)->postJson('/api/quizzes/'.$quiz->id.'/attempts')->assertForbidden();
        $this->assertSame(1, $this->essay->submissions()->count(), 'only Ada has submitted');
        $this->assertSame(0, $quiz->attempts()->count());

        // ...but is still all-powerful everywhere else.
        $this->actingAs($root)->getJson('/api/offerings/'.$this->offering->id)->assertOk();
        $this->actingAs($root)->postJson('/api/offerings/'.$this->offering->id.'/modules', ['title' => 'Week 1'])->assertCreated();
    }

    public function test_a_student_cannot_see_a_course_they_are_not_enrolled_in(): void
    {
        $other = $this->userWithRole('student');

        $this->actingAs($other)->getJson('/api/offerings/'.$this->offering->id)->assertForbidden();
        $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id)->assertOk();
    }
}
