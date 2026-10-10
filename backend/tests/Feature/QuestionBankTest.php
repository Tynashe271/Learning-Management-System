<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class QuestionBankTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $otherLecturer;

    private Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->otherLecturer = $this->userWithRole('lecturer');
        $this->teach($this->offering, $this->lecturer);
        $this->quiz = $this->offering->quizzes()->create(['title' => 'Week 1 quiz', 'due_at' => now()->addWeek(), 'max_attempts' => 1, 'published' => false]);
    }

    public function test_a_lecturer_can_build_and_list_their_own_question_bank(): void
    {
        $this->actingAs($this->lecturer)->postJson('/api/question-bank', [
            'type' => 'single_choice', 'prompt' => 'Capital of France?', 'tags' => ['geography', 'easy'],
            'options' => [['text' => 'Paris', 'is_correct' => true], ['text' => 'Rome']],
        ])->assertCreated()->assertJsonCount(2, 'options');

        $this->actingAs($this->otherLecturer)->postJson('/api/question-bank', [
            'type' => 'essay', 'prompt' => 'Discuss.',
        ])->assertCreated();

        $mine = $this->actingAs($this->lecturer)->getJson('/api/question-bank')->assertOk()->json('data');
        $this->assertCount(1, $mine); // never sees the other lecturer's bank
        $this->assertSame('Capital of France?', $mine[0]['prompt']);

        $byTag = $this->actingAs($this->lecturer)->getJson('/api/question-bank?tag=geography')->assertOk()->json('data');
        $this->assertCount(1, $byTag);
        $noMatch = $this->actingAs($this->lecturer)->getJson('/api/question-bank?tag=history')->assertOk()->json('data');
        $this->assertCount(0, $noMatch);
    }

    public function test_a_lecturer_can_update_and_delete_their_own_bank_item_but_not_anothers(): void
    {
        $id = $this->actingAs($this->lecturer)->postJson('/api/question-bank', ['type' => 'essay', 'prompt' => 'Original'])->json('id');

        $this->actingAs($this->otherLecturer)->patchJson('/api/question-bank/'.$id, ['prompt' => 'Hacked'])->assertForbidden();
        $this->actingAs($this->otherLecturer)->deleteJson('/api/question-bank/'.$id)->assertForbidden();

        $this->actingAs($this->lecturer)->patchJson('/api/question-bank/'.$id, ['prompt' => 'Updated'])->assertOk()->assertJsonPath('prompt', 'Updated');
        $this->actingAs($this->lecturer)->deleteJson('/api/question-bank/'.$id)->assertOk();
        $this->assertDatabaseMissing('question_bank_items', ['id' => $id]);
    }

    public function test_an_authored_question_can_be_saved_to_the_bank(): void
    {
        $questionId = $this->actingAs($this->lecturer)->postJson('/api/quizzes/'.$this->quiz->id.'/questions', [
            'type' => 'multiple_choice', 'prompt' => 'Pick the primes', 'points' => 3,
            'options' => [['text' => '2', 'is_correct' => true], ['text' => '3', 'is_correct' => true], ['text' => '4']],
        ])->json('id');

        $this->actingAs($this->lecturer)->postJson('/api/questions/'.$questionId.'/save-to-bank', ['tags' => ['maths']])
            ->assertCreated()->assertJsonPath('prompt', 'Pick the primes')->assertJsonPath('points', 3)->assertJsonCount(3, 'options');

        $this->assertSame(1, \App\Models\QuestionBankItem::where('owner_id', $this->lecturer->id)->count());
    }

    public function test_bank_items_can_be_inserted_into_a_quiz(): void
    {
        $singleId = $this->actingAs($this->lecturer)->postJson('/api/question-bank', [
            'type' => 'single_choice', 'prompt' => 'Capital of France?', 'options' => [['text' => 'Paris', 'is_correct' => true], ['text' => 'Rome']],
        ])->json('id');
        $essayId = $this->actingAs($this->otherLecturer)->postJson('/api/question-bank', ['type' => 'essay', 'prompt' => 'Not yours'])->json('id');

        $this->actingAs($this->lecturer)->postJson('/api/quizzes/'.$this->quiz->id.'/questions/from-bank', ['bank_item_ids' => [$essayId]])
            ->assertUnprocessable()->assertJsonValidationErrors('bank_item_ids.0');

        $response = $this->actingAs($this->lecturer)->postJson('/api/quizzes/'.$this->quiz->id.'/questions/from-bank', ['bank_item_ids' => [$singleId]])->assertCreated();
        $response->assertJsonCount(1)->assertJsonPath('0.prompt', 'Capital of France?')->assertJsonCount(2, '0.options');
        $this->assertSame(1, $this->quiz->questions()->count());
    }

    public function test_bank_items_cannot_be_added_once_the_quiz_has_attempts(): void
    {
        $student = $this->userWithRole('student');
        $this->enrol($this->offering, $student);
        $this->quiz->update(['published' => true]);
        $itemId = $this->actingAs($this->lecturer)->postJson('/api/question-bank', ['type' => 'essay', 'prompt' => 'x'])->json('id');
        $this->quiz->questions()->create(['type' => 'essay', 'prompt' => 'Existing', 'points' => 1, 'position' => 1]);
        $this->actingAs($student)->postJson('/api/quizzes/'.$this->quiz->id.'/attempts')->assertCreated();

        $this->actingAs($this->lecturer)->postJson('/api/quizzes/'.$this->quiz->id.'/questions/from-bank', ['bank_item_ids' => [$itemId]])
            ->assertUnprocessable()->assertJsonValidationErrors('quiz');
    }
}
