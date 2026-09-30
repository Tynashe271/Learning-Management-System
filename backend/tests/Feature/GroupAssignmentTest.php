<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class GroupAssignmentTest extends TestCase
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
        $this->assignment = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Project', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true, 'is_group_assignment' => true]);
    }

    private function makeGroup(array $userIds, string $name = 'Team A'): int
    {
        return $this->actingAs($this->lecturer)->postJson('/api/assignments/'.$this->assignment->id.'/groups', ['name' => $name, 'user_ids' => $userIds])
            ->assertCreated()->json('id');
    }

    public function test_a_manager_can_create_a_group_and_cannot_double_place_a_student(): void
    {
        $this->makeGroup([$this->ada->id, $this->ben->id]);

        $this->actingAs($this->lecturer)->postJson('/api/assignments/'.$this->assignment->id.'/groups', ['name' => 'Team B', 'user_ids' => [$this->ben->id, $this->cy->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('user_ids');
    }

    public function test_a_student_without_a_group_cannot_submit(): void
    {
        $this->makeGroup([$this->ada->id, $this->ben->id]);

        $this->actingAs($this->cy)->postJson('/api/assignments/'.$this->assignment->id.'/submissions', ['body' => 'our work'])
            ->assertUnprocessable();
    }

    public function test_one_members_submission_is_shared_with_the_whole_group(): void
    {
        $this->makeGroup([$this->ada->id, $this->ben->id]);

        $this->actingAs($this->ada)->postJson('/api/assignments/'.$this->assignment->id.'/submissions', ['body' => 'our work'])->assertCreated();

        $mine = $this->actingAs($this->ben)->getJson('/api/assignments/'.$this->assignment->id.'/my-grade')->assertOk()->json();
        $this->assertSame('our work', $mine['submission']['body']);

        // Ada cannot submit again for the group without resubmission being allowed.
        $this->actingAs($this->ben)->postJson('/api/assignments/'.$this->assignment->id.'/submissions', ['body' => 'try again'])
            ->assertUnprocessable()->assertJsonValidationErrors('assignment');
    }

    public function test_grading_one_members_submission_grades_the_whole_group(): void
    {
        $this->makeGroup([$this->ada->id, $this->ben->id]);
        $adaSubmissionId = $this->actingAs($this->ada)->postJson('/api/assignments/'.$this->assignment->id.'/submissions', ['body' => 'our work'])->assertCreated()->json('id');

        $this->actingAs($this->lecturer)->postJson('/api/submissions/'.$adaSubmissionId.'/grades', ['status' => 'published', 'score' => 88])->assertCreated();

        $bensGrade = $this->actingAs($this->ben)->getJson('/api/assignments/'.$this->assignment->id.'/my-grade')->assertOk()->json('grade');
        $this->assertEquals(88, $bensGrade['score']);
    }

    public function test_a_manager_can_update_group_membership_and_delete_a_group(): void
    {
        $groupId = $this->makeGroup([$this->ada->id]);

        $this->actingAs($this->lecturer)->patchJson('/api/assignment-groups/'.$groupId, ['user_ids' => [$this->ada->id, $this->ben->id]])->assertOk();
        $groups = $this->actingAs($this->lecturer)->getJson('/api/assignments/'.$this->assignment->id.'/groups')->assertOk()->json();
        $this->assertCount(2, $groups[0]['members']);

        $this->actingAs($this->lecturer)->deleteJson('/api/assignment-groups/'.$groupId)->assertOk();
        $this->actingAs($this->lecturer)->getJson('/api/assignments/'.$this->assignment->id.'/groups')->assertOk()->assertJsonCount(0);
    }

    public function test_a_student_can_see_their_own_group_but_not_before_being_placed(): void
    {
        $this->assertEmpty($this->actingAs($this->cy)->getJson('/api/assignments/'.$this->assignment->id.'/my-group')->assertOk()->json());

        $this->makeGroup([$this->ada->id, $this->ben->id]);

        $mine = $this->actingAs($this->ada)->getJson('/api/assignments/'.$this->assignment->id.'/my-group')->assertOk()->json();
        $this->assertSame('Team A', $mine['name']);
        $this->assertCount(2, $mine['members']);
    }

    public function test_only_a_manager_can_manage_groups(): void
    {
        $this->actingAs($this->ada)->postJson('/api/assignments/'.$this->assignment->id.'/groups', ['name' => 'Team A', 'user_ids' => [$this->ada->id]])->assertForbidden();
    }
}
