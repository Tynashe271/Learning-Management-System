<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class SessionsAndAttendanceTest extends TestCase
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
        $this->ada = $this->userWithRole('student', ['name' => 'Ada']);
        $this->ben = $this->userWithRole('student', ['name' => 'Ben']);
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->enrol($this->offering, $this->ben);
    }

    private function makeSession(array $overrides = []): int
    {
        return $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/sessions', $overrides + [
            'title' => 'Lecture 1', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ])->assertCreated()->json('id');
    }

    private function mark(int $sessionId, array $records)
    {
        return $this->actingAs($this->lecturer)->putJson('/api/sessions/'.$sessionId.'/attendance', ['records' => $records]);
    }

    public function test_teachers_schedule_sessions_with_an_optional_meeting_link(): void
    {
        $inRoom = $this->makeSession(['title' => 'Lab', 'location' => 'Room 4']);
        $online = $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/sessions', [
            'title' => 'Online tutorial', 'starts_at' => now()->addDays(2)->toIso8601String(), 'ends_at' => now()->addDays(2)->addHour()->toIso8601String(), 'join_url' => 'https://meet.example.edu/abc-123',
        ])->assertCreated()->assertJsonPath('join_url', 'https://meet.example.edu/abc-123');

        $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/sessions')->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.title', 'Lab')->assertJsonPath('0.location', 'Room 4')->assertJsonPath('1.join_url', 'https://meet.example.edu/abc-123')->assertJsonPath('0.my_status', null);
        $this->assertNotSame($inRoom, $online->json('id'));
    }

    public function test_session_details_are_validated(): void
    {
        $post = fn (array $body) => $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/sessions', $body + ['title' => 'S', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHour()->toIso8601String()]);

        $post(['ends_at' => now()->toIso8601String()])->assertJsonValidationErrors('ends_at');
        $post(['join_url' => 'javascript:alert(1)'])->assertJsonValidationErrors('join_url');
        $post(['join_url' => 'ftp://example.edu/x'])->assertJsonValidationErrors('join_url');
        $post(['title' => ''])->assertJsonValidationErrors('title');
        $id = $this->makeSession();
        $this->actingAs($this->lecturer)->patchJson('/api/sessions/'.$id, ['ends_at' => now()->subDay()->toIso8601String()])->assertJsonValidationErrors('ends_at');
        $this->actingAs($this->lecturer)->patchJson('/api/sessions/'.$id, ['title' => 'Renamed', 'join_url' => null])->assertOk()->assertJsonPath('title', 'Renamed');
    }

    public function test_only_managers_of_the_offering_can_change_sessions_or_mark_attendance(): void
    {
        $id = $this->makeSession();
        $other = $this->userWithRole('lecturer');
        $body = ['title' => 'S', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHour()->toIso8601String()];

        $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/sessions', $body)->assertForbidden();
        $this->actingAs($other)->postJson('/api/offerings/'.$this->offering->id.'/sessions', $body)->assertForbidden();
        $this->actingAs($this->ada)->patchJson('/api/sessions/'.$id, ['title' => 'x'])->assertForbidden();
        $this->actingAs($this->ada)->deleteJson('/api/sessions/'.$id)->assertForbidden();
        $this->actingAs($this->ada)->putJson('/api/sessions/'.$id.'/attendance', ['records' => [['user_id' => $this->ada->id, 'status' => 'present']]])->assertForbidden();
        $this->actingAs($other)->getJson('/api/sessions/'.$id.'/attendance')->assertForbidden();
        $this->actingAs($this->userWithRole('student'))->getJson('/api/offerings/'.$this->offering->id.'/sessions')->assertForbidden();
    }

    public function test_attendance_is_marked_corrected_and_shown_on_the_roll(): void
    {
        $id = $this->makeSession();

        $this->mark($id, [['user_id' => $this->ada->id, 'status' => 'present'], ['user_id' => $this->ben->id, 'status' => 'absent', 'note' => 'Sick']])->assertOk()->assertJsonPath('marked', 2);
        $this->mark($id, [['user_id' => $this->ben->id, 'status' => 'excused', 'note' => 'Sick note received']])->assertOk();

        $this->assertDatabaseCount('attendance_records', 2);
        $this->actingAs($this->lecturer)->getJson('/api/sessions/'.$id.'/attendance')->assertOk()
            ->assertJsonPath('0.user.name', 'Ada')->assertJsonPath('0.status', 'present')->assertJsonPath('1.status', 'excused')->assertJsonPath('1.note', 'Sick note received');
        $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/sessions')->assertJsonPath('0.my_status', 'present');
        $this->actingAs($this->ben)->getJson('/api/offerings/'.$this->offering->id.'/sessions')->assertJsonPath('0.my_status', 'excused');
    }

    public function test_attendance_can_only_be_recorded_for_actively_enrolled_students(): void
    {
        $id = $this->makeSession();
        $withdrawn = $this->userWithRole('student');
        $this->enrol($this->offering, $withdrawn, 'withdrawn');
        $outsider = $this->userWithRole('student');

        $this->mark($id, [['user_id' => $outsider->id, 'status' => 'present']])->assertJsonValidationErrors('records');
        $this->mark($id, [['user_id' => $withdrawn->id, 'status' => 'present']])->assertJsonValidationErrors('records');
        $this->mark($id, [['user_id' => $this->ada->id, 'status' => 'present'], ['user_id' => $outsider->id, 'status' => 'present']])->assertJsonValidationErrors('records');
        $this->mark($id, [['user_id' => $this->ada->id, 'status' => 'holiday']])->assertJsonValidationErrors('records.0.status');
        $this->mark($id, [])->assertJsonValidationErrors('records');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_the_summary_counts_late_as_attended_and_ignores_excused_sessions(): void
    {
        $sessions = [$this->makeSession(['title' => 'One']), $this->makeSession(['title' => 'Two']), $this->makeSession(['title' => 'Three']), $this->makeSession(['title' => 'Four'])];
        foreach (['present', 'late', 'absent', 'excused'] as $i => $status) {
            $this->mark($sessions[$i], [['user_id' => $this->ada->id, 'status' => $status]]);
        }
        $this->mark($sessions[0], [['user_id' => $this->ben->id, 'status' => 'absent']]);

        $mine = $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/attendance')->assertOk();
        $mine->assertJsonPath('sessions_total', 4)->assertJsonPath('present', 1)->assertJsonPath('late', 1)->assertJsonPath('absent', 1)->assertJsonPath('excused', 1)->assertJsonPath('attended', 2);
        $this->assertEquals(66.67, $mine->json('percent'));

        $all = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/attendance')->assertOk();
        $all->assertJsonCount(2, 'students')->assertJsonPath('students.0.user.name', 'Ada')->assertJsonPath('students.1.user.name', 'Ben')->assertJsonPath('students.1.absent', 1);
        $this->assertEquals(0, $all->json('students.1.percent'));
        $this->assertStringNotContainsString('Ben', json_encode($mine->json()));
    }

    public function test_a_student_with_no_marks_has_no_percentage(): void
    {
        $this->makeSession();

        $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/attendance')->assertOk()->assertJsonPath('percent', null)->assertJsonPath('attended', 0);
    }

    public function test_deleting_a_session_removes_its_attendance_and_is_audited(): void
    {
        $id = $this->makeSession();
        $this->mark($id, [['user_id' => $this->ada->id, 'status' => 'present']]);

        $this->actingAs($this->lecturer)->deleteJson('/api/sessions/'.$id)->assertOk();

        $this->assertDatabaseCount('class_sessions', 0);
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseHas('activity_log', ['description' => 'class session deleted']);
    }
}
