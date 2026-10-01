<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\QuizAttempt;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class WeightedGradebookTest extends TestCase
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

    public function test_the_weighted_percent_blends_marked_items_by_their_weight(): void
    {
        $essay = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'weight' => 70, 'published' => true]);
        $submission = $essay->submissions()->create(['user_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now()]);
        $submission->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => 80, 'status' => 'published']);

        $quiz = $this->offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'published' => true, 'max_attempts' => 1, 'weight' => 30]);
        $quiz->questions()->create(['type' => 'true_false', 'prompt' => 'a', 'points' => 8]);
        QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $this->ada->id, 'started_at' => now(), 'submitted_at' => now(), 'score' => 8, 'max_score' => 8]);

        $book = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/gradebook')->assertOk()->json();
        $ada = collect($book['rows'])->firstWhere('user.id', $this->ada->id);
        $this->assertEquals(86.0, $ada['weighted_percent']);
        $this->assertEquals(100.0, $ada['weight_used']);

        $ben = collect($book['rows'])->firstWhere('user.id', $this->ben->id);
        $this->assertNull($ben['weighted_percent']);
    }

    public function test_an_unweighted_item_is_excluded_from_the_weighted_percent_but_not_the_plain_one(): void
    {
        $weighted = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Weighted', 'due_at' => now()->addDay(), 'max_score' => 100, 'weight' => 50, 'published' => true]);
        $unweighted = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Unweighted', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
        $weighted->submissions()->create(['user_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now()])->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => 90, 'status' => 'published']);
        $unweighted->submissions()->create(['user_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now()])->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => 10, 'status' => 'published']);

        $book = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/gradebook')->assertOk()->json();
        $ada = collect($book['rows'])->firstWhere('user.id', $this->ada->id);
        $this->assertEquals(90.0, $ada['weighted_percent']);
        $this->assertEquals(50.0, $ada['percent']);
    }

    public function test_a_weight_above_100_is_rejected(): void
    {
        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/assignments', [
            'title' => 'x', 'due_at' => now()->addDay()->toIso8601String(), 'max_score' => 10, 'weight' => 150,
        ])->assertUnprocessable()->assertJsonValidationErrors('weight');
    }
}
