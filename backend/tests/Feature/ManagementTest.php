<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class ManagementTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $admin;

    private User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->admin = $this->userWithRole('university-admin');
        $this->lecturer = $this->userWithRole('lecturer');
        $this->teach($this->offering, $this->lecturer);
    }

    public function test_terms_can_be_renamed_but_not_made_to_end_before_they_start(): void
    {
        $term = AcademicTerm::first();

        $this->actingAs($this->admin)->patchJson('/api/terms/'.$term->id, ['name' => 'Semester 1'])->assertOk()->assertJsonPath('name', 'Semester 1');
        $this->actingAs($this->admin)->patchJson('/api/terms/'.$term->id, ['ends_on' => '2025-12-31'])->assertJsonValidationErrors('ends_on');
        $this->actingAs($this->admin)->patchJson('/api/terms/'.$term->id, ['starts_on' => '2026-08-01'])->assertJsonValidationErrors('ends_on');
        $this->actingAs($this->admin)->patchJson('/api/terms/'.$term->id, ['starts_on' => '2026-02-01', 'ends_on' => '2026-07-31'])->assertOk();
        $this->actingAs($this->lecturer)->patchJson('/api/terms/'.$term->id, ['name' => 'Hacked'])->assertForbidden();
    }

    public function test_courses_can_be_edited_and_codes_stay_unique(): void
    {
        $course = Course::first();
        $other = Course::create(['code' => 'MTH200', 'title' => 'Maths']);

        $this->actingAs($this->admin)->patchJson('/api/courses/'.$course->id, ['title' => 'Intro Computing', 'description' => 'New'])->assertOk()->assertJsonPath('title', 'Intro Computing');
        $this->actingAs($this->admin)->patchJson('/api/courses/'.$course->id, ['code' => 'CSC101'])->assertOk(); // its own code is fine
        $this->actingAs($this->admin)->patchJson('/api/courses/'.$course->id, ['code' => 'MTH200'])->assertJsonValidationErrors('code');
        $this->actingAs($this->lecturer)->patchJson('/api/courses/'.$other->id, ['title' => 'x'])->assertForbidden();
    }

    public function test_offering_sections_can_be_renamed_without_clashing(): void
    {
        $second = $this->offering('B');

        $this->actingAs($this->admin)->patchJson('/api/offerings/'.$this->offering->id, ['section' => 'A2'])->assertOk()->assertJsonPath('section', 'A2');
        $this->actingAs($this->admin)->patchJson('/api/offerings/'.$second->id, ['section' => 'A2'])->assertJsonValidationErrors('section');
        $this->actingAs($this->admin)->patchJson('/api/offerings/'.$second->id, ['section' => 'B'])->assertOk();
        $this->actingAs($this->admin)->patchJson('/api/offerings/'.$second->id, ['published' => false])->assertOk()->assertJsonPath('published', false);
        $this->actingAs($this->admin)->patchJson('/api/offerings/'.$second->id, [])->assertUnprocessable();
    }

    public function test_teachers_can_be_removed_from_an_offering(): void
    {
        $this->actingAs($this->lecturer)->deleteJson('/api/offerings/'.$this->offering->id.'/teachers/'.$this->lecturer->id)->assertForbidden();
        $this->actingAs($this->admin)->deleteJson('/api/offerings/'.$this->offering->id.'/teachers/'.$this->lecturer->id)->assertOk();
        $this->actingAs($this->admin)->deleteJson('/api/offerings/'.$this->offering->id.'/teachers/'.$this->lecturer->id)->assertNotFound();

        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/modules', ['title' => 'Week 1'])->assertForbidden();
    }

    public function test_a_teacher_can_publish_their_own_consultation_hours_but_not_a_colleagues(): void
    {
        $colleague = $this->userWithRole('lecturer');
        $this->teach($this->offering, $colleague);
        $student = $this->userWithRole('student');
        $this->enrol($this->offering, $student);

        $this->actingAs($this->lecturer)->patchJson('/api/offerings/'.$this->offering->id.'/teachers/'.$this->lecturer->id, ['consultation_hours' => 'Tuesdays 2-4pm, Room 204'])
            ->assertOk()->assertJsonPath('consultation_hours', 'Tuesdays 2-4pm, Room 204');
        $this->actingAs($this->lecturer)->patchJson('/api/offerings/'.$this->offering->id.'/teachers/'.$colleague->id, ['consultation_hours' => 'Sneaky'])->assertForbidden();
        $this->actingAs($this->admin)->patchJson('/api/offerings/'.$this->offering->id.'/teachers/'.$colleague->id, ['consultation_hours' => 'By appointment'])
            ->assertOk()->assertJsonPath('consultation_hours', 'By appointment');
        $this->actingAs($student)->patchJson('/api/offerings/'.$this->offering->id.'/teachers/'.$this->lecturer->id, ['consultation_hours' => 'Nope'])->assertForbidden();

        $roster = $this->actingAs($this->admin)->getJson('/api/offerings/'.$this->offering->id.'/roster')->assertOk();
        $this->assertSame('Tuesdays 2-4pm, Room 204', collect($roster->json('teachers'))->firstWhere('user_id', $this->lecturer->id)['consultation_hours']);
        $shown = $this->actingAs($student)->getJson('/api/offerings/'.$this->offering->id)->assertOk();
        $this->assertSame('By appointment', collect($shown->json('teachers'))->firstWhere('user_id', $colleague->id)['consultation_hours']);
    }

    public function test_registrars_can_import_enrolments_by_email(): void
    {
        $registrar = $this->userWithRole('registrar');
        $ada = $this->userWithRole('student', ['email' => 'ada@example.com']);
        $ben = $this->userWithRole('student', ['email' => 'ben@example.com']);
        $this->enrol($this->offering, $ben, 'withdrawn');
        $this->userWithRole('lecturer', ['email' => 'staff@example.com']);

        $response = $this->actingAs($registrar)->postJson('/api/offerings/'.$this->offering->id.'/enrolments/import', [
            'emails' => ['ADA@example.com', 'ben@example.com', 'ada@example.com', 'ghost@example.com', 'staff@example.com'],
        ])->assertOk();

        $response->assertJsonPath('enrolled', 2)->assertJsonCount(2, 'not_found');
        $this->assertEqualsCanonicalizing(['ghost@example.com', 'staff@example.com'], $response->json('not_found'));
        $this->assertDatabaseHas('enrolments', ['user_id' => $ada->id, 'status' => 'active']);
        $this->assertDatabaseHas('enrolments', ['user_id' => $ben->id, 'status' => 'active']); // re-activated
        $this->assertDatabaseCount('enrolments', 2);

        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/enrolments/import', ['emails' => ['ada@example.com']])->assertForbidden();
        $this->actingAs($registrar)->postJson('/api/offerings/'.$this->offering->id.'/enrolments/import', ['emails' => []])->assertJsonValidationErrors('emails');
        $this->actingAs($registrar)->postJson('/api/offerings/'.$this->offering->id.'/enrolments/import', ['emails' => ['not-an-email']])->assertJsonValidationErrors('emails.0');
    }

    public function test_deleting_a_module_or_item_also_removes_its_files(): void
    {
        Storage::fake('s3');
        $module = $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/modules', ['title' => 'Week 1'])->json('id');
        $upload = fn (string $name) => $this->actingAs($this->lecturer)->post('/api/modules/'.$module.'/items', ['title' => $name, 'type' => 'file', 'file' => UploadedFile::fake()->create($name.'.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $first = $upload('one');
        $upload('two');
        $this->assertCount(2, Storage::disk('s3')->allFiles());

        $this->actingAs($this->userWithRole('lecturer'))->deleteJson('/api/items/'.$first)->assertForbidden();
        $this->actingAs($this->lecturer)->deleteJson('/api/items/'.$first)->assertOk();
        $this->assertCount(1, Storage::disk('s3')->allFiles());

        $this->actingAs($this->lecturer)->deleteJson('/api/modules/'.$module)->assertOk();
        $this->assertCount(0, Storage::disk('s3')->allFiles());
        $this->assertDatabaseCount('learning_items', 0);
    }

    public function test_an_assignment_with_submissions_cannot_be_deleted(): void
    {
        $student = $this->userWithRole('student');
        $used = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Used', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $unused = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Unused', 'due_at' => now()->addDay(), 'max_score' => 10]);
        $used->submissions()->create(['user_id' => $student->id, 'body' => 'x', 'submitted_at' => now()]);

        $this->actingAs($this->lecturer)->deleteJson('/api/assignments/'.$used->id)->assertUnprocessable()->assertJsonValidationErrors('assignment');
        $this->actingAs($this->userWithRole('lecturer'))->deleteJson('/api/assignments/'.$unused->id)->assertForbidden();
        $this->actingAs($this->lecturer)->deleteJson('/api/assignments/'.$unused->id)->assertOk();
        $this->assertDatabaseHas('assignments', ['id' => $used->id]);
        $this->assertDatabaseMissing('assignments', ['id' => $unused->id]);
    }

    public function test_uploads_are_limited_to_the_allowed_file_types(): void
    {
        Storage::fake('s3');
        $module = $this->offering->modules()->create(['title' => 'Week 1']);
        $post = fn (UploadedFile $file) => $this->actingAs($this->lecturer)->post('/api/modules/'.$module->id.'/items', ['title' => 'f', 'type' => 'file', 'file' => $file], ['Accept' => 'application/json']);

        $post(UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))->assertCreated();
        $post(UploadedFile::fake()->create('slides.pptx', 10))->assertCreated();
        $post(UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload'))->assertJsonValidationErrors('file');
        $post(UploadedFile::fake()->create('run.sh', 10, 'text/x-shellscript'))->assertJsonValidationErrors('file');
        $post(UploadedFile::fake()->create('page.html', 10, 'text/html'))->assertJsonValidationErrors('file');
        $this->assertCount(2, Storage::disk('s3')->allFiles());
    }

    public function test_link_items_must_be_web_urls_and_text_items_need_a_body(): void
    {
        $module = $this->offering->modules()->create(['title' => 'Week 1']);
        $post = fn (array $item) => $this->actingAs($this->lecturer)->postJson('/api/modules/'.$module->id.'/items', $item + ['title' => 'Item']);

        $post(['type' => 'link', 'body' => 'https://example.edu/reading'])->assertCreated();
        $post(['type' => 'link', 'body' => 'javascript:alert(1)'])->assertJsonValidationErrors('body');
        $post(['type' => 'link', 'body' => 'not a url'])->assertJsonValidationErrors('body');
        $post(['type' => 'link'])->assertJsonValidationErrors('body');
        $post(['type' => 'text'])->assertJsonValidationErrors('body');
        $post(['type' => 'text', 'body' => 'Read chapter 1.'])->assertCreated();
    }

    public function test_the_audit_log_is_for_user_administrators_only(): void
    {
        $this->actingAs($this->admin)->postJson('/api/terms', ['name' => 'T2', 'starts_on' => '2026-07-01', 'ends_on' => '2026-12-01'])->assertCreated();
        $this->actingAs($this->admin)->patchJson('/api/terms/'.AcademicTerm::first()->id, ['name' => 'Renamed'])->assertOk();

        $log = $this->actingAs($this->admin)->getJson('/api/audit-log')->assertOk();
        $log->assertJsonPath('data.0.description', 'term changed')->assertJsonPath('data.0.causer.id', $this->admin->id);

        $this->actingAs($this->admin)->getJson('/api/audit-log?q=term%20changed')->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson('/api/audit-log?q=%25')->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->getJson('/api/audit-log?causer_id='.$this->lecturer->id)->assertJsonCount(0, 'data');
        $this->actingAs($this->lecturer)->getJson('/api/audit-log')->assertForbidden();
        $this->actingAs($this->userWithRole('registrar'))->getJson('/api/audit-log')->assertForbidden();
    }

    public function test_the_overview_report_counts_the_whole_institution(): void
    {
        $student = $this->userWithRole('student');
        $this->enrol($this->offering, $student);
        $this->userWithRole('student')->forceFill(['is_active' => false])->save();

        $report = $this->actingAs($this->admin)->getJson('/api/reports/overview')->assertOk();

        $report->assertJsonPath('users.total', 4)->assertJsonPath('users.active', 3)->assertJsonPath('users.by_role.student', 2)->assertJsonPath('users.by_role.lecturer', 1)
            ->assertJsonPath('offerings.total', 1)->assertJsonPath('offerings.published', 1)->assertJsonPath('enrolments_active', 1);
        $this->actingAs($this->lecturer)->getJson('/api/reports/overview')->assertForbidden();
    }

    public function test_the_offering_summary_includes_quiz_participation(): void
    {
        $ada = $this->userWithRole('student');
        $ben = $this->userWithRole('student');
        $quiz = $this->offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'published' => true, 'max_attempts' => 3]);
        foreach ([$ada, $ada, $ben] as $student) {
            $quiz->attempts()->create(['user_id' => $student->id, 'started_at' => now(), 'submitted_at' => now(), 'score' => 1, 'max_score' => 2]);
        }
        $quiz->attempts()->create(['user_id' => $this->userWithRole('student')->id, 'started_at' => now()]);

        $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/summary')->assertOk()->assertJsonPath('quizzes.0.students_attempted', 2);
    }
}
