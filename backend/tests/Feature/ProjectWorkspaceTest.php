<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class ProjectWorkspaceTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private User $ben;

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
    }

    public function test_a_student_can_propose_a_topic_once(): void
    {
        $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/projects', ['title' => 'Predicting rainfall', 'description' => 'A time series model.'])
            ->assertCreated()->assertJsonPath('status', 'proposed');

        $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/projects', ['title' => 'Something else'])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
    }

    public function test_a_manager_can_approve_a_topic_and_assign_the_supervisor(): void
    {
        $id = $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/projects', ['title' => 'Predicting rainfall'])->assertCreated()->json('id');

        $this->actingAs($this->lecturer)->patchJson('/api/projects/'.$id, ['status' => 'approved', 'supervisor_id' => $this->lecturer->id])
            ->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('supervisor.name', $this->lecturer->name);
    }

    public function test_a_student_cannot_assign_their_own_supervisor(): void
    {
        $id = $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/projects', ['title' => 'Predicting rainfall'])->assertCreated()->json('id');

        $this->actingAs($this->ada)->patchJson('/api/projects/'.$id, ['supervisor_id' => $this->lecturer->id])->assertUnprocessable();
    }

    public function test_a_student_cannot_edit_the_topic_once_reviewed(): void
    {
        $id = $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/projects', ['title' => 'Predicting rainfall'])->assertCreated()->json('id');
        $this->actingAs($this->lecturer)->patchJson('/api/projects/'.$id, ['status' => 'approved'])->assertOk();

        $this->actingAs($this->ada)->patchJson('/api/projects/'.$id, ['title' => 'New title'])->assertStatus(422);
    }

    public function test_the_assigned_supervisor_can_add_and_complete_milestones(): void
    {
        $id = $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/projects', ['title' => 'Predicting rainfall'])->assertCreated()->json('id');
        $this->actingAs($this->lecturer)->patchJson('/api/projects/'.$id, ['status' => 'approved', 'supervisor_id' => $this->lecturer->id])->assertOk();

        $milestoneId = $this->actingAs($this->lecturer)->postJson('/api/projects/'.$id.'/milestones', ['title' => 'Literature review', 'due_on' => now()->addWeek()->toDateString()])
            ->assertCreated()->json('id');

        $this->actingAs($this->ada)->getJson('/api/projects/'.$id.'/milestones')->assertOk()->assertJsonCount(1);

        $this->actingAs($this->lecturer)->patchJson('/api/project-milestones/'.$milestoneId, ['completed' => true])->assertOk()->assertJsonPath('completed_at', fn ($v) => $v !== null);
    }

    public function test_only_the_supervisor_or_a_manager_can_add_milestones(): void
    {
        $id = $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/projects', ['title' => 'Predicting rainfall'])->assertCreated()->json('id');

        $this->actingAs($this->ben)->postJson('/api/projects/'.$id.'/milestones', ['title' => 'x', 'due_on' => now()->addWeek()->toDateString()])->assertForbidden();
    }

    public function test_the_student_and_supervisor_can_log_meetings_but_an_outsider_cannot(): void
    {
        $id = $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/projects', ['title' => 'Predicting rainfall'])->assertCreated()->json('id');
        $this->actingAs($this->lecturer)->patchJson('/api/projects/'.$id, ['status' => 'approved', 'supervisor_id' => $this->lecturer->id])->assertOk();

        $this->actingAs($this->ada)->postJson('/api/projects/'.$id.'/meetings', ['occurred_on' => now()->toDateString(), 'notes' => 'Discussed scope.'])->assertCreated();
        $this->actingAs($this->lecturer)->postJson('/api/projects/'.$id.'/meetings', ['occurred_on' => now()->toDateString(), 'notes' => 'Agreed on methodology.'])->assertCreated();
        $this->actingAs($this->ben)->postJson('/api/projects/'.$id.'/meetings', ['occurred_on' => now()->toDateString(), 'notes' => 'x'])->assertForbidden();

        $meetings = $this->actingAs($this->ada)->getJson('/api/projects/'.$id.'/meetings')->assertOk()->json();
        $this->assertCount(2, $meetings);
        $this->assertSame($this->ada->name, $meetings[0]['user']['name']);
        $this->assertSame('Discussed scope.', $meetings[0]['notes']);
    }

    public function test_a_student_sees_only_their_own_project_and_a_manager_sees_everyones(): void
    {
        $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/projects', ['title' => 'Ada project'])->assertCreated();
        $this->actingAs($this->ben)->postJson('/api/offerings/'.$this->offering->id.'/projects', ['title' => 'Ben project'])->assertCreated();

        $mine = $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/projects/mine')->assertOk()->json();
        $this->assertSame('Ada project', $mine['title']);

        $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/projects')->assertOk()->assertJsonCount(2);
    }
}
