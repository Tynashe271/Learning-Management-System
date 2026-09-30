<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class SkillsPassportTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private User $ben;

    private Competency $competency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student', ['name' => 'Ada Student']);
        $this->ben = $this->userWithRole('student', ['name' => 'Ben Student']);
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->enrol($this->offering, $this->ben);
        $this->competency = $this->offering->competencies()->create(['title' => 'Solder a joint', 'description' => 'Clean, shiny, no cold joints.']);
    }

    public function test_a_manager_can_create_a_competency_and_students_start_not_started(): void
    {
        $list = $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/competencies')->assertOk()->json();
        $this->assertSame('not_started', $list[0]['my_status']);
        $this->assertEquals(0, $list[0]['my_hours']);
    }

    public function test_a_student_can_log_an_entry_and_moves_to_developing(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->ada)->post('/api/competencies/'.$this->competency->id.'/logbook', [
            'activity_date' => now()->toDateString(),
            'hours' => 2.5,
            'description' => 'Practised on scrap boards.',
            'evidence' => UploadedFile::fake()->create('joint.jpg', 100, 'image/jpeg'),
        ])->assertCreated();

        $list = $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/competencies')->assertOk()->json();
        $this->assertSame('developing', $list[0]['my_status']);
        $this->assertEquals(2.5, $list[0]['my_hours']);
        $this->assertCount(1, Storage::disk('s3')->allFiles());
    }

    public function test_a_student_cannot_log_toward_a_competency_in_a_course_they_are_not_enrolled_in(): void
    {
        $outsider = $this->userWithRole('student');

        $this->actingAs($outsider)->postJson('/api/competencies/'.$this->competency->id.'/logbook', [
            'activity_date' => now()->toDateString(), 'hours' => 1, 'description' => 'x',
        ])->assertForbidden();
    }

    public function test_a_student_sees_only_their_own_entries_but_a_manager_sees_everyones(): void
    {
        $this->actingAs($this->ada)->postJson('/api/competencies/'.$this->competency->id.'/logbook', ['activity_date' => now()->toDateString(), 'hours' => 1, 'description' => 'Ada entry'])->assertCreated();
        $this->actingAs($this->ben)->postJson('/api/competencies/'.$this->competency->id.'/logbook', ['activity_date' => now()->toDateString(), 'hours' => 1, 'description' => 'Ben entry'])->assertCreated();

        $adaView = $this->actingAs($this->ada)->getJson('/api/competencies/'.$this->competency->id.'/logbook')->assertOk()->json();
        $this->assertCount(1, $adaView);
        $this->assertSame('Ada entry', $adaView[0]['description']);

        $staffView = $this->actingAs($this->lecturer)->getJson('/api/competencies/'.$this->competency->id.'/logbook')->assertOk()->json();
        $this->assertCount(2, $staffView);
    }

    public function test_a_lecturer_can_review_an_entry_and_mark_the_student_competent(): void
    {
        $entryId = $this->actingAs($this->ada)->postJson('/api/competencies/'.$this->competency->id.'/logbook', ['activity_date' => now()->toDateString(), 'hours' => 3, 'description' => 'Solid work'])
            ->assertCreated()->json('id');

        $this->actingAs($this->lecturer)->patchJson('/api/logbook-entries/'.$entryId.'/review', ['status' => 'competent', 'reviewer_comment' => 'Great technique.'])
            ->assertOk()->assertJsonPath('reviewer_comment', 'Great technique.');

        $list = $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/competencies')->assertOk()->json();
        $this->assertSame('competent', $list[0]['my_status']);
    }

    public function test_only_a_manager_can_review_an_entry(): void
    {
        $entryId = $this->actingAs($this->ada)->postJson('/api/competencies/'.$this->competency->id.'/logbook', ['activity_date' => now()->toDateString(), 'hours' => 1, 'description' => 'x'])
            ->assertCreated()->json('id');

        $this->actingAs($this->ben)->patchJson('/api/logbook-entries/'.$entryId.'/review', ['status' => 'competent'])->assertForbidden();
    }

    public function test_evidence_can_be_downloaded_by_the_owner_and_a_manager_but_not_another_student(): void
    {
        Storage::fake('s3');
        $entryId = $this->actingAs($this->ada)->post('/api/competencies/'.$this->competency->id.'/logbook', [
            'activity_date' => now()->toDateString(), 'hours' => 1, 'description' => 'x', 'evidence' => UploadedFile::fake()->create('e.jpg', 100, 'image/jpeg'),
        ])->assertCreated()->json('id');

        $this->actingAs($this->ada)->get('/api/logbook-entries/'.$entryId.'/evidence')->assertOk();
        $this->actingAs($this->lecturer)->get('/api/logbook-entries/'.$entryId.'/evidence')->assertOk();
        $this->actingAs($this->ben)->get('/api/logbook-entries/'.$entryId.'/evidence')->assertForbidden();
    }

    public function test_a_competency_with_logbook_entries_cannot_be_deleted(): void
    {
        $this->actingAs($this->ada)->postJson('/api/competencies/'.$this->competency->id.'/logbook', ['activity_date' => now()->toDateString(), 'hours' => 1, 'description' => 'x'])->assertCreated();

        $this->actingAs($this->lecturer)->deleteJson('/api/competencies/'.$this->competency->id)->assertStatus(422);
    }
}
