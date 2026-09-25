<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\GradePublished;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['lms.limits.server_max_mb' => 50]); // do not depend on the PHP limits of the machine running the tests
        $this->admin = $this->userWithRole('super-admin');
    }

    private function save(array $settings, array $reset = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->putJson('/api/settings', ['settings' => $settings, 'reset' => $reset]);
    }

    public function test_only_administrators_with_the_settings_permission_can_see_or_change_settings(): void
    {
        foreach (['student', 'lecturer', 'registrar', 'department-admin'] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->getJson('/api/settings')->assertForbidden();
            $this->actingAs($user)->putJson('/api/settings', ['settings' => ['institution.name' => 'Hacked']])->assertForbidden();
        }
        $this->actingAs($this->userWithRole('university-admin'))->getJson('/api/settings')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/settings')->assertUnauthorized();
    }

    public function test_settings_are_listed_in_groups_with_their_value_and_default(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/settings')->assertOk();
        $groups = collect($response->json('groups'));
        $this->assertEqualsCanonicalizing(['Institution', 'Academic rules', 'Files and storage', 'Security', 'Notifications', 'Privacy and retention', 'Backups', 'Maintenance'], $groups->pluck('group')->all());
        $name = $groups->firstWhere('group', 'Institution')['settings'][0];
        $this->assertSame('institution.name', $name['key']);
        $this->assertSame('University LMS', $name['value']);
        $this->assertFalse($name['overridden']);
    }

    public function test_the_institution_details_reach_the_sign_in_page_and_emails(): void
    {
        $this->save(['institution.name' => 'Riverbend University', 'institution.support_email' => 'help@riverbend.test', 'institution.timezone' => 'Africa/Harare', 'privacy.privacy_url' => 'https://riverbend.test/privacy'])->assertOk()->assertJsonPath('changed', ['institution.name', 'institution.support_email', 'institution.timezone', 'privacy.privacy_url']);

        $this->app['auth']->forgetGuards();
        $config = $this->getJson('/api/auth/config')->assertOk();
        $config->assertJsonPath('institution.name', 'Riverbend University')->assertJsonPath('institution.support_email', 'help@riverbend.test')->assertJsonPath('institution.timezone', 'Africa/Harare')->assertJsonPath('privacy_url', 'https://riverbend.test/privacy');
        $this->assertSame('Riverbend University', config('app.name'), 'emails use the institution name');
    }

    public function test_saved_settings_are_laid_over_the_configuration_when_the_app_starts(): void
    {
        DB::table('settings')->insert([
            ['key' => 'academic.appeal_window_days', 'value' => '30', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'limits.upload_types', 'value' => '["pdf","txt"]', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'limits.message_attachment_mb', 'value' => '3', 'created_at' => now(), 'updated_at' => now()],
        ]);
        Cache::forget(Settings::CACHE_KEY);

        app(Settings::class)->apply();

        $this->assertSame(30, config('lms.appeals.window_days'));
        $this->assertSame('pdf,txt', config('lms.upload_mimes'));
        $this->assertSame(3072, config('lms.messages.max_attachment_kb'));
    }

    public function test_an_invalid_value_is_refused_and_nothing_at_all_is_saved(): void
    {
        $this->save(['institution.name' => 'New name', 'security.password_min_length' => 3, 'institution.timezone' => 'Mars/Olympus'])->assertUnprocessable()->assertJsonValidationErrors(['security.password_min_length', 'institution.timezone']);

        $this->assertSame(0, DB::table('settings')->count());
        $this->assertSame('University LMS', config('lms.institution.name'));
    }

    public function test_unknown_settings_and_unsafe_file_types_are_refused(): void
    {
        $this->save(['made.up' => 1])->assertUnprocessable();
        $this->save(['limits.upload_types' => ['pdf', 'html']])->assertUnprocessable()->assertJsonValidationErrors(['limits.upload_types.1']);
        $this->save(['limits.upload_types' => ['svg']])->assertUnprocessable();
        $this->save(['limits.upload_types' => []])->assertUnprocessable();
    }

    public function test_the_upload_size_cannot_exceed_what_the_server_accepts(): void
    {
        config(['lms.limits.server_max_mb' => 26]);
        $this->save(['limits.upload_mb' => 27])->assertUnprocessable()->assertJsonValidationErrors(['limits.upload_mb']);
        $this->save(['limits.upload_mb' => 26])->assertOk();
    }

    public function test_a_setting_can_be_put_back_to_its_default(): void
    {
        $this->save(['academic.appeal_window_days' => 60])->assertOk();
        $this->assertSame(60, config('lms.appeals.window_days'));

        $this->save([], ['academic.appeal_window_days'])->assertOk()->assertJsonPath('changed', ['academic.appeal_window_days']);

        $this->assertSame(14, config('lms.appeals.window_days'));
        $this->assertSame(0, DB::table('settings')->count());
    }

    public function test_every_change_is_recorded_with_who_made_it_and_what_it_was(): void
    {
        $this->save(['academic.appeal_window_days' => 7])->assertOk();

        $entry = DB::table('activity_log')->where('description', 'settings changed')->first();
        $this->assertSame($this->admin->id, (int) $entry->causer_id);
        $changes = json_decode($entry->properties, true)['changes'];
        $this->assertSame(['from' => 14, 'to' => 7], $changes['academic.appeal_window_days']);
    }

    public function test_security_and_maintenance_changes_are_also_flagged_as_security_events(): void
    {
        $this->save(['security.lockout_attempts' => 4])->assertOk();

        $this->assertSame(1, DB::table('security_events')->where('event', 'settings.security_changed')->count());
    }

    public function test_the_password_policy_applies_to_new_accounts_and_password_changes(): void
    {
        $this->save(['security.password_min_length' => 10, 'security.password_mixed_case' => true, 'security.password_number' => true, 'security.password_symbol' => true])->assertOk();
        $create = fn (string $password) => $this->actingAs($this->admin)->postJson('/api/users', ['name' => 'X', 'email' => uniqid().'@example.test', 'password' => $password, 'role' => 'student']);

        $create('short1!A')->assertUnprocessable()->assertJsonValidationErrors('password');
        $create('alllowercase1!')->assertUnprocessable();
        $create('NoDigitsHere!!')->assertUnprocessable();
        $create('NoSymbols1234')->assertUnprocessable();
        $create('Good-Passw0rd')->assertCreated();

        $user = $this->userWithRole('student');
        $this->actingAs($user)->postJson('/api/me/password', ['current_password' => 'password', 'password' => 'weakweakweak', 'password_confirmation' => 'weakweakweak'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->actingAs($user)->postJson('/api/me/password', ['current_password' => 'password', 'password' => 'Str0ng-Enough!', 'password_confirmation' => 'Str0ng-Enough!'])->assertOk();
    }

    public function test_the_policy_is_published_so_a_screen_can_tell_people_the_rules(): void
    {
        $this->save(['security.password_min_length' => 14, 'security.password_number' => true])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/auth/config')->assertJsonPath('password_policy', ['min_length' => 14, 'mixed_case' => false, 'number' => true, 'symbol' => false]);
    }

    public function test_the_lockout_rules_are_the_ones_the_administrator_set(): void
    {
        $this->save(['security.lockout_attempts' => 3, 'security.lockout_minutes' => 30])->assertOk();
        $victim = $this->userWithRole('student');
        $this->app['auth']->forgetGuards();
        $this->travel(0)->minutes();
        foreach (range(1, 3) as $i) {
            $this->travel($i)->minutes(); // stay under the per-minute request limit
            $this->postJson('/api/login', ['email' => $victim->email, 'password' => 'wrong-'.$i])->assertUnprocessable();
        }
        $this->travel(5)->minutes();

        $this->postJson('/api/login', ['email' => $victim->email, 'password' => 'password'])->assertStatus(429)->assertJsonPath('message', fn ($m) => str_contains($m, '30 minute'));
    }

    public function test_uploads_follow_the_size_and_types_the_administrator_allows(): void
    {
        Storage::fake('s3');
        $offering = $this->offering();
        $teacher = $this->userWithRole('lecturer');
        $this->teach($offering, $teacher);
        $module = $offering->modules()->create(['title' => 'W1', 'published' => true]);
        $add = fn (UploadedFile $file) => $this->actingAs($teacher)->post("/api/modules/{$module->id}/items", ['title' => 'F', 'type' => 'file', 'file' => $file], ['Accept' => 'application/json']);

        $add(UploadedFile::fake()->create('big.pdf', 2048, 'application/pdf'))->assertCreated();

        $this->save(['limits.upload_mb' => 1, 'limits.upload_types' => ['pdf']])->assertOk();

        $add(UploadedFile::fake()->create('big.pdf', 2048, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $add(UploadedFile::fake()->create('ok.pdf', 500, 'application/pdf'))->assertCreated();
        $add(UploadedFile::fake()->create('notes.txt', 10, 'text/plain'))->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_email_notifications_can_be_switched_off_but_the_in_app_copy_remains(): void
    {
        $submission = $this->submissionFixture();
        $notification = GradePublished::for($submission, $submission->assignment);
        $this->assertSame(['database', 'mail'], $notification->via($submission->user));

        $this->save(['notifications.email_enabled' => false])->assertOk();

        $this->assertSame(['database'], $notification->via($submission->user));
    }

    public function test_maintenance_mode_lets_only_super_administrators_in_and_says_why(): void
    {
        $student = $this->userWithRole('student');
        $registrar = $this->userWithRole('university-admin');
        $this->save(['maintenance.enabled' => true, 'maintenance.message' => 'Back at 6pm.'])->assertOk();

        $this->actingAs($student)->getJson('/api/offerings')->assertStatus(503)->assertJsonPath('message', 'Back at 6pm.')->assertJsonPath('maintenance', true)->assertHeader('Retry-After');
        $this->app['auth']->forgetGuards(); // a real request is a fresh process; in a test, the previous person would be remembered
        $this->actingAs($registrar)->getJson('/api/users')->assertStatus(503);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)->getJson('/api/users')->assertOk();
        $this->assertNull($this->getJson('/api/health')->json('maintenance'), 'the health check is not blocked by maintenance mode (whatever it reports about the machine)');
        $this->getJson('/api/auth/config')->assertOk()->assertJsonPath('maintenance.enabled', true)->assertJsonPath('maintenance.message', 'Back at 6pm.');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => $student->email, 'password' => 'password'])->assertStatus(503)->assertJsonMissingPath('token');
        $this->postJson('/api/login', ['email' => $this->admin->email, 'password' => 'password'])->assertOk()->assertJsonStructure(['token']);

        $this->save(['maintenance.enabled' => false])->assertOk();
        $this->actingAs($student)->getJson('/api/offerings')->assertOk();
    }

    public function test_the_permission_seeder_can_be_rerun_without_undoing_changes_an_administrator_made(): void
    {
        $role = Role::findByName('registrar', 'web');
        $role->givePermissionTo('manage-courses');

        $this->seed(DatabaseSeeder::class);

        $this->assertTrue($role->fresh()->hasPermissionTo('manage-courses'));
        $this->assertTrue(Role::findByName('super-admin', 'web')->hasPermissionTo('manage-system'));
        $this->assertFalse(Role::findByName('university-admin', 'web')->hasPermissionTo('manage-system'));
    }

    private function submissionFixture()
    {
        $offering = $this->offering();
        $student = $this->userWithRole('student');
        $this->enrol($offering, $student);
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);

        return $assignment->submissions()->create(['user_id' => $student->id, 'body' => 'x', 'submitted_at' => now()])->load('user', 'assignment');
    }
}
