<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class CheckinTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private ClassSession $session;

    private User $lecturer;

    private User $ada;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['lms.checkin.late_after_minutes' => 10]);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student', ['name' => 'Ada']);
        $this->ben = $this->userWithRole('student', ['name' => 'Ben']);
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->enrol($this->offering, $this->ben);
        $this->session = $this->offering->sessions()->create(['title' => 'Lecture', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
    }

    private function open(?int $minutes = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->lecturer)->postJson('/api/sessions/'.$this->session->id.'/checkin/open', $minutes ? ['minutes' => $minutes] : []);
    }

    private function checkin(User $as, string $code)
    {
        return $this->actingAs($as)->postJson('/api/sessions/'.$this->session->id.'/checkin', ['code' => $code]);
    }

    private function code(): string
    {
        return $this->open()->assertOk()->json('code');
    }

    public function test_the_teacher_opens_check_in_and_gets_a_six_digit_code(): void
    {
        $response = $this->open(20)->assertOk();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $response->json('code'));
        $response->assertJsonPath('open', true)->assertJsonPath('self_checked_in', 0);
        $this->assertEqualsWithDelta(20 * 60, strtotime($response->json('closes_at')) - strtotime($response->json('opens_at')), 2);
        $this->assertDatabaseHas('activity_log', ['description' => 'attendance check-in opened']);
    }

    public function test_the_default_window_is_fifteen_minutes(): void
    {
        $response = $this->open()->assertOk();

        $this->assertEqualsWithDelta(15 * 60, strtotime($response->json('closes_at')) - strtotime($response->json('opens_at')), 2);
    }

    public function test_a_student_checks_in_with_the_code_and_is_marked_present(): void
    {
        $code = $this->code();

        $this->checkin($this->ada, $code)->assertOk()->assertJsonPath('status', 'present');

        $record = AttendanceRecord::where('user_id', $this->ada->id)->firstOrFail();
        $this->assertSame('self', $record->source);
        $this->assertSame($this->ada->id, $record->marked_by);
        $this->actingAs($this->lecturer)->getJson('/api/sessions/'.$this->session->id.'/checkin')->assertJsonPath('self_checked_in', 1);
        $this->actingAs($this->lecturer)->getJson('/api/sessions/'.$this->session->id.'/attendance')->assertJsonPath('0.status', 'present')->assertJsonPath('0.source', 'self')->assertJsonPath('1.status', null);
        $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/sessions')->assertJsonPath('0.my_status', 'present');
        $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/attendance')->assertJsonPath('present', 1);
    }

    public function test_checking_in_more_than_ten_minutes_after_the_start_is_late(): void
    {
        $this->session->update(['starts_at' => now()->subMinutes(30), 'ends_at' => now()->addMinutes(30)]);
        $this->checkin($this->ada, $this->code())->assertOk()->assertJsonPath('status', 'late');

        $this->session->update(['starts_at' => now()->subMinutes(5)]);
        $this->checkin($this->ben, $this->session->fresh()->checkin_code)->assertOk()->assertJsonPath('status', 'present');
    }

    public function test_a_wrong_code_is_refused_and_records_nothing(): void
    {
        $code = $this->code();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->checkin($this->ada, $wrong)->assertJsonValidationErrors('code');
        $this->checkin($this->ada, '12')->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_check_in_only_works_while_it_is_open(): void
    {
        $this->checkin($this->ada, '123456')->assertJsonValidationErrors('code'); // never opened

        $code = $this->code();
        $this->travel(16)->minutes();
        $this->checkin($this->ada, $code)->assertJsonValidationErrors('code'); // window over
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_the_teacher_can_close_check_in_early(): void
    {
        $code = $this->code();

        $this->actingAs($this->lecturer)->postJson('/api/sessions/'.$this->session->id.'/checkin/close')->assertOk()->assertJsonPath('open', false)->assertJsonPath('code', null);

        $this->checkin($this->ada, $code)->assertJsonValidationErrors('code');
    }

    public function test_reopening_starts_a_new_window(): void
    {
        $first = $this->code();
        $this->actingAs($this->lecturer)->postJson('/api/sessions/'.$this->session->id.'/checkin/close')->assertOk();

        $second = $this->code();

        $this->checkin($this->ada, $second)->assertOk();
        if ($first !== $second) {
            $this->checkin($this->ben, $first)->assertJsonValidationErrors('code'); // the old code stopped working
        }
    }

    public function test_only_actively_enrolled_students_can_check_in_even_with_the_right_code(): void
    {
        $code = $this->code();
        $outsider = $this->userWithRole('student');
        $withdrawn = $this->userWithRole('student');
        $this->enrol($this->offering, $withdrawn, 'withdrawn');

        $this->checkin($outsider, $code)->assertForbidden();
        $this->checkin($withdrawn, $code)->assertForbidden();
        $this->checkin($this->lecturer, $code)->assertForbidden();
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_an_unpublished_offering_does_not_accept_check_ins(): void
    {
        $code = $this->code();
        $this->offering->update(['published' => false]);

        $this->checkin($this->ada, $code)->assertForbidden();
    }

    public function test_the_code_is_never_shown_in_session_listings(): void
    {
        $code = $this->code();

        $student = $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/sessions')->assertOk();
        $teacher = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/sessions')->assertOk();

        $student->assertJsonPath('0.checkin_open', true);
        foreach ([$student, $teacher] as $response) {
            $this->assertStringNotContainsString('checkin_code', $response->getContent());
            $this->assertStringNotContainsString('"'.$code.'"', $response->getContent());
        }
        $this->actingAs($this->ada)->getJson('/api/sessions/'.$this->session->id.'/checkin')->assertForbidden();
    }

    public function test_only_managers_can_open_close_or_view_check_in(): void
    {
        $other = $this->userWithRole('lecturer');

        $this->open(null, $this->ada)->assertForbidden();
        $this->open(null, $other)->assertForbidden();
        $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->session->id.'/checkin/close')->assertForbidden();
        $this->actingAs($other)->getJson('/api/sessions/'.$this->session->id.'/checkin')->assertForbidden();
        $this->assertNull($this->session->fresh()->checkin_code);
    }

    public function test_the_window_length_must_be_sensible(): void
    {
        $this->open(0)->assertOk(); // 0 is treated as "not given" by the helper, so the default applies
        $this->actingAs($this->lecturer)->postJson('/api/sessions/'.$this->session->id.'/checkin/open', ['minutes' => 0])->assertJsonValidationErrors('minutes');
        $this->actingAs($this->lecturer)->postJson('/api/sessions/'.$this->session->id.'/checkin/open', ['minutes' => 241])->assertJsonValidationErrors('minutes');
        $this->actingAs($this->lecturer)->postJson('/api/sessions/'.$this->session->id.'/checkin/open', ['minutes' => 240])->assertOk();
    }

    public function test_a_teachers_record_takes_priority_over_a_self_check_in(): void
    {
        $code = $this->code();
        $this->actingAs($this->lecturer)->putJson('/api/sessions/'.$this->session->id.'/attendance', ['records' => [['user_id' => $this->ada->id, 'status' => 'absent']]])->assertOk();

        $this->checkin($this->ada, $code)->assertJsonValidationErrors('code');

        $this->assertSame('absent', AttendanceRecord::where('user_id', $this->ada->id)->value('status'));
    }

    public function test_checking_in_twice_is_harmless(): void
    {
        $code = $this->code();

        $this->checkin($this->ada, $code)->assertOk()->assertJsonPath('status', 'present');
        $this->checkin($this->ada, $code)->assertOk()->assertJsonPath('status', 'present');

        $this->assertSame(1, AttendanceRecord::where('user_id', $this->ada->id)->count());
    }

    public function test_a_teacher_can_correct_a_self_check_in_and_the_student_cannot_redo_it(): void
    {
        $code = $this->code();
        $this->checkin($this->ada, $code)->assertOk();

        $this->actingAs($this->lecturer)->putJson('/api/sessions/'.$this->session->id.'/attendance', ['records' => [['user_id' => $this->ada->id, 'status' => 'absent', 'note' => 'Was not in the room']]])->assertOk();

        $record = AttendanceRecord::where('user_id', $this->ada->id)->firstOrFail();
        $this->assertSame(['absent', 'staff', $this->lecturer->id], [$record->status, $record->source, $record->marked_by]);
        $this->checkin($this->ada, $code)->assertJsonValidationErrors('code');
        $this->assertSame('absent', $record->fresh()->status);
    }

    public function test_wrong_guesses_are_rate_limited(): void
    {
        $code = $this->code();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 10; $i++) {
            $this->checkin($this->ada, $wrong)->assertUnprocessable();
        }

        $this->checkin($this->ada, $wrong)->assertStatus(429);
        $this->checkin($this->ada, $code)->assertStatus(429); // even the right code has to wait
        $this->checkin($this->ben, $code)->assertOk();        // other students are unaffected
    }
}
