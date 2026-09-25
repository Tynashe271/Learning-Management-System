<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as LaravelResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes retries safe. A client that sends an `Idempotency-Key` header (any unique string, for example a UUID) can repeat a
 * request after a timeout or dropped connection without the action happening twice: the first successful response is stored
 * for 24 hours and replayed for repeats of the same request. Requests without the header behave normally.
 */
class Idempotent
{
    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) {
            return response()->json(['message' => 'The Idempotency-Key must be 8 to 100 letters, digits, or . _ : -'], 422);
        }

        $owner = $request->user()?->id ?? 'guest:'.$request->ip();
        $cacheKey = 'idem:'.hash('sha256', $owner.'|'.$request->method().'|'.$request->path().'|'.$key);
        $fingerprint = $this->fingerprint($request);

        if ($replay = $this->replay($cacheKey, $fingerprint)) {
            return $replay;
        }
        $lock = Cache::lock($cacheKey.':lock', 30);
        if (! $lock->get()) {
            return response()->json(['message' => 'A request with this Idempotency-Key is still being processed.'], 409, ['Retry-After' => '2']);
        }
        try {
            // Another request with this key may have finished while we waited for the lock.
            if ($replay = $this->replay($cacheKey, $fingerprint)) {
                return $replay;
            }
            $response = $next($request);
            // Only successes are remembered, so a request that failed validation can be corrected and retried.
            if ($response instanceof JsonResponse && $response->isSuccessful()) {
                Cache::put($cacheKey, ['fingerprint' => $fingerprint, 'status' => $response->getStatusCode(), 'content' => $response->getContent()], now()->addHours(self::TTL_HOURS));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function replay(string $cacheKey, string $fingerprint): ?Response
    {
        $stored = Cache::get($cacheKey);
        if (! is_array($stored)) {
            return null;
        }
        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            return response()->json(['message' => 'This Idempotency-Key was already used with a different request.'], 422);
        }

        return new LaravelResponse($stored['content'], $stored['status'], ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
    }

    /** Identifies "the same request" without depending on multipart boundaries, which change on every retry. */
    private function fingerprint(Request $request): string
    {
        $files = collect($request->allFiles())->flatten()->map(fn ($f) => [$f->getClientOriginalName(), $f->getSize()])->all();
        $data = $request->except(array_keys($request->allFiles()));
        $this->sortRecursively($data);

        return hash('sha256', json_encode([$request->method(), $request->path(), $data, $files]));
    }

    private function sortRecursively(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortRecursively($value);
            }
        }
    }
}
