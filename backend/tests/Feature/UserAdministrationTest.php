<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AppealFiled;
use App\Support\SecurityLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private User $root;

    private User $uniAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->root = $this->userWithRole('super-admin');
        $this->uniAdmin = $this->userWithRole('university-admin');
    }

    private function as(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    // ---- roles and account changes -----------------------------------------------------------------------------------

    public function test_an_administrator_can_change_someones_role_email_and_name(): void
    {
        $person = $this->userWithRole('student', ['email' => 'old@example.test']);

        $this->as($this->uniAdmin)->patchJson("/api/users/{$person->id}", ['role' => 'lecturer', 'email' => 'New@Example.test', 'name' => 'Renamed'])->assertOk()
            ->assertJsonPath('roles.0.name', 'lecturer')->assertJsonPath('email', 'new@example.test')->assertJsonPath('name', 'Renamed');

        $this->assertTrue($person->fresh()->hasRole('lecturer'));
        $this->assertFalse($person->fresh()->hasRole('student'));
        $entry = DB::table('activity_log')->where('description', 'user updated')->latest('id')->first();
        $this->assertSame(['from' => 'student', 'to' => 'lecturer'], json_decode($entry->properties, true)['role'] ?? null ? ['from' => 'student', 'to' => 'lecturer'] : null);
        $this->assertSame(1, DB::table('security_events')->where('event', 'account.role_changed')->count());
    }

    public function test_only_a_super_administrator_can_grant_or_change_the_super_administrator_role(): void
    {
        $person = $this->userWithRole('lecturer');

        $this->as($this->uniAdmin)->patchJson("/api/users/{$person->id}", ['role' => 'super-admin'])->assertForbidden();
        $this->as($this->uniAdmin)->patchJson("/api/users/{$this->root->id}", ['name' => 'Taken over'])->assertForbidden();
        $this->as($this->root)->patchJson("/api/users/{$person->id}", ['role' => 'super-admin'])->assertOk();
    }

    public function test_nobody_changes_their_own_role_or_deactivates_or_deletes_themselves(): void
    {
        $this->as($this->uniAdmin)->patchJson("/api/users/{$this->uniAdmin->id}", ['role' => 'student'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->as($this->uniAdmin)->patchJson("/api/users/{$this->uniAdmin->id}", ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->as($this->root)->deleteJson("/api/users/{$this->root->id}")->assertUnprocessable();

        $this->assertTrue($this->uniAdmin->fresh()->hasRole('university-admin'));
        $this->assertTrue($this->uniAdmin->fresh()->is_active);
    }

    public function test_the_last_active_super_administrator_cannot_be_demoted_deactivated_or_deleted(): void
    {
        // Only reachable by someone who is still signed in although their own account has since been switched off.
        $stale = $this->userWithRole('super-admin', ['is_active' => false]);

        $this->as($stale)->patchJson("/api/users/{$this->root->id}", ['role' => 'lecturer'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->as($stale)->patchJson("/api/users/{$this->root->id}", ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->as($stale)->deleteJson("/api/users/{$this->root->id}")->assertUnprocessable();

        $second = $this->userWithRole('super-admin');
        $this->as($second)->patchJson("/api/users/{$this->root->id}", ['is_active' => false])->assertOk(); // now there is another one
        $this->assertFalse($this->root->fresh()->is_active);
    }

    public function test_an_email_must_stay_unique_ignoring_case_and_the_old_reset_links_stop_working(): void
    {
        $person = $this->userWithRole('student', ['email' => 'a@example.test']);
        $this->userWithRole('student', ['email' => 'taken@example.test']);
        DB::table('password_reset_tokens')->insert(['email' => 'a@example.test', 'token' => 'x', 'created_at' => now()]);

        $this->as($this->uniAdmin)->patchJson("/api/users/{$person->id}", ['email' => 'TAKEN@example.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->as($this->uniAdmin)->patchJson("/api/users/{$person->id}", ['email' => 'A@Example.test'])->assertOk(); // the same address in another case is not a change
        $this->as($this->uniAdmin)->patchJson("/api/users/{$person->id}", ['email' => 'b@example.test'])->assertOk();

        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'a@example.test')->count());
    }

    public function test_only_people_who_manage_users_can_do_any_of_this(): void
    {
        $person = $this->userWithRole('student');
        foreach (['student', 'lecturer', 'registrar', 'department-admin'] as $role) {
            $user = $this->userWithRole($role);
            $this->as($user)->patchJson("/api/users/{$person->id}", ['role' => 'lecturer'])->assertForbidden();
            $this->as($user)->getJson("/api/users/{$person->id}")->assertForbidden();
            $this->as($user)->postJson("/api/users/{$person->id}/unlock")->assertForbidden();
            $this->as($user)->deleteJson("/api/users/{$person->id}")->assertForbidden();
            $this->as($user)->getJson("/api/users/{$person->id}/export")->assertForbidden();
            $this->as($user)->getJson('/api/security/events')->assertForbidden();
        }
    }

    // ---- support -----------------------------------------------------------------------------------------------------

    public function test_the_support_view_shows_why_someone_cannot_get_in_and_what_they_are_part_of(): void
    {
        $offering = $this->offering();
        $student = $this->userWithRole('student', ['last_login_at' => now()->subDay()]);
        $this->enrol($offering, $student);
        $student->createToken('api');
        SecurityLog::event('login.failed', ['email' => SecurityLog::emailFingerprint($student->email), 'user_id' => $student->id], 'warning');

        $view = $this->as($this->uniAdmin)->getJson("/api/users/{$student->id}")->assertOk();

        $view->assertJsonPath('user.email', $student->email)->assertJsonPath('user.roles.0.name', 'student')->assertJsonPath('signin.locked', false)->assertJsonPath('signin.can_sign_in', true)
            ->assertJsonPath('enrolments.0.code', 'CSC101')->assertJsonCount(1, 'sessions')->assertJsonPath('sessions.0.kind', 'Password')->assertJsonPath('events.0.event', 'login.failed')->assertJsonPath('records.submissions', 0);
    }

    public function test_a_locked_out_account_is_shown_as_locked_and_can_be_unlocked(): void
    {
        $victim = $this->userWithRole('student');
        foreach (range(1, 5) as $i) {
            $this->travel($i)->minutes(); // stay under the per-minute limit on sign-in attempts
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/login', ['email' => $victim->email, 'password' => 'wrong-'.$i]);
        }
        $this->travel(6)->minutes();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => $victim->email, 'password' => 'password'])->assertStatus(429);

        $this->as($this->uniAdmin)->getJson("/api/users/{$victim->id}")->assertJsonPath('signin.locked', true)->assertJsonPath('signin.can_sign_in', false);
        $this->as($this->uniAdmin)->postJson("/api/users/{$victim->id}/unlock")->assertOk();
        $this->as($this->uniAdmin)->getJson("/api/users/{$victim->id}")->assertJsonPath('signin.locked', false);

        $this->travel(2)->minutes();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => $victim->email, 'password' => 'password'])->assertOk();
        $this->assertSame(1, DB::table('security_events')->where('event', 'account.unlocked')->count());
    }

    public function test_an_administrator_can_email_a_reset_link_but_only_to_active_accounts(): void
    {
        Notification::fake();
        $person = $this->userWithRole('student');
        $gone = $this->userWithRole('student', ['is_active' => false]);

        $this->as($this->uniAdmin)->postJson("/api/users/{$person->id}/reset-link")->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, $person->email));
        $this->as($this->uniAdmin)->postJson("/api/users/{$gone->id}/reset-link")->assertUnprocessable();

        Notification::assertSentTo($person, ResetPassword::class);
        Notification::assertNotSentTo($gone, ResetPassword::class);
    }

    public function test_all_of_someones_sessions_can_be_ended_at_once(): void
    {
        $person = $this->userWithRole('student');
        $token = $person->createToken('api')->plainTextToken;
        $person->createToken('sso');
        $this->assertSame(2, $person->tokens()->count());

        $this->as($this->uniAdmin)->postJson("/api/users/{$person->id}/sessions/revoke")->assertOk()->assertJsonPath('revoked', 2);

        $this->assertSame(0, $person->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_changing_a_role_takes_effect_on_the_persons_very_next_request(): void
    {
        $person = $this->userWithRole('student');
        $this->as($person)->getJson('/api/users')->assertForbidden();

        $this->as($this->uniAdmin)->patchJson("/api/users/{$person->id}", ['role' => 'registrar'])->assertOk();

        $this->as($person->fresh())->getJson('/api/users')->assertOk();
    }

    // ---- delete, export, anonymise -----------------------------------------------------------------------------------

    public function test_an_account_that_never_produced_anything_can_be_deleted(): void
    {
        $person = $this->userWithRole('student');
        $person->createToken('api');

        $this->as($this->uniAdmin)->deleteJson("/api/users/{$person->id}")->assertOk();

        $this->assertNull(User::find($person->id));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $person->id)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $person->id)->count());
        $this->assertSame(1, DB::table('activity_log')->where('description', 'user deleted')->count());
    }

    public function test_an_account_with_records_cannot_be_deleted_and_the_reason_is_given(): void
    {
        $offering = $this->offering();
        $student = $this->userWithRole('student');
        $this->enrol($offering, $student);
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $assignment->submissions()->create(['user_id' => $student->id, 'body' => 'x', 'submitted_at' => now()]);

        $this->as($this->uniAdmin)->deleteJson("/api/users/{$student->id}")->assertUnprocessable()->assertJsonPath('errors.user.0', fn ($m) => str_contains($m, '1 submissions'));
        $this->as($this->uniAdmin)->deleteJson("/api/users/{$this->uniAdmin->id}")->assertUnprocessable();

        $this->assertNotNull(User::find($student->id));
    }

    public function test_a_persons_data_can_be_exported_and_the_export_is_recorded(): void
    {
        $offering = $this->offering();
        $student = $this->userWithRole('student', ['name' => 'Ada Export']);
        $teacher = $this->userWithRole('lecturer');
        $this->enrol($offering, $student);
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $submission = $assignment->submissions()->create(['user_id' => $student->id, 'body' => 'My essay', 'storage_path' => 'submissions/1/2/file.txt', 'submitted_at' => now()]);
        $submission->gradeRecords()->create(['graded_by' => $teacher->id, 'score' => 7, 'status' => 'published', 'feedback' => 'Good']);
        $submission->gradeRecords()->create(['graded_by' => $teacher->id, 'score' => 9, 'status' => 'draft']);
        DB::table('direct_messages')->insert(['sender_id' => $student->id, 'recipient_id' => $teacher->id, 'body' => 'hello sir', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('direct_messages')->insert(['sender_id' => $teacher->id, 'recipient_id' => $student->id, 'body' => 'private reply', 'created_at' => now(), 'updated_at' => now()]);

        $export = $this->as($this->uniAdmin)->getJson("/api/users/{$student->id}/export")->assertOk();

        $export->assertJsonPath('profile.name', 'Ada Export')->assertJsonPath('enrolments.0.code', 'CSC101')->assertJsonPath('submissions.0.body', 'My essay')->assertJsonPath('submissions.0.file', 'file.txt')
            ->assertJsonCount(1, 'grades')->assertJsonPath('grades.0.score', 7)->assertJsonPath('messages_sent.0.body', 'hello sir')->assertJsonPath('messages_received_count', 1);
        $this->assertStringNotContainsString('private reply', $export->getContent(), "other people's messages are not handed over");
        $this->assertStringNotContainsString('draft', json_encode($export->json('grades')));
        $this->assertSame(1, DB::table('activity_log')->where('description', 'user data exported')->count());
    }

    public function test_anonymising_replaces_who_someone_is_but_keeps_the_academic_record(): void
    {
        Storage::fake('s3');
        $offering = $this->offering();
        $student = $this->userWithRole('student', ['name' => 'Ada Erased', 'email' => 'ada@example.test']);
        $teacher = $this->userWithRole('lecturer');
        $this->enrol($offering, $student);
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $submission = $assignment->submissions()->create(['user_id' => $student->id, 'body' => 'work', 'submitted_at' => now()]);
        $submission->gradeRecords()->create(['graded_by' => $teacher->id, 'score' => 8, 'status' => 'published']);
        $message = DB::table('direct_messages')->insertGetId(['sender_id' => $student->id, 'recipient_id' => $teacher->id, 'body' => 'personal details', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('message_attachments')->insert(['direct_message_id' => $message, 'original_name' => 'id.pdf', 'storage_path' => "message-attachments/{$message}/id.pdf", 'mime_type' => 'application/pdf', 'size' => 4, 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('s3')->put("message-attachments/{$message}/id.pdf", 'data');
        $student->createToken('api');
        $student->notify(new AppealFiled(1, 1, 'Essay'));

        $this->as($this->uniAdmin)->postJson("/api/users/{$student->id}/anonymise", ['confirm_email' => 'ada@example.test'])->assertForbidden(); // needs manage-system
        $this->as($this->root)->postJson("/api/users/{$student->id}/anonymise", ['confirm_email' => 'someone.else@example.test'])->assertUnprocessable()->assertJsonValidationErrors('confirm_email');
        $this->as($this->root)->postJson("/api/users/{$student->id}/anonymise", ['confirm_email' => 'ADA@example.test'])->assertOk();

        $student->refresh();
        $this->assertSame("Former user #{$student->id}", $student->name);
        $this->assertSame("anonymised-{$student->id}@invalid.example", $student->email);
        $this->assertFalse($student->is_active);
        $this->assertNotNull($student->anonymised_at);
        $this->assertSame(0, $student->tokens()->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $student->id)->count());
        $this->assertSame('[removed]', DB::table('direct_messages')->where('id', $message)->value('body'));
        $this->assertSame(0, DB::table('message_attachments')->count());
        Storage::disk('s3')->assertMissing("message-attachments/{$message}/id.pdf");
        $this->assertSame(1, DB::table('submissions')->where('user_id', $student->id)->count(), 'the submission is still there');
        $this->assertSame(1, DB::table('grade_records')->count(), 'and so is the grade');
        $this->postJson('/api/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertUnprocessable();
        $this->as($this->root)->patchJson("/api/users/{$student->id}", ['name' => 'Back again'])->assertUnprocessable();
        $this->as($this->root)->postJson("/api/users/{$student->id}/anonymise", ['confirm_email' => $student->email])->assertUnprocessable();
        $this->assertSame(1, DB::table('activity_log')->where('description', 'user anonymised')->count());
    }

    public function test_super_administrators_cannot_be_anonymised(): void
    {
        $other = $this->userWithRole('super-admin');

        $this->as($this->root)->postJson("/api/users/{$other->id}/anonymise", ['confirm_email' => $other->email])->assertUnprocessable();
        $this->as($this->root)->postJson("/api/users/{$this->root->id}/anonymise", ['confirm_email' => $this->root->email])->assertUnprocessable();
    }

    // ---- security events ---------------------------------------------------------------------------------------------

    public function test_sign_ins_are_recorded_and_can_be_searched_by_email_without_storing_the_email(): void
    {
        $student = $this->userWithRole('student');
        $this->postJson('/api/login', ['email' => $student->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->travel(2)->minutes();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => $student->email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])->assertUnprocessable();

        $this->assertFalse(DB::table('security_events')->where('email_hash', $student->email)->exists());
        $mine = $this->as($this->uniAdmin)->getJson('/api/security/events?email='.urlencode($student->email))->assertOk();
        $this->assertEqualsCanonicalizing(['login.failed', 'login.success'], collect($mine->json('data'))->pluck('event')->all());
        $this->assertSame($student->id, $mine->json('data.0.user_id') ?? $mine->json('data.1.user_id'));
        $this->as($this->uniAdmin)->getJson('/api/security/events?email=nobody@example.test&event=login.failed')->assertJsonCount(1, 'data');
        $this->as($this->uniAdmin)->getJson('/api/security/events?level=warning&from='.now()->toDateString().'&to='.now()->toDateString())->assertOk()->assertJsonPath('total', 2);
        $this->as($this->uniAdmin)->getJson('/api/security/events?from='.now()->addDays(2)->toDateString())->assertJsonPath('total', 0);
        $this->assertNotNull($student->fresh()->last_login_at, 'the last sign-in time is kept');
    }

    public function test_the_security_summary_counts_recent_activity(): void
    {
        SecurityLog::event('login.success');
        SecurityLog::event('login.failed', ['email' => 'abc'], 'warning');
        SecurityLog::event('login.failed', ['email' => 'abc'], 'warning');
        SecurityLog::event('login.locked', ['email' => 'abc'], 'warning');
        SecurityLog::event('throttle.hit', ['path' => 'x']);

        $summary = $this->as($this->uniAdmin)->getJson('/api/security/summary')->assertOk();

        $summary->assertJsonPath('last_24_hours.sign_ins', 1)->assertJsonPath('last_24_hours.failed_sign_ins', 2)->assertJsonPath('last_24_hours.lockouts', 1)->assertJsonPath('events.throttle.hit', null);
        $this->assertSame(0, DB::table('security_events')->where('event', 'throttle.hit')->count(), 'a flood of refused requests is not turned into a flood of database rows');
    }

    // ---- roles and permissions ---------------------------------------------------------------------------------------

    public function test_the_roles_screen_lists_every_role_with_its_permissions_and_how_many_hold_it(): void
    {
        $this->userWithRole('student');
        $this->userWithRole('student');

        $roles = collect($this->as($this->uniAdmin)->getJson('/api/roles')->assertOk()->json('roles'))->keyBy('name');

        $this->assertCount(7, $roles);
        $this->assertSame(2, $roles['student']['users']);
        $this->assertSame(['submit-assignments'], $roles['student']['permissions']);
        $this->assertTrue($roles['super-admin']['locked']);
        $this->assertContains('manage-system', $roles['super-admin']['permissions']);
        $this->assertNotContains('manage-system', $roles['university-admin']['permissions']);
        $this->as($this->userWithRole('student'))->getJson('/api/roles')->assertForbidden();
    }

    public function test_a_super_administrator_can_change_what_a_role_may_do_and_it_applies_at_once(): void
    {
        $registrar = $this->userWithRole('registrar');
        $this->as($registrar)->postJson('/api/terms', ['name' => 'T', 'starts_on' => '2027-01-01', 'ends_on' => '2027-06-01'])->assertForbidden();

        $this->as($this->root)->putJson('/api/roles/registrar/permissions', ['permissions' => ['manage-enrolments', 'manage-courses']])->assertOk()->assertJsonPath('permissions', ['manage-courses', 'manage-enrolments']);

        $this->as($registrar->fresh())->postJson('/api/terms', ['name' => 'T', 'starts_on' => '2027-01-01', 'ends_on' => '2027-06-01'])->assertCreated();
        $entry = DB::table('activity_log')->where('description', 'role permissions changed')->first();
        $this->assertSame(['manage-courses'], json_decode($entry->properties, true)['added']);
        $this->assertSame(1, DB::table('security_events')->where('event', 'role.permissions_changed')->count());

        $this->as($this->root)->postJson('/api/roles/registrar/reset')->assertOk()->assertJsonPath('permissions', ['manage-enrolments']);
        $this->as($registrar->fresh())->postJson('/api/terms', ['name' => 'T2', 'starts_on' => '2027-01-01', 'ends_on' => '2027-06-01'])->assertForbidden();
    }

    public function test_role_permissions_are_protected(): void
    {
        $this->as($this->uniAdmin)->putJson('/api/roles/registrar/permissions', ['permissions' => ['manage-courses']])->assertForbidden();
        $this->as($this->root)->putJson('/api/roles/super-admin/permissions', ['permissions' => []])->assertUnprocessable();
        $this->as($this->root)->putJson('/api/roles/registrar/permissions', ['permissions' => ['make-coffee']])->assertUnprocessable();
        $this->as($this->root)->putJson('/api/roles/nonsense/permissions', ['permissions' => []])->assertNotFound();

        $this->assertTrue(Role::findByName('super-admin', 'web')->hasPermissionTo('manage-users'));
    }
}
