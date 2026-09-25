<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['lms.security.lockout_attempts' => 3, 'lms.security.lockout_minutes' => 15]);
    }

    /** Captures what is written to the security log so tests can read it. */
    private function securityLog(): TestHandler
    {
        config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        Log::forgetChannel('security');

        return Log::channel('security')->getLogger()->getHandlers()[0];
    }

    /** @return list<string> */
    private function logged(TestHandler $handler): array
    {
        return array_map(fn ($record) => $record->message, $handler->getRecords());
    }

    private function login(string $email, string $password)
    {
        return $this->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    // ---- headers, CORS, cookies ----------------------------------------------------------------------------------

    public function test_every_response_carries_browser_safety_headers_and_hides_the_stack(): void
    {
        foreach ([$this->getJson('/api/auth/config'), $this->getJson('/api/me'), $this->getJson('/api/does-not-exist'), $this->get('/')] as $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
            $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
            $this->assertFalse($response->headers->has('X-Powered-By'));
        }
    }

    public function test_hsts_is_sent_over_https_or_when_tls_is_terminated_in_front(): void
    {
        $this->getJson('/api/auth/config')->assertHeaderMissing('Strict-Transport-Security');

        $secure = $this->getJson('https://localhost/api/auth/config');
        $this->assertStringContainsString('max-age=31536000', $secure->headers->get('Strict-Transport-Security'));
        $this->assertStringContainsString('includeSubDomains', $secure->headers->get('Strict-Transport-Security'));

        $this->refreshApplication();
        config(['lms.security.force_hsts' => true]);
        $this->getJson('/api/auth/config')->assertHeader('Strict-Transport-Security');
    }

    public function test_only_the_configured_frontend_may_call_the_api_from_a_browser(): void
    {
        config(['cors.allowed_origins' => ['https://lms.uni.test', 'https://admin.uni.test']]);

        $allowed = $this->withHeaders(['Origin' => 'https://lms.uni.test'])->getJson('/api/auth/config');
        $blocked = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/auth/config');

        $allowed->assertHeader('Access-Control-Allow-Origin', 'https://lms.uni.test');
        $this->assertFalse($blocked->headers->has('Access-Control-Allow-Origin'), 'an unlisted website gets no permission');

        // With a single allowed site the header names that site, never the caller, so the browser still blocks anyone else.
        config(['cors.allowed_origins' => ['https://lms.uni.test']]);
        $other = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/auth/config');
        $this->assertNotSame('https://evil.example', $other->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $other->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('true', $allowed->headers->get('Access-Control-Allow-Credentials'), 'cookies are never shared cross-site');
    }

    public function test_browser_preflight_requests_are_answered_for_the_frontend_only(): void
    {
        config(['cors.allowed_origins' => ['https://lms.uni.test', 'https://admin.uni.test']]);
        $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type,idempotency-key',
        ]);

        $ok = $preflight('https://lms.uni.test');
        $this->assertContains($ok->getStatusCode(), [200, 204]);
        $ok->assertHeader('Access-Control-Allow-Origin', 'https://lms.uni.test');
        $this->assertStringContainsStringIgnoringCase('idempotency-key', $ok->headers->get('Access-Control-Allow-Headers'));
        $this->assertFalse($preflight('https://evil.example')->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_cors_never_defaults_to_allowing_everyone(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_the_api_never_sets_cookies(): void
    {
        $user = $this->userWithRole('student', ['email' => 'ada@uni.test', 'password' => 'a-long-password-1']);

        $this->assertSame([], $this->login('ada@uni.test', 'a-long-password-1')->assertOk()->baseResponse->headers->getCookies());
        $this->assertSame([], $this->actingAs($user)->getJson('/api/me')->baseResponse->headers->getCookies());
    }

    public function test_the_default_framework_welcome_page_is_gone(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertStringNotContainsStringIgnoringCase('laravel', $response->getContent());
        $this->assertFileDoesNotExist(resource_path('views/welcome.blade.php'));
    }

    // ---- request ids and error bodies ----------------------------------------------------------------------------

    public function test_every_request_gets_an_id_that_appears_in_error_bodies(): void
    {
        $notFound = $this->getJson('/api/does-not-exist')->assertNotFound();
        $unauthenticated = $this->getJson('/api/me')->assertUnauthorized();
        $invalid = $this->postJson('/api/login', [])->assertUnprocessable();

        foreach ([$notFound, $unauthenticated, $invalid] as $response) {
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->headers->get('X-Request-Id'));
            $this->assertSame($response->headers->get('X-Request-Id'), $response->json('request_id'));
        }
        $this->assertNotSame($notFound->headers->get('X-Request-Id'), $unauthenticated->headers->get('X-Request-Id'));
    }

    public function test_a_caller_supplied_request_id_is_kept_only_if_it_is_well_formed(): void
    {
        $this->withHeaders(['X-Request-Id' => 'client-trace-12345'])->getJson('/api/me')->assertHeader('X-Request-Id', 'client-trace-12345')->assertJsonPath('request_id', 'client-trace-12345');

        $bad = $this->withHeaders(['X-Request-Id' => "evil\r\nSet-Cookie: x=1"])->getJson('/api/me');
        $this->assertStringNotContainsString('evil', $bad->headers->get('X-Request-Id'));
        $short = $this->withHeaders(['X-Request-Id' => 'abc'])->getJson('/api/me');
        $this->assertNotSame('abc', $short->headers->get('X-Request-Id'));
    }

    // ---- request size and input cleaning -------------------------------------------------------------------------

    public function test_oversized_json_bodies_are_refused(): void
    {
        $this->login('ada@uni.test', str_repeat('x', 600 * 1024))->assertStatus(413)->assertJsonPath('message', 'The request body is too large.');

        config(['lms.limits.json_body_kb' => 2048]);
        $this->login('ada@uni.test', str_repeat('x', 600 * 1024))->assertUnprocessable(); // within the raised limit: an ordinary failed login
    }

    public function test_plain_text_fields_are_cleaned_before_they_are_stored(): void
    {
        $admin = $this->userWithRole('university-admin');

        $response = $this->actingAs($admin)->postJson('/api/users', ['name' => "<script>alert(1)</script>Ada\x00 <b>L.</b>", 'email' => 'ada@uni.test', 'password' => 'a-long-password-1', 'role' => 'student'])->assertCreated();

        $this->assertSame('alert(1)Ada L.', $response->json('name'));
        $this->assertSame('alert(1)Ada L.', User::where('email', 'ada@uni.test')->value('name'));
    }

    public function test_control_characters_and_broken_text_never_reach_the_database(): void
    {
        $teacher = $this->userWithRole('lecturer');
        $offering = $this->offering();
        $this->teach($offering, $teacher);

        $response = $this->actingAs($teacher)->postJson('/api/offerings/'.$offering->id.'/announcements', ['title' => "Wee\x07k 1", 'body' => "Line one\nLine two\x00\x1B[31m"])->assertCreated();

        $this->assertSame('Week 1', $response->json('title'));
        $this->assertSame("Line one\nLine two[31m", $response->json('body'), 'newlines survive; control characters do not');
        $this->actingAs($this->userWithRole('university-admin'))->getJson('/api/users?q=%FF%FEabc')->assertOk(); // invalid UTF-8 in a query string
    }

    public function test_free_text_is_stored_as_typed_but_passwords_are_never_altered(): void
    {
        $teacher = $this->userWithRole('lecturer');
        $offering = $this->offering();
        $this->teach($offering, $teacher);
        $this->actingAs($teacher)->postJson('/api/offerings/'.$offering->id.'/announcements', ['title' => 'Maths', 'body' => 'Use <b>bold</b> & x < y'])->assertCreated()->assertJsonPath('body', 'Use <b>bold</b> & x < y');

        $password = "pass\x07word-with-odd-\x1Fchars";
        $admin = $this->userWithRole('university-admin');
        $id = $this->actingAs($admin)->postJson('/api/users', ['name' => 'Odd', 'email' => 'odd@uni.test', 'password' => $password, 'role' => 'student'])->assertCreated()->json('id');

        $this->assertTrue(Hash::check($password, User::findOrFail($id)->password));
        $this->login('odd@uni.test', $password)->assertOk();
    }

    public function test_nested_plain_text_fields_are_cleaned_too(): void
    {
        $teacher = $this->userWithRole('lecturer');
        $offering = $this->offering();
        $this->teach($offering, $teacher);
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);

        $response = $this->actingAs($teacher)->putJson('/api/assignments/'.$assignment->id.'/rubric', ['criteria' => [['title' => '<i>Argument</i>', 'max_points' => 10, 'levels' => [['title' => '<u>Top</u>', 'points' => 10]]]]])->assertOk();

        $response->assertJsonPath('0.title', 'Argument')->assertJsonPath('0.levels.0.title', 'Top');
    }

    // ---- accounts: enumeration, case, lockout --------------------------------------------------------------------

    public function test_a_wrong_password_and_an_unknown_email_look_exactly_alike(): void
    {
        $this->userWithRole('student', ['email' => 'ada@uni.test', 'password' => 'a-long-password-1']);

        $wrong = $this->login('ada@uni.test', 'not-the-password')->assertUnprocessable();
        $unknown = $this->login('nobody@uni.test', 'not-the-password')->assertUnprocessable();

        $this->assertSame($wrong->json('errors'), $unknown->json('errors'));
        $this->assertSame($wrong->json('message'), $unknown->json('message'));
    }

    public function test_emails_are_matched_without_regard_to_case(): void
    {
        $this->userWithRole('student', ['email' => 'ada@uni.test', 'password' => 'a-long-password-1']);

        $this->login('ADA@Uni.Test', 'a-long-password-1')->assertOk();

        $admin = $this->userWithRole('university-admin');
        $this->actingAs($admin)->postJson('/api/users', ['name' => 'Dup', 'email' => 'Ada@UNI.test', 'password' => 'a-long-password-1', 'role' => 'student'])->assertJsonValidationErrors('email');
        $created = $this->actingAs($admin)->postJson('/api/users', ['name' => 'New', 'email' => 'NEW@Uni.Test', 'password' => 'a-long-password-1', 'role' => 'student'])->assertCreated();
        $this->assertSame('new@uni.test', $created->json('email'), 'stored lower-case');
    }

    public function test_the_database_itself_refuses_emails_that_differ_only_by_case(): void
    {
        $this->userWithRole('student', ['email' => 'ada@uni.test']);

        $this->expectException(QueryException::class);

        DB::table('users')->insert(['name' => 'Dup', 'email' => 'ADA@uni.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_repeated_failures_lock_the_address_even_for_the_right_password(): void
    {
        $this->userWithRole('student', ['email' => 'ada@uni.test', 'password' => 'a-long-password-1']);

        for ($i = 0; $i < 3; $i++) {
            $this->login('ada@uni.test', 'wrong-password-'.$i)->assertUnprocessable();
        }

        $locked = $this->login('ada@uni.test', 'a-long-password-1')->assertStatus(429);
        $this->assertStringContainsString('failed sign-in attempts', $locked->json('message'));
        $this->assertGreaterThan(0, (int) $locked->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(15 * 60, (int) $locked->headers->get('Retry-After'));
        $this->login('ADA@uni.test', 'a-long-password-1')->assertStatus(429); // upper-case does not dodge it
    }

    public function test_the_lockout_ends_after_the_lockout_period(): void
    {
        $this->userWithRole('student', ['email' => 'ada@uni.test', 'password' => 'a-long-password-1']);
        for ($i = 0; $i < 3; $i++) {
            $this->login('ada@uni.test', 'wrong-'.$i);
        }
        $this->login('ada@uni.test', 'a-long-password-1')->assertStatus(429);

        $this->travel(16)->minutes();

        $this->login('ada@uni.test', 'a-long-password-1')->assertOk();
    }

    public function test_the_lockout_cannot_be_used_to_tell_which_accounts_exist(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->login('ghost@uni.test', 'wrong-'.$i)->assertUnprocessable();
        }

        $this->login('ghost@uni.test', 'wrong-again')->assertStatus(429);
    }

    public function test_a_locked_address_does_not_affect_other_people_and_a_success_resets_the_count(): void
    {
        $this->userWithRole('student', ['email' => 'ada@uni.test', 'password' => 'a-long-password-1']);
        $this->userWithRole('student', ['email' => 'ben@uni.test', 'password' => 'a-long-password-1']);

        $this->login('ada@uni.test', 'wrong-1');
        $this->login('ada@uni.test', 'wrong-2');
        $this->login('ada@uni.test', 'a-long-password-1')->assertOk();   // success clears the two failures
        $this->travel(2)->minutes();                                        // (the per-minute limiter is a separate protection)
        $this->login('ada@uni.test', 'wrong-3');
        $this->login('ada@uni.test', 'wrong-4');
        $this->login('ada@uni.test', 'a-long-password-1')->assertOk();   // still not locked: never three in a row

        for ($i = 0; $i < 3; $i++) {
            $this->login('ben@uni.test', 'wrong-'.$i);
        }
        $this->login('ben@uni.test', 'a-long-password-1')->assertStatus(429);
        $this->login('ada@uni.test', 'a-long-password-1')->assertOk();
    }

    // ---- the security log ----------------------------------------------------------------------------------------

    public function test_sign_ins_and_lockouts_are_logged_without_personal_data(): void
    {
        $handler = $this->securityLog();
        $user = $this->userWithRole('student', ['email' => 'ada@uni.test', 'password' => 'a-long-password-1']);

        $this->login('ada@uni.test', 'a-long-password-1')->assertOk();
        for ($i = 0; $i < 3; $i++) {
            $this->login('ada@uni.test', 'guess-'.$i);
        }
        $this->login('ada@uni.test', 'a-long-password-1')->assertStatus(429);

        $messages = $this->logged($handler);
        $this->assertContains('login.success', $messages);
        $this->assertContains('login.failed', $messages);
        $this->assertContains('login.locked', $messages);
        $this->assertContains('login.blocked_while_locked', $messages);
        $success = collect($handler->getRecords())->firstWhere('message', 'login.success');
        $this->assertSame($user->id, $success->context['user_id']);
        $dump = json_encode(array_map(fn ($r) => [$r->message, $r->context], $handler->getRecords()));
        $this->assertStringNotContainsString('ada@uni.test', $dump, 'addresses are logged as short hashes');
        $this->assertStringNotContainsString('a-long-password-1', $dump);
        $this->assertStringNotContainsString('guess-', $dump);
    }

    public function test_password_and_account_changes_are_logged(): void
    {
        $handler = $this->securityLog();
        $ada = $this->userWithRole('student', ['email' => 'ada@uni.test', 'password' => 'a-long-password-1']);
        $admin = $this->userWithRole('university-admin');

        $this->actingAs($ada)->postJson('/api/me/password', ['current_password' => 'wrong-one-here', 'password' => 'another-long-password-2', 'password_confirmation' => 'another-long-password-2'])->assertUnprocessable();
        $this->actingAs($ada)->postJson('/api/me/password', ['current_password' => 'a-long-password-1', 'password' => 'another-long-password-2', 'password_confirmation' => 'another-long-password-2'])->assertOk();
        $this->actingAs($admin)->patchJson('/api/users/'.$ada->id, ['is_active' => false])->assertOk();
        $this->postJson('/api/forgot-password', ['email' => 'ada@uni.test'])->assertOk();

        $messages = $this->logged($handler);
        foreach (['password.change_failed', 'password.changed', 'account.deactivated', 'password.reset_requested'] as $event) {
            $this->assertContains($event, $messages);
        }
        $this->assertStringNotContainsString('another-long-password-2', json_encode(array_map(fn ($r) => $r->context, $handler->getRecords())));
    }

    public function test_denied_access_and_throttling_are_logged(): void
    {
        $handler = $this->securityLog();
        $student = $this->userWithRole('student');

        $this->actingAs($student)->getJson('/api/audit-log')->assertForbidden();
        $forbidden = collect($handler->getRecords())->firstWhere('message', 'access.denied');
        $this->assertSame($student->id, $forbidden->context['user_id']);
        $this->assertSame('api/audit-log', $forbidden->context['path']);

        $admin = $this->userWithRole('university-admin');
        for ($i = 0; $i < 11; $i++) {
            $last = $this->actingAs($admin)->getJson('/api/reports/overview');
        }
        $last->assertStatus(429);
        $this->assertContains('throttle.hit', $this->logged($handler));
    }

    public function test_a_password_reset_is_logged_and_a_bad_token_is_logged_as_a_warning(): void
    {
        $handler = $this->securityLog();
        $this->userWithRole('student', ['email' => 'ada@uni.test']);

        $this->postJson('/api/reset-password', ['token' => 'nope', 'email' => 'ada@uni.test', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])->assertUnprocessable();

        $this->assertTrue($handler->hasWarningThatContains('password.reset_failed'));
    }
}
