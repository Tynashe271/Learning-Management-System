<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class OpenBookQuizTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    public function test_a_quiz_can_be_marked_open_book_and_students_see_the_label(): void
    {
        $this->seed(DatabaseSeeder::class);
        $offering = $this->offering();
        $lecturer = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student');
        $this->teach($offering, $lecturer);
        $this->enrol($offering, $student);

        $id = $this->actingAs($lecturer)->postJson('/api/offerings/'.$offering->id.'/quizzes', [
            'title' => 'Open book test', 'due_at' => now()->addWeek()->toIso8601String(), 'is_open_book' => true, 'published' => true,
        ])->assertCreated()->assertJsonPath('is_open_book', true)->json('id');

        $this->actingAs($student)->getJson('/api/quizzes/'.$id)->assertOk()->assertJsonPath('is_open_book', true);

        $this->actingAs($lecturer)->patchJson('/api/quizzes/'.$id, ['is_open_book' => false])->assertOk()->assertJsonPath('is_open_book', false);
    }

    public function test_a_quiz_defaults_to_closed_book(): void
    {
        $this->seed(DatabaseSeeder::class);
        $offering = $this->offering();
        $lecturer = $this->userWithRole('lecturer');
        $this->teach($offering, $lecturer);

        $id = $this->actingAs($lecturer)->postJson('/api/offerings/'.$offering->id.'/quizzes', [
            'title' => 'Regular test', 'due_at' => now()->addWeek()->toIso8601String(),
        ])->assertCreated()->json('id');

        $this->actingAs($lecturer)->getJson('/api/quizzes/'.$id)->assertOk()->assertJsonPath('is_open_book', false);
    }
}
