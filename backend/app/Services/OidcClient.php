<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * OpenID Connect authorization-code flow with PKCE. Only RS256 ID tokens are accepted, and the signature, issuer,
 * audience, expiry, and nonce are all checked before any claim is trusted.
 */
class OidcClient
{
    private const STATE_MINUTES = 10;

    private const DISCOVERY_SECONDS = 3600;

    public function enabled(): bool
    {
        $c = config('lms.sso');

        return $c['enabled'] && $c['issuer'] && $c['client_id'] && $c['client_secret'];
    }

    public function redirectUri(): string
    {
        return config('lms.sso.redirect_uri') ?: rtrim(config('lms.frontend_url'), '/').'/sso/callback';
    }

    /** The provider URL to send the browser to. State, nonce, and the PKCE verifier are kept server-side. */
    public function authorizationUrl(): string
    {
        $meta = $this->metadata();
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        Cache::put('sso:state:'.$state, ['nonce' => $nonce, 'verifier' => $verifier], now()->addMinutes(self::STATE_MINUTES));

        return $meta['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('lms.sso.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'scope' => config('lms.sso.scopes'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges the authorization code and returns the verified ID-token claims. A state value works once.
     *
     * @return array<string, mixed>
     *
     * @throws SsoException
     */
    public function claimsForCode(string $code, string $state): array
    {
        $session = Cache::pull('sso:state:'.$state);
        if (! is_array($session)) {
            throw new SsoException('The sign-in session expired or is invalid. Please start again.');
        }
        $meta = $this->metadata();
        try {
            $response = Http::asForm()->timeout(15)->post($meta['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'client_id' => config('lms.sso.client_id'),
                'client_secret' => config('lms.sso.client_secret'),
                'code_verifier' => $session['verifier'],
            ]);
        } catch (Throwable $e) {
            Log::error('SSO token request failed: '.$e->getMessage());
            throw new SsoException('The identity provider could not be reached.');
        }
        $idToken = $response->json('id_token');
        if ($response->failed() || ! is_string($idToken)) {
            Log::warning('SSO token endpoint refused the code', ['status' => $response->status(), 'error' => $response->json('error')]);
            throw new SsoException('The identity provider did not accept the sign-in.');
        }

        return $this->verify($idToken, $session['nonce']);
    }

    /** @return array<string, mixed> */
    private function verify(string $jwt, string $nonce): array
    {
        $claims = null;
        // Try the cached signing keys first, then once more with fresh ones in case the provider rotated them.
        foreach ([false, true] as $refresh) {
            try {
                JWT::$leeway = 60;
                $claims = (array) JWT::decode($jwt, JWK::parseKeySet($this->signingKeys($refresh), 'RS256'));
                break;
            } catch (Throwable $e) {
                Log::info('SSO ID token check failed'.($refresh ? ' after refreshing keys' : '').': '.$e->getMessage());
            }
        }
        if ($claims === null) {
            throw new SsoException('The identity provider sent a token that could not be verified.');
        }

        $audience = (array) ($claims['aud'] ?? []);
        $valid = rtrim((string) ($claims['iss'] ?? ''), '/') === rtrim((string) config('lms.sso.issuer'), '/')
            && in_array(config('lms.sso.client_id'), $audience, true)
            && (count($audience) === 1 || ($claims['azp'] ?? null) === config('lms.sso.client_id'))
            && isset($claims['nonce']) && hash_equals($nonce, (string) $claims['nonce'])
            && ! empty($claims['sub']);
        if (! $valid) {
            Log::warning('SSO ID token failed issuer, audience, nonce, or subject checks');
            throw new SsoException('The identity provider sent a token that could not be verified.');
        }

        return $claims;
    }

    /**
     * Asks the identity provider, fresh (not from the cache), whether it answers as configured: the discovery document names
     * the same issuer and the address of its signing keys works. For the administrator's "test" button.
     *
     * @return array{ok: bool, message: string}
     */
    public function selfTest(): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'message' => 'Single sign-on is not switched on (LMS_SSO_ENABLED).'];
        }
        Cache::forget('sso:metadata:'.md5(rtrim((string) config('lms.sso.issuer'), '/')));
        Cache::forget('sso:jwks:'.md5((string) config('lms.sso.issuer')));
        try {
            $meta = $this->metadata();
            $keys = Http::timeout(10)->get($meta['jwks_uri'])->throw()->json();
        } catch (SsoException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'The provider answered, but its signing keys could not be fetched: '.$e->getMessage()];
        }

        return count($keys['keys'] ?? []) > 0
            ? ['ok' => true, 'message' => 'The identity provider answers and publishes '.count($keys['keys']).' signing key(s). Sign-in still has to be tried with a real account.']
            : ['ok' => false, 'message' => 'The identity provider publishes no signing keys.'];
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        $issuer = rtrim((string) config('lms.sso.issuer'), '/');

        return Cache::remember('sso:metadata:'.md5($issuer), self::DISCOVERY_SECONDS, function () use ($issuer) {
            try {
                $meta = Http::timeout(10)->get($issuer.'/.well-known/openid-configuration')->throw()->json();
            } catch (Throwable $e) {
                Log::error('SSO discovery failed: '.$e->getMessage());
                throw new SsoException('The identity provider could not be reached.');
            }
            if (rtrim((string) ($meta['issuer'] ?? ''), '/') !== $issuer || ! isset($meta['authorization_endpoint'], $meta['token_endpoint'], $meta['jwks_uri'])) {
                Log::error('SSO discovery document does not match the configured issuer');
                throw new SsoException('Single sign-on is not configured correctly.');
            }

            return $meta;
        });
    }

    /** @return array<string, mixed> */
    private function signingKeys(bool $refresh): array
    {
        $key = 'sso:jwks:'.md5((string) config('lms.sso.issuer'));
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::DISCOVERY_SECONDS, fn () => Http::timeout(10)->get($this->metadata()['jwks_uri'])->throw()->json());
    }
}
