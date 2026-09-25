<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * While maintenance mode is on (Administration > Settings > Maintenance) only super administrators can use the system;
 * everyone else gets a 503 with the institution's message. The health check, the sign-in page's settings, and sign-in and
 * sign-out stay reachable, so an administrator can still get in and turn it off again.
 */
class MaintenanceMode
{
    private const ALWAYS = ['api/health', 'api/auth/config', 'api/login', 'api/logout', 'api/forgot-password', 'api/reset-password'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('lms.maintenance.enabled') || $request->is(self::ALWAYS)) {
            return $next($request);
        }
        if (Auth::guard('sanctum')->user()?->hasRole('super-admin')) {
            return $next($request);
        }

        return response()->json(['message' => (string) config('lms.maintenance.message'), 'maintenance' => true], 503, ['Retry-After' => 300]);
    }
}
