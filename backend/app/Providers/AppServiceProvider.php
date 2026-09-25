<?php

namespace App\Providers;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Policies\AssignmentPolicy;
use App\Policies\CourseOfferingPolicy;
use App\Policies\QuizPolicy;
use App\Services\OidcClient;
use App\Services\VirusScanner;
use App\Support\Settings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\LazyLoadingViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(VirusScanner::class, fn () => new VirusScanner);
        $this->app->singleton(OidcClient::class, fn () => new OidcClient);
        $this->app->singleton(Settings::class, fn ($app) => new Settings($app['config']));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Saved administration settings are laid over the configuration before anything reads it.
        $this->app->make(Settings::class)->apply();

        Gate::policy(CourseOffering::class, CourseOfferingPolicy::class);
        Gate::policy(Assignment::class, AssignmentPolicy::class);
        Gate::policy(Quiz::class, QuizPolicy::class);
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('lms.frontend_url'), '/').'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset()));
        // Find N+1 queries early: in development and tests loading a relation on the fly is an error; in production it is
        // only logged, so a missed case slows one request instead of breaking it.
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            if (app()->isProduction()) {
                Log::warning('Lazy loading', ['model' => $model::class, 'relation' => $relation]);

                return;
            }
            throw new LazyLoadingViolationException($model, $relation);
        });
        // Expensive endpoints (exports, imports, reports, similarity, copying) get a tighter per-person limit.
        RateLimiter::for('heavy', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        // Unauthenticated status endpoints are limited by address.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        // Ten wrong codes a minute cannot brute-force a six-digit code inside its window.
        RateLimiter::for('checkin', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('sso', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(5)->by($request->ip().'|'.$request->input('email')));
        // A super-admin passes every check except the ones that mean "you are a student in this course": submitting work
        // and taking a quiz still need an active enrolment, so a super-admin cannot leave stray submissions in a class.
        Gate::before(fn ($user, $ability) => $user->hasRole('super-admin') && ! in_array($ability, ['submit', 'take'], true) ? true : null);
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip().'|'.$request->input('email')));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('submissions', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
    }
}
