<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class PasswordAndProfileTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private const OLD = 'old-password-123';

    private const NEW = 'brand-new-password-456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function student(array $attributes = []): User
    {
        return $this->userWithRole('student', $attributes + ['email' => 'ada@example.com', 'password' => self::OLD]);
    }

    public function test_users_can_rename_themselves_but_nothing_else(): void
    {
        $user = $this->student();

        $this->actingAs($user)->patchJson('/api/me', ['name' => 'Ada L.', 'email' => 'other@example.com', 'is_active' => false])->assertOk()->assertJsonPath('name', 'Ada L.');

        $this->assertSame('ada@example.com', $user->fresh()->email);
        $this->actingAs($user)->patchJson('/api/me', ['name' => ''])->assertJsonValidationErrors('name');
    }

    public function test_changing_password_needs_the_current_one_and_signs_out_other_devices(): void
    {
        $user = $this->student();
        $keep = $user->createToken('this device')->plainTextToken;
        $user->createToken('other device');
        $payload = ['current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => self::NEW];

        $this->withToken($keep)->postJson('/api/me/password', ['current_password' => 'wrong-password-1'] + $payload)->assertJsonValidationErrors('current_password');
        $this->withToken($keep)->postJson('/api/me/password', array_merge($payload, ['password_confirmation' => 'mismatch-mismatch']))->assertJsonValidationErrors('password');
        $this->withToken($keep)->postJson('/api/me/password', array_merge($payload, ['password' => self::OLD, 'password_confirmation' => self::OLD]))->assertJsonValidationErrors('password');
        $this->withToken($keep)->postJson('/api/me/password', array_merge($payload, ['password' => 'short', 'password_confirmation' => 'short']))->assertJsonValidationErrors('password');
        $this->assertSame(2, $user->tokens()->count());

        $this->withToken($keep)->postJson('/api/me/password', $payload)->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($keep)->getJson('/api/me')->assertOk();
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => self::OLD])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => self::NEW])->assertOk();
    }

    public function test_forgot_password_answers_identically_and_only_mails_active_accounts(): void
    {
        Notification::fake();
        $active = $this->student();
        $inactive = $this->student(['email' => 'gone@example.com']);
        $inactive->forceFill(['is_active' => false])->save();

        $known = $this->postJson('/api/forgot-password', ['email' => 'ada@example.com'])->assertOk();
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        $this->postJson('/api/forgot-password', ['email' => 'gone@example.com'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        Notification::assertSentTo($active, ResetPassword::class);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_the_reset_link_points_at_the_frontend(): void
    {
        Notification::fake();
        config(['lms.frontend_url' => 'https://lms.example.edu/']);
        $user = $this->student();

        $this->postJson('/api/forgot-password', ['email' => 'ada@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            return str_starts_with($n->toMail($user)->actionUrl, 'https://lms.example.edu/reset-password?token=')
                && str_contains($n->toMail($user)->actionUrl, 'email=ada%40example.com');
        });
    }

    public function test_a_valid_token_resets_the_password_once_and_revokes_sessions(): void
    {
        $user = $this->student();
        $user->createToken('device');
        $token = Password::createToken($user);
        $payload = ['token' => $token, 'email' => 'ada@example.com', 'password' => self::NEW, 'password_confirmation' => self::NEW];

        $this->postJson('/api/reset-password', $payload)->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => self::NEW])->assertOk();
        $this->postJson('/api/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_bad_tokens_and_weak_passwords_are_rejected(): void
    {
        $user = $this->student();
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', ['token' => 'not-the-token', 'email' => 'ada@example.com', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertJsonValidationErrors('email');
        $this->postJson('/api/reset-password', ['token' => $token, 'email' => 'ada@example.com', 'password' => 'short', 'password_confirmation' => 'short'])->assertJsonValidationErrors('password');
        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => self::OLD])->assertOk();
    }

    public function test_reset_tokens_expire(): void
    {
        $user = $this->student();
        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/reset-password', ['token' => $token, 'email' => 'ada@example.com', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertJsonValidationErrors('email');
    }
}
