<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class SupportRequestTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private User $ada;

    private User $ben;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->ada = $this->userWithRole('student');
        $this->ben = $this->userWithRole('student');
        $this->admin = $this->userWithRole('university-admin');
    }

    public function test_any_signed_in_user_can_file_a_support_request(): void
    {
        $this->actingAs($this->ada)->postJson('/api/support-requests', [
            'category' => 'sign_in', 'subject' => 'Cannot sign in', 'description' => 'My password keeps failing.',
        ])->assertCreated()->assertJsonPath('status', 'open')->assertJsonPath('category', 'sign_in');
    }

    public function test_filing_requires_a_known_category(): void
    {
        $this->actingAs($this->ada)->postJson('/api/support-requests', [
            'category' => 'not-a-category', 'subject' => 'x', 'description' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors('category');
    }

    public function test_a_non_admin_only_sees_their_own_requests(): void
    {
        $this->actingAs($this->ada)->postJson('/api/support-requests', ['category' => 'technical', 'subject' => 'Ada issue', 'description' => 'x']);
        $this->actingAs($this->ben)->postJson('/api/support-requests', ['category' => 'technical', 'subject' => 'Ben issue', 'description' => 'x']);

        $mine = $this->actingAs($this->ada)->getJson('/api/support-requests')->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame('Ada issue', $mine[0]['subject']);
    }

    public function test_an_admin_can_list_and_filter_every_request(): void
    {
        $this->actingAs($this->ada)->postJson('/api/support-requests', ['category' => 'sign_in', 'subject' => 'Ada issue', 'description' => 'x']);
        $this->actingAs($this->ben)->postJson('/api/support-requests', ['category' => 'submission', 'subject' => 'Ben issue', 'description' => 'x']);

        $all = $this->actingAs($this->admin)->getJson('/api/support-requests')->assertOk()->json('data');
        $this->assertCount(2, $all);

        $filtered = $this->actingAs($this->admin)->getJson('/api/support-requests?category=submission')->assertOk()->json('data');
        $this->assertCount(1, $filtered);
        $this->assertSame('Ben issue', $filtered[0]['subject']);
    }

    public function test_resolving_a_request_notifies_the_filer(): void
    {
        Notification::fake();
        $id = $this->actingAs($this->ada)->postJson('/api/support-requests', ['category' => 'technical', 'subject' => 'x', 'description' => 'x'])->json('id');

        $this->actingAs($this->admin)->patchJson('/api/support-requests/'.$id, ['status' => 'resolved', 'resolution_note' => 'Fixed it.'])
            ->assertOk()->assertJsonPath('status', 'resolved')->assertJsonPath('resolved_at', fn ($v) => $v !== null);

        Notification::assertSentTo($this->ada, \App\Notifications\SupportRequestResolved::class);
    }

    public function test_a_non_admin_cannot_update_a_request(): void
    {
        $id = $this->actingAs($this->ada)->postJson('/api/support-requests', ['category' => 'technical', 'subject' => 'x', 'description' => 'x'])->json('id');

        $this->actingAs($this->ben)->patchJson('/api/support-requests/'.$id, ['status' => 'resolved'])->assertForbidden();
    }

    public function test_assigning_to_someone_without_manage_users_is_rejected(): void
    {
        $id = $this->actingAs($this->ada)->postJson('/api/support-requests', ['category' => 'technical', 'subject' => 'x', 'description' => 'x'])->json('id');

        $this->actingAs($this->admin)->patchJson('/api/support-requests/'.$id, ['assigned_to' => $this->ben->id])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }
}
