<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class CourseCopyTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $source;

    private AcademicTerm $nextTerm;

    private User $admin;

    private User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->seed(DatabaseSeeder::class);
        $this->source = $this->offering();
        $this->nextTerm = AcademicTerm::create(['name' => 'Term 2', 'starts_on' => '2026-01-15', 'ends_on' => '2026-07-15']); // 14 days after Term 1
        $this->admin = $this->userWithRole('university-admin');
        $this->lecturer = $this->userWithRole('lecturer');
        $this->teach($this->source, $this->lecturer);
    }

    /** Fills the source offering with one of everything, plus things that must never be copied. */
    private function stock(): array
    {
        Storage::disk('s3')->put('course-files/1/original.pdf', 'PDF-BYTES');
        $module = $this->source->modules()->create(['title' => 'Week 1', 'position' => 1, 'published' => true]);
        $module->items()->create(['title' => 'Notes', 'type' => 'file', 'storage_path' => 'course-files/1/original.pdf', 'position' => 1, 'published' => true]);
        $module->items()->create(['title' => 'Reading', 'type' => 'link', 'body' => 'https://example.edu/reading', 'position' => 2, 'published' => false]);
        $assignment = Assignment::create(['course_offering_id' => $this->source->id, 'title' => 'Essay', 'instructions' => 'Write', 'due_at' => '2026-03-01 12:00:00', 'max_score' => 100, 'published' => true]);
        $assignment->rubricCriteria()->createMany([['title' => 'Argument', 'max_points' => 60, 'position' => 0], ['title' => 'Style', 'description' => 'Clarity', 'max_points' => 40, 'position' => 1]]);
        $quiz = $this->source->quizzes()->create(['title' => 'Quiz', 'opens_at' => '2026-02-01 08:00:00', 'due_at' => '2026-02-08 08:00:00', 'time_limit_minutes' => 20, 'max_attempts' => 2, 'published' => true]);
        $question = $quiz->questions()->create(['type' => 'single_choice', 'prompt' => 'Pick one', 'points' => 3, 'position' => 1]);
        $question->options()->createMany([['text' => 'Right', 'is_correct' => true, 'position' => 0], ['text' => 'Wrong', 'is_correct' => false, 'position' => 1]]);

        // Things that belong to the old class and must stay behind.
        $student = $this->userWithRole('student');
        $this->enrol($this->source, $student);
        $assignment->submissions()->create(['user_id' => $student->id, 'body' => 'x', 'submitted_at' => now()]);
        $quiz->attempts()->create(['user_id' => $student->id, 'started_at' => now(), 'submitted_at' => now(), 'score' => 3, 'max_score' => 3]);
        $this->source->announcements()->create(['user_id' => $this->lecturer->id, 'title' => 'Old news', 'body' => 'x']);
        $this->source->threads()->create(['user_id' => $student->id, 'title' => 'Old thread', 'body' => 'x']);

        return [$assignment, $quiz];
    }

    private function copy(array $body = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson('/api/offerings/'.$this->source->id.'/copy', $body + ['academic_term_id' => $this->nextTerm->id, 'section' => 'A']);
    }

    public function test_copying_builds_an_unpublished_offering_with_the_course_structure(): void
    {
        $this->stock();

        $response = $this->copy()->assertCreated();

        $response->assertJsonPath('offering.published', false)->assertJsonPath('offering.section', 'A')->assertJsonPath('offering.academic_term_id', $this->nextTerm->id)
            ->assertJsonPath('offering.course_id', $this->source->course_id)->assertJsonPath('date_shift_days', 14)
            ->assertJsonPath('copied.modules', 1)->assertJsonPath('copied.items', 2)->assertJsonPath('copied.files', 1)->assertJsonPath('copied.assignments', 1)
            ->assertJsonPath('copied.rubric_criteria', 2)->assertJsonPath('copied.quizzes', 1)->assertJsonPath('copied.questions', 1);
        $copy = CourseOffering::findOrFail($response->json('offering.id'));
        $this->assertSame(['Week 1'], $copy->modules()->pluck('title')->all());
        $this->assertSame(['Notes', 'Reading'], $copy->modules()->first()->items()->orderBy('position')->pluck('title')->all());
        $this->assertSame([true, false], $copy->modules()->first()->items()->orderBy('position')->pluck('published')->map(fn ($p) => (bool) $p)->all());
        $this->assertSame('https://example.edu/reading', $copy->modules()->first()->items()->where('type', 'link')->value('body'));
    }

    public function test_files_are_duplicated_so_the_two_offerings_do_not_share_storage(): void
    {
        $this->stock();

        $copy = CourseOffering::findOrFail($this->copy()->assertCreated()->json('offering.id'));

        $newPath = $copy->modules()->first()->items()->where('type', 'file')->value('storage_path');
        $this->assertNotSame('course-files/1/original.pdf', $newPath);
        $this->assertStringStartsWith('course-files/'.$copy->id.'/', $newPath);
        $this->assertStringEndsWith('.pdf', $newPath);
        Storage::disk('s3')->assertExists($newPath);
        $this->assertSame('PDF-BYTES', Storage::disk('s3')->get($newPath));

        // Removing the old term's file must not break next term's copy.
        Storage::disk('s3')->delete('course-files/1/original.pdf');
        Storage::disk('s3')->assertExists($newPath);
    }

    public function test_assignments_and_quizzes_come_across_unpublished_with_shifted_dates_and_their_rubrics_and_questions(): void
    {
        [$assignment, $quiz] = $this->stock();

        $copy = CourseOffering::findOrFail($this->copy()->assertCreated()->json('offering.id'));

        $newAssignment = $copy->assignments()->first();
        $this->assertFalse($newAssignment->published);
        $this->assertSame($assignment->due_at->addDays(14)->timestamp, $newAssignment->due_at->timestamp);
        $this->assertSame(100, (int) $newAssignment->max_score);
        $this->assertSame([['Argument', 60], ['Style', 40]], $newAssignment->rubricCriteria()->orderBy('position')->get()->map(fn ($c) => [$c->title, $c->max_points])->all());

        $newQuiz = $copy->quizzes()->first();
        $this->assertFalse($newQuiz->published);
        $this->assertSame($quiz->due_at->addDays(14)->timestamp, $newQuiz->due_at->timestamp);
        $this->assertSame($quiz->opens_at->addDays(14)->timestamp, $newQuiz->opens_at->timestamp);
        $this->assertSame(20, $newQuiz->time_limit_minutes);
        $this->assertSame(2, $newQuiz->max_attempts);
        $question = $newQuiz->questions()->first();
        $this->assertSame([['Right', true], ['Wrong', false]], $question->options()->get()->map(fn ($o) => [$o->text, $o->is_correct])->all());
        $this->assertSame(3, $question->points);
    }

    public function test_people_and_history_are_never_copied(): void
    {
        $this->stock();

        $copy = CourseOffering::findOrFail($this->copy()->assertCreated()->json('offering.id'));

        $this->assertSame(0, $copy->enrolments()->count());
        $this->assertSame(0, $copy->announcements()->count());
        $this->assertSame(0, $copy->threads()->count());
        $this->assertSame(0, $copy->teachers()->count());
        $this->assertSame(0, $copy->assignments()->first()->submissions()->count());
        $this->assertSame(0, $copy->quizzes()->first()->attempts()->count());
        // ...and the original is untouched.
        $this->assertSame(1, $this->source->enrolments()->count());
        $this->assertSame(1, $this->source->assignments()->first()->submissions()->count());
        $this->assertTrue($this->source->assignments()->first()->published);
    }

    public function test_teachers_can_optionally_come_along(): void
    {
        $this->stock();

        $copy = CourseOffering::findOrFail($this->copy(['copy_teachers' => true])->assertCreated()->json('offering.id'));

        $this->assertSame([$this->lecturer->id], $copy->teachers()->pluck('user_id')->all());
    }

    public function test_the_same_section_cannot_be_created_twice_in_a_term(): void
    {
        $this->stock();
        $this->copy()->assertCreated();

        $this->copy()->assertJsonValidationErrors('section');
        $this->copy(['academic_term_id' => $this->source->academic_term_id, 'section' => 'A'])->assertJsonValidationErrors('section');
        $this->assertSame(2, CourseOffering::count());
    }

    public function test_only_course_administrators_can_copy_and_the_input_is_validated(): void
    {
        $this->stock();

        $this->copy([], $this->lecturer)->assertForbidden();
        $this->copy([], $this->userWithRole('student'))->assertForbidden();
        $this->copy(['academic_term_id' => 9999])->assertJsonValidationErrors('academic_term_id');
        $this->copy(['section' => ''])->assertJsonValidationErrors('section');
        $this->assertSame(1, CourseOffering::count());
    }

    public function test_a_missing_source_file_stops_the_copy_and_leaves_nothing_behind(): void
    {
        $this->stock();
        Storage::disk('s3')->put('course-files/1/second.pdf', 'SECOND');
        $module = $this->source->modules()->first();
        $module->items()->create(['title' => 'Lost', 'type' => 'file', 'storage_path' => 'course-files/1/gone.pdf', 'position' => 3, 'published' => true]);
        $filesBefore = Storage::disk('s3')->allFiles();

        $this->copy()->assertUnprocessable()->assertJsonValidationErrors('offering');

        $this->assertSame(1, CourseOffering::count(), 'the half-built offering was rolled back');
        $this->assertEqualsCanonicalizing($filesBefore, Storage::disk('s3')->allFiles(), 'files copied before the failure were removed');
    }

    public function test_the_copy_is_recorded_in_the_audit_log(): void
    {
        $this->stock();

        $id = $this->copy()->assertCreated()->json('offering.id');

        $this->assertDatabaseHas('activity_log', ['description' => 'offering copied', 'subject_id' => $id]);
    }
}
