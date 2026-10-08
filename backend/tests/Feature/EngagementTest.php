<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class EngagementTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->student = $this->userWithRole('student');
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->student);
    }

    public function test_participation_points_are_tallied_from_real_actions_in_the_course(): void
    {
        $session = $this->offering->sessions()->create(['title' => 'Lecture', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
        $session->attendance()->create(['user_id' => $this->student->id, 'status' => 'present', 'marked_by' => $this->lecturer->id]);

        $thread = $this->offering->threads()->create(['user_id' => $this->student->id, 'title' => 'Question', 'body' => 'x']);
        $otherThread = $this->offering->threads()->create(['user_id' => $this->lecturer->id, 'title' => 'Notice', 'body' => 'x']);
        $otherThread->posts()->create(['user_id' => $this->student->id, 'body' => 'reply']);

        $quiz = $this->offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'max_attempts' => 1, 'published' => true]);
        $quiz->attempts()->create(['user_id' => $this->student->id, 'started_at' => now(), 'submitted_at' => now(), 'score' => 1, 'max_score' => 1]);

        $assignment = $this->offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $assignment->submissions()->create(['user_id' => $this->student->id, 'body' => 'x', 'submitted_at' => now()]);

        // attendance (1) + thread (2) + reply (1) + quiz attempt (1) + submission (2) = 7
        $forManager = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/engagement')->assertOk()->json();
        $this->assertSame(7, $forManager['students'][0]['points']);
    }

    public function test_only_a_manager_can_see_the_class_engagement_breakdown(): void
    {
        $this->actingAs($this->student)->getJson('/api/offerings/'.$this->offering->id.'/engagement')->assertForbidden();
    }
}
