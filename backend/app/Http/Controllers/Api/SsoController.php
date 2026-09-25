<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OidcClient;
use App\Services\SsoException;
use App\Support\PasswordPolicy;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Sign in with the university's identity provider (OpenID Connect). The frontend redirects; this API finishes the job. */
class SsoController extends Controller
{
    public function __construct(private OidcClient $oidc) {}

    /** What the login screen should offer. */
    public function config(): JsonResponse
    {
        return response()->json([
            'sso_enabled' => $this->oidc->enabled(),
            'sso_label' => config('lms.sso.label'),
            'password_login' => ! $this->oidc->enabled() || config('lms.sso.password_login'),
            'privacy_url' => config('lms.legal.privacy_url'),
            'terms_url' => config('lms.legal.terms_url'),
            'institution' => [
                'name' => config('lms.institution.name'),
                'short_name' => config('lms.institution.short_name'),
                'support_email' => config('lms.institution.support_email'),
                'support_phone' => config('lms.institution.support_phone'),
                'website' => config('lms.institution.website'),
                'timezone' => config('lms.institution.timezone'),
                'locale' => config('lms.institution.locale'),
            ],
            'password_policy' => PasswordPolicy::describe(),
            'maintenance' => ['enabled' => (bool) config('lms.maintenance.enabled'), 'message' => config('lms.maintenance.message')],
        ]);
    }

    /** Step 1: returns the identity provider's sign-in URL for the frontend to send the browser to. */
    public function redirect(): JsonResponse
    {
        abort_unless($this->oidc->enabled(), 404, 'Single sign-on is not enabled.');
        try {
            return response()->json(['url' => $this->oidc->authorizationUrl()]);
        } catch (SsoException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /** Step 2: the frontend posts the code and state it received back from the provider. */
    public function callback(Request $request): JsonResponse
    {
        abort_unless($this->oidc->enabled(), 404, 'Single sign-on is not enabled.');
        $data = $request->validate(['code' => ['required', 'string', 'max:4096'], 'state' => ['required', 'string', 'max:255']]);
        try {
            $claims = $this->oidc->claimsForCode($data['code'], $data['state']);
            $user = $this->resolveUser($claims);
        } catch (SsoException $e) {
            SecurityLog::event('sso.failed', ['reason' => $e->getMessage()], 'warning');

            return response()->json(['message' => $e->getMessage()], $e->getCode() === 403 ? 403 : 422);
        }
        $user->forceFill(['last_login_at' => now()])->save();
        activity()->causedBy($user)->performedOn($user)->log('signed in with SSO');
        SecurityLog::event('login.success', ['user_id' => $user->id, 'email' => SecurityLog::emailFingerprint($user->email), 'method' => 'sso']);

        return response()->json(['token' => $user->createToken('sso')->plainTextToken, 'user' => $user->only('id', 'name', 'email'), 'roles' => $user->getRoleNames()]);
    }

    /** @param  array<string, mixed>  $claims */
    private function resolveUser(array $claims): User
    {
        $sso = config('lms.sso');
        $email = mb_strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new SsoException('The identity provider did not share an email address.', 403);
        }
        if ($sso['require_verified_email'] && ($claims['email_verified'] ?? false) !== true) {
            throw new SsoException('The identity provider has not verified your email address.', 403);
        }
        if ($sso['allowed_domains'] && ! in_array(Str::after($email, '@'), array_map('mb_strtolower', $sso['allowed_domains']), true)) {
            throw new SsoException('Accounts from this email domain cannot sign in here.', 403);
        }

        $subject = (string) $claims['sub'];
        $user = User::where('sso_subject', $subject)->first() ?? User::whereRaw('lower(email) = ?', [$email])->first();
        if ($user && $user->sso_subject !== null && $user->sso_subject !== $subject) {
            // The email now belongs to a different identity than the one linked earlier: never merge them silently.
            throw new SsoException('This LMS account is linked to a different identity. Contact an administrator.', 403);
        }
        if (! $user) {
            if (! $sso['auto_provision']) {
                throw new SsoException('You do not have an LMS account yet. Ask an administrator to create one.', 403);
            }
            $user = DB::transaction(function () use ($claims, $email) {
                $user = User::create(['name' => $this->displayName($claims, $email), 'email' => $email, 'password' => Str::random(48)]);
                $user->assignRole('student');

                return $user;
            });
        }
        if (! $user->is_active) {
            throw new SsoException('This account has been deactivated.', 403);
        }
        if ($user->sso_subject === null) {
            $user->forceFill(['sso_subject' => $subject])->save();
        }

        return $user;
    }

    /** @param  array<string, mixed>  $claims */
    private function displayName(array $claims, string $email): string
    {
        $name = trim((string) ($claims['name'] ?? trim(($claims['given_name'] ?? '').' '.($claims['family_name'] ?? ''))));

        return Str::limit($name !== '' ? $name : Str::before($email, '@'), 255, '');
    }
}
