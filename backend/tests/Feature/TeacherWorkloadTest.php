<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\GradeRecord;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

/** Roadmap/admin review item: a teacher workload overview for course administrators. */
class TeacherWorkloadTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private function as(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    public function test_a_course_admin_sees_each_teachers_offerings_unmarked_work_and_feedback_time(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->userWithRole('university-admin');
        $lecturer = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student');

        $offering = $this->offering();
        $this->teach($offering, $lecturer);
        $this->enrol($offering, $student);

        $assignment = Assignment::create(['course_offering_id' => $offering->id, 'title' => 'Essay', 'max_score' => 100, 'published' => true, 'due_at' => now()->addDay()]);
        $gradedSubmission = Submission::create(['assignment_id' => $assignment->id, 'user_id' => $student->id, 'body' => 'Done', 'submitted_at' => now()->subHours(5)]);
        GradeRecord::create(['submission_id' => $gradedSubmission->id, 'graded_by' => $lecturer->id, 'score' => 90, 'status' => 'published', 'created_at' => now()]);
        $unmarkedSubmission = Submission::create(['assignment_id' => $assignment->id, 'user_id' => $this->userWithRole('student')->id, 'body' => 'Pending', 'submitted_at' => now()]);

        $quiz = Quiz::create(['course_offering_id' => $offering->id, 'title' => 'Quiz', 'published' => true, 'due_at' => now()->addDay()]);
        $question = QuizQuestion::create(['quiz_id' => $quiz->id, 'type' => 'essay', 'prompt' => 'Explain.', 'points' => 10]);
        $attempt = QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $student->id, 'started_at' => now(), 'submitted_at' => now()]);
        QuizAnswer::create(['quiz_attempt_id' => $attempt->id, 'quiz_question_id' => $question->id, 'response' => ['text' => 'An answer'], 'is_correct' => false, 'points' => 0, 'needs_manual_grading' => true]);

        $report = $this->as($admin)->getJson('/api/reports/workload')->assertOk();

        $row = collect($report->json('teachers'))->firstWhere('id', $lecturer->id);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['offerings_count']);
        $this->assertSame(1, $row['unmarked_submissions']);
        $this->assertSame(1, $row['unmarked_essay_answers']);
        $this->assertEquals(5.0, $row['avg_feedback_hours']);
        $this->assertSame(1, $row['feedback_count']);

        $this->as($this->userWithRole('student'))->getJson('/api/reports/workload')->assertForbidden();

        $csv = $this->as($admin)->get('/api/reports/workload?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString($lecturer->name, $csv);
        $this->assertStringContainsString('Unmarked submissions', $csv);
    }
}
