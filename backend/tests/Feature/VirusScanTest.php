<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Enrolment;
use App\Models\User;
use App\Services\ScannerUnavailable;
use App\Services\VirusScanner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\Concerns\FakesClamd;
use Tests\TestCase;

/** The scanner is exercised against a scripted stand-in for clamd, connected through a real socket pair. */
class VirusScanTest extends TestCase
{
    use BuildsCourses, FakesClamd, RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** Decodes what the scanner sent: the command, then length-prefixed chunks ending in a zero-length chunk. */
    private function decode(string $wire): array
    {
        $this->assertStringStartsWith("zINSTREAM\0", $wire);
        $rest = substr($wire, strlen("zINSTREAM\0"));
        $chunks = [];
        while (strlen($rest) >= 4) {
            $length = unpack('N', substr($rest, 0, 4))[1];
            $rest = substr($rest, 4);
            if ($length === 0) {
                $this->assertSame('', $rest, 'nothing may follow the terminating chunk');

                return $chunks;
            }
            $chunks[] = substr($rest, 0, $length);
            $rest = substr($rest, $length);
        }
        $this->fail('The stream was not terminated with a zero-length chunk.');
    }

    public function test_a_clean_file_is_streamed_in_chunks_using_the_clamd_protocol(): void
    {
        $content = random_bytes(20000);
        [$client, $server] = $this->pair("stream: OK\0");
        $path = $this->tempFile($content);

        $result = (new VirusScanner(fn () => $client))->scan($path);

        $this->assertNull($result);
        $chunks = $this->decode(stream_get_contents($server));
        $this->assertGreaterThan(1, count($chunks));
        $this->assertLessThanOrEqual(8192, max(array_map('strlen', $chunks)));
        $this->assertSame($content, implode('', $chunks));
    }

    public function test_an_empty_file_is_sent_as_just_the_terminator(): void
    {
        [$client, $server] = $this->pair("stream: OK\0");

        $this->assertNull((new VirusScanner(fn () => $client))->scan($this->tempFile('')));

        $this->assertSame([], $this->decode(stream_get_contents($server)));
    }

    public function test_an_infected_file_returns_the_signature_name(): void
    {
        [$client] = $this->pair("stream: Win.Test.EICAR_HDB-1 FOUND\0");

        $this->assertSame('Win.Test.EICAR_HDB-1', (new VirusScanner(fn () => $client))->scan($this->tempFile('X5O!P%@AP')));
    }

    public function test_answers_the_scanner_does_not_understand_count_as_unavailable(): void
    {
        foreach (["INSTREAM size limit exceeded. ERROR\0", "\0", ''] as $answer) {
            [$client, $server] = $this->pair($answer);
            if ($answer === '') {
                fclose($server); // clamd hung up without saying anything
            }
            try {
                (new VirusScanner(fn () => $client))->scan($this->tempFile('data'));
                $this->fail('An unreadable answer must not be treated as clean.');
            } catch (ScannerUnavailable $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_a_file_that_cannot_be_read_is_never_reported_clean(): void
    {
        $this->expectException(ScannerUnavailable::class);

        (new VirusScanner(fn () => $this->fail('should not connect')))->scan('/definitely/not/a/file');
    }

    public function test_scanning_is_off_unless_switched_on(): void
    {
        $this->assertFalse((new VirusScanner)->enabled());
        config(['lms.virus_scan.enabled' => true]);
        $this->assertTrue((new VirusScanner)->enabled());
    }

    // ---- through the upload endpoints ----------------------------------------------------------------------------

    private function uploadFixture(): array
    {
        Storage::fake('s3');
        $this->seed(DatabaseSeeder::class);
        $offering = $this->offering();
        $lecturer = $this->userWithRole('lecturer');
        $this->teach($offering, $lecturer);
        $module = $offering->modules()->create(['title' => 'Week 1']);

        return [$offering, $lecturer, $module];
    }

    private function fileItem(User $as, int $moduleId)
    {
        return $this->actingAs($as)->post('/api/modules/'.$moduleId.'/items', ['title' => 'Notes', 'type' => 'file', 'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json']);
    }

    public function test_clean_uploads_are_stored(): void
    {
        [, $lecturer, $module] = $this->uploadFixture();
        $this->useScanner(fn () => $this->pair("stream: OK\0")[0]);

        $this->fileItem($lecturer, $module->id)->assertCreated();

        $this->assertCount(1, Storage::disk('s3')->allFiles());
    }

    public function test_infected_uploads_are_rejected_and_nothing_is_stored(): void
    {
        [, $lecturer, $module] = $this->uploadFixture();
        $this->useScanner(fn () => $this->pair("stream: Eicar-Test-Signature FOUND\0")[0]);

        $this->fileItem($lecturer, $module->id)->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertCount(0, Storage::disk('s3')->allFiles());
        $this->assertDatabaseCount('learning_items', 0);
    }

    public function test_uploads_are_refused_while_the_scanner_is_down(): void
    {
        [, $lecturer, $module] = $this->uploadFixture();
        $this->useScanner(fn () => throw new ScannerUnavailable('connection refused'));

        $response = $this->fileItem($lecturer, $module->id)->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertStringContainsString('temporarily unavailable', $response->json('errors.file.0'));
        $this->assertCount(0, Storage::disk('s3')->allFiles());
    }

    public function test_uploads_can_be_allowed_through_while_the_scanner_is_down_if_configured(): void
    {
        [, $lecturer, $module] = $this->uploadFixture();
        $this->useScanner(fn () => throw new ScannerUnavailable('connection refused'), failOpen: true);

        $this->fileItem($lecturer, $module->id)->assertCreated();
    }

    public function test_the_scanner_is_not_contacted_when_scanning_is_off(): void
    {
        [, $lecturer, $module] = $this->uploadFixture();
        $calls = 0;
        $this->useScanner(function () use (&$calls) {
            $calls++;

            throw new ScannerUnavailable('should not be called');
        }, enabled: false);

        $this->fileItem($lecturer, $module->id)->assertCreated();

        $this->assertSame(0, $calls);
    }

    public function test_invalid_files_are_rejected_before_they_are_scanned(): void
    {
        [, $lecturer, $module] = $this->uploadFixture();
        $calls = 0;
        $this->useScanner(function () use (&$calls) {
            $calls++;

            return $this->pair("stream: OK\0")[0];
        });

        $this->actingAs($lecturer)->post('/api/modules/'.$module->id.'/items', ['title' => 'x', 'type' => 'file', 'file' => UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, $calls);
    }

    public function test_student_submissions_are_scanned_too(): void
    {
        [$offering] = $this->uploadFixture();
        $student = $this->userWithRole('student');
        Enrolment::create(['course_offering_id' => $offering->id, 'user_id' => $student->id, 'status' => 'active']);
        $assignment = Assignment::create(['course_offering_id' => $offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $this->useScanner(fn () => $this->pair("stream: Eicar-Test-Signature FOUND\0")[0]);

        $this->actingAs($student)->post('/api/assignments/'.$assignment->id.'/submissions', ['file' => UploadedFile::fake()->create('essay.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('submissions', 0);
        $this->assertCount(0, Storage::disk('s3')->allFiles());
    }

    protected function tearDown(): void
    {
        $this->closeClamdSockets();
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }
}
