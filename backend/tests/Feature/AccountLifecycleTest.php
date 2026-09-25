<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->create($attributes)->assignRole($role);
    }

    public function test_active_user_can_log_in_and_use_the_token(): void
    {
        $this->userWithRole('student', ['email' => 'ada@example.com', 'password' => 'a-long-password-1']);

        $token = $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'a-long-password-1'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('email', 'ada@example.com');
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $admin = $this->userWithRole('university-admin');
        $student = $this->userWithRole('student', ['email' => 'ada@example.com', 'password' => 'a-long-password-1']);

        $this->actingAs($admin)->patchJson('/api/users/'.$student->id, ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);

        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'a-long-password-1'])
            ->assertUnprocessable();
    }

    public function test_deactivating_a_user_revokes_their_tokens(): void
    {
        $admin = $this->userWithRole('university-admin');
        $student = $this->userWithRole('student');
        $student->createToken('api');

        $this->actingAs($admin)->patchJson('/api/users/'.$student->id, ['is_active' => false])->assertOk();

        $this->assertSame(0, $student->tokens()->count());
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->userWithRole('university-admin');

        $this->actingAs($admin)->patchJson('/api/users/'.$admin->id, ['is_active' => false])->assertUnprocessable();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_university_admin_cannot_modify_a_super_admin_and_students_cannot_modify_anyone(): void
    {
        $admin = $this->userWithRole('university-admin');
        $super = $this->userWithRole('super-admin');
        $student = $this->userWithRole('student');

        $this->actingAs($admin)->patchJson('/api/users/'.$super->id, ['is_active' => false])->assertForbidden();
        $this->actingAs($student)->patchJson('/api/users/'.$admin->id, ['name' => 'Hacked'])->assertForbidden();
        $this->assertTrue($super->fresh()->is_active);
    }

    public function test_tokens_expire(): void
    {
        $student = $this->userWithRole('student');
        $token = $student->createToken('api')->plainTextToken;

        $this->travel(config('sanctum.expiration') + 1)->minutes();

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }
}
