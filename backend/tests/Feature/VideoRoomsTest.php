<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class VideoRoomsTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student');
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->session = $this->offering->sessions()->create(['title' => 'Lecture 1', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
    }

    private function enableVideo(): void
    {
        config(['lms.video.enabled' => true, 'lms.video.api_key' => 'test-key']);
    }

    private function fakeRoom(string $url = 'https://example.daily.co/session-1-abc'): void
    {
        Http::fake(['api.daily.co/*' => Http::response(['url' => $url, 'name' => 'session-1-abc'], 200)]);
    }

    public function test_video_is_off_by_default_and_the_join_address_says_so(): void
    {
        $this->actingAs($this->lecturer)->postJson("/api/sessions/{$this->session->id}/join")->assertStatus(503);
    }

    public function test_a_teacher_and_an_enrolled_student_can_join_but_a_stranger_cannot(): void
    {
        $this->enableVideo();
        $this->fakeRoom();

        $this->actingAs($this->lecturer)->postJson("/api/sessions/{$this->session->id}/join")->assertOk()->assertJsonPath('url', 'https://example.daily.co/session-1-abc');
        $this->actingAs($this->ada)->postJson("/api/sessions/{$this->session->id}/join")->assertOk()->assertJsonPath('url', 'https://example.daily.co/session-1-abc');
        $this->actingAs($this->userWithRole('student'))->postJson("/api/sessions/{$this->session->id}/join")->assertForbidden();
    }

    public function test_the_room_is_created_once_and_reused(): void
    {
        $this->enableVideo();
        $this->fakeRoom();

        $this->actingAs($this->lecturer)->postJson("/api/sessions/{$this->session->id}/join")->assertOk();
        Http::fake(['api.daily.co/*' => Http::response(['message' => 'should not be called again'], 500)]);
        $this->actingAs($this->ada)->postJson("/api/sessions/{$this->session->id}/join")->assertOk()->assertJsonPath('url', 'https://example.daily.co/session-1-abc');
        $this->assertSame('https://example.daily.co/session-1-abc', $this->session->fresh()->video_room_url);
    }

    public function test_a_failed_room_creation_is_reported_but_not_fatal(): void
    {
        $this->enableVideo();
        Http::fake(['api.daily.co/*' => Http::response(['error' => 'invalid-request-error'], 400)]);

        $this->actingAs($this->lecturer)->postJson("/api/sessions/{$this->session->id}/join")->assertStatus(503);
        $this->assertNull($this->session->fresh()->video_room_url);
    }

    public function test_auth_config_reports_whether_video_is_enabled(): void
    {
        $this->getJson('/api/auth/config')->assertOk()->assertJsonPath('video_enabled', false);
        $this->enableVideo();
        $this->getJson('/api/auth/config')->assertOk()->assertJsonPath('video_enabled', true);
    }
}
