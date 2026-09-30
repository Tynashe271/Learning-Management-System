<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class QuizAutosaveTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $student;

    private User $outsider;

    private Quiz $quiz;

    private int $questionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $lecturer = $this->userWithRole('lecturer');
        $this->student = $this->userWithRole('student');
        $this->outsider = $this->userWithRole('student');
        $this->teach($this->offering, $lecturer);
        $this->enrol($this->offering, $this->student);
        $this->quiz = $this->offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'max_attempts' => 1, 'published' => true]);
        $question = $this->quiz->questions()->create(['type' => 'short_answer', 'prompt' => 'Q', 'points' => 1, 'position' => 1]);
        $question->options()->create(['text' => 'answer', 'is_correct' => true, 'position' => 0]);
        $this->questionId = $question->id;
    }

    public function test_a_draft_is_saved_and_returned_with_the_in_progress_attempt(): void
    {
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated()->json('id');

        $this->actingAs($this->student)->putJson('/api/attempts/'.$attemptId.'/draft', ['answers' => [['question_id' => $this->questionId, 'text' => 'partial']]])->assertOk();

        $attempt = $this->actingAs($this->student)->getJson('/api/attempts/'.$attemptId)->assertOk()->json();
        $this->assertSame('partial', $attempt['draft_answers'][0]['text']);
    }

    public function test_only_the_owner_can_save_a_draft(): void
    {
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated()->json('id');

        $this->actingAs($this->outsider)->putJson('/api/attempts/'.$attemptId.'/draft', ['answers' => []])->assertForbidden();
    }

    public function test_a_draft_cannot_be_saved_after_submission(): void
    {
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated()->json('id');
        $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => [['question_id' => $this->questionId, 'text' => 'answer']]])->assertOk();

        $this->actingAs($this->student)->putJson('/api/attempts/'.$attemptId.'/draft', ['answers' => []])->assertStatus(422);
    }

    public function test_the_draft_is_cleared_once_the_attempt_is_submitted(): void
    {
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated()->json('id');
        $this->actingAs($this->student)->putJson('/api/attempts/'.$attemptId.'/draft', ['answers' => [['question_id' => $this->questionId, 'text' => 'partial']]])->assertOk();
        $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => [['question_id' => $this->questionId, 'text' => 'answer']]])->assertOk();

        $this->assertNull($this->quiz->attempts()->find($attemptId)->draft_answers);
    }
}
