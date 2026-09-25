<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\DirectMessage;
use App\Models\Enrolment;
use App\Models\ItemCompletion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->teach($this->offering, $this->lecturer);
    }

    private function students(int $count, ?CourseOffering $offering = null): Collection
    {
        $offering ??= $this->offering;
        $users = User::factory()->count($count)->create();
        $users->each(fn ($u) => $u->assignRole('student'));
        Enrolment::insert($users->map(fn ($u) => ['course_offering_id' => $offering->id, 'user_id' => $u->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()])->all());

        return $users;
    }

    private function queryCount(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * The number of queries an endpoint runs must not grow with the amount of data, which is what an N+1 problem is.
     * $grow adds more rows; the request is measured with a few rows and again with many.
     */
    private function assertQueriesDoNotGrow(callable $grow, callable $request): void
    {
        $grow(3);
        $request(); // warm caches (permissions, etc.) so they do not count against the first measurement
        $small = $this->queryCount($request);
        $grow(40);
        $large = $this->queryCount($request);

        $this->assertLessThanOrEqual($small + 1, $large, "queries grew from {$small} to {$large} as data grew");
    }

    // ---- N+1: query counts stay flat as classes grow -------------------------------------------------------------

    public function test_the_roster_uses_the_same_queries_for_a_big_class_as_a_small_one(): void
    {
        $this->assertQueriesDoNotGrow(fn (int $n) => $this->students($n), fn () => $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/roster')->assertOk());
    }

    public function test_the_gradebook_uses_the_same_queries_however_many_students_have_marks(): void
    {
        $essay = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
        $quiz = $this->offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'published' => true, 'max_attempts' => 1]);
        $quiz->questions()->create(['type' => 'true_false', 'prompt' => 'q', 'points' => 5]);
        $this->assertQueriesDoNotGrow(function (int $n) use ($essay, $quiz) {
            foreach ($this->students($n) as $student) {
                $essay->submissions()->create(['user_id' => $student->id, 'body' => 'x', 'submitted_at' => now()])->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => 50, 'status' => 'published']);
                $quiz->attempts()->create(['user_id' => $student->id, 'started_at' => now(), 'submitted_at' => now(), 'score' => 3, 'max_score' => 5]);
            }
        }, fn () => $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/gradebook')->assertOk());
    }

    public function test_progress_and_attendance_summaries_use_flat_query_counts(): void
    {
        $module = $this->offering->modules()->create(['title' => 'W1', 'published' => true]);
        $item = $module->items()->create(['title' => 'Read', 'type' => 'text', 'body' => 'x', 'published' => true]);
        $session = $this->offering->sessions()->create(['title' => 'L1', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
        $grow = function (int $n) use ($item, $session) {
            foreach ($this->students($n) as $student) {
                ItemCompletion::create(['learning_item_id' => $item->id, 'user_id' => $student->id]);
                $session->attendance()->create(['user_id' => $student->id, 'status' => 'present', 'marked_by' => $this->lecturer->id]);
            }
        };
        $this->assertQueriesDoNotGrow($grow, fn () => $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/progress')->assertOk());
        $this->assertQueriesDoNotGrow(fn (int $n) => $n, fn () => $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/attendance')->assertOk());
    }

    public function test_an_offering_page_uses_flat_queries_however_many_modules_and_items_it_has(): void
    {
        $this->assertQueriesDoNotGrow(function (int $n) {
            for ($i = 0; $i < $n; $i++) {
                $module = $this->offering->modules()->create(['title' => "Week {$i}", 'position' => $i, 'published' => true]);
                $module->items()->createMany([['title' => 'a', 'type' => 'text', 'body' => 'x', 'published' => true], ['title' => 'b', 'type' => 'text', 'body' => 'y', 'published' => true]]);
                $this->offering->assignments()->create(['title' => "A{$i}", 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
            }
        }, fn () => $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id)->assertOk());
    }

    public function test_a_students_course_list_uses_flat_queries(): void
    {
        $student = $this->userWithRole('student');
        $this->assertQueriesDoNotGrow(function (int $n) use ($student) {
            for ($i = 0; $i < $n; $i++) {
                $this->enrol($this->offering("S{$i}".uniqid()), $student);
            }
        }, fn () => $this->actingAs($student)->getJson('/api/offerings')->assertOk());
    }

    public function test_discussions_the_inbox_and_the_audit_log_use_flat_queries(): void
    {
        $student = $this->userWithRole('student');
        $this->enrol($this->offering, $student);
        $this->assertQueriesDoNotGrow(function (int $n) use ($student) {
            for ($i = 0; $i < $n; $i++) {
                $thread = $this->offering->threads()->create(['user_id' => $student->id, 'title' => "T{$i}", 'body' => 'b']);
                $thread->posts()->create(['user_id' => $student->id, 'body' => 'reply']);
            }
        }, fn () => $this->actingAs($student)->getJson('/api/offerings/'.$this->offering->id.'/discussions')->assertOk());

        $this->assertQueriesDoNotGrow(function (int $n) use ($student) {
            foreach ($this->students($n) as $other) {
                DirectMessage::create(['sender_id' => $other->id, 'recipient_id' => $student->id, 'body' => 'hi']);
            }
        }, fn () => $this->actingAs($student)->getJson('/api/messages')->assertOk());

        $admin = $this->userWithRole('university-admin');
        $this->assertQueriesDoNotGrow(function (int $n) use ($admin) {
            for ($i = 0; $i < $n; $i++) {
                activity()->causedBy($admin)->log("event {$i}");
            }
        }, fn () => $this->actingAs($admin)->getJson('/api/audit-log')->assertOk());
    }

    public function test_a_quiz_with_many_questions_uses_flat_queries_for_its_author(): void
    {
        $quiz = $this->offering->quizzes()->create(['title' => 'Quiz', 'due_at' => now()->addDay(), 'published' => true, 'max_attempts' => 1]);
        $this->assertQueriesDoNotGrow(function (int $n) use ($quiz) {
            for ($i = 0; $i < $n; $i++) {
                $quiz->questions()->create(['type' => 'single_choice', 'prompt' => "Q{$i}", 'points' => 1, 'position' => $i])->options()->createMany([['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => false]]);
            }
        }, fn () => $this->actingAs($this->lecturer)->getJson('/api/quizzes/'.$quiz->id)->assertOk());
    }

    // ---- pagination ----------------------------------------------------------------------------------------------

    public function test_the_roster_is_paged_with_a_hard_ceiling(): void
    {
        $this->students(130);
        $url = '/api/offerings/'.$this->offering->id.'/roster';

        $first = $this->actingAs($this->lecturer)->getJson($url)->assertOk();
        $this->assertCount(100, $first->json('enrolments'));
        $this->assertSame(['page' => 1, 'per_page' => 100, 'total' => 130, 'last_page' => 2], $first->json('meta'));
        $this->assertCount(30, $this->actingAs($this->lecturer)->getJson($url.'?page=2')->json('enrolments'));
        $this->assertCount(20, $this->actingAs($this->lecturer)->getJson($url.'?per_page=20')->json('enrolments'));
        $this->actingAs($this->lecturer)->getJson($url.'?per_page=201')->assertJsonValidationErrors('per_page');
        $this->actingAs($this->lecturer)->getJson($url.'?per_page=0')->assertJsonValidationErrors('per_page');
        $this->actingAs($this->lecturer)->getJson($url.'?page=0')->assertJsonValidationErrors('page');
        $this->actingAs($this->lecturer)->getJson($url.'?per_page=abc')->assertJsonValidationErrors('per_page');
    }

    public function test_the_gradebook_pages_alphabetically_and_the_csv_still_contains_everyone(): void
    {
        foreach (range(1, 250) as $i) {
            $u = User::factory()->create(['name' => sprintf('Student %03d', $i)]);
            $u->assignRole('student');
            $this->enrol($this->offering, $u);
        }
        $url = '/api/offerings/'.$this->offering->id.'/gradebook';

        $page1 = $this->actingAs($this->lecturer)->getJson($url)->assertOk();
        $page3 = $this->actingAs($this->lecturer)->getJson($url.'?page=3')->assertOk();

        $this->assertCount(100, $page1->json('rows'));
        $this->assertSame('Student 001', $page1->json('rows.0.user.name'));
        $this->assertSame('Student 100', $page1->json('rows.99.user.name'));
        $this->assertSame('Student 201', $page3->json('rows.0.user.name'));
        $this->assertCount(50, $page3->json('rows'));
        $this->assertSame(['page' => 3, 'per_page' => 100, 'total' => 250, 'last_page' => 3], $page3->json('meta'));

        $csv = $this->actingAs($this->lecturer)->get($url.'?format=csv')->assertOk()->streamedContent();
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(251, $lines, 'a header plus every one of the 250 students');
        $this->assertStringContainsString('Student 250', $csv);
        $this->assertSame(1, substr_count($csv, 'Name,Email'), 'the header is written once, not once per chunk');
    }

    public function test_progress_and_attendance_summaries_are_paged_too(): void
    {
        $this->students(120);
        $module = $this->offering->modules()->create(['title' => 'W1', 'published' => true]);
        $module->items()->create(['title' => 'Read', 'type' => 'text', 'body' => 'x', 'published' => true]);

        $progress = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/progress')->assertOk();
        $attendance = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/attendance?per_page=50')->assertOk();

        $this->assertCount(100, $progress->json('students'));
        $this->assertSame(120, $progress->json('meta.total'));
        $this->assertCount(50, $attendance->json('students'));
        $this->assertSame(3, $attendance->json('meta.last_page'));
    }

    // ---- caching, budgets, and limits ----------------------------------------------------------------------------

    public function test_the_institution_overview_is_calculated_once_a_minute(): void
    {
        $admin = $this->userWithRole('university-admin');
        // Real caches (Redis) turn what is stored into text and back; the test cache must too, or a value that cannot survive
        // that trip (an object) would pass here and be broken in production.
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array'); // the store reads its settings when it is first built, so build it again
        Cache::forget('reports:overview');
        $this->actingAs($admin)->getJson('/api/reports/overview')->assertOk();
        $cached = $this->actingAs($admin)->getJson('/api/reports/overview')->assertOk();
        $this->assertEquals(['lecturer' => 1, 'university-admin' => 1], $cached->json('users.by_role'), 'the cached copy is as usable as the fresh one');
        $this->assertArrayNotHasKey('__PHP_Incomplete_Class_Name', $cached->json('users.by_role'));
        Cache::forget('reports:overview');

        $first = $this->actingAs($admin)->getJson('/api/reports/overview')->assertOk()->json('users.total');

        $this->userWithRole('student');
        $this->assertSame($first, $this->actingAs($admin)->getJson('/api/reports/overview')->json('users.total'), 'a second look within a minute reuses the calculation');
        $queries = $this->queryCount(fn () => $this->actingAs($admin)->getJson('/api/reports/overview')->assertOk());

        Cache::forget('reports:overview');
        $this->assertSame($first + 1, $this->actingAs($admin)->getJson('/api/reports/overview')->json('users.total'));
        $this->assertLessThan(10, $queries, 'a cached overview runs no counting queries');
    }

    public function test_the_overview_recalculates_after_a_minute(): void
    {
        $admin = $this->userWithRole('university-admin');
        $first = $this->actingAs($admin)->getJson('/api/reports/overview')->json('users.total');
        $this->userWithRole('student');

        $this->travel(61)->seconds();

        $this->assertSame($first + 1, $this->actingAs($admin)->getJson('/api/reports/overview')->json('users.total'));
    }

    public function test_message_uploads_have_a_daily_budget_per_person(): void
    {
        Storage::fake('s3');
        config(['lms.limits.daily_upload_mb' => 1]);
        $ada = $this->userWithRole('student');
        $ben = $this->userWithRole('student');
        $this->enrol($this->offering, $ada);
        $this->enrol($this->offering, $ben);
        $send = fn (User $from, User $to, int $kb) => $this->actingAs($from)->post('/api/messages/'.$to->id, ['body' => 'x', 'attachments' => [UploadedFile::fake()->create('f.pdf', $kb, 'application/pdf')]], ['Accept' => 'application/json']);

        $send($ada, $ben, 600)->assertCreated();
        $send($ada, $ben, 600)->assertJsonValidationErrors('attachments');   // 1200 KB in a day is over the 1 MB budget
        $send($ben, $ada, 600)->assertCreated();                                 // Ben's own budget is untouched
        $send($ada, $ben, 300)->assertCreated();                                 // 900 KB in total is still inside the budget

        $this->travel(25)->hours();
        $send($ada, $ben, 600)->assertCreated();                                 // a new day
    }

    public function test_expensive_endpoints_have_a_tighter_limit_than_ordinary_ones(): void
    {
        $admin = $this->userWithRole('university-admin');
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($admin)->getJson('/api/reports/overview')->assertOk();
        }
        $this->actingAs($admin)->getJson('/api/reports/overview')->assertStatus(429);

        $this->actingAs($admin)->getJson('/api/terms')->assertOk(); // an ordinary endpoint is unaffected
        $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id.'/gradebook')->assertOk();
    }

    // ---- indexes -------------------------------------------------------------------------------------------------

    public function test_every_foreign_key_column_has_an_index_that_starts_with_it(): void
    {
        $missing = [];
        foreach (Schema::getTables() as $table) {
            $leading = collect(Schema::getIndexes($table['name']))->map(fn ($index) => $index['columns'][0] ?? null)->filter()->all();
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (! in_array($foreignKey['columns'][0], $leading, true)) {
                    $missing[] = $table['name'].'.'.$foreignKey['columns'][0];
                }
            }
        }

        $this->assertSame([], $missing, 'Foreign-key columns without an index scan the whole table on every lookup: '.implode(', ', $missing));
    }

    public function test_the_named_indexes_and_the_case_insensitive_email_index_exist(): void
    {
        $names = fn (string $table) => collect(Schema::getIndexes($table))->pluck('name')->all();

        $this->assertContains('idx_enrolments_user_id_status', $names('enrolments'));
        $this->assertContains('idx_grade_records_submission_id_status_id', $names('grade_records'));
        $this->assertContains('idx_quiz_questions_quiz_id_position', $names('quiz_questions'));
        $this->assertContains('users_email_lower_unique', $names('users'));
    }
}
