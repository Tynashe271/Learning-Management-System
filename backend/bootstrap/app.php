<?php

use App\Http\Middleware\ConditionalGet;
use App\Http\Middleware\Idempotent;
use App\Http\Middleware\LimitJsonBody;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\SecurityHeaders;
use App\Support\SecurityLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Authentication is by bearer token, not cookies, so there is no session and no CSRF surface to defend.
        // Behind a reverse proxy or load balancer, list its address so client IPs (used for rate limits) and https are believed.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        // There is no login page: unauthenticated callers get a 401, never a redirect to a missing route.
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->prepend(RequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->api(prepend: [LimitJsonBody::class, MaintenanceMode::class], append: [SanitizeInput::class]);
        $middleware->alias(['idempotent' => Idempotent::class, 'conditional.get' => ConditionalGet::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errors under /api are always JSON, even without an Accept header (a browser, curl, a fresh Postman tab).
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Every JSON error carries the request id, so a user's report can be matched to the exact log lines.
        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            if ($request->is('api/*') && $response instanceof JsonResponse) {
                $response->setData(array_merge((array) $response->getData(true), ['request_id' => $request->attributes->get('request_id')]));
            }

            return $response;
        });

        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            return response()->json(['message' => 'The upload is too large.'], 413);
        });

        // Database or Redis unreachable: say "try again shortly" instead of a stack trace, and tell clients when to retry.
        $exceptions->render(function (QueryException|PDOException|RedisException $e, Request $request) {
            $down = str_contains($e->getMessage(), 'SQLSTATE[08') || str_contains($e->getMessage(), 'Connection refused')
                || str_contains($e->getMessage(), 'could not translate host') || $e instanceof RedisException;
            if (! $down || ! $request->is('api/*')) {
                return null;
            }
            report($e);

            return response()->json(['message' => 'The service is temporarily unavailable. Please try again in a moment.'], 503, ['Retry-After' => '5']);
        });

        // Security events: who was throttled, and who tried something they were not allowed to do.
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            SecurityLog::event('throttle.hit', ['method' => $request->method(), 'path' => $request->path()], 'warning');

            return null;
        });
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                SecurityLog::event('access.denied', ['method' => $request->method(), 'path' => $request->path()]);
            }

            return null;
        });
        // abort(403) throws a plain HttpException rather than an authorization one, so watch for the status code too.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() === 403 && $request->is('api/*')) {
                SecurityLog::event('access.denied', ['method' => $request->method(), 'path' => $request->path()]);
            }

            return null;
        });

        Integration::handles($exceptions);
    })->create();
