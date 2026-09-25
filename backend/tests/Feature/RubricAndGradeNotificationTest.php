<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\GradePublished;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class RubricAndGradeNotificationTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private Assignment $essay;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student');
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->essay = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
        $this->submission = $this->essay->submissions()->create(['user_id' => $this->ada->id, 'body' => 'My essay', 'submitted_at' => now()]);
    }

    private function saveRubric(?array $criteria = null)
    {
        return $this->actingAs($this->lecturer)->putJson('/api/assignments/'.$this->essay->id.'/rubric', ['criteria' => $criteria ?? [
            ['title' => 'Argument', 'description' => 'Clear thesis', 'max_points' => 60],
            ['title' => 'Style', 'max_points' => 40],
        ]]);
    }

    /** @return list<int> criterion ids in rubric order */
    private function criterionIds(): array
    {
        return $this->essay->rubricCriteria()->orderBy('position')->pluck('id')->all();
    }

    public function test_a_rubric_must_add_up_to_the_assignment_score(): void
    {
        $this->saveRubric([['title' => 'Argument', 'max_points' => 60], ['title' => 'Style', 'max_points' => 30]])->assertJsonValidationErrors('criteria');
        $this->assertSame(0, $this->essay->rubricCriteria()->count());

        $this->saveRubric()->assertOk()->assertJsonCount(2)->assertJsonPath('0.title', 'Argument')->assertJsonPath('1.max_points', 40);
        $this->saveRubric([['title' => 'Everything', 'max_points' => 100]])->assertOk()->assertJsonCount(1);
        $this->assertSame(1, $this->essay->rubricCriteria()->count());
    }

    public function test_students_can_read_the_rubric_but_only_managers_can_change_it(): void
    {
        $this->saveRubric();

        $this->actingAs($this->ada)->getJson('/api/assignments/'.$this->essay->id.'/rubric')->assertOk()->assertJsonCount(2);
        $this->actingAs($this->ada)->putJson('/api/assignments/'.$this->essay->id.'/rubric', ['criteria' => [['title' => 'x', 'max_points' => 100]]])->assertForbidden();
        $this->actingAs($this->userWithRole('lecturer'))->putJson('/api/assignments/'.$this->essay->id.'/rubric', ['criteria' => [['title' => 'x', 'max_points' => 100]]])->assertForbidden();
        $this->actingAs($this->userWithRole('student'))->getJson('/api/assignments/'.$this->essay->id.'/rubric')->assertForbidden();
    }

    public function test_grading_with_a_rubric_sums_the_criteria_marks(): void
    {
        $this->saveRubric();
        [$argument, $style] = $this->criterionIds();

        $grade = $this->actingAs($this->lecturer)->postJson('/api/submissions/'.$this->submission->id.'/grades', [
            'status' => 'published', 'feedback' => 'Well argued.',
            'criteria' => [['criterion_id' => $argument, 'points' => 45, 'comment' => 'Strong thesis'], ['criterion_id' => $style, 'points' => 30.5]],
        ])->assertCreated();

        $this->assertEquals(75.5, $grade->json('score'));
        $grade->assertJsonCount(2, 'criteria_scores')->assertJsonPath('criteria_scores.0.title', 'Argument')->assertJsonPath('criteria_scores.0.max_points', 60)->assertJsonPath('criteria_scores.0.comment', 'Strong thesis');
        $mine = $this->actingAs($this->ada)->getJson('/api/assignments/'.$this->essay->id.'/my-grade')->assertOk();
        $this->assertEquals(75.5, $mine->json('grade.score'));
        $mine->assertJsonPath('grade.criteria_scores.1.title', 'Style');
    }

    public function test_rubric_grades_must_cover_every_criterion_within_its_maximum(): void
    {
        $this->saveRubric();
        [$argument, $style] = $this->criterionIds();
        $post = fn (array $body) => $this->actingAs($this->lecturer)->postJson('/api/submissions/'.$this->submission->id.'/grades', $body + ['status' => 'draft']);

        $post(['score' => 80])->assertJsonValidationErrors('criteria');
        $post(['criteria' => [['criterion_id' => $argument, 'points' => 40]]])->assertJsonValidationErrors('criteria');
        $post(['criteria' => [['criterion_id' => $argument, 'points' => 40], ['criterion_id' => $argument, 'points' => 20]]])->assertJsonValidationErrors('criteria');
        $post(['criteria' => [['criterion_id' => $argument, 'points' => 61], ['criterion_id' => $style, 'points' => 10]]])->assertJsonValidationErrors('criteria');
        $post(['criteria' => [['criterion_id' => $argument, 'points' => -1], ['criterion_id' => $style, 'points' => 10]]])->assertJsonValidationErrors('criteria.0.points');
        $post(['criteria' => [['criterion_id' => 999999, 'points' => 1], ['criterion_id' => $style, 'points' => 10]]])->assertJsonValidationErrors('criteria.0.criterion_id');
        $this->assertDatabaseCount('grade_records', 0);
    }

    public function test_criteria_marks_are_refused_when_the_assignment_has_no_rubric(): void
    {
        $this->actingAs($this->lecturer)->postJson('/api/submissions/'.$this->submission->id.'/grades', ['status' => 'draft', 'score' => 50, 'criteria' => [['criterion_id' => 1, 'points' => 50]]])->assertJsonValidationErrors('criteria');
        $this->actingAs($this->lecturer)->postJson('/api/submissions/'.$this->submission->id.'/grades', ['status' => 'draft', 'score' => 50])->assertCreated();
    }

    public function test_the_rubric_freezes_once_grading_starts(): void
    {
        $this->saveRubric();
        [$argument, $style] = $this->criterionIds();
        $this->actingAs($this->lecturer)->postJson('/api/submissions/'.$this->submission->id.'/grades', ['status' => 'draft', 'criteria' => [['criterion_id' => $argument, 'points' => 1], ['criterion_id' => $style, 'points' => 1]]])->assertCreated();

        $this->saveRubric([['title' => 'Different', 'max_points' => 100]])->assertJsonValidationErrors('rubric');
        $this->actingAs($this->lecturer)->deleteJson('/api/assignments/'.$this->essay->id.'/rubric')->assertJsonValidationErrors('rubric');
        $this->assertSame(2, $this->essay->rubricCriteria()->count());
    }

    public function test_an_ungraded_rubric_can_be_removed(): void
    {
        $this->saveRubric();

        $this->actingAs($this->lecturer)->deleteJson('/api/assignments/'.$this->essay->id.'/rubric')->assertOk();

        $this->assertSame(0, $this->essay->rubricCriteria()->count());
    }

    public function test_publishing_a_grade_notifies_the_student_but_saving_a_draft_does_not(): void
    {
        Notification::fake();
        $url = '/api/submissions/'.$this->submission->id.'/grades';

        $this->actingAs($this->lecturer)->postJson($url, ['status' => 'draft', 'score' => 60])->assertCreated();
        Notification::assertNothingSent();

        $this->actingAs($this->lecturer)->postJson($url, ['status' => 'published', 'score' => 85, 'change_reason' => 'final mark'])->assertCreated();
        Notification::assertSentTo($this->ada, GradePublished::class, fn (GradePublished $n) => $n->title === 'Essay' && $n->submissionId === $this->submission->id);
        Notification::assertCount(1);
    }

    public function test_the_notification_is_stored_for_the_student_and_keeps_the_mark_out_of_the_email(): void
    {
        $this->actingAs($this->lecturer)->postJson('/api/submissions/'.$this->submission->id.'/grades', ['status' => 'published', 'score' => 87])->assertCreated();

        $stored = $this->actingAs($this->ada)->getJson('/api/notifications')->assertOk();
        $stored->assertJsonPath('data.0.data.title', 'Essay')->assertJsonPath('data.0.data.assignment_id', $this->essay->id);

        $mail = (new GradePublished($this->essay->id, $this->submission->id, 'Essay'))->toMail($this->ada);
        $this->assertStringContainsString('Essay', $mail->subject);
        $this->assertStringNotContainsString('87', implode(' ', $mail->introLines).$mail->subject);
    }
}
