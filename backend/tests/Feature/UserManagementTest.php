<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_create_users_and_university_admin_cannot_create_super_admin(): void
    {
        $this->seed(DatabaseSeeder::class);
        $student = User::factory()->create();
        $student->assignRole('student');
        $admin = User::factory()->create();
        $admin->assignRole('university-admin');
        $payload = ['name' => 'New User', 'email' => 'new@example.com', 'password' => 'secure-password-123', 'role' => 'super-admin'];

        $this->actingAs($student)->postJson('/api/users', $payload)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/users', $payload)->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }
}
