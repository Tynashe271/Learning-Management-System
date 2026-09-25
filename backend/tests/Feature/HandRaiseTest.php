<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class HandRaiseTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private User $ben;

    private int $sessionId;

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
        $this->sessionId = $this->offering->sessions()->create(['title' => 'Lecture 1', 'starts_at' => now(), 'ends_at' => now()->addHour()])->id;
    }

    public function test_a_student_can_raise_and_lower_their_own_hand(): void
    {
        $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertOk();

        $list = $this->actingAs($this->lecturer)->getJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertOk()->json();
        $this->assertCount(1, $list);
        $this->assertSame('Ada Student', $list[0]['user']['name']);

        $this->actingAs($this->ada)->deleteJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertOk();
        $this->actingAs($this->lecturer)->getJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertOk()->assertJsonCount(0);
    }

    public function test_raising_a_hand_twice_does_not_duplicate_and_refreshes_the_time(): void
    {
        $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertOk();
        $this->travel(1)->minutes();
        $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertOk();

        $this->actingAs($this->lecturer)->getJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertOk()->assertJsonCount(1);
    }

    public function test_a_non_enrolled_or_withdrawn_student_cannot_raise_a_hand(): void
    {
        $outsider = $this->userWithRole('student');
        $this->actingAs($outsider)->postJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertForbidden();

        $this->offering->enrolments()->where('user_id', $this->ben->id)->update(['status' => 'withdrawn']);
        $this->actingAs($this->ben)->postJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertForbidden();
    }

    public function test_the_teacher_sees_hands_ordered_by_time_raised_and_can_clear_one(): void
    {
        $this->actingAs($this->ben)->postJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertOk();
        $this->travel(1)->minutes();
        $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertOk();

        $list = $this->actingAs($this->lecturer)->getJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertOk()->json();
        $this->assertSame(['Ben Student', 'Ada Student'], array_column(array_column($list, 'user'), 'name'));

        $this->actingAs($this->lecturer)->deleteJson('/api/sessions/'.$this->sessionId.'/hand-raises/'.$this->ben->id)->assertOk();
        $this->actingAs($this->lecturer)->getJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertOk()->assertJsonCount(1);
    }

    public function test_a_student_cannot_see_or_clear_the_live_list(): void
    {
        $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertOk();

        $this->actingAs($this->ben)->getJson('/api/sessions/'.$this->sessionId.'/hand-raises')->assertForbidden();
        $this->actingAs($this->ben)->deleteJson('/api/sessions/'.$this->sessionId.'/hand-raises/'.$this->ada->id)->assertForbidden();
    }
}
