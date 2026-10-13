<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseModule;
use App\Models\GradeRecord;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

/** The institution-wide "average coursework mark per module" report: visible to course administrators, never to students. */
class ModuleAveragesReportTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private function as(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    public function test_a_course_admin_sees_each_modules_average_blended_from_its_assignments_and_quizzes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->userWithRole('university-admin');
        $lecturer = $this->userWithRole('lecturer');
        $ada = $this->userWithRole('student');
        $ben = $this->userWithRole('student');

        $offering = $this->offering();
        $this->teach($offering, $lecturer);
        $this->enrol($offering, $ada);
        $this->enrol($offering, $ben);
        $module = CourseModule::create(['course_offering_id' => $offering->id, 'title' => 'Week 1', 'position' => 1, 'published' => true]);

        // Assignment: 80/100 and 60/100 published -> average 70%.
        $assignment = Assignment::create(['course_offering_id' => $offering->id, 'course_module_id' => $module->id, 'title' => 'Essay', 'max_score' => 100, 'published' => true, 'due_at' => now()->addDay()]);
        $s1 = Submission::create(['assignment_id' => $assignment->id, 'user_id' => $ada->id, 'body' => 'A', 'submitted_at' => now()]);
        $s2 = Submission::create(['assignment_id' => $assignment->id, 'user_id' => $ben->id, 'body' => 'B', 'submitted_at' => now()]);
        GradeRecord::create(['submission_id' => $s1->id, 'graded_by' => $lecturer->id, 'score' => 80, 'status' => 'published']);
        GradeRecord::create(['submission_id' => $s2->id, 'graded_by' => $lecturer->id, 'score' => 60, 'status' => 'published']);

        // Quiz: 10 points total; best attempts 5 (50%) and 9 (90%) -> average 70%.
        $quiz = Quiz::create(['course_offering_id' => $offering->id, 'course_module_id' => $module->id, 'title' => 'Quiz', 'published' => true, 'due_at' => now()->addDay()]);
        $question = QuizQuestion::create(['quiz_id' => $quiz->id, 'type' => 'essay', 'prompt' => 'Explain.', 'points' => 10]);
        foreach ([[$ada, 5], [$ben, 9]] as [$student, $score]) {
            $attempt = QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $student->id, 'started_at' => now(), 'submitted_at' => now(), 'score' => $score]);
        }

        $report = $this->as($admin)->getJson('/api/reports/module-averages')->assertOk();
        $row = collect($report->json('modules'))->firstWhere('module_id', $module->id);
        $this->assertNotNull($row);
        $this->assertSame('Week 1', $row['module']);
        $this->assertEquals(70.0, $row['average_percent']);
        $this->assertSame(2, $row['assessments_counted']);

        // A module nobody has graded anything in yet is left out, not shown as zero.
        $emptyModule = CourseModule::create(['course_offering_id' => $offering->id, 'title' => 'Week 2', 'position' => 2, 'published' => true]);
        $report = $this->as($admin)->getJson('/api/reports/module-averages')->assertOk();
        $this->assertNull(collect($report->json('modules'))->firstWhere('module_id', $emptyModule->id));

        // Students, and the course's own lecturer (who manages this one offering but not the catalogue), cannot see it.
        $this->as($ada)->getJson('/api/reports/module-averages')->assertForbidden();
        $this->as($lecturer)->getJson('/api/reports/module-averages')->assertForbidden();

        $csv = $this->as($admin)->get('/api/reports/module-averages?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Week 1', $csv);
        $this->assertStringContainsString('Average %', $csv);
    }
}
