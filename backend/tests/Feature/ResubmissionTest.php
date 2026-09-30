<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class ResubmissionTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $student;

    private User $lecturer;

    private function setUpAssignment(bool $allowResubmission): Assignment
    {
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->student = $this->userWithRole('student');
        $this->lecturer = $this->userWithRole('lecturer');
        $this->enrol($this->offering, $this->student);
        $this->teach($this->offering, $this->lecturer);

        return Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true, 'allow_resubmission' => $allowResubmission]);
    }

    public function test_resubmitting_is_refused_when_the_assignment_does_not_allow_it(): void
    {
        $assignment = $this->setUpAssignment(allowResubmission: false);
        $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'draft one'])->assertCreated();

        $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'draft two'])
            ->assertUnprocessable()->assertJsonValidationErrors('assignment');
    }

    public function test_a_student_can_resubmit_before_the_deadline_and_the_prior_version_is_kept(): void
    {
        $assignment = $this->setUpAssignment(allowResubmission: true);
        $id = $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'draft one'])->assertCreated()->json('id');

        $updated = $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'draft two'])->assertOk()->json();
        $this->assertSame($id, $updated['id']);
        $this->assertSame('draft two', $updated['body']);
        $this->assertSame(2, $updated['version']);

        $versions = $this->actingAs($this->student)->getJson('/api/submissions/'.$id.'/versions')->assertOk()->json();
        $this->assertCount(1, $versions);
        $this->assertSame(1, $versions[0]['version']);
        $this->assertSame('draft one', $versions[0]['body']);
    }

    public function test_a_grader_can_leave_feedback_on_the_current_draft_and_the_student_can_read_it(): void
    {
        $assignment = $this->setUpAssignment(allowResubmission: true);
        $id = $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'draft one'])->assertCreated()->json('id');

        $this->actingAs($this->lecturer)->postJson('/api/submissions/'.$id.'/feedback', ['body' => 'Good start, add a conclusion.'])->assertCreated();

        $feedback = $this->actingAs($this->student)->getJson('/api/submissions/'.$id.'/feedback')->assertOk()->json();
        $this->assertCount(1, $feedback);
        $this->assertSame('Good start, add a conclusion.', $feedback[0]['body']);
        $this->assertSame(1, $feedback[0]['version']);
        $this->assertSame($this->lecturer->name, $feedback[0]['author']['name']);
    }

    public function test_a_student_cannot_leave_feedback_or_read_someone_elses(): void
    {
        $assignment = $this->setUpAssignment(allowResubmission: true);
        $id = $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'draft one'])->assertCreated()->json('id');
        $outsider = $this->userWithRole('student');

        $this->actingAs($this->student)->postJson('/api/submissions/'.$id.'/feedback', ['body' => 'nice try'])->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/submissions/'.$id.'/feedback')->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/submissions/'.$id.'/versions')->assertForbidden();
    }

    public function test_resubmission_is_refused_once_a_grade_is_published(): void
    {
        $assignment = $this->setUpAssignment(allowResubmission: true);
        $id = $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'draft one'])->assertCreated()->json('id');
        $this->actingAs($this->lecturer)->postJson('/api/submissions/'.$id.'/grades', ['status' => 'published', 'score' => 90])->assertCreated();

        $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'too late now'])
            ->assertUnprocessable()->assertJsonValidationErrors('assignment');
    }
}
