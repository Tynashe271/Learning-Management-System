<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class PreflightAndEmptyStatesTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->seed(DatabaseSeeder::class);
    }

    /** Runs the checklist and returns [exit code, [check name => result]]. */
    private function preflight(array $options = []): array
    {
        $code = Artisan::call('lms:preflight', $options);
        preg_match_all('/\|\s*(PASS|WARN|FAIL)\s*\|\s*[A-Za-z]+\s*\|\s*(.+?)\s*\|/', Artisan::output(), $matches, PREG_SET_ORDER);

        return [$code, collect($matches)->mapWithKeys(fn ($m) => [$m[2] => $m[1]])->all()];
    }

    // ---- the launch checklist ------------------------------------------------------------------------------------

    public function test_a_development_install_passes_what_must_always_be_true_and_only_warns_about_production_items(): void
    {
        config(['app.env' => 'local', 'app.debug' => true]);

        [$code, $results] = $this->preflight();

        $this->assertSame(0, $code, 'warnings do not fail a development install');
        $this->assertSame('WARN', $results['Debug mode is off']);
        $this->assertSame('PASS', $results['App key is set']);
        $this->assertSame('PASS', $results['Database is reachable']);
        $this->assertSame('PASS', $results['Rate limits are defined']);
        $this->assertSame('PASS', $results['Horizon dashboard is restricted']);
        $this->assertSame('PASS', $results['Uploads limited to safe file types']);
        $this->assertSame('PASS', $results['CORS allows only named sites']);
    }

    public function test_production_standards_turn_the_same_warnings_into_failures(): void
    {
        config(['app.debug' => true]);

        [$code, $results] = $this->preflight(['--production' => true]);

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $results['Debug mode is off']);
        $this->assertSame('FAIL', $results['Uploads are virus-scanned']);
        $this->assertSame('FAIL', $results['API address uses https']);
    }

    public function test_settings_that_are_right_pass_in_production(): void
    {
        config([
            'app.debug' => false, 'app.url' => 'https://lms.uni.test', 'lms.frontend_url' => 'https://app.uni.test', 'session.secure' => true,
            'lms.virus_scan.enabled' => true, 'lms.security.force_hsts' => true, 'cors.allowed_origins' => ['https://app.uni.test'],
        ]);

        [, $results] = $this->preflight(['--production' => true]);

        foreach (['Debug mode is off', 'API address uses https', 'Frontend address uses https', 'HSTS is sent', 'Session cookies are secure', 'Uploads are virus-scanned', 'CORS allows only named sites'] as $check) {
            $this->assertSame('PASS', $results[$check], $check);
        }
    }

    public function test_a_wildcard_cors_setting_fails_even_on_a_development_machine(): void
    {
        config(['cors.allowed_origins' => ['*']]);

        [$code, $results] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $results['CORS allows only named sites']);
    }

    public function test_tokens_that_never_expire_and_dangerous_upload_types_fail_everywhere(): void
    {
        config(['sanctum.expiration' => null, 'lms.upload_mimes' => 'pdf,php,exe']);

        [$code, $results] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $results['Sign-in tokens expire']);
        $this->assertSame('FAIL', $results['Uploads limited to safe file types']);
    }

    public function test_a_missing_app_key_and_weak_lockout_settings_are_caught(): void
    {
        config(['app.key' => '', 'lms.security.lockout_attempts' => 100]);

        [$code, $results] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $results['App key is set']);
        $this->assertSame('FAIL', $results['Accounts lock after failed sign-ins']);
    }

    public function test_the_default_local_passwords_are_flagged(): void
    {
        config(['database.connections.pgsql.password' => 'local-lms-password']);

        [, $results] = $this->preflight(['--production' => true]);

        $this->assertSame('FAIL', $results['Local default passwords replaced']);
    }

    public function test_a_broken_file_store_is_a_failure_not_a_crash(): void
    {
        Storage::shouldReceive('disk')->andThrow(new \RuntimeException('unreachable'));

        [$code, $results] = $this->preflight();

        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $results['File storage is reachable']);
    }

    // ---- empty states: what a brand-new person sees --------------------------------------------------------------

    public function test_a_new_student_gets_empty_but_well_formed_lists(): void
    {
        $student = $this->userWithRole('student');
        $api = fn (string $url) => $this->actingAs($student)->getJson($url)->assertOk();

        foreach (['/api/notifications', '/api/offerings', '/api/my-appeals'] as $url) {
            $api($url)->assertJsonPath('data', [])->assertJsonPath('total', 0);
        }
        $api('/api/messages')->assertJsonPath('conversations', [])->assertJsonPath('unread_total', 0);
    }

    public function test_a_brand_new_course_returns_empty_collections_not_errors(): void
    {
        $lecturer = $this->userWithRole('lecturer');
        $offering = $this->offering();
        $this->teach($offering, $lecturer);
        $o = '/api/offerings/'.$offering->id;
        $api = fn (string $url) => $this->actingAs($lecturer)->getJson($url)->assertOk();

        $api($o)->assertJsonPath('modules', [])->assertJsonPath('assignments', [])->assertJsonPath('quizzes', []);
        $api($o.'/roster')->assertJsonPath('enrolments', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.last_page', 1);
        $api($o.'/gradebook')->assertJsonPath('columns', [])->assertJsonPath('rows', [])->assertJsonPath('meta.total', 0);
        $api($o.'/progress')->assertJsonPath('items_total', 0)->assertJsonPath('students', []);
        $api($o.'/attendance')->assertJsonPath('sessions_total', 0)->assertJsonPath('students', []);
        $api($o.'/summary')->assertJsonPath('assignments', [])->assertJsonPath('quizzes', [])->assertJsonPath('enrolled', 0);
        $api($o.'/announcements')->assertJsonPath('data', []);
        $api($o.'/discussions')->assertJsonPath('data', []);
        $api($o.'/sessions')->assertExactJson([]);
        $api($o.'/appeals')->assertJsonPath('data', []);
        $this->assertSame('', $this->actingAs($lecturer)->get($o.'/gradebook?format=csv')->assertOk()->streamedContent() === '' ? 'empty' : '', 'even an empty class exports a header row');
    }

    public function test_an_enrolled_student_in_an_empty_course_sees_zeroes_and_no_percentages(): void
    {
        $student = $this->userWithRole('student');
        $offering = $this->offering();
        $this->enrol($offering, $student);
        $o = '/api/offerings/'.$offering->id;

        $this->actingAs($student)->getJson($o.'/progress')->assertOk()->assertJsonPath('items_total', 0)->assertJsonPath('percent', null);
        $this->actingAs($student)->getJson($o.'/attendance')->assertOk()->assertJsonPath('percent', null)->assertJsonPath('sessions_total', 0);
        $this->actingAs($student)->getJson($o.'/my-grades')->assertOk()->assertJsonPath('columns', [])->assertJsonPath('grades.percent', null);
        $this->actingAs($student)->getJson('/api/me/digest-preview')->assertOk()->assertJsonPath('summary', null);
    }

    public function test_an_empty_audit_log_and_user_search_are_fine(): void
    {
        $admin = $this->userWithRole('university-admin');

        $this->actingAs($admin)->getJson('/api/users?q=zzzz-nobody')->assertOk()->assertJsonPath('data', []);
        $this->actingAs($admin)->getJson('/api/audit-log?q=nothing-matches-this')->assertOk()->assertJsonPath('data', []);
    }

    // ---- what the login screen needs -----------------------------------------------------------------------------

    public function test_the_login_screen_can_link_to_the_institutions_privacy_policy_and_terms(): void
    {
        $this->getJson('/api/auth/config')->assertJsonPath('privacy_url', null)->assertJsonPath('terms_url', null);

        config(['lms.legal.privacy_url' => 'https://uni.example/privacy', 'lms.legal.terms_url' => 'https://uni.example/terms']);
        $this->getJson('/api/auth/config')->assertOk()->assertJsonPath('privacy_url', 'https://uni.example/privacy')->assertJsonPath('terms_url', 'https://uni.example/terms');
    }

    public function test_failed_requests_come_back_in_one_consistent_shape(): void
    {
        $student = $this->userWithRole('student');
        $responses = [
            $this->getJson('/api/nope')->assertNotFound(),
            $this->getJson('/api/me')->assertUnauthorized(),
            $this->actingAs($student)->getJson('/api/audit-log')->assertForbidden(),
            $this->postJson('/api/login', [])->assertUnprocessable(),
        ];

        foreach ($responses as $response) {
            $this->assertIsString($response->json('message'));
            $this->assertNotEmpty($response->json('request_id'));
        }
        $this->assertInstanceOf(User::class, $student);
    }
}
