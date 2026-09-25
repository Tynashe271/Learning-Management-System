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

class GradebookTest extends TestCase
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
        $this->ada = $this->userWithRole('student', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        $this->ben = $this->userWithRole('student', ['name' => 'Ben Bitdiddle', 'email' => 'ben@example.com']);
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->enrol($this->offering, $this->ben);
    }

    /** Ada: assignment graded 60 then re-graded 80 (published), quiz attempts 5 then 8 of 8. Ben: nothing. */
    private function markUp(): void
    {
        $essay = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
        Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Hidden draft', 'due_at' => now()->addDay(), 'max_score' => 50, 'published' => false]);
        $submission = $essay->submissions()->create(['user_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now()]);
        $submission->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => 60, 'status' => 'published']);
        $submission->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => 80, 'status' => 'published', 'change_reason' => 'regrade']);
        $submission->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => 10, 'status' => 'draft', 'change_reason' => 'wip']);

        $quiz = $this->offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'published' => true, 'max_attempts' => 3]);
        $quiz->questions()->create(['type' => 'true_false', 'prompt' => 'a', 'points' => 5]);
        $quiz->questions()->create(['type' => 'true_false', 'prompt' => 'b', 'points' => 3]);
        foreach ([5, 8] as $score) {
            QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $this->ada->id, 'started_at' => now(), 'submitted_at' => now(), 'score' => $score, 'max_score' => 8]);
        }
        QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $this->ben->id, 'started_at' => now()]); // in progress: not counted
    }

    public function test_gradebook_uses_the_latest_published_grade_and_best_quiz_attempt(): void
    {
        $this->markUp();
        $withdrawn = $this->userWithRole('student');
        $this->enrol($this->offering, $withdrawn, 'withdrawn');

        $book = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/gradebook')->assertOk();

        $book->assertJsonCount(2, 'columns')->assertJsonPath('columns.0.type', 'assignment')->assertJsonPath('columns.0.max', 100)->assertJsonPath('columns.1.type', 'quiz')->assertJsonPath('columns.1.max', 8);
        $book->assertJsonCount(2, 'rows')->assertJsonPath('rows.0.user.email', 'ada@example.com');
        $ada = $book->json('rows.0');
        $this->assertEquals(80, $ada['scores']['assignment:'.$this->offering->assignments()->first()->id]);
        $this->assertEquals(8, array_values($ada['scores'])[1]);
        $this->assertEquals(88, $ada['total']);
        $this->assertEquals(108, $ada['possible']);
        $this->assertEquals(81.48, $ada['percent']);
        $ben = $book->json('rows.1');
        $this->assertSame([null, null], array_values($ben['scores']));
        $this->assertNull($ben['percent']);
    }

    public function test_only_offering_managers_can_read_the_full_gradebook(): void
    {
        $this->markUp();
        $url = '/api/offerings/'.$this->offering->id.'/gradebook';

        $this->actingAs($this->ada)->getJson($url)->assertForbidden();
        $this->actingAs($this->userWithRole('lecturer'))->getJson($url)->assertForbidden();
        $this->actingAs($this->userWithRole('university-admin'))->getJson($url)->assertOk();
    }

    public function test_a_student_sees_only_their_own_published_marks(): void
    {
        $this->markUp();

        $mine = $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/my-grades')->assertOk();
        $mine->assertJsonPath('grades.user.id', $this->ada->id)->assertJsonPath('grades.total', 88);
        $this->assertStringNotContainsString('ben@example.com', $mine->getContent());

        $theirs = $this->actingAs($this->ben)->getJson('/api/offerings/'.$this->offering->id.'/my-grades')->assertOk();
        $theirs->assertJsonPath('grades.total', 0)->assertJsonPath('grades.percent', null);

        $this->actingAs($this->userWithRole('student'))->getJson('/api/offerings/'.$this->offering->id.'/my-grades')->assertForbidden();
    }

    public function test_the_gradebook_downloads_as_csv_and_neutralises_spreadsheet_formulas(): void
    {
        $this->markUp();
        $evil = $this->userWithRole('student', ['name' => '=HYPERLINK("http://evil.example","click")', 'email' => 'evil@example.com']);
        $this->enrol($this->offering, $evil);

        $response = $this->actingAs($this->lecturer)->get('/api/offerings/'.$this->offering->id.'/gradebook?format=csv')->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Name,Email,"Essay (out of 100)","Quiz (out of 8)",Total,Possible,Percent', $csv);
        $this->assertStringContainsString('"Ada Lovelace",ada@example.com,80,8,88,108,81.48', str_replace('Ada Lovelace,', '"Ada Lovelace",', $csv));
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);
        $this->actingAs($this->ada)->get('/api/offerings/'.$this->offering->id.'/gradebook?format=csv')->assertForbidden();
    }
}
