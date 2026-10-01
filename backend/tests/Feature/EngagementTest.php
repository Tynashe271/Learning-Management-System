<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    private function loginOn(User $user, string $date): void
    {
        // created_at is not mass-assignable on this model, and must be backdated for this test, so set and save it directly.
        $event = new SecurityEvent(['event' => 'login.success', 'user_id' => $user->id]);
        $event->created_at = Carbon::parse($date);
        $event->save();
    }

    public function test_a_streak_counts_consecutive_days_ending_today_and_keeps_the_longest_ever_run(): void
    {
        $this->loginOn($this->student, now()->subDays(10)->toDateString());
        $this->loginOn($this->student, now()->subDays(9)->toDateString());
        $this->loginOn($this->student, now()->subDays(8)->toDateString());
        $this->loginOn($this->student, now()->subDay()->toDateString());
        $this->loginOn($this->student, now()->toDateString());

        $data = $this->actingAs($this->student)->getJson('/api/me/engagement')->assertOk()->json();
        $this->assertSame(2, $data['streak']['current_days']);
        $this->assertSame(3, $data['streak']['longest_days']);
    }

    public function test_no_logins_means_no_streak(): void
    {
        $data = $this->actingAs($this->student)->getJson('/api/me/engagement')->assertOk()->json();
        $this->assertSame(0, $data['streak']['current_days']);
        $this->assertSame(0, $data['streak']['longest_days']);
    }

    public function test_a_skill_signed_off_competent_earns_a_skill_badge(): void
    {
        $competency = $this->offering->competencies()->create(['title' => 'Solder a joint']);
        $competency->statuses()->create(['user_id' => $this->student->id, 'status' => 'competent', 'updated_by' => $this->lecturer->id]);

        $data = $this->actingAs($this->student)->getJson('/api/me/engagement')->assertOk()->json();
        $this->assertContains("skill:{$competency->id}", array_column($data['badges'], 'key'));
    }

    public function test_crossing_a_course_content_milestone_earns_a_badge_but_not_the_ones_still_ahead(): void
    {
        $module = $this->offering->modules()->create(['title' => 'Topic 1', 'published' => true]);
        $items = collect(range(1, 4))->map(fn ($i) => $module->items()->create(['title' => "Item $i", 'type' => 'text', 'body' => 'x', 'published' => true]));
        $items->take(2)->each(fn ($item) => $this->actingAs($this->student)->postJson("/api/items/{$item->id}/complete")->assertOk());

        $data = $this->actingAs($this->student)->getJson('/api/me/engagement')->assertOk()->json();
        $keys = array_column($data['badges'], 'key');
        $this->assertContains("milestone:{$this->offering->id}:25", $keys);
        $this->assertContains("milestone:{$this->offering->id}:50", $keys);
        $this->assertNotContains("milestone:{$this->offering->id}:75", $keys);
        $this->assertNotContains("milestone:{$this->offering->id}:100", $keys);
    }

    public function test_a_perfect_quiz_score_earns_a_badge(): void
    {
        $quiz = $this->offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'max_attempts' => 1, 'published' => true]);
        $quiz->attempts()->create(['user_id' => $this->student->id, 'started_at' => now(), 'submitted_at' => now(), 'score' => 5, 'max_score' => 5]);

        $data = $this->actingAs($this->student)->getJson('/api/me/engagement')->assertOk()->json();
        $this->assertContains("quiz_ace:{$quiz->id}", array_column($data['badges'], 'key'));
    }

    public function test_a_less_than_perfect_quiz_score_does_not_earn_the_ace_badge(): void
    {
        $quiz = $this->offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'max_attempts' => 1, 'published' => true]);
        $quiz->attempts()->create(['user_id' => $this->student->id, 'started_at' => now(), 'submitted_at' => now(), 'score' => 3, 'max_score' => 5]);

        $data = $this->actingAs($this->student)->getJson('/api/me/engagement')->assertOk()->json();
        $this->assertNotContains("quiz_ace:{$quiz->id}", array_column($data['badges'], 'key'));
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
        $mine = $this->actingAs($this->student)->getJson('/api/me/engagement')->assertOk()->json();
        $this->assertSame(7, $mine['participation'][0]['points']);

        $forManager = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/engagement')->assertOk()->json();
        $this->assertSame(7, $forManager['students'][0]['points']);
    }

    public function test_only_a_manager_can_see_the_class_engagement_breakdown(): void
    {
        $this->actingAs($this->student)->getJson('/api/offerings/'.$this->offering->id.'/engagement')->assertForbidden();
    }

    public function test_a_student_can_manage_their_own_learning_goals(): void
    {
        $id = $this->actingAs($this->student)->postJson('/api/me/learning-goals', ['title' => 'Finish the React course', 'target_date' => now()->addMonth()->toDateString()])->assertCreated()->json('id');

        $list = $this->actingAs($this->student)->getJson('/api/me/learning-goals')->assertOk()->json();
        $this->assertCount(1, $list);
        $this->assertNull($list[0]['completed_at']);

        $updated = $this->actingAs($this->student)->patchJson("/api/learning-goals/{$id}", ['completed' => true])->assertOk()->json();
        $this->assertNotNull($updated['completed_at']);

        $this->actingAs($this->student)->deleteJson("/api/learning-goals/{$id}")->assertOk();
        $this->assertCount(0, $this->actingAs($this->student)->getJson('/api/me/learning-goals')->json());
    }

    public function test_a_student_cannot_manage_another_students_learning_goal(): void
    {
        $other = $this->userWithRole('student');
        $id = $this->actingAs($other)->postJson('/api/me/learning-goals', ['title' => 'Not yours'])->assertCreated()->json('id');

        $this->actingAs($this->student)->patchJson("/api/learning-goals/{$id}", ['completed' => true])->assertForbidden();
        $this->actingAs($this->student)->deleteJson("/api/learning-goals/{$id}")->assertForbidden();
    }
}
