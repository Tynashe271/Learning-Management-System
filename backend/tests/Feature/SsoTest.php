<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

/**
 * Runs the OpenID Connect flow against a simulated identity provider that signs real RS256 tokens, so signature,
 * issuer, audience, nonce, expiry, and key-rotation handling are all exercised for real.
 */
class SsoTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    /** @var array<string, array{kid: string, private: string, jwk: array<string, string>}>|null */
    private static ?array $keys = null;

    /** @var array{kid: string, private: string, jwk: array<string, string>} */
    private array $signing;

    /** @var list<array<string, string>> */
    private array $published;

    /** @var array<string, mixed> claims that override (or, when null, remove) the defaults in the next ID token */
    private array $claims = [];

    private string $nonce = '';

    private ?string $seenVerifier = null;

    /** @var array<string, mixed>|null  a canned reply for the token endpoint */
    private ?array $tokenReply = null;

    private int $tokenReplyStatus = 200;

    private int $discoveryStatus = 200;

    private string $discoveryIssuer = 'https://idp.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        self::$keys ??= ['a' => $this->makeKey('key-a'), 'b' => $this->makeKey('key-b')];
        $this->signing = self::$keys['a'];
        $this->published = [self::$keys['a']['jwk']];
        $this->configure();

        Http::fake([
            'https://idp.test/.well-known/openid-configuration' => fn () => Http::response([
                'issuer' => $this->discoveryIssuer,
                'authorization_endpoint' => 'https://idp.test/authorize',
                'token_endpoint' => 'https://idp.test/token',
                'jwks_uri' => 'https://idp.test/jwks',
            ], $this->discoveryStatus),
            'https://idp.test/jwks' => fn () => Http::response(['keys' => $this->published]),
            'https://idp.test/token' => function ($request) {
                $this->seenVerifier = $request['code_verifier'] ?? null;

                return Http::response($this->tokenReply ?? ['access_token' => 'x', 'id_token' => $this->idToken()], $this->tokenReplyStatus);
            },
        ]);
    }

    private function configure(array $overrides = []): void
    {
        config([
            'lms.frontend_url' => 'https://lms.test',
            'lms.sso' => $overrides + [
                'enabled' => true, 'label' => 'Uni sign-in', 'issuer' => 'https://idp.test', 'client_id' => 'lms-client', 'client_secret' => 's3cret',
                'redirect_uri' => null, 'scopes' => 'openid email profile', 'auto_provision' => false, 'allowed_domains' => [],
                'require_verified_email' => true, 'password_login' => true,
            ],
        ]);
    }

    private function makeKey(string $kid): array
    {
        // Windows builds of PHP ship openssl.cnf but do not point at it by default.
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        foreach ([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'] as $config) {
            if ($config && is_file($config)) {
                $options['config'] = $config;
                break;
            }
        }
        $resource = openssl_pkey_new($options);
        $this->assertNotFalse($resource, 'OpenSSL could not generate a test key: '.openssl_error_string());
        openssl_pkey_export($resource, $pem, null, isset($options['config']) ? ['config' => $options['config']] : []);
        $rsa = openssl_pkey_get_details($resource)['rsa'];
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return ['kid' => $kid, 'private' => $pem, 'jwk' => ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid, 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]];
    }

    private function claimSet(): array
    {
        return array_filter($this->claims + [
            'iss' => 'https://idp.test', 'aud' => 'lms-client', 'sub' => 'sub-1', 'email' => 'ada@uni.test', 'email_verified' => true,
            'name' => 'Ada Lovelace', 'nonce' => $this->nonce, 'iat' => time(), 'exp' => time() + 600,
        ], fn ($v) => $v !== null);
    }

    private function idToken(): string
    {
        return JWT::encode($this->claimSet(), $this->signing['private'], 'RS256', $this->signing['kid']);
    }

    /** Starts a sign-in and returns the state, remembering the nonce the provider would echo back. */
    private function begin(): string
    {
        $url = $this->getJson('/api/auth/sso')->assertOk()->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->nonce = $query['nonce'];

        return $query['state'];
    }

    private function finish(string $state, string $code = 'auth-code')
    {
        return $this->postJson('/api/auth/sso/callback', ['code' => $code, 'state' => $state]);
    }

    private function signIn(array $claims = [])
    {
        $this->claims = $claims;

        return $this->finish($this->begin());
    }

    private function student(array $attributes = []): User
    {
        return $this->userWithRole('student', $attributes + ['email' => 'ada@uni.test']);
    }

    private function assertRefused($response, int $status = 422): void
    {
        $response->assertStatus($status)->assertJsonStructure(['message'])->assertJsonMissingPath('token');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ---- setup and discovery -------------------------------------------------------------------------------------

    public function test_the_login_screen_can_ask_whether_sso_is_available(): void
    {
        $this->getJson('/api/auth/config')->assertOk()->assertJsonPath('sso_enabled', true)->assertJsonPath('sso_label', 'Uni sign-in')->assertJsonPath('password_login', true);

        $this->configure(['enabled' => false, 'label' => 'x', 'issuer' => null, 'client_id' => null, 'client_secret' => null]);
        $this->getJson('/api/auth/config')->assertOk()->assertJsonPath('sso_enabled', false)->assertJsonPath('password_login', true);
    }

    public function test_sso_is_off_until_fully_configured(): void
    {
        $this->configure(['enabled' => true, 'client_secret' => null]);

        $this->getJson('/api/auth/config')->assertJsonPath('sso_enabled', false);
        $this->getJson('/api/auth/sso')->assertNotFound();
        $this->postJson('/api/auth/sso/callback', ['code' => 'x', 'state' => 'y'])->assertNotFound();
    }

    public function test_the_provider_url_carries_state_nonce_and_a_pkce_challenge(): void
    {
        $url = $this->getJson('/api/auth/sso')->assertOk()->json('url');

        $this->assertStringStartsWith('https://idp.test/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('lms-client', $q['client_id']);
        $this->assertSame('https://lms.test/sso/callback', $q['redirect_uri']);
        $this->assertSame('openid email profile', $q['scope']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertGreaterThanOrEqual(32, strlen($q['state']));
        $this->assertGreaterThanOrEqual(32, strlen($q['nonce']));
        $this->assertNotSame($q['state'], $q['nonce']);
        $this->assertStringNotContainsString('s3cret', $url, 'the client secret never goes in the browser URL');
        $this->assertNotSame($q['state'], parse_str((string) parse_url($this->getJson('/api/auth/sso')->json('url'), PHP_URL_QUERY), $again) ?: $again['state']);
    }

    public function test_the_redirect_uri_can_be_overridden(): void
    {
        $this->configure(['redirect_uri' => 'https://other.test/callback']);

        parse_str((string) parse_url($this->getJson('/api/auth/sso')->json('url'), PHP_URL_QUERY), $q);

        $this->assertSame('https://other.test/callback', $q['redirect_uri']);
    }

    public function test_an_unreachable_or_mismatched_provider_gives_a_clean_error(): void
    {
        $this->discoveryStatus = 500;
        $this->getJson('/api/auth/sso')->assertStatus(502)->assertJsonStructure(['message']);

        $this->discoveryStatus = 200;
        $this->discoveryIssuer = 'https://someone-else.test';
        $this->getJson('/api/auth/sso')->assertStatus(502)->assertJsonStructure(['message']);
    }

    // ---- the happy path ------------------------------------------------------------------------------------------

    public function test_an_existing_user_signs_in_and_gets_a_working_token(): void
    {
        $ada = $this->student();

        $response = $this->signIn()->assertOk()->assertJsonPath('user.email', 'ada@uni.test')->assertJsonPath('roles.0', 'student');

        $this->assertSame('sub-1', $ada->fresh()->sso_subject);
        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('token'))->getJson('/api/me')->assertOk()->assertJsonPath('id', $ada->id);
        $this->assertDatabaseHas('activity_log', ['description' => 'signed in with SSO']);
        $this->assertSame(1, User::count());
    }

    public function test_the_code_verifier_matches_the_challenge_sent_to_the_provider(): void
    {
        $this->student();
        $url = $this->getJson('/api/auth/sso')->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->nonce = $q['nonce'];

        $this->finish($q['state'])->assertOk();

        $this->assertNotNull($this->seenVerifier);
        $this->assertSame($q['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $this->seenVerifier, true)), '+/', '-_'), '='));
    }

    public function test_the_token_request_carries_the_secret_and_code_server_side(): void
    {
        $this->student();

        $this->signIn()->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://idp.test/token'
            && $request['grant_type'] === 'authorization_code' && $request['code'] === 'auth-code'
            && $request['client_secret'] === 's3cret' && $request['client_id'] === 'lms-client' && $request['redirect_uri'] === 'https://lms.test/sso/callback');
    }

    public function test_email_matching_ignores_case(): void
    {
        $ada = $this->student();

        $this->signIn(['email' => 'ADA@Uni.TEST'])->assertOk()->assertJsonPath('user.id', $ada->id);
    }

    public function test_once_linked_the_identity_subject_finds_the_user_even_if_their_email_changes(): void
    {
        $ada = $this->student();
        $this->signIn()->assertOk();

        $this->signIn(['email' => 'ada.new@uni.test'])->assertOk()->assertJsonPath('user.id', $ada->id);

        $this->assertSame(1, User::count());
    }

    public function test_a_token_for_several_audiences_is_accepted_when_it_names_us_as_the_authorized_party(): void
    {
        $this->student();

        $this->signIn(['aud' => ['lms-client', 'another-app'], 'azp' => 'lms-client'])->assertOk();
    }

    public function test_a_trailing_slash_on_the_issuer_is_tolerated(): void
    {
        $this->student();

        $this->signIn(['iss' => 'https://idp.test/'])->assertOk();
    }

    // ---- who may sign in -----------------------------------------------------------------------------------------

    public function test_someone_without_an_account_is_turned_away_unless_auto_provisioning_is_on(): void
    {
        $this->assertRefused($this->signIn(), 403);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_auto_provisioning_creates_a_student_and_never_staff(): void
    {
        $this->configure(['auto_provision' => true]);

        $response = $this->signIn(['name' => 'New Student', 'email' => 'new@uni.test', 'sub' => 'sub-new', 'roles' => ['admin'], 'groups' => ['staff']])->assertOk();

        $user = User::where('email', 'new@uni.test')->firstOrFail();
        $this->assertSame('New Student', $user->name);
        $this->assertSame(['student'], $user->getRoleNames()->all());
        $this->assertSame('sub-new', $user->sso_subject);
        $response->assertJsonPath('roles.0', 'student');
        $this->postJson('/api/login', ['email' => 'new@uni.test', 'password' => 'password'])->assertUnprocessable();
    }

    public function test_display_names_fall_back_to_given_and_family_names_then_the_email(): void
    {
        $this->configure(['auto_provision' => true]);

        $this->signIn(['name' => null, 'given_name' => 'Grace', 'family_name' => 'Hopper', 'email' => 'grace@uni.test', 'sub' => 's2'])->assertOk();
        $this->signIn(['name' => null, 'email' => 'anon@uni.test', 'sub' => 's3'])->assertOk();

        $this->assertSame('Grace Hopper', User::where('email', 'grace@uni.test')->value('name'));
        $this->assertSame('anon', User::where('email', 'anon@uni.test')->value('name'));
    }

    public function test_only_allowed_email_domains_can_sign_in(): void
    {
        $this->configure(['allowed_domains' => ['uni.test', 'staff.uni.test'], 'auto_provision' => true]);
        $outsider = $this->student(['email' => 'ada@evil.test']);

        $this->assertRefused($this->signIn(['email' => 'ada@evil.test']), 403);
        $this->assertRefused($this->signIn(['email' => 'ada@uni.test.evil.test']), 403);
        $this->assertNull($outsider->fresh()->sso_subject);
        $this->signIn(['email' => 'ok@STAFF.uni.test', 'sub' => 'sub-ok'])->assertOk();
    }

    public function test_unverified_or_missing_emails_are_refused(): void
    {
        $this->student();

        $this->assertRefused($this->signIn(['email_verified' => false]), 403);
        $this->assertRefused($this->signIn(['email_verified' => 'true']), 403); // only a real boolean counts
        $this->assertRefused($this->signIn(['email_verified' => null]), 403);
        $this->assertRefused($this->signIn(['email' => null]), 403);
        $this->assertRefused($this->signIn(['email' => 'not-an-email']), 403);
    }

    public function test_requiring_a_verified_email_can_be_turned_off_for_providers_that_do_not_send_the_claim(): void
    {
        $this->configure(['require_verified_email' => false]);
        $this->student();

        $this->signIn(['email_verified' => null])->assertOk();
    }

    public function test_deactivated_accounts_cannot_sign_in(): void
    {
        $this->student()->forceFill(['is_active' => false])->save();

        $this->assertRefused($this->signIn(), 403);
    }

    public function test_an_email_already_linked_to_a_different_identity_is_never_merged(): void
    {
        $ada = $this->student();
        $ada->forceFill(['sso_subject' => 'the-real-ada'])->save();

        $this->assertRefused($this->signIn(['sub' => 'someone-who-reused-the-email']), 403);

        $this->assertSame('the-real-ada', $ada->fresh()->sso_subject);
    }

    // ---- the sign-in session -------------------------------------------------------------------------------------

    public function test_a_state_value_works_only_once(): void
    {
        $this->student();
        $state = $this->begin();

        $this->finish($state)->assertOk();

        $this->finish($state)->assertUnprocessable()->assertJsonStructure(['message'])->assertJsonMissingPath('token');
        $this->assertDatabaseCount('personal_access_tokens', 1); // only the first sign-in produced a token
    }

    public function test_an_unknown_or_missing_state_is_refused(): void
    {
        $this->student();

        $this->assertRefused($this->finish('made-up-state'));
        $this->postJson('/api/auth/sso/callback', ['code' => 'x'])->assertJsonValidationErrors('state');
        $this->postJson('/api/auth/sso/callback', ['state' => 'x'])->assertJsonValidationErrors('code');
    }

    public function test_a_sign_in_that_takes_too_long_expires(): void
    {
        $this->student();
        $state = $this->begin();

        $this->travel(11)->minutes();

        $this->assertRefused($this->finish($state));
    }

    // ---- attacks on the token ------------------------------------------------------------------------------------

    public function test_tokens_that_fail_any_check_are_refused(): void
    {
        $this->student();
        $cases = [
            'wrong nonce (replayed token)' => ['nonce' => 'not-the-nonce'],
            'no nonce' => ['nonce' => null],
            'wrong audience' => ['aud' => 'some-other-app'],
            'several audiences without azp' => ['aud' => ['lms-client', 'another-app']],
            'several audiences, wrong azp' => ['aud' => ['lms-client', 'another-app'], 'azp' => 'another-app'],
            'wrong issuer' => ['iss' => 'https://evil.test'],
            'expired' => ['exp' => time() - 3600],
            'no subject' => ['sub' => null],
            'empty subject' => ['sub' => ''],
        ];

        foreach ($cases as $label => $claims) {
            $this->assertRefused($this->signIn($claims), 422);
            $this->assertNull(User::where('email', 'ada@uni.test')->value('sso_subject'), "$label must not link the account");
        }
    }

    public function test_a_token_signed_with_an_unpublished_key_is_refused(): void
    {
        $this->student();
        $this->signing = self::$keys['b']; // the provider does not publish key-b

        $this->assertRefused($this->signIn());
    }

    public function test_a_token_claiming_a_published_key_id_but_signed_by_someone_else_is_refused(): void
    {
        $this->student();
        $this->signing = ['kid' => 'key-a'] + self::$keys['b']; // attacker's key, published key's id

        $this->assertRefused($this->signIn());
    }

    public function test_the_hmac_key_confusion_attack_is_refused(): void
    {
        $this->student();
        $state = $this->begin();
        // Sign with HS256 using the public modulus as the "secret", the classic algorithm-confusion forgery.
        $forged = JWT::encode($this->claimSet(), self::$keys['a']['jwk']['n'].str_repeat('x', 32), 'HS256', 'key-a');
        $this->tokenReply = ['id_token' => $forged];

        $this->assertRefused($this->finish($state));
    }

    public function test_an_unsigned_token_is_refused(): void
    {
        $this->student();
        $state = $this->begin();
        $b64 = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');
        $this->tokenReply = ['id_token' => $b64(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'key-a']).'.'.$b64($this->claimSet()).'.'];

        $this->assertRefused($this->finish($state));
    }

    public function test_garbage_instead_of_a_token_is_refused(): void
    {
        $this->student();
        $state = $this->begin();
        $this->tokenReply = ['id_token' => 'this.is.notatoken'];

        $this->assertRefused($this->finish($state));
    }

    public function test_a_reply_without_an_id_token_or_an_error_from_the_provider_is_refused(): void
    {
        $this->student();

        $this->tokenReply = ['access_token' => 'only-an-access-token'];
        $this->assertRefused($this->finish($this->begin()));

        $this->tokenReply = ['error' => 'invalid_grant'];
        $this->tokenReplyStatus = 400;
        $this->assertRefused($this->finish($this->begin()));
    }

    public function test_signing_key_rotation_is_picked_up_without_a_restart(): void
    {
        $this->student();
        $this->signIn()->assertOk(); // caches the provider's key set (key-a)

        $this->signing = self::$keys['b'];
        $this->published = [self::$keys['b']['jwk']]; // the provider rotates to key-b

        $this->signIn()->assertOk();
    }

    public function test_error_messages_do_not_reveal_why_a_token_failed(): void
    {
        $this->student();

        $message = $this->signIn(['aud' => 'some-other-app'])->json('message');

        $this->assertStringNotContainsString('aud', $message);
        $this->assertStringNotContainsString('lms-client', $message);
        $this->assertStringNotContainsString('nonce', $message);
    }

    // ---- password sign-in alongside SSO --------------------------------------------------------------------------

    public function test_password_sign_in_can_be_limited_to_super_admins_once_sso_is_on(): void
    {
        $this->configure(['password_login' => false]);
        $student = $this->userWithRole('student', ['email' => 'stu@uni.test', 'password' => 'a-long-password-1']);
        $admin = $this->userWithRole('super-admin', ['email' => 'root@uni.test', 'password' => 'a-long-password-1']);

        $this->getJson('/api/auth/config')->assertJsonPath('password_login', false);
        $this->postJson('/api/login', ['email' => $student->email, 'password' => 'a-long-password-1'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'a-long-password-1'])->assertOk();
        // A wrong password still gets the ordinary message, so this cannot be used to test guesses.
        $this->postJson('/api/login', ['email' => $student->email, 'password' => 'wrong'])->assertJsonPath('errors.email.0', 'Invalid credentials.');
    }

    public function test_the_password_setting_does_nothing_while_sso_is_disabled(): void
    {
        $this->configure(['enabled' => false, 'password_login' => false]);
        $this->userWithRole('student', ['email' => 'stu@uni.test', 'password' => 'a-long-password-1']);

        $this->getJson('/api/auth/config')->assertJsonPath('password_login', true);
        $this->postJson('/api/login', ['email' => 'stu@uni.test', 'password' => 'a-long-password-1'])->assertOk();
    }
}
