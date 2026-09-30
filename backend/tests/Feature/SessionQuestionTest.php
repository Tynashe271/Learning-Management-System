<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class SessionQuestionTest extends TestCase
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

    public function test_a_student_can_ask_a_question_and_see_the_queue(): void
    {
        $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->sessionId.'/questions', ['body' => 'What is on the exam?'])->assertCreated();

        $list = $this->actingAs($this->ben)->getJson('/api/sessions/'.$this->sessionId.'/questions')->assertOk()->json();
        $this->assertCount(1, $list);
        $this->assertSame('Ada Student', $list[0]['user']['name']);
        $this->assertSame('What is on the exam?', $list[0]['body']);
        $this->assertNull($list[0]['answered_at']);
    }

    public function test_a_question_body_is_required_and_limited(): void
    {
        $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->sessionId.'/questions', ['body' => ''])->assertUnprocessable();
        $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->sessionId.'/questions', ['body' => str_repeat('a', 501)])->assertUnprocessable();
    }

    public function test_a_non_enrolled_or_withdrawn_student_cannot_ask_or_view_questions(): void
    {
        $outsider = $this->userWithRole('student');
        $this->actingAs($outsider)->postJson('/api/sessions/'.$this->sessionId.'/questions', ['body' => 'Hi?'])->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/sessions/'.$this->sessionId.'/questions')->assertForbidden();

        $this->offering->enrolments()->where('user_id', $this->ben->id)->update(['status' => 'withdrawn']);
        $this->actingAs($this->ben)->postJson('/api/sessions/'.$this->sessionId.'/questions', ['body' => 'Hi?'])->assertForbidden();
    }

    public function test_the_teacher_can_toggle_a_question_answered(): void
    {
        $questionId = $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->sessionId.'/questions', ['body' => 'What is on the exam?'])->json('id');

        $answered = $this->actingAs($this->lecturer)->patchJson('/api/session-questions/'.$questionId)->assertOk()->json();
        $this->assertNotNull($answered['answered_at']);

        $unanswered = $this->actingAs($this->lecturer)->patchJson('/api/session-questions/'.$questionId)->assertOk()->json();
        $this->assertNull($unanswered['answered_at']);
    }

    public function test_a_student_cannot_mark_a_question_answered(): void
    {
        $questionId = $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->sessionId.'/questions', ['body' => 'What is on the exam?'])->json('id');

        $this->actingAs($this->ben)->patchJson('/api/session-questions/'.$questionId)->assertForbidden();
    }

    public function test_a_student_can_delete_their_own_question_but_not_someone_elses(): void
    {
        $questionId = $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->sessionId.'/questions', ['body' => 'What is on the exam?'])->json('id');

        $this->actingAs($this->ben)->deleteJson('/api/session-questions/'.$questionId)->assertForbidden();
        $this->actingAs($this->ada)->deleteJson('/api/session-questions/'.$questionId)->assertOk();

        $this->actingAs($this->lecturer)->getJson('/api/sessions/'.$this->sessionId.'/questions')->assertOk()->assertJsonCount(0);
    }

    public function test_the_teacher_can_delete_any_question(): void
    {
        $questionId = $this->actingAs($this->ada)->postJson('/api/sessions/'.$this->sessionId.'/questions', ['body' => 'What is on the exam?'])->json('id');

        $this->actingAs($this->lecturer)->deleteJson('/api/session-questions/'.$questionId)->assertOk();
    }
}
