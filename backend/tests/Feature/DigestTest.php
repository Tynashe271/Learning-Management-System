<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\GradeAppeal;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use App\Notifications\Digest;
use App\Notifications\GradePublished;
use App\Services\DigestBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class DigestTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['lms.frontend_url' => 'https://lms.test', 'lms.digest.default' => 'off']);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student', ['name' => 'Ada']);
        $this->ben = $this->userWithRole('student', ['name' => 'Ben']);
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->enrol($this->offering, $this->ben);
    }

    private function build(User $user, ?\DateTimeInterface $since = null): ?array
    {
        return app(DigestBuilder::class)->build($user, $since ? now()->setTimestamp($since->getTimestamp()) : now()->subDay());
    }

    private function assignment(array $overrides = []): Assignment
    {
        return Assignment::create($overrides + ['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDays(3), 'max_score' => 100, 'published' => true]);
    }

    private function optIn(User $user, string $frequency = 'daily'): User
    {
        $user->forceFill(['digest_frequency' => $frequency])->save();

        return $user;
    }

    // ---- preferences ---------------------------------------------------------------------------------------------

    public function test_summaries_are_off_until_someone_turns_them_on(): void
    {
        $this->actingAs($this->ada)->getJson('/api/me/preferences')->assertOk()->assertJsonPath('digest_frequency', 'off')->assertJsonPath('digest_sent_at', null);

        $this->actingAs($this->ada)->patchJson('/api/me/preferences', ['digest_frequency' => 'weekly'])->assertOk()->assertJsonPath('digest_frequency', 'weekly');

        $this->assertSame('weekly', $this->ada->fresh()->digest_frequency);
        $this->actingAs($this->ada)->getJson('/api/me')->assertJsonPath('digest_frequency', 'weekly');
    }

    public function test_only_known_frequencies_are_accepted(): void
    {
        $this->actingAs($this->ada)->patchJson('/api/me/preferences', ['digest_frequency' => 'hourly'])->assertJsonValidationErrors('digest_frequency');
        $this->actingAs($this->ada)->patchJson('/api/me/preferences', [])->assertJsonValidationErrors('digest_frequency');
        $this->assertSame('off', $this->ada->fresh()->digest_frequency);
    }

    public function test_people_change_only_their_own_setting(): void
    {
        $this->actingAs($this->ada)->patchJson('/api/me/preferences', ['digest_frequency' => 'daily', 'user_id' => $this->ben->id, 'digest_sent_at' => now()->toIso8601String()])->assertOk();

        $this->assertSame('off', $this->ben->fresh()->digest_frequency);
        $this->assertNull($this->ada->fresh()->digest_sent_at);
    }

    public function test_new_accounts_start_on_the_institutions_default(): void
    {
        config(['lms.digest.default' => 'weekly']);
        $this->assertSame('weekly', User::factory()->create()->fresh()->digest_frequency);

        $admin = $this->userWithRole('university-admin');
        $created = $this->actingAs($admin)->postJson('/api/users', ['name' => 'New', 'email' => 'new@uni.test', 'password' => 'a-long-password-1', 'role' => 'student'])->assertCreated();
        $this->assertSame('weekly', User::findOrFail($created->json('id'))->digest_frequency);

        config(['lms.digest.default' => 'monthly']); // a typo in the configuration falls back to off
        $this->assertSame('off', User::factory()->create()->fresh()->digest_frequency);
    }

    // ---- what goes in a summary ----------------------------------------------------------------------------------

    public function test_a_fresh_student_has_nothing_to_report(): void
    {
        $this->assertNull($this->build($this->ben));
    }

    public function test_unread_updates_since_the_last_summary_are_grouped_by_kind(): void
    {
        $this->ada->notify(new AnnouncementPosted(1, $this->offering->id, 'Welcome to week 1'));
        $this->ada->notify(new AnnouncementPosted(2, $this->offering->id, 'Room change'));
        $this->ada->notify(new GradePublished(1, 1, 'Essay'));

        $summary = $this->build($this->ada);

        $this->assertSame(3, $summary['notifications']['total']);
        $groups = collect($summary['notifications']['groups'])->keyBy('label');
        $this->assertSame(2, $groups['New announcements']['count']);
        $this->assertEqualsCanonicalizing(['Welcome to week 1', 'Room change'], $groups['New announcements']['titles']);
        $this->assertSame(['Essay'], $groups['Grades published']['titles']);
    }

    public function test_read_and_older_updates_are_left_out(): void
    {
        $this->ada->notify(new AnnouncementPosted(1, $this->offering->id, 'Already read'));
        $this->ada->unreadNotifications()->first()->markAsRead();
        $this->ada->notify(new AnnouncementPosted(2, $this->offering->id, 'Old news'));
        $this->ada->unreadNotifications()->where('data->title', 'Old news')->update(['created_at' => now()->subDays(3)]);
        $this->ada->notify(new AnnouncementPosted(3, $this->offering->id, 'Fresh'));

        $summary = $this->build($this->ada, now()->subDay());

        $this->assertSame(1, $summary['notifications']['total']);
        $this->assertSame(['Fresh'], $summary['notifications']['groups'][0]['titles']);
    }

    public function test_only_unsubmitted_work_due_within_a_week_appears_as_a_deadline(): void
    {
        $this->assignment(['title' => 'Due soon']);
        $this->assignment(['title' => 'Already handed in', 'due_at' => now()->addDays(2)])->submissions()->create(['user_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now()]);
        $this->assignment(['title' => 'Too far away', 'due_at' => now()->addDays(10)]);
        $this->assignment(['title' => 'Already over', 'due_at' => now()->subDay()]);
        $this->assignment(['title' => 'Draft', 'published' => false]);
        $other = $this->offering('B');
        $this->assignment(['title' => 'Someone else\'s course', 'course_offering_id' => $other->id]);

        $summary = $this->build($this->ada);

        $this->assertSame(['Due soon'], array_column($summary['deadlines'], 'title'));
        $this->assertSame('assignment', $summary['deadlines'][0]['type']);
        $this->assertSame('CSC101', $summary['deadlines'][0]['course']);
    }

    public function test_quizzes_due_soon_appear_until_they_have_been_taken(): void
    {
        $open = $this->offering->quizzes()->create(['title' => 'Open quiz', 'due_at' => now()->addDays(2), 'published' => true, 'max_attempts' => 1]);
        $taken = $this->offering->quizzes()->create(['title' => 'Taken quiz', 'due_at' => now()->addDays(2), 'published' => true, 'max_attempts' => 1]);
        $this->offering->quizzes()->create(['title' => 'Not open yet', 'due_at' => now()->addDays(2), 'opens_at' => now()->addDay(), 'published' => true, 'max_attempts' => 1]);
        $taken->attempts()->create(['user_id' => $this->ada->id, 'started_at' => now(), 'submitted_at' => now(), 'score' => 1, 'max_score' => 1]);
        $open->attempts()->create(['user_id' => $this->ada->id, 'started_at' => now()]); // started but not finished

        $summary = $this->build($this->ada);

        $this->assertSame(['Open quiz'], array_column($summary['deadlines'], 'title'));
        $this->assertSame('quiz', $summary['deadlines'][0]['type']);
    }

    public function test_withdrawn_students_and_unpublished_courses_have_no_deadlines(): void
    {
        $this->assignment();
        $this->offering->enrolments()->where('user_id', $this->ben->id)->update(['status' => 'withdrawn']);
        $this->assertNull($this->build($this->ben));

        $this->offering->update(['published' => false]);
        $this->assertNull($this->build($this->ada));
    }

    public function test_teaching_staff_hear_about_work_waiting_for_a_grade_and_open_appeals(): void
    {
        $graded = $this->assignment(['title' => 'Graded essay']);
        $waiting = $this->assignment(['title' => 'Waiting essay']);
        $this->assignment(['title' => 'Unpublished', 'published' => false])->submissions()->create(['user_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now()]);
        $cy = $this->userWithRole('student');
        $this->enrol($this->offering, $cy);
        // Ada: a published grade. Ben: only a draft. Cy: nothing yet.
        $adaSub = $graded->submissions()->create(['user_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now()]);
        $adaGrade = $adaSub->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => 50, 'status' => 'published']);
        $benSub = $waiting->submissions()->create(['user_id' => $this->ben->id, 'body' => 'x', 'submitted_at' => now()]);
        $benSub->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => 40, 'status' => 'draft']);
        $waiting->submissions()->create(['user_id' => $cy->id, 'body' => 'x', 'submitted_at' => now()]);
        GradeAppeal::create(['submission_id' => $adaSub->id, 'user_id' => $this->ada->id, 'grade_record_id' => $adaGrade->id, 'reason' => 'I think it deserves more.']);

        $summary = $this->build($this->lecturer);

        $this->assertSame([['assignment' => 'Waiting essay', 'course' => 'CSC101', 'awaiting' => 2]], $summary['to_grade']);
        $this->assertSame(1, $summary['open_appeals']);
    }

    public function test_assistants_see_work_to_grade_but_not_appeals_and_students_see_neither(): void
    {
        $assistant = $this->userWithRole('teaching-assistant');
        $this->teach($this->offering, $assistant);
        $essay = $this->assignment();
        $sub = $essay->submissions()->create(['user_id' => $this->ada->id, 'body' => 'x', 'submitted_at' => now()]);
        $grade = $sub->gradeRecords()->create(['graded_by' => $this->lecturer->id, 'score' => 50, 'status' => 'published']);
        GradeAppeal::create(['submission_id' => $sub->id, 'user_id' => $this->ada->id, 'grade_record_id' => $grade->id, 'reason' => 'I think it deserves more.']);
        $essay->submissions()->create(['user_id' => $this->ben->id, 'body' => 'y', 'submitted_at' => now()]);

        $this->assertSame(1, $this->build($assistant)['to_grade'][0]['awaiting']);
        $this->assertArrayNotHasKey('open_appeals', $this->build($assistant));
        $this->assertArrayNotHasKey('to_grade', $this->build($this->ben) ?? []);
    }

    // ---- previews and the email ----------------------------------------------------------------------------------

    public function test_the_preview_shows_what_would_be_sent_without_sending_anything(): void
    {
        Notification::fake();
        $this->assignment(['title' => 'Due soon']);

        $preview = $this->actingAs($this->ada)->getJson('/api/me/digest-preview')->assertOk();

        $preview->assertJsonPath('summary.deadlines.0.title', 'Due soon');
        $this->assertNull($this->ada->fresh()->digest_sent_at);
        Notification::assertNothingSent();
        $this->actingAs($this->ben->fresh())->getJson('/api/me/digest-preview')->assertOk()->assertJsonPath('summary.deadlines.0.title', 'Due soon');
        $this->actingAs($this->userWithRole('student'))->getJson('/api/me/digest-preview')->assertOk()->assertJsonPath('summary', null);
    }

    public function test_the_email_reads_well_and_leaks_nothing(): void
    {
        $summary = [
            'notifications' => ['total' => 3, 'groups' => [['label' => 'New announcements', 'count' => 2, 'titles' => ['Welcome', '[click here](http://evil.test) **now**']], ['label' => 'Grades published', 'count' => 1, 'titles' => ['Essay']]]],
            'deadlines' => [['type' => 'assignment', 'title' => 'Essay', 'course' => 'CSC101', 'due_at' => '2026-10-05T12:00:00+00:00']],
        ];

        $mail = (new Digest($summary, 'daily'))->toMail($this->ada);
        $text = implode("\n", $mail->introLines);

        $this->assertStringContainsString('daily summary', $mail->subject);
        $this->assertStringContainsString('Hello Ada', $mail->greeting);
        $this->assertStringContainsString('**3 unread updates**', $text);
        $this->assertStringContainsString('New announcements: 2', $text);
        $this->assertStringContainsString('Essay (CSC101, assignment) due Mon 5 Oct, 12:00 UTC', $text);
        $this->assertStringContainsString('turn it off', implode(' ', $mail->outroLines));
        $this->assertSame('https://lms.test', $mail->actionUrl);
        $this->assertStringNotContainsString('](', $text, 'typed titles cannot become links');
        $this->assertStringNotContainsString('**now**', $text);
    }

    public function test_the_email_says_so_when_there_is_only_one_update_and_one_person(): void
    {
        $mail = (new Digest(['notifications' => ['total' => 1, 'groups' => [['label' => 'Grades published', 'count' => 1, 'titles' => []]]]], 'weekly'))->toMail($this->ada);
        $text = implode("\n", $mail->introLines);

        $this->assertStringContainsString('1 unread update**', $text);
    }

    // ---- the scheduled command -----------------------------------------------------------------------------------

    private function news(User $user, string $title = 'Update'): void
    {
        $user->notify(new AnnouncementPosted(1, $this->offering->id, $title));
    }

    public function test_the_command_emails_only_active_people_who_opted_in_and_have_news(): void
    {
        $cy = $this->optIn($this->userWithRole('student'));
        $off = $this->userWithRole('student');
        $gone = $this->optIn($this->userWithRole('student'));
        $gone->forceFill(['is_active' => false])->save();
        $this->optIn($this->ada);
        $this->optIn($this->ben, 'weekly');
        foreach ([$this->ada, $this->ben, $off, $gone] as $user) {
            $this->news($user);
        }
        Notification::fake();

        $this->artisan('lms:send-digests daily')->expectsOutputToContain('1 sent, 1 skipped')->assertExitCode(0);

        Notification::assertSentTo($this->ada, Digest::class, fn (Digest $d) => $d->frequency === 'daily' && $d->summary['notifications']['total'] === 1);
        Notification::assertNotSentTo([$this->ben, $off, $gone, $cy], Digest::class);
        Notification::assertCount(1);
        $this->assertNotNull($this->ada->fresh()->digest_sent_at);
        $this->assertNotNull($cy->fresh()->digest_sent_at, 'the clock advances even when there was nothing to say');
        $this->assertNull($this->ben->fresh()->digest_sent_at);
        $this->assertNull($off->fresh()->digest_sent_at);
        $this->assertNull($gone->fresh()->digest_sent_at);
    }

    public function test_running_the_command_again_in_the_same_period_sends_nothing_more(): void
    {
        $this->optIn($this->ada);
        $this->news($this->ada);

        $this->artisan('lms:send-digests daily')->expectsOutputToContain('1 sent');
        $this->news($this->ada, 'Later'); // a real, stored notification: the first run has already happened
        $this->artisan('lms:send-digests daily')->expectsOutputToContain('0 sent, 0 skipped');

        $this->travel(21)->hours();
        Notification::fake();
        $this->artisan('lms:send-digests daily')->expectsOutputToContain('1 sent');
        Notification::assertSentTo($this->ada, Digest::class, fn (Digest $d) => in_array('Later', $d->summary['notifications']['groups'][0]['titles'], true));
    }

    public function test_the_next_summary_only_covers_what_is_new(): void
    {
        $this->optIn($this->ada);
        $this->news($this->ada, 'Monday news');
        $this->travel(2)->seconds();
        $this->artisan('lms:send-digests daily')->expectsOutputToContain('1 sent');

        $this->travel(21)->hours();
        $this->news($this->ada, 'Tuesday news');
        Notification::fake();
        $this->artisan('lms:send-digests daily');

        Notification::assertSentTo($this->ada, Digest::class, fn (Digest $d) => $d->summary['notifications']['groups'][0]['titles'] === ['Tuesday news']);
    }

    public function test_weekly_summaries_use_a_longer_gap(): void
    {
        $this->optIn($this->ben, 'weekly');
        $this->news($this->ben);
        $this->travel(2)->seconds();
        Notification::fake();

        $this->artisan('lms:send-digests weekly')->expectsOutputToContain('1 sent');
        $this->travel(6)->days();
        $this->artisan('lms:send-digests weekly')->expectsOutputToContain('0 sent, 0 skipped');
        $this->travel(1)->days();
        $this->artisan('lms:send-digests weekly')->expectsOutputToContain('0 sent, 1 skipped');

        Notification::assertSentToTimes($this->ben, Digest::class, 1);
    }

    public function test_the_command_rejects_other_frequencies(): void
    {
        $this->artisan('lms:send-digests hourly')->expectsOutputToContain('daily" or "weekly')->assertExitCode(2);
    }

    public function test_turning_summaries_off_stops_them(): void
    {
        $this->optIn($this->ada);
        $this->news($this->ada);
        $this->actingAs($this->ada)->patchJson('/api/me/preferences', ['digest_frequency' => 'off'])->assertOk();
        Notification::fake();

        $this->artisan('lms:send-digests daily');

        Notification::assertNothingSent();
    }

    public function test_a_real_email_is_produced_end_to_end(): void
    {
        $this->optIn($this->ada);
        $this->assignment(['title' => 'Due soon']);
        $this->news($this->ada, 'Welcome');

        $this->artisan('lms:send-digests daily')->expectsOutputToContain('1 sent');

        $this->assertSame(1, \DB::table('notifications')->where('type', AnnouncementPosted::class)->count(), 'the announcement stays as an in-app notification too');
    }

    public function test_both_digests_are_on_the_schedule(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'lms:send-digests'));

        $this->assertCount(2, $events);
        $this->assertSame(['0 7 * * *', '0 7 * * 1'], $events->pluck('expression')->sort()->values()->all());
        foreach ($events as $event) {
            $this->assertSame('UTC', $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
        }
    }
}
