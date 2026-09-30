<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class RandomQuestionSelectionTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private User $ben;

    private Quiz $quiz;

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
        $this->quiz = $this->offering->quizzes()->create(['title' => 'Big bank', 'due_at' => now()->addDay(), 'max_attempts' => 1, 'published' => true, 'questions_per_attempt' => 2]);
        for ($i = 1; $i <= 6; $i++) {
            $q = $this->quiz->questions()->create(['type' => 'true_false', 'prompt' => "Q$i", 'points' => 1, 'position' => $i]);
            $q->options()->createMany([['text' => 'True', 'is_correct' => true, 'position' => 0], ['text' => 'False', 'is_correct' => false, 'position' => 1]]);
        }
    }

    public function test_an_attempt_only_gets_the_configured_number_of_questions(): void
    {
        $attempt = $this->actingAs($this->ada)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated()->json();
        $this->assertCount(2, $attempt['questions']);
        $ids = collect($attempt['questions'])->pluck('id');
        $this->assertSame($ids->unique()->count(), $ids->count());
    }

    public function test_two_students_can_be_dealt_different_questions(): void
    {
        // Draw many attempts across students and check the sets are not all identical (probabilistically near-certain with 6 choose 2 = 15 combinations).
        $seen = [];
        foreach (range(1, 8) as $i) {
            $user = $this->userWithRole('student');
            $this->enrol($this->offering, $user);
            $attempt = $this->actingAs($user)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated()->json();
            $seen[] = collect($attempt['questions'])->pluck('id')->sort()->values()->implode(',');
        }
        $this->assertGreaterThan(1, count(array_unique($seen)), 'Expected at least some variety across 8 draws from 15 possible pairs.');
    }

    public function test_resuming_an_attempt_keeps_the_same_questions(): void
    {
        $first = $this->actingAs($this->ada)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated()->json();
        $resumed = $this->actingAs($this->ada)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertOk()->json();
        $this->assertSame(collect($first['questions'])->pluck('id')->sort()->values()->all(), collect($resumed['questions'])->pluck('id')->sort()->values()->all());
    }

    public function test_the_attempts_score_and_max_score_reflect_only_its_own_drawn_questions(): void
    {
        $attempt = $this->actingAs($this->ada)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated()->json();
        $answers = collect($attempt['questions'])->map(fn ($q) => ['question_id' => $q['id'], 'option_ids' => [collect($q['options'])->firstWhere('text', 'True')['id']]])->all();

        $result = $this->actingAs($this->ada)->postJson('/api/attempts/'.$attempt['id'].'/submit', ['answers' => $answers])->assertOk()->json();
        $this->assertEquals(2, $result['max_score']);
    }

    public function test_a_quiz_without_a_limit_still_shows_every_question(): void
    {
        $unlimited = $this->offering->quizzes()->create(['title' => 'Full quiz', 'due_at' => now()->addDay(), 'max_attempts' => 1, 'published' => true]);
        $q = $unlimited->questions()->create(['type' => 'true_false', 'prompt' => 'Q', 'points' => 1, 'position' => 1]);
        $q->options()->createMany([['text' => 'True', 'is_correct' => true, 'position' => 0], ['text' => 'False', 'is_correct' => false, 'position' => 1]]);

        $attempt = $this->actingAs($this->ada)->postJson('/api/quizzes/'.$unlimited->id.'/attempts')->assertCreated()->json();
        $this->assertCount(1, $attempt['questions']);
    }
}
