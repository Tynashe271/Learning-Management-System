<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class QuizTest extends TestCase
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

    /** A published 4-question quiz worth 8 points: single (2), multiple (3), true/false (1), short answer (2). */
    private function makeQuiz(array $overrides = []): Quiz
    {
        $quiz = $this->offering->quizzes()->create($overrides + ['title' => 'Week 1 quiz', 'due_at' => now()->addDay(), 'max_attempts' => 1, 'published' => true]);
        $single = $quiz->questions()->create(['type' => 'single_choice', 'prompt' => 'Capital of France?', 'points' => 2, 'position' => 1]);
        $single->options()->createMany([['text' => 'Paris', 'is_correct' => true, 'position' => 0], ['text' => 'Rome', 'is_correct' => false, 'position' => 1]]);
        $multi = $quiz->questions()->create(['type' => 'multiple_choice', 'prompt' => 'Pick the primes', 'points' => 3, 'position' => 2]);
        $multi->options()->createMany([['text' => '2', 'is_correct' => true, 'position' => 0], ['text' => '3', 'is_correct' => true, 'position' => 1], ['text' => '4', 'is_correct' => false, 'position' => 2]]);
        $tf = $quiz->questions()->create(['type' => 'true_false', 'prompt' => 'PHP is compiled ahead of time', 'points' => 1, 'position' => 3]);
        $tf->options()->createMany([['text' => 'True', 'is_correct' => false, 'position' => 0], ['text' => 'False', 'is_correct' => true, 'position' => 1]]);
        $short = $quiz->questions()->create(['type' => 'short_answer', 'prompt' => 'Largest planet?', 'points' => 2, 'position' => 4]);
        $short->options()->createMany([['text' => 'Jupiter', 'is_correct' => true, 'position' => 0]]);

        return $quiz;
    }

    /** Correct answers for the quiz built by makeQuiz(), keyed by question position. */
    private function correctAnswers(Quiz $quiz): array
    {
        return $quiz->questions()->ordered()->with('options')->get()->map(fn ($q) => $q->type === 'short_answer'
            ? ['question_id' => $q->id, 'text' => '  JUPITER ']
            : ['question_id' => $q->id, 'option_ids' => $q->options->where('is_correct', true)->pluck('id')->all()])->all();
    }

    public function test_lecturer_builds_a_quiz_with_each_question_type(): void
    {
        $id = $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/quizzes', [
            'title' => 'Midterm', 'due_at' => now()->addWeek()->toIso8601String(), 'time_limit_minutes' => 30, 'max_attempts' => 2,
        ])->assertCreated()->assertJsonPath('max_attempts', 2)->json('id');
        $url = '/api/quizzes/'.$id.'/questions';

        $this->actingAs($this->lecturer)->postJson($url, ['type' => 'single_choice', 'prompt' => 'Q1', 'options' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]])
            ->assertCreated()->assertJsonCount(2, 'options');
        $this->actingAs($this->lecturer)->postJson($url, ['type' => 'multiple_choice', 'prompt' => 'Q2', 'points' => 3, 'options' => [['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => true], ['text' => 'c']]])->assertCreated();
        $this->actingAs($this->lecturer)->postJson($url, ['type' => 'true_false', 'prompt' => 'Q3', 'correct' => false])
            ->assertCreated()->assertJsonPath('options.0.text', 'True')->assertJsonPath('options.0.is_correct', false)->assertJsonPath('options.1.is_correct', true);
        $this->actingAs($this->lecturer)->postJson($url, ['type' => 'short_answer', 'prompt' => 'Q4', 'options' => [['text' => 'yes'], ['text' => 'yeah']]])
            ->assertCreated()->assertJsonPath('options.1.is_correct', true);

        $this->actingAs($this->lecturer)->getJson('/api/quizzes/'.$id)->assertOk()->assertJsonCount(4, 'questions')->assertJsonPath('questions.0.options.0.is_correct', true);
    }

    public function test_invalid_questions_are_rejected(): void
    {
        $quiz = $this->makeQuiz();
        $url = '/api/quizzes/'.$quiz->id.'/questions';
        $lecturer = $this->actingAs($this->lecturer);

        $lecturer->postJson($url, ['type' => 'single_choice', 'prompt' => 'Q', 'options' => [['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => true]]])->assertJsonValidationErrors('options');
        $lecturer->postJson($url, ['type' => 'single_choice', 'prompt' => 'Q', 'options' => [['text' => 'a', 'is_correct' => true]]])->assertJsonValidationErrors('options');
        $lecturer->postJson($url, ['type' => 'multiple_choice', 'prompt' => 'Q', 'options' => [['text' => 'a'], ['text' => 'b']]])->assertJsonValidationErrors('options');
        $lecturer->postJson($url, ['type' => 'true_false', 'prompt' => 'Q'])->assertJsonValidationErrors('correct');
        $lecturer->postJson($url, ['type' => 'short_answer', 'prompt' => 'Q'])->assertJsonValidationErrors('options');
        $lecturer->postJson($url, ['type' => 'essay', 'prompt' => 'Q', 'options' => [['text' => 'a']]])->assertJsonValidationErrors('options');
        $lecturer->postJson($url, ['type' => 'matching', 'prompt' => 'Q'])->assertJsonValidationErrors('type');
        $this->assertSame(4, $quiz->questions()->count());
    }

    public function test_only_managers_of_the_offering_can_author_quizzes(): void
    {
        $other = $this->userWithRole('lecturer');
        $payload = ['title' => 'Nope', 'due_at' => now()->addDay()->toIso8601String()];

        $this->actingAs($this->student)->postJson('/api/offerings/'.$this->offering->id.'/quizzes', $payload)->assertForbidden();
        $this->actingAs($other)->postJson('/api/offerings/'.$this->offering->id.'/quizzes', $payload)->assertForbidden();
        $quiz = $this->makeQuiz();
        $this->actingAs($other)->postJson('/api/quizzes/'.$quiz->id.'/questions', ['type' => 'true_false', 'prompt' => 'Q', 'correct' => true])->assertForbidden();
        $this->actingAs($other)->deleteJson('/api/quizzes/'.$quiz->id)->assertForbidden();
    }

    public function test_students_see_quiz_details_but_never_questions_or_answers_before_starting(): void
    {
        $quiz = $this->makeQuiz();

        $response = $this->actingAs($this->student)->getJson('/api/quizzes/'.$quiz->id)->assertOk();
        $response->assertJsonPath('total_points', 8)->assertJsonPath('questions_count', 4)->assertJsonPath('attempts_used', 0)->assertJsonMissingPath('questions');
        $this->assertStringNotContainsString('is_correct', $response->getContent());
    }

    public function test_unpublished_quizzes_are_hidden_from_students(): void
    {
        $quiz = $this->makeQuiz(['published' => false]);

        $this->actingAs($this->student)->getJson('/api/quizzes/'.$quiz->id)->assertForbidden();
        $this->actingAs($this->student)->postJson('/api/quizzes/'.$quiz->id.'/attempts')->assertForbidden();
        $this->actingAs($this->student)->getJson('/api/offerings/'.$this->offering->id)->assertJsonCount(0, 'quizzes');
        $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id)->assertJsonCount(1, 'quizzes');
    }

    public function test_starting_an_attempt_hides_correct_answers(): void
    {
        $quiz = $this->makeQuiz();

        $response = $this->actingAs($this->student)->postJson('/api/quizzes/'.$quiz->id.'/attempts')
            ->assertCreated()->assertJsonCount(4, 'questions')->assertJsonPath('questions.0.type', 'single_choice')->assertJsonCount(2, 'questions.0.options');
        $response->assertJsonPath('questions.3.options', []);
        $this->assertStringNotContainsString('is_correct', $response->getContent());
        $this->assertStringNotContainsString('Jupiter', $response->getContent());
    }

    public function test_only_enrolled_students_can_attempt_within_the_window(): void
    {
        $outsider = $this->userWithRole('student');
        $withdrawn = $this->userWithRole('student');
        $this->enrol($this->offering, $withdrawn, 'withdrawn');
        $quiz = $this->makeQuiz();
        $early = $this->makeQuiz(['title' => 'Later', 'opens_at' => now()->addHours(2)]);
        $url = fn (Quiz $q) => '/api/quizzes/'.$q->id.'/attempts';

        $this->actingAs($outsider)->postJson($url($quiz))->assertForbidden();
        $this->actingAs($withdrawn)->postJson($url($quiz))->assertForbidden();
        $this->actingAs($this->lecturer)->postJson($url($quiz))->assertForbidden();
        $this->actingAs($this->student)->postJson($url($early))->assertForbidden();

        $this->travel(3)->days();
        $this->actingAs($this->student)->postJson($url($quiz))->assertForbidden();
    }

    public function test_answers_are_auto_graded_per_question_type(): void
    {
        $quiz = $this->makeQuiz();
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$quiz->id.'/attempts')->json('id');

        $result = $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => $this->correctAnswers($quiz)])->assertOk();

        $result->assertJsonPath('score', '8.00')->assertJsonPath('max_score', '8.00');
        $this->assertSame([true, true, true, true], array_column($result->json('answers'), 'is_correct'));
    }

    public function test_partial_wrong_and_missing_answers_earn_nothing_for_that_question(): void
    {
        $quiz = $this->makeQuiz();
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$quiz->id.'/attempts')->json('id');
        [$single, $multi, $tf, $short] = $quiz->questions()->ordered()->with('options')->get()->all();
        $wrongSingle = $single->options->firstWhere('is_correct', false)->id;
        $onlyOneOfTwoPrimes = $multi->options->firstWhere('text', '2')->id;

        $result = $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => [
            ['question_id' => $single->id, 'option_ids' => [$wrongSingle]],
            ['question_id' => $multi->id, 'option_ids' => [$onlyOneOfTwoPrimes]],
            ['question_id' => $short->id, 'text' => 'Saturn'],
            // true/false left unanswered
        ]])->assertOk();

        $result->assertJsonPath('score', '0.00')->assertJsonPath('max_score', '8.00');
        $this->assertSame([false, false, false, false], array_column($result->json('answers'), 'is_correct'));
        $this->assertSame(4, $result->json('answers') ? count($result->json('answers')) : 0);
    }

    public function test_extra_selected_options_on_a_multiple_choice_question_are_wrong(): void
    {
        $quiz = $this->makeQuiz();
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$quiz->id.'/attempts')->json('id');
        $multi = $quiz->questions()->ordered()->with('options')->get()[1];

        $result = $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => [
            ['question_id' => $multi->id, 'option_ids' => $multi->options->pluck('id')->all()],
        ]])->assertOk();

        $this->assertFalse($result->json('answers.1.is_correct'));
    }

    public function test_an_unfinished_attempt_is_resumed_and_attempts_are_limited(): void
    {
        $quiz = $this->makeQuiz(['max_attempts' => 2]);
        $url = '/api/quizzes/'.$quiz->id.'/attempts';

        $first = $this->actingAs($this->student)->postJson($url)->assertCreated()->json('id');
        $this->actingAs($this->student)->postJson($url)->assertOk()->assertJsonPath('id', $first);
        $this->actingAs($this->student)->postJson('/api/attempts/'.$first.'/submit', ['answers' => []])->assertOk();

        $second = $this->actingAs($this->student)->postJson($url)->assertCreated()->json('id');
        $this->assertNotSame($first, $second);
        $this->actingAs($this->student)->postJson('/api/attempts/'.$second.'/submit', ['answers' => []])->assertOk();

        $this->actingAs($this->student)->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('quiz');
        $this->actingAs($this->student)->getJson('/api/quizzes/'.$quiz->id.'/my-attempts')->assertOk()->assertJsonCount(2);
    }

    public function test_an_attempt_cannot_be_submitted_twice(): void
    {
        $quiz = $this->makeQuiz();
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$quiz->id.'/attempts')->json('id');
        $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => $this->correctAnswers($quiz)])->assertOk();

        $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => []])->assertUnprocessable()->assertJsonValidationErrors('attempt');
    }

    public function test_answers_for_unknown_questions_are_rejected_and_the_attempt_stays_open(): void
    {
        $quiz = $this->makeQuiz();
        $foreign = $this->makeQuiz(['title' => 'Other quiz'])->questions()->first();
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$quiz->id.'/attempts')->json('id');

        $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => [['question_id' => $foreign->id, 'option_ids' => []]]])->assertUnprocessable();

        $this->assertNull(Quiz::find($quiz->id)->attempts()->first()->submitted_at);
    }

    public function test_the_time_limit_closes_an_attempt_with_no_score(): void
    {
        $quiz = $this->makeQuiz(['time_limit_minutes' => 10, 'max_attempts' => 2]);
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$quiz->id.'/attempts')->json('id');

        $this->travel(12)->minutes();

        $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => $this->correctAnswers($quiz)])
            ->assertUnprocessable()->assertJsonValidationErrors('attempt');
        $closed = $this->actingAs($this->student)->getJson('/api/attempts/'.$attemptId)->assertOk();
        $closed->assertJsonPath('score', '0.00')->assertJsonPath('max_score', '8.00');
        $this->assertNotNull($closed->json('submitted_at'));
        // The expired attempt used one of the two attempts; a fresh one is still available.
        $this->actingAs($this->student)->postJson('/api/quizzes/'.$quiz->id.'/attempts')->assertCreated();
    }

    public function test_a_short_grace_period_after_the_time_limit_is_allowed(): void
    {
        $quiz = $this->makeQuiz(['time_limit_minutes' => 10]);
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$quiz->id.'/attempts')->json('id');

        $this->travel(10)->minutes();
        $this->travel(30)->seconds();

        $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => $this->correctAnswers($quiz)])->assertOk()->assertJsonPath('score', '8.00');
    }

    public function test_attempts_belong_to_their_owner_and_the_teaching_staff(): void
    {
        $other = $this->userWithRole('student');
        $this->enrol($this->offering, $other);
        $otherTeacher = $this->userWithRole('lecturer');
        $quiz = $this->makeQuiz();
        $attemptId = $this->actingAs($this->student)->postJson('/api/quizzes/'.$quiz->id.'/attempts')->json('id');
        $this->actingAs($this->student)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => $this->correctAnswers($quiz)])->assertOk();

        $this->actingAs($other)->getJson('/api/attempts/'.$attemptId)->assertForbidden();
        $this->actingAs($other)->postJson('/api/attempts/'.$attemptId.'/submit', ['answers' => []])->assertForbidden();
        $this->actingAs($otherTeacher)->getJson('/api/attempts/'.$attemptId)->assertForbidden();
        $this->actingAs($this->lecturer)->getJson('/api/attempts/'.$attemptId)->assertOk()->assertJsonPath('score', '8.00');
        $this->actingAs($this->lecturer)->getJson('/api/quizzes/'.$quiz->id.'/attempts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $this->student->id);
        $this->actingAs($this->student)->getJson('/api/quizzes/'.$quiz->id.'/attempts')->assertForbidden();
    }

    public function test_questions_and_quizzes_are_locked_once_attempted(): void
    {
        $quiz = $this->makeQuiz();
        $question = $quiz->questions()->first();
        $this->actingAs($this->student)->postJson('/api/quizzes/'.$quiz->id.'/attempts')->assertCreated();

        $this->actingAs($this->lecturer)->postJson('/api/quizzes/'.$quiz->id.'/questions', ['type' => 'true_false', 'prompt' => 'Q', 'correct' => true])->assertUnprocessable();
        $this->actingAs($this->lecturer)->patchJson('/api/questions/'.$question->id, ['prompt' => 'Changed', 'options' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]])->assertUnprocessable();
        $this->actingAs($this->lecturer)->deleteJson('/api/questions/'.$question->id)->assertUnprocessable();
        $this->actingAs($this->lecturer)->deleteJson('/api/quizzes/'.$quiz->id)->assertUnprocessable();
        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id]);
    }

    public function test_an_unattempted_quiz_can_be_edited_and_deleted(): void
    {
        $quiz = $this->makeQuiz();
        $question = $quiz->questions()->ordered()->first();

        $this->actingAs($this->lecturer)->patchJson('/api/questions/'.$question->id, ['prompt' => 'Capital of Italy?', 'points' => 5, 'options' => [['text' => 'Rome', 'is_correct' => true], ['text' => 'Paris']]])
            ->assertOk()->assertJsonPath('prompt', 'Capital of Italy?')->assertJsonPath('options.0.text', 'Rome');
        $this->assertSame(5, $question->fresh()->points);
        $this->assertSame(2, $question->options()->count());
        $this->actingAs($this->lecturer)->deleteJson('/api/questions/'.$question->id)->assertOk();
        $this->actingAs($this->lecturer)->deleteJson('/api/quizzes/'.$quiz->id)->assertOk();
        $this->assertDatabaseMissing('quizzes', ['id' => $quiz->id]);
    }

    public function test_changing_a_quiz_deadline_needs_a_reason_and_a_sane_window(): void
    {
        $quiz = $this->makeQuiz();
        $later = now()->addDays(3)->toIso8601String();

        $this->actingAs($this->lecturer)->patchJson('/api/quizzes/'.$quiz->id, ['due_at' => $later])->assertJsonValidationErrors('change_reason');
        $this->actingAs($this->lecturer)->patchJson('/api/quizzes/'.$quiz->id, ['due_at' => $later, 'opens_at' => now()->addDays(4)->toIso8601String(), 'change_reason' => 'x'])->assertJsonValidationErrors('opens_at');
        $this->actingAs($this->lecturer)->patchJson('/api/quizzes/'.$quiz->id, ['due_at' => $later, 'change_reason' => 'Public holiday'])->assertOk();
        $this->assertDatabaseHas('activity_log', ['description' => 'quiz changed']);
    }

    public function test_a_practice_quiz_allows_far_more_attempts_than_a_graded_one(): void
    {
        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/quizzes', [
            'title' => 'Graded', 'due_at' => now()->addWeek()->toIso8601String(), 'max_attempts' => 21,
        ])->assertJsonValidationErrors('max_attempts');

        $id = $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/quizzes', [
            'title' => 'Practice round', 'due_at' => now()->addWeek()->toIso8601String(), 'max_attempts' => 500, 'is_practice' => true, 'published' => true,
        ])->assertCreated()->assertJsonPath('is_practice', true)->assertJsonPath('max_attempts', 500)->json('id');

        $this->actingAs($this->lecturer)->patchJson('/api/quizzes/'.$id, ['max_attempts' => 1000])->assertJsonValidationErrors('max_attempts');
        $this->actingAs($this->lecturer)->patchJson('/api/quizzes/'.$id, ['max_attempts' => 999])->assertOk();

        // A student sees that it is a practice quiz, but never the questions, before starting an attempt.
        $shown = $this->actingAs($this->student)->getJson('/api/quizzes/'.$id)->assertOk();
        $shown->assertJsonPath('is_practice', true);
        $this->assertArrayNotHasKey('questions', $shown->json());
    }

    public function test_practice_quizzes_are_excluded_from_the_gradebook(): void
    {
        $this->makeQuiz(); // a normal, graded quiz
        $this->offering->quizzes()->create(['title' => 'Practice', 'due_at' => now()->addDay(), 'max_attempts' => 10, 'published' => true, 'is_practice' => true]);

        $book = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/gradebook')->assertOk();

        $this->assertCount(1, collect($book->json('columns'))->where('type', 'quiz'));
    }
}
