<?php

namespace Tests\Feature;

use App\Models\AttachmentPlacement;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class AttachmentWorkspaceTest extends TestCase
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
        $this->ada = $this->userWithRole('student', ['name' => 'Ada Student']);
        $this->ben = $this->userWithRole('student');
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->enrol($this->offering, $this->ben);
    }

    private function makePlacement(): int
    {
        return $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/attachments', [
            'user_id' => $this->ada->id,
            'organisation' => 'Acme Ltd',
            'supervisor_name' => 'Sam Supervisor',
            'supervisor_email' => 'sam@acme.test',
            'objectives' => 'Learn the ropes.',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addWeeks(8)->toDateString(),
        ])->assertCreated()->json('id');
    }

    public function test_a_manager_can_create_a_placement_and_the_student_can_see_their_own(): void
    {
        $this->makePlacement();

        $mine = $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id.'/attachments/mine')->assertOk()->json();
        $this->assertSame('Acme Ltd', $mine['organisation']);

        $this->assertEmpty($this->actingAs($this->ben)->getJson('/api/offerings/'.$this->offering->id.'/attachments/mine')->assertOk()->json());
    }

    public function test_a_student_can_log_a_week_and_only_the_owner_or_a_manager_can_read_it(): void
    {
        $id = $this->makePlacement();

        $this->actingAs($this->ada)->postJson('/api/attachment-placements/'.$id.'/logbook', [
            'week_ending' => now()->toDateString(), 'hours' => 35, 'activities' => 'Shadowed the ops team.',
        ])->assertCreated();

        $this->actingAs($this->ada)->getJson('/api/attachment-placements/'.$id.'/logbook')->assertOk()->assertJsonCount(1);
        $this->actingAs($this->lecturer)->getJson('/api/attachment-placements/'.$id.'/logbook')->assertOk()->assertJsonCount(1);
        $this->actingAs($this->ben)->getJson('/api/attachment-placements/'.$id.'/logbook')->assertForbidden();
    }

    public function test_a_duplicate_week_ending_is_rejected(): void
    {
        $id = $this->makePlacement();
        $week = now()->toDateString();
        $this->actingAs($this->ada)->postJson('/api/attachment-placements/'.$id.'/logbook', ['week_ending' => $week, 'hours' => 10, 'activities' => 'x'])->assertCreated();

        $this->actingAs($this->ada)->postJson('/api/attachment-placements/'.$id.'/logbook', ['week_ending' => $week, 'hours' => 10, 'activities' => 'x again'])
            ->assertUnprocessable()->assertJsonValidationErrors('week_ending');
    }

    public function test_requesting_supervisor_feedback_emails_the_supervisor_a_working_link(): void
    {
        Notification::fake();
        $id = $this->makePlacement();

        $this->actingAs($this->lecturer)->postJson('/api/attachment-placements/'.$id.'/request-supervisor-feedback')->assertOk();

        Notification::assertSentOnDemand(\App\Notifications\AttachmentFeedbackRequested::class, function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'sam@acme.test';
        });

        $placement = AttachmentPlacement::find($id);
        $token = $placement->feedbackTokens()->first()->token_hash;
        $this->assertNotNull($token);
    }

    public function test_the_supervisor_can_use_a_real_token_to_view_and_submit_feedback_once(): void
    {
        $id = $this->makePlacement();
        Notification::fake();
        $this->actingAs($this->lecturer)->postJson('/api/attachment-placements/'.$id.'/request-supervisor-feedback')->assertOk();
        $sent = null;
        Notification::assertSentOnDemand(\App\Notifications\AttachmentFeedbackRequested::class, function ($notification) use (&$sent) {
            $sent = $notification;

            return true;
        });
        $token = (fn () => $this->token)->call($sent);

        $this->getJson('/api/attachment-feedback/'.$token)->assertOk()->assertJsonPath('organisation', 'Acme Ltd')->assertJsonPath('already_submitted', false);

        $this->postJson('/api/attachment-feedback/'.$token, ['rating' => 4, 'comment' => 'Reliable and eager to learn.'])->assertOk();

        $placement = AttachmentPlacement::find($id);
        $this->assertEquals(4, $placement->supervisor_rating);
        $this->assertNotNull($placement->supervisor_submitted_at);

        // The token cannot be reused.
        $this->postJson('/api/attachment-feedback/'.$token, ['rating' => 1])->assertNotFound();
    }

    public function test_an_unknown_or_expired_token_is_refused(): void
    {
        $this->getJson('/api/attachment-feedback/not-a-real-token')->assertNotFound();
    }

    public function test_only_a_manager_can_create_a_placement_or_request_feedback(): void
    {
        $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/attachments', [
            'user_id' => $this->ada->id, 'organisation' => 'x', 'supervisor_name' => 'x', 'supervisor_email' => 'x@x.test', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addWeek()->toDateString(),
        ])->assertForbidden();
    }
}
