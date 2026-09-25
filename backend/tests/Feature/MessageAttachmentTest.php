<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\DirectMessage;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Services\ScannerUnavailable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Concerns\BuildsCourses;
use Tests\Concerns\FakesClamd;
use Tests\TestCase;

class MessageAttachmentTest extends TestCase
{
    use BuildsCourses, FakesClamd, RefreshDatabase;

    private CourseOffering $course;

    private User $lecturer;

    private User $ada;

    private User $ben;

    private User $cy;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->seed(DatabaseSeeder::class);
        $this->course = $this->offering('A');
        $elsewhere = $this->offering('B');
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student', ['name' => 'Ada']);
        $this->ben = $this->userWithRole('student', ['name' => 'Ben']);
        $this->cy = $this->userWithRole('student', ['name' => 'Cy']);
        $this->teach($this->course, $this->lecturer);
        $this->enrol($this->course, $this->ada);
        $this->enrol($this->course, $this->ben);
        $this->enrol($elsewhere, $this->cy);
    }

    private function pdf(string $name = 'notes.pdf', int $kb = 20): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    private function send(User $from, User $to, array $fields = [], array $files = [])
    {
        return $this->actingAs($from)->post('/api/messages/'.$to->id, $fields + ($files ? ['attachments' => $files] : []), ['Accept' => 'application/json']);
    }

    protected function tearDown(): void
    {
        $this->closeClamdSockets();
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_message_can_carry_files(): void
    {
        $response = $this->send($this->ada, $this->ben, ['body' => 'Here are my notes'], [$this->pdf('week1.pdf', 30), $this->pdf('week2.pdf', 40)])->assertCreated();

        $response->assertJsonPath('body', 'Here are my notes')->assertJsonCount(2, 'attachments')->assertJsonPath('attachments.0.original_name', 'week1.pdf')->assertJsonPath('attachments.1.original_name', 'week2.pdf');
        $this->assertGreaterThan(0, $response->json('attachments.0.size'));
        $this->assertStringNotContainsString('storage_path', $response->getContent());
        $this->assertStringNotContainsString('message-attachments', $response->getContent());
        $files = Storage::disk('s3')->allFiles();
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertStringStartsWith('message-attachments/'.$response->json('id').'/', $file);
        }
    }

    public function test_a_message_can_be_only_a_file(): void
    {
        $this->send($this->ada, $this->ben, [], [$this->pdf()])->assertCreated()->assertJsonPath('body', '')->assertJsonCount(1, 'attachments');
    }

    public function test_a_message_needs_text_or_a_file(): void
    {
        $this->send($this->ada, $this->ben)->assertJsonValidationErrors('body');
        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_there_are_limits_on_how_many_files_how_big_and_what_type(): void
    {
        $tooMany = array_map(fn ($i) => $this->pdf("f{$i}.pdf"), range(1, 6));

        $this->send($this->ada, $this->ben, ['body' => 'x'], $tooMany)->assertJsonValidationErrors('attachments');
        $this->send($this->ada, $this->ben, ['body' => 'x'], [$this->pdf('big.pdf', 10241)])->assertJsonValidationErrors('attachments.0');
        $this->send($this->ada, $this->ben, ['body' => 'x'], [UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload')])->assertJsonValidationErrors('attachments.0');
        $this->send($this->ada, $this->ben, ['body' => 'x'], [UploadedFile::fake()->create('page.html', 10, 'text/html')])->assertJsonValidationErrors('attachments.0');
        $this->send($this->ada, $this->ben, ['body' => 'x'], [$this->pdf('ok.pdf'), UploadedFile::fake()->create('run.sh', 10, 'text/x-shellscript')])->assertJsonValidationErrors('attachments.1');
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertCount(0, Storage::disk('s3')->allFiles());
    }

    public function test_the_recipient_is_checked_before_any_file_is_scanned_or_stored(): void
    {
        $calls = 0;
        $this->useScanner(function () use (&$calls) {
            $calls++;

            return $this->pair("stream: OK\0")[0];
        });

        $this->send($this->ada, $this->cy, ['body' => 'hi'], [$this->pdf()])->assertForbidden();

        $this->assertSame(0, $calls);
        $this->assertCount(0, Storage::disk('s3')->allFiles());
    }

    public function test_attachments_are_virus_scanned(): void
    {
        $this->useScanner(fn () => $this->pair("stream: Eicar-Test-Signature FOUND\0")[0]);

        $this->send($this->ada, $this->ben, ['body' => 'x'], [$this->pdf()])->assertJsonValidationErrors('attachments.0');

        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertCount(0, Storage::disk('s3')->allFiles());
    }

    public function test_attachments_are_refused_while_the_scanner_is_down_unless_configured_otherwise(): void
    {
        $this->useScanner(fn () => throw new ScannerUnavailable('connection refused'));
        $this->send($this->ada, $this->ben, ['body' => 'x'], [$this->pdf()])->assertJsonValidationErrors('attachments.0');

        $this->useScanner(fn () => throw new ScannerUnavailable('connection refused'), failOpen: true);
        $this->send($this->ada, $this->ben, ['body' => 'x'], [$this->pdf()])->assertCreated();
    }

    public function test_only_the_two_people_in_the_conversation_can_download_a_file(): void
    {
        $upload = UploadedFile::fake()->createWithContent('plan.txt', 'the secret plan');
        $attachment = $this->send($this->ada, $this->ben, ['body' => 'x'], [$upload])->assertCreated()->json('attachments.0');
        $url = '/api/message-attachments/'.$attachment['id'].'/download';

        $this->assertSame('the secret plan', $this->actingAs($this->ada)->get($url)->assertOk()->streamedContent());
        $this->assertSame('the secret plan', $this->actingAs($this->ben)->get($url)->assertOk()->streamedContent());
        $this->actingAs($this->lecturer)->get($url)->assertForbidden();
        $this->actingAs($this->cy)->get($url)->assertForbidden();
        $this->actingAs($this->userWithRole('super-admin'))->get($url)->assertForbidden(); // even administrators cannot read private messages
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_downloads_are_forced_to_download_with_the_original_name_and_no_sniffing(): void
    {
        $attachment = $this->send($this->ada, $this->ben, ['body' => 'x'], [$this->pdf('Lecture notes.pdf')])->assertCreated()->json('attachments.0');

        $response = $this->actingAs($this->ben)->get('/api/message-attachments/'.$attachment['id'].'/download')->assertOk();

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Lecture notes.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_awkward_file_names_are_cleaned(): void
    {
        $names = ['..\\..\\windows\\report.pdf', "evil\r\nX-Injected: 1.pdf", 'C:\\Users\\ada\\Desktop\\thesis.pdf'];
        $response = $this->send($this->ada, $this->ben, ['body' => 'x'], array_map(fn ($n) => $this->pdf($n), $names))->assertCreated();

        $stored = array_column($response->json('attachments'), 'original_name');
        $this->assertSame('report.pdf', $stored[0]);
        $this->assertSame('thesis.pdf', $stored[2]);
        $this->assertStringNotContainsString("\r", $stored[1]);
        $this->assertStringNotContainsString("\n", $stored[1]);
        foreach ($stored as $name) {
            $this->assertStringNotContainsString('/', $name);
            $this->assertStringNotContainsString('\\', $name);
        }
    }

    public function test_the_thread_and_the_inbox_show_attachments(): void
    {
        $this->send($this->ada, $this->ben, ['body' => 'see attached'], [$this->pdf('a.pdf')])->assertCreated();

        $this->actingAs($this->ben)->getJson('/api/messages')->assertOk()->assertJsonPath('conversations.0.last_message.attachments.0.original_name', 'a.pdf');
        $this->actingAs($this->ben)->getJson('/api/messages/'.$this->ada->id)->assertOk()->assertJsonPath('data.0.attachments.0.original_name', 'a.pdf');
        $this->actingAs($this->ada)->getJson('/api/messages/'.$this->ben->id)->assertJsonPath('data.0.attachments.0.original_name', 'a.pdf');
    }

    public function test_a_storage_failure_partway_through_leaves_nothing_behind(): void
    {
        $real = Storage::disk('s3');
        $uploads = 0;
        $flaky = Mockery::mock($real);
        // UploadedFile::store() writes through putFileAs().
        $flaky->shouldReceive('putFileAs')->andReturnUsing(function (...$args) use ($real, &$uploads) {
            if (++$uploads === 2) {
                throw new RuntimeException('disk full');
            }

            return $real->putFileAs(...$args);
        });
        Storage::set('s3', $flaky);

        $this->send($this->ada, $this->ben, ['body' => 'x'], [$this->pdf('one.pdf'), $this->pdf('two.pdf')])->assertStatus(500);

        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertDatabaseCount('message_attachments', 0);
        $this->assertCount(0, $real->allFiles(), 'the first file was removed when the second failed');
    }

    public function test_attachment_rows_disappear_with_their_message(): void
    {
        $id = $this->send($this->ada, $this->ben, ['body' => 'x'], [$this->pdf()])->assertCreated()->json('id');

        DirectMessage::findOrFail($id)->delete();

        $this->assertSame(0, MessageAttachment::count());
    }
}
