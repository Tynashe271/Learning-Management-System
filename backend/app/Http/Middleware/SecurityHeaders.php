<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Browser-facing safety headers on every response. This is a JSON API, so the policy is "load nothing, frame nothing". */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; sandbox",
        ]);
        // HSTS only means something over HTTPS: sent when the request arrived securely (behind a proxy, list it in
        // TRUSTED_PROXIES so the scheme is believed) or when LMS_FORCE_HSTS says TLS is terminated in front of us.
        if ($request->isSecure() || config('lms.security.force_hsts')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
