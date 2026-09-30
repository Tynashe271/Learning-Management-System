<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class VoiceFeedbackTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private User $ben;

    private Assignment $assignment;

    private int $submissionId;

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
        $this->assignment = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
        $this->submissionId = $this->actingAs($this->ada)->postJson('/api/assignments/'.$this->assignment->id.'/submissions', ['body' => 'my essay'])->assertCreated()->json('id');
    }

    public function test_a_grader_can_attach_a_voice_note_and_the_student_can_download_it(): void
    {
        Storage::fake('s3');

        $gradeId = $this->actingAs($this->lecturer)->post('/api/submissions/'.$this->submissionId.'/grades', [
            'status' => 'published',
            'score' => 85,
            'feedback_recording' => UploadedFile::fake()->create('feedback.mp3', 500, 'audio/mpeg'),
        ])->assertCreated()->json('id');

        $this->actingAs($this->ada)->get('/api/grades/'.$gradeId.'/recording')->assertOk();
        $this->assertCount(1, Storage::disk('s3')->allFiles());
    }

    public function test_a_grade_without_a_recording_has_no_download(): void
    {
        $gradeId = $this->actingAs($this->lecturer)->postJson('/api/submissions/'.$this->submissionId.'/grades', ['status' => 'published', 'score' => 85])->assertCreated()->json('id');

        $this->actingAs($this->ada)->get('/api/grades/'.$gradeId.'/recording')->assertNotFound();
    }

    public function test_only_the_student_or_a_grader_can_download_the_recording(): void
    {
        Storage::fake('s3');
        $gradeId = $this->actingAs($this->lecturer)->post('/api/submissions/'.$this->submissionId.'/grades', [
            'status' => 'published',
            'score' => 85,
            'feedback_recording' => UploadedFile::fake()->create('feedback.mp3', 500, 'audio/mpeg'),
        ])->assertCreated()->json('id');

        $this->actingAs($this->ben)->get('/api/grades/'.$gradeId.'/recording')->assertForbidden();
    }

    public function test_only_audio_or_video_files_are_accepted_as_a_recording(): void
    {
        $this->actingAs($this->lecturer)->post('/api/submissions/'.$this->submissionId.'/grades', [
            'status' => 'published',
            'score' => 85,
            'feedback_recording' => UploadedFile::fake()->create('feedback.pdf', 500, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('feedback_recording');
    }
}
