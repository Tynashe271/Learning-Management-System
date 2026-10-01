<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class AccommodationTest extends TestCase
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

    private function makeQuiz(int $timeLimitMinutes): int
    {
        $quiz = $this->offering->quizzes()->create(['title' => 'Timed quiz', 'due_at' => now()->addDay(), 'max_attempts' => 1, 'published' => true, 'time_limit_minutes' => $timeLimitMinutes]);
        $q = $quiz->questions()->create(['type' => 'true_false', 'prompt' => 'Q', 'points' => 1, 'position' => 1]);
        $q->options()->createMany([['text' => 'True', 'is_correct' => true, 'position' => 0], ['text' => 'False', 'is_correct' => false, 'position' => 1]]);

        return $quiz->id;
    }

    public function test_a_student_with_an_accommodation_gets_extra_time_on_a_timed_quiz(): void
    {
        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/accommodations', ['user_id' => $this->ada->id, 'extra_time_percent' => 50])->assertCreated();
        $quizId = $this->makeQuiz(20);

        $attempt = $this->actingAs($this->ada)->postJson('/api/quizzes/'.$quizId.'/attempts')->assertCreated()->json();
        $minutesLeft = now()->diffInMinutes($attempt['deadline']);
        $this->assertGreaterThan(25, $minutesLeft);
        $this->assertLessThanOrEqual(30, $minutesLeft);
    }

    public function test_a_student_without_an_accommodation_gets_the_plain_time_limit(): void
    {
        $quizId = $this->makeQuiz(20);

        $attempt = $this->actingAs($this->ben)->postJson('/api/quizzes/'.$quizId.'/attempts')->assertCreated()->json();
        $minutesLeft = now()->diffInMinutes($attempt['deadline']);
        $this->assertLessThanOrEqual(20, $minutesLeft);
    }

    public function test_an_accommodation_only_applies_in_its_own_course(): void
    {
        $other = $this->offering('B');
        $this->enrol($other, $this->ada);
        $this->teach($other, $this->lecturer);
        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/accommodations', ['user_id' => $this->ada->id, 'extra_time_percent' => 100])->assertCreated();

        $otherQuiz = $other->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'max_attempts' => 1, 'published' => true, 'time_limit_minutes' => 20]);
        $q = $otherQuiz->questions()->create(['type' => 'true_false', 'prompt' => 'Q', 'points' => 1, 'position' => 1]);
        $q->options()->createMany([['text' => 'True', 'is_correct' => true, 'position' => 0], ['text' => 'False', 'is_correct' => false, 'position' => 1]]);

        $attempt = $this->actingAs($this->ada)->postJson('/api/quizzes/'.$otherQuiz->id.'/attempts')->assertCreated()->json();
        $this->assertLessThanOrEqual(20, now()->diffInMinutes($attempt['deadline']));
    }

    public function test_setting_an_accommodation_again_updates_it_instead_of_duplicating(): void
    {
        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/accommodations', ['user_id' => $this->ada->id, 'extra_time_percent' => 25])->assertCreated();
        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/accommodations', ['user_id' => $this->ada->id, 'extra_time_percent' => 50])->assertCreated();

        $list = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/accommodations')->assertOk()->json();
        $this->assertCount(1, $list);
        $this->assertSame(50, $list[0]['extra_time_percent']);
    }

    public function test_only_a_manager_can_set_or_view_accommodations(): void
    {
        $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/accommodations', ['user_id' => $this->ada->id, 'extra_time_percent' => 50])->assertForbidden();
        $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/accommodations')->assertForbidden();
    }
}
