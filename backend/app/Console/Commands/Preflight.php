<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A launch checklist that reads the real configuration. Each line is PASS, WARN, or FAIL. Anything that only matters in
 * production is a WARN on a development machine and a FAIL on a production one; run with --production to see what a
 * production launch would require. Exits with an error if anything FAILs, so it can gate a deployment.
 */
class Preflight extends Command
{
    protected $signature = 'lms:preflight {--production : judge against production standards even if APP_ENV says otherwise}';

    protected $description = 'Check this installation against the launch checklist (security, reliability, cost limits)';

    private bool $production = false;

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    private array $rows = [];

    public function handle(): int
    {
        $this->production = (bool) $this->option('production') || app()->isProduction();
        $this->info($this->production ? 'Checking against PRODUCTION standards.' : 'Development install: production-only items show as WARN. Use --production to treat them as FAIL.');

        $this->security();
        $this->reliability();
        $this->costsAndLimits();

        $this->table(['Result', 'Area', 'Check', 'Detail'], $this->rows);
        $counts = array_count_values(array_column($this->rows, 0));
        $this->line(sprintf('%d passed, %d warnings, %d failed.', $counts['PASS'] ?? 0, $counts['WARN'] ?? 0, $counts['FAIL'] ?? 0));

        return ($counts['FAIL'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function security(): void
    {
        $this->check('Security', 'App key is set', filled(config('app.key')), 'Run php artisan key:generate.', always: true);
        $this->check('Security', 'Debug mode is off', ! config('app.debug'), 'APP_DEBUG=true shows stack traces and settings to anyone who triggers an error.');
        $this->check('Security', 'API address uses https', str_starts_with((string) config('app.url'), 'https://'), 'Set APP_URL to the https address.');
        $this->check('Security', 'Frontend address uses https', str_starts_with((string) config('lms.frontend_url'), 'https://'), 'Set LMS_FRONTEND_URL to the https address.');
        $origins = (array) config('cors.allowed_origins');
        $this->check('Security', 'CORS allows only named sites', $origins !== [] && ! in_array('*', $origins, true), 'Set LMS_CORS_ORIGINS to the frontend address; "*" lets any website call the API.', always: in_array('*', $origins, true));
        $this->check('Security', 'Trusted proxies are configured', filled(env('TRUSTED_PROXIES')), 'Behind a reverse proxy, set TRUSTED_PROXIES so client IPs (used by rate limits) and https are read correctly.');
        $this->check('Security', 'HSTS is sent', config('lms.security.force_hsts') || str_starts_with((string) config('app.url'), 'https://'), 'Serve over https, or set LMS_FORCE_HSTS=true when TLS is terminated in front.');
        $this->check('Security', 'Session cookies are secure', config('session.secure') === true, 'SESSION_SECURE_COOKIE should be true (this API uses bearer tokens, so cookies are not used anyway).');
        $ttl = config('sanctum.expiration');
        $this->check('Security', 'Sign-in tokens expire', is_int($ttl) && $ttl > 0 && $ttl <= 1440, is_int($ttl) ? "Tokens last {$ttl} minutes." : 'SANCTUM_TOKEN_EXPIRATION is not set.', always: ! is_int($ttl) || $ttl <= 0);
        $cost = (int) (Hash::info(Hash::make('probe'))['options']['cost'] ?? 12);
        $this->check('Security', 'Passwords use a strong hash', $cost >= 12, "bcrypt cost {$cost}; raise BCRYPT_ROUNDS to at least 12.");
        $attempts = (int) config('lms.security.lockout_attempts');
        $this->check('Security', 'Accounts lock after failed sign-ins', $attempts >= 3 && $attempts <= 10, "Locks after {$attempts} failures for ".config('lms.security.lockout_minutes').' minutes.', always: true);
        try {
            $missing = array_values(array_filter(['login', 'api', 'heavy', 'public', 'password', 'submissions', 'messages', 'checkin', 'sso', 'health'], fn ($name) => RateLimiter::limiter($name) === null));
        } catch (Throwable $e) {
            $missing = ['(could not check: '.$e->getMessage().')'];
        }
        $this->check('Security', 'Rate limits are defined', $missing === [], 'Missing: '.implode(', ', $missing), always: true);
        $types = array_map('trim', explode(',', (string) config('lms.upload_mimes')));
        $risky = array_intersect($types, ['php', 'phtml', 'exe', 'sh', 'bat', 'js', 'html', 'htm', 'svg']);
        $this->check('Security', 'Uploads limited to safe file types', $types !== [''] && $risky === [], $risky ? 'Remove: '.implode(', ', $risky) : 'Set LMS_UPLOAD_MIMES.', always: true);
        $this->check('Security', 'Uploads are virus-scanned', (bool) config('lms.virus_scan.enabled'), 'Start ClamAV (podman compose --profile scan up -d) and set LMS_VIRUS_SCAN=true.');
        $this->check('Security', 'Setup password not left in the environment', blank(env('LMS_ADMIN_PASSWORD')), 'Remove LMS_ADMIN_PASSWORD from .env once the first admin exists.');
        $this->check('Security', 'Local default passwords replaced', config('database.connections.pgsql.password') !== 'local-lms-password' && config('filesystems.disks.s3.key') !== 'localminio', 'Set real DB_PASSWORD and AWS_* credentials.');
        $this->check('Security', 'Horizon dashboard is restricted', Gate::has('viewHorizon') && ! Gate::forUser(new User)->allows('viewHorizon'), 'The viewHorizon gate must exist and refuse ordinary users.', always: true);
    }

    private function reliability(): void
    {
        $dbOk = $this->attempt(fn () => DB::select('select 1') !== []);
        $this->check('Reliability', 'Database is reachable', $dbOk, 'Cannot run a query.', always: true);
        if ($dbOk) {
            $pending = $this->pendingMigrations();
            $this->check('Reliability', 'No migrations are waiting', $pending === 0, "{$pending} pending; run php artisan migrate.", always: true);
            if (DB::connection()->getDriverName() === 'pgsql') {
                $super = (bool) DB::selectOne('select rolsuper from pg_roles where rolname = current_user')?->rolsuper;
                $this->check('Reliability', 'App connects with a restricted database role', ! $super, 'The app is connecting as a superuser; use a role with no DDL or admin rights (see infra/postgres).');
            }
        }
        $redisNeeded = in_array('redis', [config('cache.default'), config('queue.default')], true);
        if ($redisNeeded) {
            $this->check('Reliability', 'Redis is reachable', $this->attempt(fn () => (bool) Redis::connection()->ping()), 'Cache or queue is set to redis but it did not answer.', always: true);
        }
        $this->check('Reliability', 'Cache is shared between processes', ! in_array(config('cache.default'), ['array', 'file'], true), 'Use redis so rate limits, lockouts, and idempotency work across processes.');
        $this->check('Reliability', 'Jobs run in the background', config('queue.default') !== 'sync', 'QUEUE_CONNECTION=sync sends emails during the web request.');
        $this->check('Reliability', 'Failed jobs are retried', (int) config('horizon.defaults.supervisor-1.tries') > 1, 'Set tries above 1 in config/horizon.php.');
        $this->check('Reliability', 'File storage is reachable', $this->attempt(function () {
            Storage::disk('s3')->exists('.health');

            return true;
        }), 'Cannot reach the S3 bucket.', always: true);
        $this->check('Reliability', 'Email is set up to deliver', ! in_array(config('mail.default'), ['log', 'array'], true) && config('mail.mailers.smtp.host') !== 'mailpit', 'Mail still goes to the local test inbox (mailpit) or the log.');
        $this->check('Reliability', 'Errors are reported somewhere', filled(config('sentry.dsn')) || str_contains((string) env('LOG_STACK'), 'stderr'), 'Set SENTRY_LARAVEL_DSN, or add stderr to LOG_STACK and ship the container logs.');
        $this->check('Reliability', 'Upload limits in PHP fit the API', $this->bytes(ini_get('upload_max_filesize')) >= 26 * 1048576 && $this->bytes(ini_get('post_max_size')) >= 60 * 1048576, 'upload_max_filesize is '.ini_get('upload_max_filesize').' and post_max_size is '.ini_get('post_max_size').'; the API needs 26M and 60M (infra/php/lms.ini).');
        $this->check('Reliability', 'PHP hides errors and its version', ! filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) && ! filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN), 'Set display_errors=Off and expose_php=Off (infra/php/lms.ini).');
    }

    private function costsAndLimits(): void
    {
        $this->check('Limits', 'JSON request bodies are capped', (int) config('lms.limits.json_body_kb') > 0, 'LMS_JSON_BODY_KB is '.config('lms.limits.json_body_kb').'.', always: true);
        $this->check('Limits', 'Daily upload budget per person', (int) config('lms.limits.daily_upload_mb') > 0, 'LMS_DAILY_UPLOAD_MB is '.config('lms.limits.daily_upload_mb').'.', always: true);
        $this->check('Limits', 'Message attachments are capped', (int) config('lms.messages.max_attachments') > 0 && (int) config('lms.messages.max_attachment_kb') > 0, config('lms.messages.max_attachments').' files of '.config('lms.messages.max_attachment_kb').' KB.', always: true);
    }

    /** Records one result. Production-only items are a WARN on a development machine unless $always is set. */
    private function check(string $area, string $name, bool $ok, string $problem, bool $always = false): void
    {
        if ($ok) {
            $this->rows[] = ['PASS', $area, $name, ''];

            return;
        }
        $this->rows[] = [$always || $this->production ? 'FAIL' : 'WARN', $area, $name, $problem];
    }

    private function attempt(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            return false;
        }
    }

    private function pendingMigrations(): int
    {
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));

        return count(array_diff($files, app('migration.repository')->getRan()));
    }

    private function bytes(string|false $value): int
    {
        $value = trim((string) $value);
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
