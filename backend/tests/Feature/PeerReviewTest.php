<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class PeerReviewTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private User $ben;

    private User $cy;

    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student', ['name' => 'Ada Student']);
        $this->ben = $this->userWithRole('student', ['name' => 'Ben Student']);
        $this->cy = $this->userWithRole('student', ['name' => 'Cy Student']);
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->enrol($this->offering, $this->ben);
        $this->enrol($this->offering, $this->cy);
        $this->assignment = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
        foreach ([$this->ada, $this->ben, $this->cy] as $student) {
            $this->actingAs($student)->postJson('/api/assignments/'.$this->assignment->id.'/submissions', ['body' => $student->name.'s essay'])->assertCreated();
        }
    }

    public function test_a_manager_can_assign_peer_reviews_and_nobody_reviews_their_own_work(): void
    {
        $this->actingAs($this->lecturer)->postJson('/api/assignments/'.$this->assignment->id.'/peer-reviews/assign', ['per_student' => 2])
            ->assertOk()->assertJson(['assigned' => 6]);

        $mine = $this->actingAs($this->ada)->getJson('/api/assignments/'.$this->assignment->id.'/my-peer-reviews')->assertOk()->json();
        $this->assertCount(2, $mine);
        foreach ($mine as $review) {
            $this->assertNotSame("Ada Student's essay", $review['submission']['body']);
        }
    }

    public function test_a_reviewer_can_submit_a_review_and_the_submission_owner_sees_it_anonymously(): void
    {
        $this->actingAs($this->lecturer)->postJson('/api/assignments/'.$this->assignment->id.'/peer-reviews/assign', ['per_student' => 2])->assertOk();
        $reviewId = collect($this->actingAs($this->ben)->getJson('/api/assignments/'.$this->assignment->id.'/my-peer-reviews')->json())->first()['id'];

        $this->actingAs($this->ben)->patchJson('/api/peer-reviews/'.$reviewId, ['body' => 'Well argued, but check your references.'])->assertOk();

        $submissionId = $this->actingAs($this->ada)->getJson('/api/assignments/'.$this->assignment->id.'/my-grade')->json('submission.id');
        // Ada's own submission may or may not have been reviewed by Ben; find whichever submission Ben actually reviewed.
        $review = \App\Models\PeerReview::where('reviewer_id', $this->ben->id)->whereNotNull('submitted_at')->first();
        $owner = \App\Models\Submission::find($review->submission_id)->user;

        $received = $this->actingAs($owner)->getJson('/api/submissions/'.$review->submission_id.'/peer-reviews')->assertOk()->json();
        $this->assertCount(1, $received);
        $this->assertSame('Well argued, but check your references.', $received[0]['body']);
        $this->assertArrayNotHasKey('reviewer_id', $received[0]);
        $this->assertArrayNotHasKey('reviewer', $received[0]);
    }

    public function test_only_the_assigned_reviewer_can_submit_that_review(): void
    {
        $this->actingAs($this->lecturer)->postJson('/api/assignments/'.$this->assignment->id.'/peer-reviews/assign', ['per_student' => 2])->assertOk();
        $reviewId = collect($this->actingAs($this->ada)->getJson('/api/assignments/'.$this->assignment->id.'/my-peer-reviews')->json())->first()['id'];

        $this->actingAs($this->ben)->patchJson('/api/peer-reviews/'.$reviewId, ['body' => 'nice try'])->assertForbidden();
    }

    public function test_peer_review_is_refused_for_group_assignments(): void
    {
        $group = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Group project', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true, 'is_group_assignment' => true]);

        $this->actingAs($this->lecturer)->postJson('/api/assignments/'.$group->id.'/peer-reviews/assign', ['per_student' => 1])->assertStatus(422);
    }

    public function test_only_a_manager_can_assign_peer_reviews(): void
    {
        $this->actingAs($this->ada)->postJson('/api/assignments/'.$this->assignment->id.'/peer-reviews/assign', ['per_student' => 1])->assertForbidden();
    }
}
