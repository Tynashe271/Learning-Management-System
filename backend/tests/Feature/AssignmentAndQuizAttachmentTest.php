<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

/** A lecturer or TA can attach one file (e.g. a question paper) when creating an assignment or quiz. */
class AssignmentAndQuizAttachmentTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->student = $this->userWithRole('student');
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->student);
    }

    public function test_an_assignment_can_be_created_with_a_question_paper_and_everyone_enrolled_can_download_it(): void
    {
        $id = $this->actingAs($this->lecturer)->post('/api/offerings/'.$this->offering->id.'/assignments', [
            'title' => 'Essay 1',
            'due_at' => now()->addWeek()->toIso8601String(),
            'max_score' => 100,
            'published' => true,
            'file' => UploadedFile::fake()->create('question-paper.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');

        $this->actingAs($this->student)->get('/api/assignments/'.$id.'/file')->assertOk();
        $this->actingAs($this->userWithRole('student'))->get('/api/assignments/'.$id.'/file')->assertForbidden(); // not enrolled
    }

    public function test_a_quiz_can_be_created_with_a_question_paper_and_says_so_to_a_student(): void
    {
        $id = $this->actingAs($this->lecturer)->post('/api/offerings/'.$this->offering->id.'/quizzes', [
            'title' => 'Midterm',
            'published' => true,
            'due_at' => now()->addWeek()->toIso8601String(),
            'file' => UploadedFile::fake()->create('question-paper.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');

        $this->actingAs($this->student)->getJson('/api/quizzes/'.$id)->assertOk()->assertJsonPath('has_file', true);
        $this->actingAs($this->student)->get('/api/quizzes/'.$id.'/file')->assertOk();
    }

    public function test_an_assignment_or_quiz_without_a_file_downloads_nothing(): void
    {
        $assignmentId = $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/assignments', ['title' => 'No file', 'due_at' => now()->addWeek()->toIso8601String(), 'max_score' => 10, 'published' => true])->assertCreated()->json('id');
        $this->actingAs($this->student)->get('/api/assignments/'.$assignmentId.'/file')->assertNotFound();

        $quizId = $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/quizzes', ['title' => 'No file', 'due_at' => now()->addWeek()->toIso8601String(), 'published' => true])->assertCreated()->json('id');
        $this->actingAs($this->student)->getJson('/api/quizzes/'.$quizId)->assertJsonPath('has_file', false);
        $this->actingAs($this->student)->get('/api/quizzes/'.$quizId.'/file')->assertNotFound();
    }
}
