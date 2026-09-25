<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ordinary JSON requests are small. File uploads have their own, larger limits; this stops giant JSON bodies. */
class LimitJsonBody
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('lms.limits.json_body_kb') * 1024;
        if ($limit > 0 && $request->isJson() && ((int) $request->header('Content-Length') > $limit || strlen($request->getContent()) > $limit)) {
            return response()->json(['message' => 'The request body is too large.'], 413);
        }

        return $next($request);
    }
}
