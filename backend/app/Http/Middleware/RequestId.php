<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Gives every request an id that appears in its logs, in error bodies, and in the X-Request-Id response header. */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Request-Id');
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9._-]{8,64}$/', $id)) {
            $id = (string) Str::uuid();
        }
        $request->attributes->set('request_id', $id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
