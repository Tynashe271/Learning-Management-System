<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\GradeRecord;
use App\Models\Submission;
use App\Models\TeachingAssignment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfflineMarkingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Assignment, 2: Submission, 3: Submission} */
    private function setUp2(): array
    {
        $this->seed(DatabaseSeeder::class);
        $lecturer = User::factory()->create()->assignRole('lecturer');
        $term = AcademicTerm::create(['name' => 'Term 1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
        $course = Course::create(['code' => 'CSC101', 'title' => 'Computing']);
        $offering = CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'A', 'published' => true]);
        TeachingAssignment::create(['course_offering_id' => $offering->id, 'user_id' => $lecturer->id]);
        $assignment = Assignment::create(['course_offering_id' => $offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);

        $ungraded = Submission::create(['assignment_id' => $assignment->id, 'user_id' => User::factory()->create()->assignRole('student')->id, 'body' => 'my answer', 'submitted_at' => now()]);
        $gradedStudent = User::factory()->create()->assignRole('student');
        $graded = Submission::create(['assignment_id' => $assignment->id, 'user_id' => $gradedStudent->id, 'body' => 'already marked', 'submitted_at' => now()]);
        GradeRecord::create(['submission_id' => $graded->id, 'graded_by' => $lecturer->id, 'score' => 90, 'status' => 'published']);

        return [$lecturer, $assignment, $ungraded, $graded];
    }

    public function test_marking_pack_contains_only_ungraded_submissions_and_a_grades_template(): void
    {
        Storage::fake('s3');
        [$lecturer, $assignment, $ungraded, $graded] = $this->setUp2();
        $ungraded->update(['storage_path' => UploadedFile::fake()->create('answer.pdf', 10)->store('submissions', 's3')]);

        $response = $this->actingAs($lecturer)->get('/api/assignments/'.$assignment->id.'/marking-pack')->assertOk();

        $zip = new \ZipArchive;
        $zip->open($response->getFile()->getPathname());
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertContains('grades.csv', $names);
        $csv = $zip->getFromName('grades.csv');
        $this->assertStringContainsString((string) $ungraded->id, $csv);
        $this->assertStringNotContainsString((string) $graded->id, $csv);
        $this->assertTrue(collect($names)->contains(fn ($n) => str_starts_with($n, $ungraded->id.'-')));
    }

    public function test_non_manager_cannot_download_the_marking_pack(): void
    {
        [, $assignment] = $this->setUp2();
        $outsider = User::factory()->create()->assignRole('lecturer');

        $this->actingAs($outsider)->get('/api/assignments/'.$assignment->id.'/marking-pack')->assertForbidden();
    }

    public function test_importing_grades_creates_draft_grades_and_reports_row_errors(): void
    {
        [$lecturer, $assignment, $ungraded] = $this->setUp2();
        $csv = "submission_id,score,feedback\n{$ungraded->id},88,Good work\n9999,50,Nobody\n{$ungraded->id},70,Duplicate\nabc,50,Not a submission id\n";
        $file = UploadedFile::fake()->createWithContent('grades.csv', $csv);

        $response = $this->actingAs($lecturer)->post('/api/assignments/'.$assignment->id.'/grades/import', ['file' => $file])->assertOk();

        $response->assertJson(['dry_run' => false, 'created' => 1]);
        $this->assertCount(3, $response->json('errors'));
        $this->assertDatabaseHas('grade_records', ['submission_id' => $ungraded->id, 'score' => 88, 'status' => 'draft']);
    }

    public function test_dry_run_reports_without_creating_anything(): void
    {
        [$lecturer, $assignment, $ungraded] = $this->setUp2();
        $csv = "submission_id,score,feedback\n{$ungraded->id},88,Good work\n";
        $file = UploadedFile::fake()->createWithContent('grades.csv', $csv);

        $this->actingAs($lecturer)->post('/api/assignments/'.$assignment->id.'/grades/import', ['file' => $file, 'dry_run' => true])
            ->assertOk()->assertJson(['dry_run' => true, 'created' => 0, 'would_create' => 1]);

        // Only the pre-existing published grade from setUp2() should exist - nothing new from the dry run.
        $this->assertDatabaseCount('grade_records', 1);
    }

    public function test_out_of_range_score_is_rejected(): void
    {
        [$lecturer, $assignment, $ungraded] = $this->setUp2();
        $csv = "submission_id,score,feedback\n{$ungraded->id},500,Too high\n";
        $file = UploadedFile::fake()->createWithContent('grades.csv', $csv);

        $response = $this->actingAs($lecturer)->post('/api/assignments/'.$assignment->id.'/grades/import', ['file' => $file])->assertOk();

        $response->assertJson(['created' => 0]);
        $this->assertCount(1, $response->json('errors'));
    }

    public function test_non_manager_cannot_import_grades(): void
    {
        [, $assignment, $ungraded] = $this->setUp2();
        $outsider = User::factory()->create()->assignRole('lecturer');
        $csv = "submission_id,score,feedback\n{$ungraded->id},88,Good work\n";
        $file = UploadedFile::fake()->createWithContent('grades.csv', $csv);

        $this->actingAs($outsider)->post('/api/assignments/'.$assignment->id.'/grades/import', ['file' => $file])->assertForbidden();
    }
}
