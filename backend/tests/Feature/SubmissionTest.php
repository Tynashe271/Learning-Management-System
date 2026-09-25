<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function setUpAssignment(): array
    {
        $this->seed(DatabaseSeeder::class);
        $student = User::factory()->create()->assignRole('student');
        $term = AcademicTerm::create(['name' => 'Term 1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
        $course = Course::create(['code' => 'CSC101', 'title' => 'Computing']);
        $offering = CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'A', 'published' => true]);
        Enrolment::create(['course_offering_id' => $offering->id, 'user_id' => $student->id, 'status' => 'active']);
        $assignment = Assignment::create(['course_offering_id' => $offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);

        return [$student, $assignment];
    }

    public function test_student_can_submit_a_file_once_and_download_it(): void
    {
        Storage::fake('s3');
        [$student, $assignment] = $this->setUpAssignment();

        $id = $this->actingAs($student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['file' => UploadedFile::fake()->create('essay.pdf', 50, 'application/pdf')])
            ->assertCreated()->json('id');

        $this->actingAs($student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'second try'])
            ->assertUnprocessable()->assertJsonValidationErrors('assignment');

        $this->assertCount(1, Storage::disk('s3')->allFiles());
        $this->actingAs($student)->get('/api/submissions/'.$id.'/download')->assertOk();
    }

    public function test_submissions_close_at_the_deadline(): void
    {
        [$student, $assignment] = $this->setUpAssignment();

        $this->travel(2)->days();

        $this->actingAs($student)->postJson('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'late'])->assertForbidden();
    }
}
