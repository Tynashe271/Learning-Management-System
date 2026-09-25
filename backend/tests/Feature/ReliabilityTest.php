<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PDOException;
use RuntimeException;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class ReliabilityTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->student = $this->userWithRole('student');
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->student);
    }

    private function alive(): void
    {
        Cache::put('lms:scheduler:heartbeat', time(), 900);
    }

    // ---- health and uptime monitoring ----------------------------------------------------------------------------

    public function test_the_health_endpoint_reports_ok_without_signing_in(): void
    {
        $this->alive();

        $response = $this->getJson('/api/health')->assertOk();

        $response->assertJsonPath('status', 'ok')->assertJsonPath('checks.database', 'ok')->assertJsonPath('checks.cache', 'ok')
            ->assertJsonPath('checks.storage', 'ok')->assertJsonPath('checks.scheduler', 'ok')->assertJsonPath('checks.queue', 'ok');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(['checks', 'status', 'time'], collect(array_keys($response->json()))->except([])->sort()->values()->all() ?: []);
    }

    public function test_the_health_endpoint_reveals_no_versions_hosts_or_errors(): void
    {
        $this->alive();
        DB::partialMock()->shouldReceive('select')->andThrow(new RuntimeException('password authentication failed for user "lms" at db.internal'));

        $body = $this->getJson('/api/health')->assertStatus(503)->getContent();

        foreach (['password', 'db.internal', 'lms"', 'RuntimeException', 'PHP', 'Laravel'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_a_stopped_scheduler_shows_as_degraded_but_the_service_stays_up(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.scheduler', 'stale');

        Cache::put('lms:scheduler:heartbeat', time() - 600, 900);
        $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.scheduler', 'stale');
    }

    public function test_the_database_being_down_is_a_503(): void
    {
        $this->alive();
        DB::partialMock()->shouldReceive('select')->andThrow(new RuntimeException('connection refused'));

        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('status', 'down')->assertJsonPath('checks.database', 'fail');
    }

    public function test_file_storage_being_down_is_a_503(): void
    {
        $this->alive();
        Storage::shouldReceive('disk')->with('s3')->andThrow(new RuntimeException('bucket unreachable'));

        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.storage', 'fail');
    }

    public function test_a_failing_cache_is_a_503(): void
    {
        // Fake only what the health check touches; the rate limiter holds its own cache handle and is unaffected.
        Cache::shouldReceive('put')->andThrow(new RuntimeException('redis down'));
        Cache::shouldReceive('get')->andReturn(time());

        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.cache', 'fail');
    }

    public function test_a_backed_up_queue_and_failing_jobs_show_as_degraded(): void
    {
        $this->alive();
        Queue::shouldReceive('size')->andReturn(5000);
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.queue', 'backlog');
    }

    public function test_many_recent_failed_jobs_show_as_degraded(): void
    {
        $this->alive();
        foreach (range(1, 10) as $i) {
            DB::table('failed_jobs')->insert(['uuid' => "00000000-0000-0000-0000-0000000000{$i}0", 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        }

        $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.queue', 'failing');
    }

    public function test_the_health_endpoint_is_rate_limited_by_address(): void
    {
        $this->alive();
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/health')->assertOk();
        }

        $this->getJson('/api/health')->assertStatus(429);
    }

    public function test_the_scheduler_writes_a_heartbeat_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'scheduler-heartbeat');

        $this->assertNotNull($event, 'the heartbeat job must be scheduled');
        $this->assertSame('* * * * *', $event->expression);
    }

    // ---- error handling ------------------------------------------------------------------------------------------

    private function failingRoute(callable $throw): string
    {
        $path = '/api/_test/'.uniqid('fail');
        Route::middleware('api')->get($path, $throw);

        return $path;
    }

    public function test_an_unexpected_error_is_a_generic_json_500_with_a_request_id_and_no_secrets(): void
    {
        config(['app.debug' => false]);
        $path = $this->failingRoute(fn () => throw new RuntimeException('secret database password hunter2'));

        $response = $this->getJson($path)->assertStatus(500);

        $this->assertStringNotContainsString('hunter2', $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('request_id'));
        $this->assertIsString($response->json('message'));
    }

    public function test_a_database_that_cannot_be_reached_is_a_503_with_a_retry_hint(): void
    {
        config(['app.debug' => false]);
        $path = $this->failingRoute(fn () => throw new QueryException('pgsql', 'select 1', [], new PDOException('SQLSTATE[08006] [7] could not connect: Connection refused')));

        $response = $this->getJson($path)->assertStatus(503);

        $response->assertHeader('Retry-After', '5');
        $this->assertStringContainsString('temporarily unavailable', $response->json('message'));
        $this->assertNotNull($response->json('request_id'));
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_a_broken_query_is_a_generic_500_and_never_shows_the_sql(): void
    {
        config(['app.debug' => false]);
        $path = $this->failingRoute(fn () => throw new QueryException('pgsql', 'select secret_column from hidden_table', [], new PDOException('SQLSTATE[42703] undefined column')));

        $response = $this->getJson($path)->assertStatus(500);

        $this->assertStringNotContainsString('secret_column', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_a_redis_outage_is_a_503_too(): void
    {
        if (! class_exists(\RedisException::class)) {
            $this->markTestSkipped('The Redis extension is not installed here.');
        }
        config(['app.debug' => false]);
        $path = $this->failingRoute(fn () => throw new \RedisException('Connection refused'));

        $this->getJson($path)->assertStatus(503)->assertHeader('Retry-After', '5');
    }

    public function test_an_oversized_request_is_a_clean_413(): void
    {
        $path = $this->failingRoute(fn () => throw new PostTooLargeException);

        $this->getJson($path)->assertStatus(413)->assertJsonPath('message', 'The upload is too large.');
    }

    public function test_the_deployment_config_sets_timeouts_retries_and_compression(): void
    {
        $this->assertSame(5, config('database.connections.pgsql.options')[\PDO::ATTR_TIMEOUT]);
        $this->assertSame(5, config('filesystems.disks.s3.http.connect_timeout'));
        $this->assertSame(60, config('filesystems.disks.s3.http.timeout'));
        $this->assertGreaterThan(0.0, config('database.redis.default.timeout'));
        $this->assertSame(3, config('horizon.defaults.supervisor-1.tries'));
        $this->assertSame(30, config('horizon.defaults.supervisor-1.backoff'));
        $this->assertSame(60, config('horizon.defaults.supervisor-1.timeout'));

        $nginx = base_path('../infra/nginx/default.conf');
        if (! is_file($nginx)) {
            $this->markTestIncomplete('The infra folder is not visible from here.');
        }
        $conf = file_get_contents($nginx);
        foreach (['server_tokens off', 'gzip on', 'gzip_types application/json', 'fastcgi_read_timeout 60s', 'autoindex off', 'client_max_body_size 55m', 'fastcgi_hide_header X-Powered-By'] as $directive) {
            $this->assertStringContainsString($directive, $conf);
        }
    }

    // ---- safe retries (idempotency) ------------------------------------------------------------------------------

    private function announce(array $body = ['title' => 'Welcome', 'body' => 'Read chapter 1.'], string $key = 'retry-key-0001', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->lecturer)->withHeaders(['Idempotency-Key' => $key])->postJson('/api/offerings/'.$this->offering->id.'/announcements', $body);
    }

    public function test_repeating_a_request_with_the_same_key_does_not_repeat_the_action(): void
    {
        $first = $this->announce()->assertCreated();
        $second = $this->announce()->assertCreated();

        $this->assertSame(1, $this->offering->announcements()->count());
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame($first->getContent(), $second->getContent());
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertFalse($first->headers->has('Idempotent-Replayed'));
    }

    public function test_requests_without_a_key_behave_normally(): void
    {
        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/announcements', ['title' => 'A', 'body' => 'b'])->assertCreated();
        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/announcements', ['title' => 'A', 'body' => 'b'])->assertCreated();

        $this->assertSame(2, $this->offering->announcements()->count());
    }

    public function test_reusing_a_key_for_a_different_request_is_refused(): void
    {
        $this->announce()->assertCreated();

        $this->announce(['title' => 'Something else', 'body' => 'entirely'])->assertUnprocessable()->assertJsonPath('message', 'This Idempotency-Key was already used with a different request.');

        $this->assertSame(1, $this->offering->announcements()->count());
    }

    public function test_the_same_key_from_different_people_is_independent(): void
    {
        $other = $this->userWithRole('lecturer');
        $this->teach($this->offering, $other);

        $this->announce()->assertCreated();
        $this->announce(as: $other)->assertCreated();

        $this->assertSame(2, $this->offering->announcements()->count());
    }

    public function test_a_failed_request_is_not_remembered_so_it_can_be_corrected_and_retried(): void
    {
        $this->announce(['title' => 'No body'])->assertUnprocessable();

        $this->announce(['title' => 'With body', 'body' => 'Now valid'])->assertCreated();

        $this->assertSame(1, $this->offering->announcements()->count());
    }

    public function test_a_malformed_key_is_rejected(): void
    {
        foreach (['short', str_repeat('k', 101), 'has spaces in it!', 'bad/slash/key1'] as $key) {
            $this->announce(key: $key)->assertUnprocessable();
        }
        $this->assertSame(0, $this->offering->announcements()->count());
    }

    public function test_a_request_still_in_progress_is_answered_with_a_conflict(): void
    {
        $cacheKey = 'idem:'.hash('sha256', $this->lecturer->id.'|POST|api/offerings/'.$this->offering->id.'/announcements|retry-key-0001');
        $lock = Cache::lock($cacheKey.':lock', 30);
        $this->assertTrue($lock->get());

        $this->announce()->assertStatus(409)->assertHeader('Retry-After');

        $lock->release();
        $this->announce()->assertCreated();
    }

    public function test_the_stored_answer_expires_after_a_day(): void
    {
        $this->announce()->assertCreated();

        $this->travel(25)->hours();

        $this->announce()->assertCreated();
        $this->assertSame(2, $this->offering->announcements()->count());
    }

    public function test_file_uploads_can_be_retried_safely_too(): void
    {
        $assignment = $this->offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 20, 'published' => true]);
        $send = fn () => $this->actingAs($this->student)->withHeaders(['Idempotency-Key' => 'upload-key-0001', 'Accept' => 'application/json'])
            ->post('/api/assignments/'.$assignment->id.'/submissions', ['body' => 'Notes', 'file' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')]);

        $first = $send()->assertCreated();
        $again = $send()->assertCreated();

        $this->assertSame(1, DB::table('submissions')->count());
        $this->assertCount(1, Storage::disk('s3')->allFiles());
        $this->assertSame($first->json('id'), $again->json('id'));
        $again->assertHeader('Idempotent-Replayed', 'true');
    }

    // ---- caching repeated requests -------------------------------------------------------------------------------

    public function test_an_unchanged_answer_is_a_304_and_a_changed_one_is_a_fresh_200(): void
    {
        $first = $this->actingAs($this->student)->getJson('/api/offerings')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('private', $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('Authorization', $first->headers->get('Vary'));

        $again = $this->actingAs($this->student)->withHeaders(['If-None-Match' => $etag])->getJson('/api/offerings');
        $again->assertStatus(304);
        $this->assertSame('', $again->getContent());

        $this->enrol($this->offering('B'), $this->student);
        $changed = $this->actingAs($this->student)->withHeaders(['If-None-Match' => $etag])->getJson('/api/offerings')->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
    }

    public function test_a_weakened_etag_from_a_compressing_proxy_still_matches(): void
    {
        $etag = $this->actingAs($this->student)->getJson('/api/offerings')->assertOk()->headers->get('ETag');

        // nginx turns "abc" into W/"abc" when it gzips the body, and the browser echoes that back.
        $this->actingAs($this->student)->withHeaders(['If-None-Match' => 'W/'.$etag])->getJson('/api/offerings')->assertStatus(304);
        $this->actingAs($this->student)->withHeaders(['If-None-Match' => '"stale", W/'.$etag])->getJson('/api/offerings')->assertStatus(304);
        $this->actingAs($this->student)->withHeaders(['If-None-Match' => '*'])->getJson('/api/offerings')->assertStatus(304);
        $this->actingAs($this->student)->withHeaders(['If-None-Match' => 'W/"something-else"'])->getJson('/api/offerings')->assertOk();
    }

    public function test_one_persons_cached_answer_is_never_handed_to_another(): void
    {
        $mine = $this->actingAs($this->student)->getJson('/api/me')->assertOk();
        $etag = $mine->headers->get('ETag');

        $theirs = $this->actingAs($this->lecturer)->withHeaders(['If-None-Match' => $etag])->getJson('/api/me')->assertOk();

        $this->assertNotSame($etag, $theirs->headers->get('ETag'));
        $theirs->assertJsonPath('id', $this->lecturer->id);
    }

    public function test_streamed_downloads_and_writes_are_never_given_an_etag(): void
    {
        $module = $this->offering->modules()->create(['title' => 'Week 1', 'published' => true]);
        $item = $module->items()->create(['title' => 'Notes', 'type' => 'file', 'storage_path' => 'course-files/notes.txt', 'published' => true]);
        Storage::disk('s3')->put($item->storage_path, 'file content');

        $download = $this->actingAs($this->student)->withHeaders(['If-None-Match' => '"'.md5('').'"'])->get('/api/items/'.$item->id.'/download')->assertOk();

        $this->assertSame('file content', $download->streamedContent(), 'a stale validator must not turn a file into an empty 304');
        $this->assertFalse($download->headers->has('ETag'));
        $this->assertFalse($this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/announcements', ['title' => 'A', 'body' => 'b'])->headers->has('ETag'));
    }
}
