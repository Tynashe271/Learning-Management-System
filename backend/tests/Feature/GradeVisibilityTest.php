<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\GradeRecord;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_sees_only_their_latest_published_grade(): void
    {
        $this->seed(DatabaseSeeder::class);
        $student = User::factory()->create();
        $student->assignRole('student');
        $lecturer = User::factory()->create();
        $term = AcademicTerm::create(['name' => 'Term 1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
        $course = Course::create(['code' => 'CSC101', 'title' => 'Computing']);
        $offering = CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'A', 'published' => true]);
        Enrolment::create(['course_offering_id' => $offering->id, 'user_id' => $student->id, 'status' => 'active']);
        $assignment = Assignment::create(['course_offering_id' => $offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
        $submission = Submission::create(['assignment_id' => $assignment->id, 'user_id' => $student->id, 'body' => 'Answer', 'submitted_at' => now()]);
        GradeRecord::create(['submission_id' => $submission->id, 'graded_by' => $lecturer->id, 'score' => 70, 'status' => 'published']);
        GradeRecord::create(['submission_id' => $submission->id, 'graded_by' => $lecturer->id, 'score' => 80, 'status' => 'draft', 'change_reason' => 'Moderation']);

        $this->actingAs($student)->getJson('/api/assignments/'.$assignment->id.'/my-grade')
            ->assertOk()->assertJsonPath('grade.score', 70);
    }
}
