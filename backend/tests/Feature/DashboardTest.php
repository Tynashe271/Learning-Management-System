<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    // ---- agenda -----------------------------------------------------------------------------------------------

    public function test_a_students_agenda_merges_their_sessions_and_deadlines(): void
    {
        $offering = $this->offering();
        $teacher = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student');
        $this->teach($offering, $teacher);
        $this->enrol($offering, $student);
        $offering->sessions()->create(['title' => 'Lecture 1', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDays(3), 'max_score' => 10, 'published' => true]);
        $offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDays(30), 'max_attempts' => 1, 'published' => true]); // outside the default window

        $agenda = $this->actingAs($student)->getJson('/api/me/agenda')->assertOk();

        $agenda->assertJsonCount(1, 'sessions')->assertJsonPath('sessions.0.title', 'Lecture 1');
        $agenda->assertJsonCount(1, 'deadlines')->assertJsonPath('deadlines.0.title', 'Essay');
        $this->assertSame($assignment->id, $agenda->json('deadlines.0.id'));
    }

    public function test_a_submitted_assignment_drops_off_the_agenda(): void
    {
        $offering = $this->offering();
        $student = $this->userWithRole('student');
        $this->enrol($offering, $student);
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDays(2), 'max_score' => 10, 'published' => true]);
        $assignment->submissions()->create(['user_id' => $student->id, 'body' => 'done', 'submitted_at' => now()]);

        $this->actingAs($student)->getJson('/api/me/agenda')->assertOk()->assertJsonCount(0, 'deadlines');
    }

    public function test_a_teachers_agenda_shows_what_they_teach_not_what_they_take(): void
    {
        $offering = $this->offering();
        $teacher = $this->userWithRole('lecturer');
        $this->teach($offering, $teacher);
        $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDays(3), 'max_score' => 10, 'published' => true]);

        $agenda = $this->actingAs($teacher)->getJson('/api/me/agenda')->assertOk();

        $agenda->assertJsonCount(1, 'deadlines')->assertJsonPath('deadlines.0.title', 'Essay');
    }

    // ---- insights -----------------------------------------------------------------------------------------------

    public function test_insights_are_for_students_only(): void
    {
        $this->actingAs($this->userWithRole('lecturer'))->getJson('/api/me/insights')->assertForbidden();
    }

    public function test_missing_work_is_overdue_and_undone_only(): void
    {
        $offering = $this->offering();
        $student = $this->userWithRole('student');
        $this->enrol($offering, $student);
        $overdue = $offering->assignments()->create(['title' => 'Late essay', 'due_at' => now()->subDay(), 'max_score' => 10, 'published' => true]);
        $offering->assignments()->create(['title' => 'Future essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $done = $offering->assignments()->create(['title' => 'Done essay', 'due_at' => now()->subDay(), 'max_score' => 10, 'published' => true]);
        $done->submissions()->create(['user_id' => $student->id, 'body' => 'x', 'submitted_at' => now()->subDays(2)]);

        $insights = $this->actingAs($student)->getJson('/api/me/insights')->assertOk();

        $insights->assertJsonCount(1, 'missing')->assertJsonPath('missing.0.id', $overdue->id);
    }

    public function test_recent_feedback_only_surfaces_published_grades_with_text(): void
    {
        $offering = $this->offering();
        $teacher = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student');
        $this->teach($offering, $teacher);
        $this->enrol($offering, $student);
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $submission = $assignment->submissions()->create(['user_id' => $student->id, 'body' => 'x', 'submitted_at' => now()]);
        $submission->gradeRecords()->create(['graded_by' => $teacher->id, 'score' => 8, 'status' => 'draft', 'feedback' => 'Not yet visible']);
        $submission->gradeRecords()->create(['graded_by' => $teacher->id, 'score' => 9, 'status' => 'published', 'feedback' => 'Well argued.']);

        $insights = $this->actingAs($student)->getJson('/api/me/insights')->assertOk();

        $insights->assertJsonCount(1, 'recent_feedback')->assertJsonPath('recent_feedback.0.feedback', 'Well argued.')->assertJsonPath('recent_feedback.0.score', 9);
    }

    public function test_weak_topics_need_a_below_threshold_average_and_ignore_ungrouped_work(): void
    {
        $offering = $this->offering();
        $teacher = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student');
        $this->teach($offering, $teacher);
        $this->enrol($offering, $student);
        $weakTopic = $offering->modules()->create(['title' => 'Loops', 'position' => 0, 'published' => true]);
        $strongTopic = $offering->modules()->create(['title' => 'Variables', 'position' => 1, 'published' => true]);

        $weakAssignment = $offering->assignments()->create(['title' => 'Loop drill', 'course_module_id' => $weakTopic->id, 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $weakSubmission = $weakAssignment->submissions()->create(['user_id' => $student->id, 'body' => 'x', 'submitted_at' => now()]);
        $weakSubmission->gradeRecords()->create(['graded_by' => $teacher->id, 'score' => 4, 'status' => 'published']); // 40%

        $strongAssignment = $offering->assignments()->create(['title' => 'Variable drill', 'course_module_id' => $strongTopic->id, 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $strongSubmission = $strongAssignment->submissions()->create(['user_id' => $student->id, 'body' => 'x', 'submitted_at' => now()]);
        $strongSubmission->gradeRecords()->create(['graded_by' => $teacher->id, 'score' => 9, 'status' => 'published']); // 90%

        $ungrouped = $offering->assignments()->create(['title' => 'One-off', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $ungroupedSubmission = $ungrouped->submissions()->create(['user_id' => $student->id, 'body' => 'x', 'submitted_at' => now()]);
        $ungroupedSubmission->gradeRecords()->create(['graded_by' => $teacher->id, 'score' => 1, 'status' => 'published']); // 10%, but has no topic

        $insights = $this->actingAs($student)->getJson('/api/me/insights')->assertOk();

        $insights->assertJsonCount(1, 'weak_topics')->assertJsonPath('weak_topics.0.title', 'Loops')->assertJsonPath('weak_topics.0.percent', 40)->assertJsonCount(0, 'weak_topics.0.practice_quiz_ids');
    }

    public function test_a_weak_topics_practice_quizzes_are_linked_but_a_strong_topics_are_not(): void
    {
        $offering = $this->offering();
        $teacher = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student');
        $this->teach($offering, $teacher);
        $this->enrol($offering, $student);
        $weakTopic = $offering->modules()->create(['title' => 'Loops', 'position' => 0, 'published' => true]);
        $strongTopic = $offering->modules()->create(['title' => 'Variables', 'position' => 1, 'published' => true]);
        $weakAssignment = $offering->assignments()->create(['title' => 'Loop drill', 'course_module_id' => $weakTopic->id, 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $weakSubmission = $weakAssignment->submissions()->create(['user_id' => $student->id, 'body' => 'x', 'submitted_at' => now()]);
        $weakSubmission->gradeRecords()->create(['graded_by' => $teacher->id, 'score' => 3, 'status' => 'published']); // 30%
        $strongAssignment = $offering->assignments()->create(['title' => 'Variable drill', 'course_module_id' => $strongTopic->id, 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $strongSubmission = $strongAssignment->submissions()->create(['user_id' => $student->id, 'body' => 'x', 'submitted_at' => now()]);
        $strongSubmission->gradeRecords()->create(['graded_by' => $teacher->id, 'score' => 9, 'status' => 'published']); // 90%

        $practiceForWeak = $offering->quizzes()->create(['title' => 'Loop practice', 'course_module_id' => $weakTopic->id, 'due_at' => now()->addDay(), 'max_attempts' => 999, 'published' => true, 'is_practice' => true]);
        $offering->quizzes()->create(['title' => 'Variable practice', 'course_module_id' => $strongTopic->id, 'due_at' => now()->addDay(), 'max_attempts' => 999, 'published' => true, 'is_practice' => true]);

        $insights = $this->actingAs($student)->getJson('/api/me/insights')->assertOk();

        $insights->assertJsonCount(1, 'weak_topics')->assertJsonPath('weak_topics.0.title', 'Loops')->assertJsonPath('weak_topics.0.practice_quiz_ids', [$practiceForWeak->id]);
    }

    public function test_weak_topics_counts_a_quizs_best_submitted_attempt(): void
    {
        $offering = $this->offering();
        $student = $this->userWithRole('student');
        $this->enrol($offering, $student);
        $topic = $offering->modules()->create(['title' => 'Loops', 'position' => 0, 'published' => true]);
        $quiz = $offering->quizzes()->create(['title' => 'Loop quiz', 'course_module_id' => $topic->id, 'due_at' => now()->addDay(), 'max_attempts' => 2, 'published' => true]);
        $quiz->questions()->create(['type' => 'single_choice', 'prompt' => 'Q1', 'points' => 5, 'position' => 0]);
        $quiz->questions()->create(['type' => 'single_choice', 'prompt' => 'Q2', 'points' => 5, 'position' => 1]);
        $quiz->attempts()->create(['user_id' => $student->id, 'started_at' => now()->subMinutes(20), 'submitted_at' => now()->subMinutes(15), 'score' => 3, 'max_score' => 10]);
        $quiz->attempts()->create(['user_id' => $student->id, 'started_at' => now()->subMinutes(10), 'submitted_at' => now()->subMinutes(5), 'score' => 6, 'max_score' => 10]); // the best of the two

        $insights = $this->actingAs($student)->getJson('/api/me/insights')->assertOk();

        $insights->assertJsonCount(1, 'weak_topics')->assertJsonPath('weak_topics.0.title', 'Loops')->assertJsonPath('weak_topics.0.percent', 60);
    }

    // ---- calendar -----------------------------------------------------------------------------------------------

    public function test_the_calendar_token_is_created_once_and_reused(): void
    {
        $student = $this->userWithRole('student');

        $first = $this->actingAs($student)->postJson('/api/me/calendar-token')->assertOk()->json('url');
        $second = $this->actingAs($student)->postJson('/api/me/calendar-token')->assertOk()->json('url');

        $this->assertSame($first, $second);
        $this->assertNotNull($student->fresh()->calendar_token);
    }

    public function test_the_calendar_feed_is_a_valid_ics_for_a_real_token_and_a_404_for_a_bad_one(): void
    {
        $offering = $this->offering();
        $student = $this->userWithRole('student');
        $this->enrol($offering, $student);
        $offering->sessions()->create(['title' => 'Lecture 1', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        $token = $this->actingAs($student)->postJson('/api/me/calendar-token')->json('url');
        $path = parse_url($token, PHP_URL_PATH);

        $feed = $this->getJson($path)->assertOk();

        $feed->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertStringContainsString('BEGIN:VCALENDAR', $feed->getContent());
        $this->assertStringContainsString('SUMMARY:CSC101: Lecture 1', $feed->getContent());
        $this->assertStringContainsString('END:VCALENDAR', $feed->getContent());

        $this->getJson('/api/me/calendar/not-a-real-token.ics')->assertNotFound();
    }

    public function test_the_calendar_can_be_downloaded_as_a_pdf(): void
    {
        $offering = $this->offering();
        $student = $this->userWithRole('student');
        $this->enrol($offering, $student);
        $offering->sessions()->create(['title' => 'Lecture 1', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);

        $pdf = $this->actingAs($student)->get('/api/me/calendar.pdf')->assertOk();

        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    // ---- overview access and warnings -----------------------------------------------------------------------------

    public function test_a_registrar_can_now_see_the_overview_but_a_lecturer_still_cannot(): void
    {
        $this->actingAs($this->userWithRole('registrar'))->getJson('/api/reports/overview')->assertOk();
        $this->actingAs($this->userWithRole('lecturer'))->getJson('/api/reports/overview')->assertForbidden();
    }

    public function test_the_overview_warns_about_unstaffed_and_soon_unpublished_offerings(): void
    {
        $admin = $this->userWithRole('university-admin');
        $unstaffed = $this->offering('B'); // published, no teacher, by BuildsCourses::offering()'s default
        $term = AcademicTerm::create(['name' => 'Starting Soon', 'starts_on' => now()->addDays(5)->toDateString(), 'ends_on' => now()->addMonths(4)->toDateString()]);
        $course = Course::create(['code' => 'CSC202', 'title' => 'Soon']);
        $soon = CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'A', 'published' => false]);

        $warnings = $this->actingAs($admin)->getJson('/api/reports/overview')->assertOk()->json('warnings');

        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'CSC101') && str_contains($w, 'no teacher')));
        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'CSC202') && str_contains($w, 'not published')));
    }
}
