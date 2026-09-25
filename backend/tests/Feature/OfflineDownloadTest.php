<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;
use ZipArchive;

class OfflineDownloadTest extends TestCase
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

    /** @return array{0: int, 1: string, 2: string} zip entry names, index.txt contents, temp file path */
    private function download(User $as, int $moduleId): array
    {
        $response = $this->actingAs($as)->get('/api/modules/'.$moduleId.'/download')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $index = $zip->getFromName('index.txt');
        $zip->close();

        return [$names, $index, $path];
    }

    public function test_a_manager_can_download_a_topics_offline_bundle_with_its_contents(): void
    {
        $module = $this->offering->modules()->create(['title' => 'Week 1', 'position' => 0, 'published' => true]);
        $module->items()->create(['title' => 'Welcome reading', 'type' => 'text', 'body' => 'Hello and welcome.', 'position' => 0, 'published' => true]);
        $module->items()->create(['title' => 'Course syllabus', 'type' => 'link', 'body' => 'https://example.org/syllabus', 'position' => 1, 'published' => true]);
        Storage::disk('s3')->put('course-files/notes.pdf', 'PDF BYTES');
        $module->items()->create(['title' => 'Lecture notes', 'type' => 'file', 'storage_path' => 'course-files/notes.pdf', 'position' => 2, 'published' => true]);

        [$names, $index] = $this->download($this->lecturer, $module->id);

        $this->assertContains('index.txt', $names);
        $this->assertContains('01-welcome-reading.txt', $names);
        $this->assertContains('03-lecture-notes.pdf', $names);
        $this->assertStringContainsString('Week 1', $index);
        $this->assertStringContainsString('Welcome reading', $index);
        $this->assertStringContainsString('https://example.org/syllabus', $index);
        $this->assertStringContainsString('Lecture notes', $index);
    }

    public function test_a_student_can_download_a_published_topic_but_not_an_unpublished_one(): void
    {
        $published = $this->offering->modules()->create(['title' => 'Published week', 'position' => 0, 'published' => true]);
        $unpublished = $this->offering->modules()->create(['title' => 'Draft week', 'position' => 1, 'published' => false]);

        $this->actingAs($this->student)->get('/api/modules/'.$published->id.'/download')->assertOk();
        $this->actingAs($this->student)->get('/api/modules/'.$unpublished->id.'/download')->assertForbidden();
        $this->actingAs($this->lecturer)->get('/api/modules/'.$unpublished->id.'/download')->assertOk();
    }

    public function test_a_students_bundle_excludes_unpublished_items_that_a_managers_bundle_includes(): void
    {
        $module = $this->offering->modules()->create(['title' => 'Week 1', 'position' => 0, 'published' => true]);
        $module->items()->create(['title' => 'Public reading', 'type' => 'text', 'body' => 'Visible to all.', 'position' => 0, 'published' => true]);
        $module->items()->create(['title' => 'Draft reading', 'type' => 'text', 'body' => 'Not ready yet.', 'position' => 1, 'published' => false]);

        [$studentNames] = $this->download($this->student, $module->id);
        $this->assertContains('01-public-reading.txt', $studentNames);
        $this->assertNotContains('02-draft-reading.txt', $studentNames);

        [$managerNames] = $this->download($this->lecturer, $module->id);
        $this->assertContains('01-public-reading.txt', $managerNames);
        $this->assertContains('02-draft-reading.txt', $managerNames);
    }

    public function test_a_topic_with_nothing_published_still_downloads_with_an_explanatory_index(): void
    {
        $module = $this->offering->modules()->create(['title' => 'Empty week', 'position' => 0, 'published' => true]);

        [$names, $index] = $this->download($this->student, $module->id);

        $this->assertSame(['index.txt'], $names);
        $this->assertStringContainsString('Nothing published under this topic yet.', $index);
    }
}
