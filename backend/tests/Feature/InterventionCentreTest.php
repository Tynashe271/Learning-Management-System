<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class InterventionCentreTest extends TestCase
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

    public function test_a_student_with_a_missed_assignment_is_flagged(): void
    {
        Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->subDay(), 'max_score' => 100, 'published' => true]);

        $rows = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/at-risk')->assertOk()->json();
        $ada = collect($rows)->firstWhere('user.id', $this->ada->id);
        $this->assertEquals(1, $ada['missing_assignments']);
        $this->assertTrue($ada['flagged']);
    }

    public function test_a_student_with_a_declining_trend_is_flagged(): void
    {
        $scores = [95, 90, 85, 50, 45, 40];
        foreach ($scores as $i => $score) {
            $a = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => "A$i", 'due_at' => now()->subDays(count($scores) - $i), 'max_score' => 100, 'published' => true]);
            $submission = $a->submissions()->create(['user_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now()]);
            $submission->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => $score, 'status' => 'published']);
        }

        $rows = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/at-risk')->assertOk()->json();
        $ada = collect($rows)->firstWhere('user.id', $this->ada->id);
        $this->assertTrue($ada['declining']);
        $this->assertEquals(0, $ada['missing_assignments']);
        $this->assertTrue($ada['flagged']);
    }

    public function test_a_student_with_no_signals_is_not_flagged(): void
    {
        $rows = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/at-risk')->assertOk()->json();
        $ben = collect($rows)->firstWhere('user.id', $this->ben->id);
        $this->assertFalse($ben['flagged']);
        $this->assertNull($ben['inactive_days']);
    }

    public function test_a_lecturer_can_create_and_resolve_an_intervention_plan_and_message_the_student(): void
    {
        Notification::fake();

        $id = $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/intervention-plans', [
            'user_id' => $this->ada->id,
            'reason' => 'Missed two assignments in a row.',
            'action_plan' => 'Meet weekly for the next month.',
            'message_to_student' => 'Hi! I noticed you have missed a couple of deadlines - want to grab 15 minutes this week?',
        ])->assertCreated()->assertJsonPath('status', 'open')->json();

        Notification::assertSentTo($this->ada, \App\Notifications\SupportCheckIn::class);

        $this->actingAs($this->lecturer)->patchJson('/api/intervention-plans/'.$id['id'], ['status' => 'resolved'])
            ->assertOk()->assertJsonPath('status', 'resolved')->assertJsonPath('resolved_at', fn ($v) => $v !== null);

        $plans = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/intervention-plans')->assertOk()->json();
        $this->assertCount(1, $plans);

        $rows = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/at-risk')->assertOk()->json();
        $this->assertFalse(collect($rows)->firstWhere('user.id', $this->ada->id)['has_open_plan']);
    }

    public function test_only_a_manager_can_see_at_risk_students_or_manage_plans(): void
    {
        $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/at-risk')->assertForbidden();
        $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/intervention-plans', ['user_id' => $this->ada->id, 'reason' => 'x'])->assertForbidden();
    }
}
