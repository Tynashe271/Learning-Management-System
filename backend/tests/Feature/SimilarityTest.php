<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class SimilarityTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private Assignment $essay;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->teach($this->offering, $this->lecturer);
        $this->essay = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
    }

    /** Sixty distinct words, so every five-word phrase is unique. */
    private function text(string $prefix, int $words = 60): string
    {
        return implode(' ', array_map(fn ($i) => $prefix.$i, range(1, $words)));
    }

    private function submit(string $name, ?string $body, ?string $path = null): User
    {
        $student = $this->userWithRole('student', ['name' => $name]);
        $this->enrol($this->offering, $student);
        $this->essay->submissions()->create(['user_id' => $student->id, 'body' => $body, 'storage_path' => $path, 'submitted_at' => now()]);

        return $student;
    }

    private function check(string $query = '', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->lecturer)->getJson('/api/assignments/'.$this->essay->id.'/similarity'.$query);
    }

    public function test_copied_answers_are_flagged_and_unrelated_ones_are_not(): void
    {
        $original = $this->text('word');
        $this->submit('Ada', $original);
        $this->submit('Ben', str_replace('word10', 'changed10', str_replace('word40', 'changed40', $original))); // two edits
        $this->submit('Cy', $this->text('other'));

        $response = $this->check()->assertOk();

        $response->assertJsonPath('compared', 3)->assertJsonCount(1, 'pairs');
        $pair = $response->json('pairs.0');
        $this->assertEqualsCanonicalizing(['Ada', 'Ben'], User::whereIn('id', [$pair['a']['user']['id'], $pair['b']['user']['id']])->pluck('name')->all());
        $this->assertGreaterThan(0.6, $pair['similarity']);
        $this->assertLessThan(0.8, $pair['similarity']);
        $this->assertStringContainsString('Screening only', $response->json('note'));
    }

    public function test_identical_answers_score_one_and_the_threshold_controls_what_is_reported(): void
    {
        $text = $this->text('word');
        $this->submit('Ada', $text);
        $this->submit('Ben', $text);
        $this->submit('Cy', str_replace('word30', 'changed30', $text));

        $default = $this->check()->assertOk();
        $this->assertCount(3, $default->json('pairs'));
        $this->assertEquals(1.0, $default->json('pairs.0.similarity'));

        $this->assertCount(1, $this->check('?threshold=1')->json('pairs'));
        $this->assertSame(1.0, (float) $this->check('?threshold=1')->json('threshold'));
    }

    public function test_whitespace_case_and_punctuation_do_not_hide_copying(): void
    {
        $words = explode(' ', $this->text('word'));
        $this->submit('Ada', implode(' ', $words));
        $this->submit('Ben', mb_strtoupper(implode(",\n  ", $words)).'!!!');

        $this->check()->assertOk()->assertJsonCount(1, 'pairs')->assertJsonPath('pairs.0.similarity', 1);
    }

    public function test_short_answers_are_skipped_instead_of_flagged(): void
    {
        $this->submit('Ada', 'The answer is yes');
        $this->submit('Ben', 'The answer is yes');
        $this->submit('Cy', null);

        $this->check()->assertOk()->assertJsonCount(0, 'pairs')->assertJsonPath('compared', 0)->assertJsonPath('skipped_too_short_or_unreadable', 3);
    }

    public function test_text_files_are_compared_with_written_answers(): void
    {
        $text = $this->text('word');
        Storage::disk('s3')->put('submissions/1/9/answer.txt', $text);
        Storage::disk('s3')->put('submissions/1/9/scan.pdf', $text);
        $this->submit('Ada', $text);
        $this->submit('Ben', null, 'submissions/1/9/answer.txt');
        $this->submit('Cy', null, 'submissions/1/9/scan.pdf'); // PDFs are not read

        $response = $this->check()->assertOk();

        $response->assertJsonPath('compared', 2)->assertJsonCount(1, 'pairs')->assertJsonPath('skipped_too_short_or_unreadable', 1);
    }

    public function test_a_missing_or_oversized_file_does_not_break_the_check(): void
    {
        Storage::disk('s3')->put('submissions/1/9/huge.txt', str_repeat('lorem ipsum dolor sit amet ', 30000));
        $this->submit('Ada', $this->text('word'));
        $this->submit('Ben', null, 'submissions/1/9/missing.txt');
        $this->submit('Cy', null, 'submissions/1/9/huge.txt');

        $this->check()->assertOk()->assertJsonPath('compared', 1)->assertJsonPath('skipped_too_short_or_unreadable', 2);
    }

    public function test_only_graders_of_the_offering_can_run_the_check(): void
    {
        $ada = $this->submit('Ada', $this->text('word'));

        $this->check('', $ada)->assertForbidden();
        $this->check('', $this->userWithRole('lecturer'))->assertForbidden();
        $this->check('', $this->userWithRole('student'))->assertForbidden();
        $this->check('', $this->userWithRole('university-admin'))->assertForbidden(); // administering is not grading
        $this->check('', $this->userWithRole('super-admin'))->assertOk();
    }

    public function test_the_threshold_is_validated_and_runs_are_audited(): void
    {
        $this->check('?threshold=0.05')->assertJsonValidationErrors('threshold');
        $this->check('?threshold=2')->assertJsonValidationErrors('threshold');
        $this->check('?threshold=abc')->assertJsonValidationErrors('threshold');

        $this->check('?threshold=0.7')->assertOk();

        $this->assertDatabaseHas('activity_log', ['description' => 'similarity check run']);
    }

    public function test_the_report_names_students_but_never_shows_their_work(): void
    {
        $text = $this->text('secretword');
        $this->submit('Ada', $text);
        $this->submit('Ben', $text);

        $body = $this->check()->assertOk()->getContent();

        $this->assertStringNotContainsString('secretword', $body);
        $this->assertStringContainsString('Ada', $body);
    }

    public function test_a_class_too_big_to_compare_in_one_go_is_refused(): void
    {
        $now = now();
        $rows = User::factory()->count(301)->create()->map(fn ($student) => [
            'assignment_id' => $this->essay->id, 'user_id' => $student->id, 'body' => 'x', 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ])->all();
        DB::table('submissions')->insert($rows);

        $this->check()->assertJsonValidationErrors('assignment');
    }
}
