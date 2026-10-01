<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class CourseAnalyticsTest extends TestCase
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

    public function test_completion_is_the_average_share_of_published_content_done(): void
    {
        $module = $this->offering->modules()->create(['title' => 'Topic 1', 'published' => true]);
        $items = collect(range(1, 2))->map(fn ($i) => $module->items()->create(['title' => "Item $i", 'type' => 'text', 'body' => 'x', 'published' => true]));
        $this->actingAs($this->ada)->postJson("/api/items/{$items[0]->id}/complete")->assertOk();
        $this->actingAs($this->ada)->postJson("/api/items/{$items[1]->id}/complete")->assertOk();
        $this->actingAs($this->ben)->postJson("/api/items/{$items[0]->id}/complete")->assertOk();

        $data = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/analytics')->assertOk()->json();
        $this->assertEquals(75.0, $data['completion']['average_percent']);
        $this->assertSame(2, $data['completion']['students']);
    }

    public function test_assessment_performance_and_submission_patterns_are_reported(): void
    {
        $module = $this->offering->modules()->create(['title' => 'Topic 1', 'published' => true]);
        $assignment = $this->offering->assignments()->create(['title' => 'Essay', 'course_module_id' => $module->id, 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $adaSub = $assignment->submissions()->create(['user_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now(), 'late' => false]);
        $adaSub->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => 8, 'status' => 'published']);
        $assignment->submissions()->create(['user_id' => $this->ben->id, 'body' => 'y', 'submitted_at' => now(), 'late' => true]);

        $data = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/analytics')->assertOk()->json();
        $row = collect($data['assessments'])->firstWhere('id', $assignment->id);
        $this->assertSame('assignment', $row['type']);
        $this->assertEquals(80.0, $row['average_percent']);
        $this->assertSame(2, $row['enrolled']);
        $this->assertSame(2, $row['submitted']);
        $this->assertSame(1, $row['on_time']);
        $this->assertSame(1, $row['late']);
        $this->assertSame(0, $row['missing']);
    }

    public function test_a_topic_with_no_assessments_is_not_in_the_weak_topics_list(): void
    {
        $module = $this->offering->modules()->create(['title' => 'Untested topic', 'published' => true]);

        $data = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/analytics')->assertOk()->json();
        $this->assertNotContains($module->id, array_column($data['weak_topics'], 'module_id'));
    }

    public function test_response_time_averages_hours_from_submission_to_first_published_grade(): void
    {
        $assignment = $this->offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $submission = $assignment->submissions()->create(['user_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now()->subHours(5)]);
        $grade = $submission->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => 8, 'status' => 'published']);
        $grade->forceFill(['created_at' => now()])->save();

        $data = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/analytics')->assertOk()->json();
        $this->assertEquals(5.0, $data['response_time']['average_hours']);
        $this->assertSame(1, $data['response_time']['graded']);
    }

    public function test_competency_counts_are_reported_by_status(): void
    {
        $competency = $this->offering->competencies()->create(['title' => 'Solder a joint']);
        $competency->statuses()->create(['user_id' => $this->ada->id, 'status' => 'competent', 'updated_by' => $this->lecturer->id]);
        $competency->statuses()->create(['user_id' => $this->ben->id, 'status' => 'developing', 'updated_by' => $this->lecturer->id]);

        $data = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/analytics')->assertOk()->json();
        $row = collect($data['competencies'])->firstWhere('id', $competency->id);
        $this->assertSame(1, $row['competent']);
        $this->assertSame(1, $row['developing']);
        $this->assertSame(0, $row['not_started']);
    }

    public function test_only_a_manager_can_see_course_analytics(): void
    {
        $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/analytics')->assertForbidden();
    }
}
