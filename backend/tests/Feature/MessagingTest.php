<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\DirectMessage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $course;

    private CourseOffering $elsewhere;

    private User $lecturer;

    private User $ada;

    private User $ben;

    private User $cy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->course = $this->offering('A');
        $this->elsewhere = $this->offering('B');
        $this->lecturer = $this->userWithRole('lecturer', ['name' => 'Dr Lee']);
        $this->ada = $this->userWithRole('student', ['name' => 'Ada']);
        $this->ben = $this->userWithRole('student', ['name' => 'Ben']);
        $this->cy = $this->userWithRole('student', ['name' => 'Cy']);
        $this->teach($this->course, $this->lecturer);
        $this->enrol($this->course, $this->ada);
        $this->enrol($this->course, $this->ben);
        $this->enrol($this->elsewhere, $this->cy);
    }

    private function send(User $from, User $to, string $body = 'Hello')
    {
        return $this->actingAs($from)->postJson('/api/messages/'.$to->id, ['body' => $body]);
    }

    public function test_people_who_share_a_course_can_message_each_other(): void
    {
        $this->send($this->ada, $this->ben)->assertCreated()->assertJsonPath('body', 'Hello')->assertJsonPath('sender_id', $this->ada->id);
        $this->send($this->ada, $this->lecturer)->assertCreated();
        $this->send($this->lecturer, $this->ada)->assertCreated();
    }

    public function test_people_with_no_course_in_common_cannot_message_each_other(): void
    {
        $this->send($this->ada, $this->cy)->assertForbidden();
        $this->send($this->cy, $this->lecturer)->assertForbidden();
        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_withdrawn_students_lose_the_link_that_lets_them_write(): void
    {
        $this->course->enrolments()->where('user_id', $this->ben->id)->update(['status' => 'withdrawn']);

        $this->send($this->ben, $this->ada)->assertForbidden();
        $this->send($this->ada, $this->ben)->assertForbidden();
    }

    public function test_administrators_can_write_to_anyone_and_the_person_can_reply(): void
    {
        $admin = $this->userWithRole('university-admin');

        $this->send($this->cy, $admin)->assertForbidden(); // students cannot start a conversation with an administrator
        $this->send($admin, $this->cy, 'About your fees')->assertCreated();
        $this->send($this->cy, $admin, 'Thanks, will do')->assertCreated();
    }

    public function test_you_cannot_message_yourself_or_a_deactivated_account(): void
    {
        $this->send($this->ada, $this->ada)->assertJsonValidationErrors('recipient');
        $this->ben->forceFill(['is_active' => false])->save();
        $this->send($this->ada, $this->ben)->assertForbidden();
    }

    public function test_message_bodies_are_validated(): void
    {
        $this->send($this->ada, $this->ben, '')->assertJsonValidationErrors('body');
        $this->send($this->ada, $this->ben, str_repeat('x', 5001))->assertJsonValidationErrors('body');
        $this->actingAs($this->ada)->postJson('/api/messages/'.$this->ben->id, [])->assertJsonValidationErrors('body');
    }

    public function test_the_inbox_lists_conversations_with_unread_counts_and_opening_one_marks_it_read(): void
    {
        $this->send($this->ada, $this->ben, 'First');
        $this->send($this->ada, $this->ben, 'Second');
        $this->send($this->lecturer, $this->ben, 'From the lecturer');

        $inbox = $this->actingAs($this->ben)->getJson('/api/messages')->assertOk();
        $inbox->assertJsonPath('unread_total', 3)->assertJsonCount(2, 'conversations')
            ->assertJsonPath('conversations.0.user.name', 'Dr Lee')->assertJsonPath('conversations.0.unread', 1)->assertJsonPath('conversations.0.last_message.body', 'From the lecturer')
            ->assertJsonPath('conversations.1.user.name', 'Ada')->assertJsonPath('conversations.1.unread', 2)->assertJsonPath('conversations.1.last_message.body', 'Second');

        $this->actingAs($this->ben)->getJson('/api/messages/'.$this->ada->id)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.body', 'Second');

        $this->actingAs($this->ben)->getJson('/api/messages')->assertJsonPath('unread_total', 1)->assertJsonPath('conversations.1.unread', 0);
        // The sender's own view shows nothing unread for messages they wrote.
        $this->actingAs($this->ada)->getJson('/api/messages')->assertJsonPath('unread_total', 0);
    }

    public function test_a_thread_shows_both_directions_newest_first(): void
    {
        $this->send($this->ada, $this->ben, 'one');
        $this->send($this->ben, $this->ada, 'two');
        $this->send($this->ada, $this->ben, 'three');

        $thread = $this->actingAs($this->ada)->getJson('/api/messages/'.$this->ben->id)->assertOk();

        $this->assertSame(['three', 'two', 'one'], array_column($thread->json('data'), 'body'));
    }

    public function test_conversations_are_private_to_their_two_people(): void
    {
        $this->send($this->ada, $this->ben, 'secret plans');

        $this->actingAs($this->lecturer)->getJson('/api/messages/'.$this->ada->id)->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->cy)->getJson('/api/messages/'.$this->ada->id)->assertOk()->assertJsonCount(0, 'data');
        $response = $this->actingAs($this->lecturer)->getJson('/api/messages')->assertOk();
        $this->assertStringNotContainsString('secret plans', $response->getContent());
        // Snooping does not mark someone else's messages as read.
        $this->assertNull(DirectMessage::first()->read_at);
    }

    public function test_messaging_requires_signing_in(): void
    {
        $this->getJson('/api/messages')->assertUnauthorized();
        $this->postJson('/api/messages/'.$this->ada->id, ['body' => 'hi'])->assertUnauthorized();
    }
}
