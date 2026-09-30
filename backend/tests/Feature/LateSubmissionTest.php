<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class LateSubmissionTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $student;

    private function setUpAssignment(bool $allowLate): Assignment
    {
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->student = $this->userWithRole('student');
        $this->enrol($this->offering, $this->student);

        return Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true, 'allow_late_submissions' => $allowLate]);
    }

    public function test_a_late_submission_is_still_refused_when_the_assignment_does_not_allow_it(): void
    {
        $assignment = $this->setUpAssignment(allowLate: false);

        $this->travel(2)->days();

        $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'late'])->assertForbidden();
    }

    public function test_a_late_submission_needs_an_explanation_when_the_assignment_allows_it(): void
    {
        $assignment = $this->setUpAssignment(allowLate: true);

        $this->travel(2)->days();

        $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'late'])
            ->assertUnprocessable()->assertJsonValidationErrors('late_explanation');
    }

    public function test_a_late_submission_with_an_explanation_is_accepted_and_flagged(): void
    {
        $assignment = $this->setUpAssignment(allowLate: true);

        $this->travel(2)->days();

        $submission = $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', [
            'body' => 'late',
            'late_explanation' => 'My laptop broke and I had to borrow one.',
        ])->assertCreated()->json();

        $this->assertTrue($submission['late']);
        $this->assertSame('My laptop broke and I had to borrow one.', $submission['late_explanation']);
    }

    public function test_an_on_time_submission_is_not_flagged_and_needs_no_explanation(): void
    {
        $assignment = $this->setUpAssignment(allowLate: true);

        $submission = $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'on time'])
            ->assertCreated()->json();

        $this->assertFalse($submission['late']);
        $this->assertNull($submission['late_explanation']);
    }

    public function test_the_grader_sees_the_late_flag_and_explanation_in_the_submissions_list(): void
    {
        $assignment = $this->setUpAssignment(allowLate: true);
        $lecturer = $this->userWithRole('lecturer');
        $this->teach($this->offering, $lecturer);

        $this->travel(2)->days();
        $this->actingAs($this->student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'late', 'late_explanation' => 'Power cut.'])->assertCreated();

        $list = $this->actingAs($lecturer)->getJson('/api/assignments/'.$assignment->id.'/submissions')->assertOk()->json('data');
        $this->assertTrue($list[0]['late']);
        $this->assertSame('Power cut.', $list[0]['late_explanation']);
    }
}
