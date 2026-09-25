<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\GradeAppeal;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\AppealFiled;
use App\Notifications\AppealResolved;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class GradeAppealTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private const REASON = 'I believe the second criterion was marked too harshly given the evidence I cited.';

    private CourseOffering $offering;

    private User $lecturer;

    private User $assistant;

    private User $ada;

    private Assignment $essay;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->assistant = $this->userWithRole('teaching-assistant');
        $this->ada = $this->userWithRole('student', ['name' => 'Ada']);
        $this->teach($this->offering, $this->lecturer);
        $this->teach($this->offering, $this->assistant);
        $this->enrol($this->offering, $this->ada);
        $this->essay = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
        $this->submission = $this->gradedSubmission($this->ada, 60);
    }

    private function gradedSubmission(User $student, float $score, string $status = 'published'): Submission
    {
        $submission = $this->essay->submissions()->create(['user_id' => $student->id, 'body' => 'My essay', 'submitted_at' => now()]);
        $submission->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => $score, 'status' => $status]);

        return $submission;
    }

    private function appeal(?User $as = null, ?Submission $submission = null, string $reason = self::REASON)
    {
        return $this->actingAs($as ?? $this->ada)->postJson('/api/submissions/'.($submission ?? $this->submission)->id.'/appeal', ['reason' => $reason]);
    }

    private function regrade(float $score, string $status = 'published')
    {
        return $this->actingAs($this->lecturer)->postJson('/api/submissions/'.$this->submission->id.'/grades', ['status' => $status, 'score' => $score, 'change_reason' => 'Reviewed after the appeal']);
    }

    private function resolve(string $outcome, ?User $as = null, string $response = 'I re-read the essay against the rubric.')
    {
        $id = GradeAppeal::firstOrFail()->id;

        return $this->actingAs($as ?? $this->lecturer)->postJson('/api/appeals/'.$id.'/resolve', ['outcome' => $outcome, 'response' => $response]);
    }

    public function test_a_student_can_appeal_a_published_grade_and_the_teaching_staff_are_told(): void
    {
        Notification::fake();

        $response = $this->appeal()->assertCreated();

        $response->assertJsonPath('status', 'open')->assertJsonPath('user_id', $this->ada->id)->assertJsonPath('reason', self::REASON);
        $this->assertSame($this->submission->gradeRecords()->first()->id, $response->json('grade_record_id'));
        Notification::assertSentTo($this->lecturer, AppealFiled::class, fn (AppealFiled $n) => $n->title === 'Essay');
        Notification::assertSentTo($this->assistant, AppealFiled::class);
        Notification::assertNotSentTo($this->ada, AppealFiled::class);
        $this->assertDatabaseHas('activity_log', ['description' => 'grade appeal filed']);
    }

    public function test_the_reason_has_to_be_substantial(): void
    {
        $this->appeal(reason: 'Unfair.')->assertJsonValidationErrors('reason');
        $this->appeal(reason: str_repeat('x', 5001))->assertJsonValidationErrors('reason');
        $this->actingAs($this->ada)->postJson('/api/submissions/'.$this->submission->id.'/appeal', [])->assertJsonValidationErrors('reason');
        $this->assertDatabaseCount('grade_appeals', 0);
    }

    public function test_only_the_student_whose_work_it_is_can_appeal(): void
    {
        $ben = $this->userWithRole('student');
        $this->enrol($this->offering, $ben);

        $this->appeal($ben)->assertForbidden();
        $this->appeal($this->lecturer)->assertForbidden();
        $this->appeal($this->userWithRole('student'))->assertForbidden();
        $this->assertDatabaseCount('grade_appeals', 0);
    }

    public function test_there_must_be_a_published_grade_to_appeal(): void
    {
        $ben = $this->userWithRole('student');
        $this->enrol($this->offering, $ben);
        $draftOnly = $this->gradedSubmission($ben, 40, 'draft');
        $cy = $this->userWithRole('student');
        $this->enrol($this->offering, $cy);
        $ungraded = $this->essay->submissions()->create(['user_id' => $cy->id, 'body' => 'x', 'submitted_at' => now()]);

        $this->appeal($ben, $draftOnly)->assertJsonValidationErrors('appeal');
        $this->appeal($cy, $ungraded)->assertJsonValidationErrors('appeal');
        $this->assertDatabaseCount('grade_appeals', 0);
    }

    public function test_appeals_must_be_filed_within_the_window(): void
    {
        config(['lms.appeals.window_days' => 14]);
        $this->travel(13)->days();
        $this->appeal()->assertCreated();
        $this->actingAs($this->ada)->deleteJson('/api/appeals/'.GradeAppeal::first()->id)->assertOk();

        $this->travel(2)->days();

        $this->appeal()->assertJsonValidationErrors('appeal');
        $this->assertStringContainsString('closed', $this->appeal()->json('errors.appeal.0'));
    }

    public function test_the_window_runs_from_the_latest_published_grade(): void
    {
        config(['lms.appeals.window_days' => 14]);
        $this->travel(20)->days();
        $this->regrade(70)->assertCreated(); // a new grade is published on day 20

        $this->appeal()->assertCreated()->assertJsonPath('grade_record_id', $this->submission->gradeRecords()->where('status', 'published')->max('id'));
    }

    public function test_a_grade_can_only_be_appealed_once(): void
    {
        $this->appeal()->assertCreated();

        $this->appeal()->assertJsonValidationErrors('appeal');
        $this->assertDatabaseCount('grade_appeals', 1);
    }

    public function test_a_decided_grade_cannot_be_appealed_again(): void
    {
        $this->appeal()->assertCreated();
        $this->resolve('rejected')->assertOk();

        $this->appeal()->assertJsonValidationErrors('appeal');
        $this->assertDatabaseCount('grade_appeals', 1);
    }

    public function test_a_student_no_longer_enrolled_cannot_appeal(): void
    {
        $this->offering->enrolments()->where('user_id', $this->ada->id)->update(['status' => 'withdrawn']);

        $this->appeal()->assertForbidden();
    }

    public function test_students_and_staff_can_list_appeals_with_open_ones_first(): void
    {
        $ben = $this->userWithRole('student');
        $this->enrol($this->offering, $ben);
        $benSubmission = $this->gradedSubmission($ben, 50);
        $this->appeal($ben, $benSubmission)->assertCreated();
        $this->appeal()->assertCreated();
        GradeAppeal::where('user_id', $ben->id)->firstOrFail()->update(['status' => 'rejected', 'response' => 'No change.']);

        $this->actingAs($this->ada)->getJson('/api/my-appeals')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $this->ada->id);
        $all = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/appeals')->assertOk();
        $this->assertSame(['open', 'rejected'], array_column($all->json('data'), 'status'));
        $all->assertJsonPath('data.0.student.name', 'Ada')->assertJsonPath('data.0.submission.assignment.title', 'Essay');
        $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/appeals?status=rejected')->assertJsonCount(1, 'data');
        $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/appeals?status=bogus')->assertJsonValidationErrors('status');
        $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/appeals')->assertForbidden();
        $this->actingAs($this->userWithRole('lecturer'))->getJson('/api/offerings/'.$this->offering->id.'/appeals')->assertForbidden();
    }

    public function test_appeal_details_are_visible_to_the_student_and_the_staff_only(): void
    {
        $id = $this->appeal()->assertCreated()->json('id');
        $this->regrade(70)->assertCreated();

        $detail = $this->actingAs($this->ada)->getJson('/api/appeals/'.$id)->assertOk();
        $detail->assertJsonPath('submission.assignment.title', 'Essay')->assertJsonCount(2, 'submission.grade_records');
        $this->actingAs($this->lecturer)->getJson('/api/appeals/'.$id)->assertOk();
        $this->actingAs($this->assistant)->getJson('/api/appeals/'.$id)->assertOk();
        $this->actingAs($this->userWithRole('student'))->getJson('/api/appeals/'.$id)->assertForbidden();
        $this->actingAs($this->userWithRole('lecturer'))->getJson('/api/appeals/'.$id)->assertForbidden();
    }

    public function test_an_appeal_can_only_be_upheld_after_a_revised_grade_is_published(): void
    {
        Notification::fake();
        $this->appeal()->assertCreated();

        $this->resolve('upheld')->assertJsonValidationErrors('outcome');
        $this->regrade(75, 'draft')->assertCreated(); // a draft does not count as a revision
        $this->resolve('upheld')->assertJsonValidationErrors('outcome');
        $this->regrade(75)->assertCreated();

        $this->resolve('upheld')->assertOk()->assertJsonPath('status', 'upheld')->assertJsonPath('resolved_by', $this->lecturer->id)
            ->assertJsonPath('response', 'I re-read the essay against the rubric.');
        Notification::assertSentTo($this->ada, AppealResolved::class, fn (AppealResolved $n) => $n->outcome === 'upheld' && $n->title === 'Essay');
        $this->assertDatabaseHas('activity_log', ['description' => 'grade appeal decided']);
    }

    public function test_an_appeal_cannot_be_rejected_once_the_grade_has_been_changed(): void
    {
        $this->appeal()->assertCreated();
        $this->regrade(75)->assertCreated();

        $this->resolve('rejected')->assertJsonValidationErrors('outcome');
        $this->assertSame('open', GradeAppeal::first()->status);
    }

    public function test_an_appeal_can_be_rejected_with_an_explanation(): void
    {
        Notification::fake();
        $this->appeal()->assertCreated();

        $this->resolve('rejected')->assertOk()->assertJsonPath('status', 'rejected');

        Notification::assertSentTo($this->ada, AppealResolved::class, fn (AppealResolved $n) => $n->outcome === 'rejected');
        $this->assertNotNull(GradeAppeal::first()->resolved_at);
    }

    public function test_the_decision_needs_a_valid_outcome_and_an_explanation(): void
    {
        $id = $this->appeal()->assertCreated()->json('id');
        $url = '/api/appeals/'.$id.'/resolve';

        $this->actingAs($this->lecturer)->postJson($url, ['outcome' => 'maybe', 'response' => 'Some words here.'])->assertJsonValidationErrors('outcome');
        $this->actingAs($this->lecturer)->postJson($url, ['outcome' => 'rejected'])->assertJsonValidationErrors('response');
        $this->actingAs($this->lecturer)->postJson($url, ['outcome' => 'rejected', 'response' => 'No.'])->assertJsonValidationErrors('response');
        $this->assertSame('open', GradeAppeal::first()->status);
    }

    public function test_only_people_allowed_to_resolve_appeals_can_decide_them(): void
    {
        $this->appeal()->assertCreated();

        $this->resolve('rejected', $this->assistant)->assertForbidden();            // assistants can grade but not decide appeals
        $this->resolve('rejected', $this->ada)->assertForbidden();
        $this->resolve('rejected', $this->userWithRole('lecturer'))->assertForbidden(); // not their course
        $this->resolve('rejected', $this->userWithRole('registrar'))->assertForbidden();
        $this->assertSame('open', GradeAppeal::first()->status);

        $this->resolve('rejected', $this->userWithRole('university-admin'))->assertOk(); // an administrator can mediate
    }

    public function test_a_decided_appeal_is_final(): void
    {
        $id = $this->appeal()->assertCreated()->json('id');
        $this->resolve('rejected')->assertOk();

        $this->resolve('rejected')->assertJsonValidationErrors('appeal');
        $this->actingAs($this->ada)->deleteJson('/api/appeals/'.$id)->assertJsonValidationErrors('appeal');
    }

    public function test_a_student_can_withdraw_an_open_appeal_and_file_it_again(): void
    {
        $ben = $this->userWithRole('student');
        $this->enrol($this->offering, $ben);
        $id = $this->appeal()->assertCreated()->json('id');

        $this->actingAs($ben)->deleteJson('/api/appeals/'.$id)->assertForbidden();
        $this->actingAs($this->lecturer)->deleteJson('/api/appeals/'.$id)->assertForbidden();
        $this->actingAs($this->ada)->deleteJson('/api/appeals/'.$id)->assertOk();

        $this->assertDatabaseCount('grade_appeals', 0);
        $this->assertDatabaseHas('activity_log', ['description' => 'grade appeal withdrawn']);
        $this->appeal()->assertCreated();
    }

    public function test_the_emails_state_the_outcome_but_keep_the_details_in_the_lms(): void
    {
        $filed = (new AppealFiled(1, 5, 'Essay'))->toMail($this->lecturer);
        $upheld = (new AppealResolved(1, 'Essay', 'upheld'))->toMail($this->ada);
        $rejected = (new AppealResolved(1, 'Essay', 'rejected'))->toMail($this->ada);

        $this->assertStringContainsString('Essay', $filed->subject);
        $this->assertStringContainsString('upheld', implode(' ', $upheld->introLines));
        $this->assertStringContainsString('stands', implode(' ', $rejected->introLines));
        $this->assertStringNotContainsString(self::REASON, implode(' ', $filed->introLines));
    }

    public function test_the_permission_to_resolve_appeals_goes_to_the_right_roles(): void
    {
        foreach (['lecturer', 'university-admin', 'department-admin', 'super-admin'] as $role) {
            $this->assertTrue(Role::findByName($role)->hasPermissionTo('resolve-appeals'), "$role should be able to resolve appeals");
        }
        foreach (['teaching-assistant', 'registrar', 'student'] as $role) {
            $this->assertFalse(Role::findByName($role)->hasPermissionTo('resolve-appeals'), "$role should not be able to resolve appeals");
        }
    }

    public function test_appeals_require_signing_in(): void
    {
        $this->postJson('/api/submissions/'.$this->submission->id.'/appeal', ['reason' => self::REASON])->assertUnauthorized();
        $this->getJson('/api/my-appeals')->assertUnauthorized();
    }
}
