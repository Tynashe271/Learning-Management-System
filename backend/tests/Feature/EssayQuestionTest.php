<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class EssayQuestionTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $student;

    private Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->student = $this->userWithRole('student');
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->student);
        $this->quiz = $this->offering->quizzes()->create(['title' => 'Reflection', 'due_at' => now()->addDay(), 'max_attempts' => 1, 'published' => true]);
    }

    private function addEssayQuestion(int $points = 10): int
    {
        return $this->actingAs($this->lecturer)->postJson('/api/quizzes/'.$this->quiz->id.'/questions', ['type' => 'essay', 'prompt' => 'Reflect on what you learned.', 'points' => $points])
            ->assertCreated()->json('id');
    }

    public function test_an_essay_question_needs_no_options(): void
    {
        $this->actingAs($this->lecturer)->postJson('/api/quizzes/'.$this->quiz->id.'/questions', ['type' => 'essay', 'prompt' => 'Reflect.', 'points' => 10, 'options' => [['text' => 'nope']]])
            ->assertUnprocessable()->assertJsonValidationErrors('options');

        $this->actingAs($this->lecturer)->postJson('/api/quizzes/'.$this->quiz->id.'/questions', ['type' => 'essay', 'prompt' => 'Reflect.', 'points' => 10])->assertCreated();
    }

    public function test_a_student_can_answer_an_essay_question_and_it_awaits_manual_grading(): void
    {
        $questionId = $this->addEssayQuestion();
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated()->json('id');

        $result = $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', [
            'answers' => [['question_id' => $questionId, 'text' => 'I learned a lot about testing.']],
        ])->assertOk()->json();

        $this->assertTrue($result['awaiting_manual_grading']);
        $this->assertTrue($result['answers'][0]['needs_manual_grading']);
        $this->assertEquals(0, $result['score']);
        $this->assertSame('I learned a lot about testing.', $result['answers'][0]['response']['text']);
    }

    public function test_a_grader_can_mark_an_essay_answer_and_the_attempts_score_updates(): void
    {
        $questionId = $this->addEssayQuestion(points: 10);
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated()->json('id');
        $result = $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => [['question_id' => $questionId, 'text' => 'My reflection.']]])->assertOk()->json();
        $answerId = $result['answers'][0]['id'];

        $graded = $this->actingAs($this->lecturer)->patchJson('/api/quiz-answers/'.$answerId.'/grade', ['points' => 7])->assertOk()->json();
        $this->assertEquals(7, $graded['score']);
        $this->assertFalse($graded['awaiting_manual_grading']);

        $mine = $this->actingAs($this->student)->getJson('/api/attempts/'.$attemptId)->assertOk()->json();
        $this->assertEquals(7, $mine['score']);
    }

    public function test_manual_marks_cannot_exceed_the_questions_points(): void
    {
        $questionId = $this->addEssayQuestion(points: 5);
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated()->json('id');
        $result = $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => [['question_id' => $questionId, 'text' => 'x']]])->assertOk()->json();

        $this->actingAs($this->lecturer)->patchJson('/api/quiz-answers/'.$result['answers'][0]['id'].'/grade', ['points' => 6])->assertUnprocessable();
    }

    public function test_a_student_cannot_mark_their_own_essay_answer(): void
    {
        $questionId = $this->addEssayQuestion();
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated()->json('id');
        $result = $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => [['question_id' => $questionId, 'text' => 'x']]])->assertOk()->json();

        $this->actingAs($this->student)->patchJson('/api/quiz-answers/'.$result['answers'][0]['id'].'/grade', ['points' => 10])->assertForbidden();
    }
}
