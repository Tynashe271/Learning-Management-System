<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountInvitation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = $this->userWithRole('university-admin');
    }

    private function csv(string $content, string $name = 'users.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function importUsers(string $content, array $fields = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post('/api/users/import', ['file' => $this->csv($content)] + $fields, ['Accept' => 'application/json']);
    }

    public function test_users_are_created_from_a_csv_with_roles_and_invitations(): void
    {
        Notification::fake();

        $response = $this->importUsers("name,email,role\nAda Lovelace,ada@uni.test,student\nGrace Hopper,grace@uni.test,lecturer\n")->assertOk();

        $response->assertJsonPath('created', 2)->assertJsonPath('invited', true)->assertJsonCount(0, 'errors');
        $ada = User::where('email', 'ada@uni.test')->first();
        $this->assertTrue($ada->hasRole('student'));
        $this->assertTrue(User::where('email', 'grace@uni.test')->first()->hasRole('lecturer'));
        $this->assertTrue($ada->is_active);
        Notification::assertSentTo($ada, AccountInvitation::class);
        Notification::assertCount(2);
        $this->assertDatabaseHas('activity_log', ['description' => 'users imported']);
    }

    public function test_bad_rows_are_reported_by_line_and_the_good_rows_are_still_created(): void
    {
        $this->userWithRole('student', ['email' => 'taken@uni.test']);
        Notification::fake();
        $csv = "name,email,role\n"
            ."Ada,ada@uni.test,student\n"        // line 2: fine
            ."Grace,grace@uni.test,lecturer\n"   // line 3: fine
            ."Bad Email,not-an-email,student\n"  // line 4
            ."Wrong Role,wrong@uni.test,wizard\n" // line 5
            ."Twin,ADA@uni.test,student\n"       // line 6: same email as line 2, different case
            ."Existing,taken@uni.test,student\n" // line 7
            .",blank@uni.test,student\n"         // line 8: no name
            ."Root,root@uni.test,super-admin\n"; // line 9: an ordinary admin cannot create one

        $response = $this->importUsers($csv)->assertOk();

        $response->assertJsonPath('created', 2)->assertJsonCount(6, 'errors');
        $byLine = collect($response->json('errors'))->keyBy('line');
        $this->assertSame([4, 5, 6, 7, 8, 9], $byLine->keys()->all());
        $this->assertStringContainsString('more than once', $byLine[6]['errors'][0]);
        $this->assertStringContainsString('already exists', $byLine[7]['errors'][0]);
        $this->assertStringContainsString('super-admin', $byLine[9]['errors'][0]);
        $this->assertDatabaseMissing('users', ['email' => 'root@uni.test']);
        $this->assertSame(2 + 2, User::count()); // admin + taken + ada + grace
    }

    public function test_a_super_admin_can_import_other_super_admins(): void
    {
        Notification::fake();

        $this->importUsers("name,email,role\nRoot,root@uni.test,super-admin\n", [], $this->userWithRole('super-admin'))->assertOk()->assertJsonPath('created', 1);

        $this->assertTrue(User::where('email', 'root@uni.test')->first()->hasRole('super-admin'));
    }

    public function test_a_dry_run_checks_the_file_without_creating_anything(): void
    {
        Notification::fake();
        $before = User::count();

        $response = $this->importUsers("name,email,role\nAda,ada@uni.test,student\nBad,nope,student\n", ['dry_run' => '1'])->assertOk();

        $response->assertJsonPath('dry_run', true)->assertJsonPath('created', 0)->assertJsonPath('would_create', 1)->assertJsonCount(1, 'errors');
        $this->assertSame($before, User::count());
        Notification::assertNothingSent();
    }

    public function test_invitations_can_be_switched_off(): void
    {
        Notification::fake();

        $this->importUsers("name,email,role\nAda,ada@uni.test,student\n", ['send_invitations' => '0'])->assertOk()->assertJsonPath('created', 1)->assertJsonPath('invited', false);

        Notification::assertNothingSent();
    }

    public function test_the_welcome_link_lets_the_new_user_choose_a_password_for_a_week(): void
    {
        Notification::fake();
        $this->importUsers("name,email,role\nAda,ada@uni.test,student\n")->assertOk();
        $token = null;
        Notification::assertSentTo(User::where('email', 'ada@uni.test')->first(), AccountInvitation::class, function (AccountInvitation $n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $newPassword = 'my-own-new-password-1';

        $this->travel(3)->days();
        $this->postJson('/api/reset-password', ['token' => $token, 'email' => 'ada@uni.test', 'password' => $newPassword, 'password_confirmation' => $newPassword])->assertOk();

        $this->postJson('/api/login', ['email' => 'ada@uni.test', 'password' => $newPassword])->assertOk()->assertJsonPath('roles.0', 'student');
        $this->postJson('/api/reset-password', ['token' => $token, 'email' => 'ada@uni.test', 'password' => $newPassword.'x', 'password_confirmation' => $newPassword.'x'])->assertJsonValidationErrors('email');
    }

    public function test_the_welcome_link_expires_after_a_week(): void
    {
        Notification::fake();
        $this->importUsers("name,email,role\nAda,ada@uni.test,student\n")->assertOk();
        $token = null;
        Notification::assertSentTo(User::where('email', 'ada@uni.test')->first(), AccountInvitation::class, function (AccountInvitation $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->travel(8)->days();

        $this->postJson('/api/reset-password', ['token' => $token, 'email' => 'ada@uni.test', 'password' => 'my-own-new-password-1', 'password_confirmation' => 'my-own-new-password-1'])->assertJsonValidationErrors('email');
    }

    public function test_an_ordinary_reset_token_cannot_pass_as_a_week_long_welcome_token(): void
    {
        $user = $this->userWithRole('student', ['email' => 'ada@uni.test']);
        $token = Password::createToken($user);

        $this->travel(3)->hours();

        $this->postJson('/api/reset-password', ['token' => $token, 'email' => 'ada@uni.test', 'password' => 'my-own-new-password-1', 'password_confirmation' => 'my-own-new-password-1'])->assertJsonValidationErrors('email');
    }

    public function test_the_imported_users_cannot_sign_in_until_they_choose_a_password(): void
    {
        Notification::fake();
        $this->importUsers("name,email,role\nAda,ada@uni.test,student\n")->assertOk();

        $this->postJson('/api/login', ['email' => 'ada@uni.test', 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'ada@uni.test', 'password' => ''])->assertUnprocessable();
    }

    public function test_files_without_the_required_columns_or_rows_are_refused(): void
    {
        $this->importUsers("name,email\nAda,ada@uni.test\n")->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->importUsers("name,email,role\n")->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->importUsers('')->assertUnprocessable();
        $this->actingAs($this->admin)->postJson('/api/users/import', [])->assertJsonValidationErrors('file');
        $this->assertSame(1, User::count());
    }

    public function test_excel_style_files_with_a_byte_order_mark_and_semicolons_work(): void
    {
        Notification::fake();

        $this->importUsers("\xEF\xBB\xBFName;Email;Role\r\nAda;ada@uni.test;student\r\n\r\nBen;ben@uni.test;student\r\n")->assertOk()->assertJsonPath('created', 2);
    }

    public function test_columns_may_come_in_any_order_and_extra_columns_are_ignored(): void
    {
        Notification::fake();

        $this->importUsers("student number,role,email,name\n2026001,student,ada@uni.test,Ada\n")->assertOk()->assertJsonPath('created', 1);

        $this->assertSame('Ada', User::where('email', 'ada@uni.test')->value('name'));
    }

    public function test_there_is_a_limit_on_the_number_of_rows(): void
    {
        $rows = implode("\n", array_map(fn ($i) => "User {$i},user{$i}@uni.test,student", range(1, 1001)));

        $this->importUsers("name,email,role\n".$rows)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(1, User::count());
    }

    public function test_only_user_administrators_can_import_users(): void
    {
        $csv = "name,email,role\nAda,ada@uni.test,student\n";

        $this->importUsers($csv, [], $this->userWithRole('lecturer'))->assertForbidden();
        $this->importUsers($csv, [], $this->userWithRole('student'))->assertForbidden();
        $this->assertSame(3, User::count());

        // A registrar now also manages users, so they can import too.
        $this->importUsers($csv, [], $this->userWithRole('registrar'))->assertOk();
        $this->assertSame(5, User::count());
    }

    public function test_enrolments_can_be_imported_from_a_csv_with_an_email_column(): void
    {
        $offering = $this->offering();
        $registrar = $this->userWithRole('registrar');
        $ada = $this->userWithRole('student', ['email' => 'ada@uni.test']);
        $this->userWithRole('student', ['email' => 'ben@uni.test']);

        $response = $this->actingAs($registrar)->post('/api/offerings/'.$offering->id.'/enrolments/import', [
            'file' => $this->csv("student number,Email\n1,ADA@uni.test\n2,ben@uni.test\n3,ghost@uni.test\n4,not-an-email\n", 'enrol.csv'),
        ], ['Accept' => 'application/json'])->assertOk();

        $response->assertJsonPath('enrolled', 2)->assertJsonPath('not_found.0', 'ghost@uni.test')->assertJsonPath('invalid.0', 'not-an-email');
        $this->assertDatabaseHas('enrolments', ['course_offering_id' => $offering->id, 'user_id' => $ada->id, 'status' => 'active']);
    }

    public function test_an_enrolment_csv_without_a_header_uses_the_first_column(): void
    {
        $offering = $this->offering();
        $this->userWithRole('student', ['email' => 'ada@uni.test']);

        $this->actingAs($this->userWithRole('registrar'))->post('/api/offerings/'.$offering->id.'/enrolments/import', ['file' => $this->csv("ada@uni.test\n", 'enrol.csv')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('enrolled', 1);
    }

    public function test_an_enrolment_import_needs_either_a_list_or_a_file(): void
    {
        $offering = $this->offering();

        $this->actingAs($this->userWithRole('registrar'))->postJson('/api/offerings/'.$offering->id.'/enrolments/import', [])->assertJsonValidationErrors(['emails', 'file']);
    }
}
