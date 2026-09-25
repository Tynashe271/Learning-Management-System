<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a client that already has a JSON answer ask "has this changed?" (If-None-Match) and get a tiny 304 instead of the whole
 * body again. The answer is marked private, since it depends on who is asking. File downloads are streamed and skipped.
 */
class ConditionalGet
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $request->isMethodCacheable() || ! $response instanceof JsonResponse || $response->getStatusCode() !== 200) {
            return $response;
        }

        $etag = '"'.md5((string) $response->getContent()).'"';
        $response->headers->set('ETag', $etag);
        $response->setPrivate();
        $response->headers->set('Vary', 'Authorization, Origin');

        // If-None-Match uses weak comparison, so W/"abc" matches "abc". This matters in practice: a proxy such as nginx
        // weakens the ETag when it compresses the body, and the browser then sends the weak form back.
        $candidates = array_map(fn ($tag) => preg_replace('#^W/#', '', trim($tag)), explode(',', (string) $request->header('If-None-Match')));
        if (in_array($etag, $candidates, true) || in_array('*', $candidates, true)) {
            $response->setNotModified();
        }

        return $response;
    }
}
