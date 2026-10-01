<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class AcademicIntegrityTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private User $ben;

    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student');
        $this->ben = $this->userWithRole('student');
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->enrol($this->offering, $this->ben);
        $this->assignment = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
    }

    public function test_a_student_can_declare_ai_use_with_a_description(): void
    {
        $submission = $this->actingAs($this->ada)->postJson('/api/assignments/'.$this->assignment->id.'/submissions', [
            'body' => 'my essay', 'used_ai' => true, 'ai_use_description' => 'Used it to brainstorm an outline.',
        ])->assertCreated()->json();

        $this->assertTrue($submission['used_ai']);
        $this->assertSame('Used it to brainstorm an outline.', $submission['ai_use_description']);
    }

    public function test_declaring_ai_use_without_a_description_is_rejected(): void
    {
        $this->actingAs($this->ada)->postJson('/api/assignments/'.$this->assignment->id.'/submissions', ['body' => 'my essay', 'used_ai' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('ai_use_description');
    }

    public function test_a_submission_defaults_to_no_ai_use(): void
    {
        $submission = $this->actingAs($this->ada)->postJson('/api/assignments/'.$this->assignment->id.'/submissions', ['body' => 'my essay'])->assertCreated()->json();
        $this->assertFalse($submission['used_ai']);
        $this->assertNull($submission['ai_use_description']);
    }

    public function test_a_lecturer_can_open_and_resolve_an_integrity_case(): void
    {
        $submissionId = $this->actingAs($this->ada)->postJson('/api/assignments/'.$this->assignment->id.'/submissions', ['body' => 'my essay'])->assertCreated()->json('id');

        $caseId = $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/integrity-cases', [
            'user_id' => $this->ada->id, 'submission_id' => $submissionId, 'description' => 'Very high similarity with another student.',
        ])->assertCreated()->assertJsonPath('status', 'open')->json('id');

        $this->actingAs($this->lecturer)->patchJson('/api/integrity-cases/'.$caseId, ['status' => 'upheld', 'outcome' => 'Student admitted to copying; grade appeal process followed separately.'])
            ->assertOk()->assertJsonPath('status', 'upheld')->assertJsonPath('resolved_at', fn ($v) => $v !== null);

        $cases = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/integrity-cases')->assertOk()->json();
        $this->assertCount(1, $cases);
    }

    public function test_a_submission_belonging_to_another_student_cannot_be_attached_to_the_case(): void
    {
        $bensSubmissionId = $this->actingAs($this->ben)->postJson('/api/assignments/'.$this->assignment->id.'/submissions', ['body' => 'x'])->assertCreated()->json('id');

        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/integrity-cases', [
            'user_id' => $this->ada->id, 'submission_id' => $bensSubmissionId, 'description' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors('submission_id');
    }

    public function test_a_submission_from_a_different_course_cannot_be_attached_to_the_case(): void
    {
        $otherOffering = $this->offering('B');
        $this->teach($otherOffering, $this->lecturer);
        $this->enrol($otherOffering, $this->ada);
        $otherAssignment = Assignment::create(['course_offering_id' => $otherOffering->id, 'title' => 'Other essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
        $otherSubmissionId = $this->actingAs($this->ada)->postJson('/api/assignments/'.$otherAssignment->id.'/submissions', ['body' => 'x'])->assertCreated()->json('id');

        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/integrity-cases', [
            'user_id' => $this->ada->id, 'submission_id' => $otherSubmissionId, 'description' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors('submission_id');
    }

    public function test_only_a_manager_can_see_or_open_integrity_cases(): void
    {
        $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/integrity-cases')->assertForbidden();
        $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/integrity-cases', ['user_id' => $this->ben->id, 'description' => 'x'])->assertForbidden();
    }
}
