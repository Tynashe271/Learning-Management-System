<?php

namespace Tests\Feature;

use App\Models\LibraryResource;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class LibraryResourceTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_lecturer_can_upload_a_textbook_and_anyone_signed_in_can_browse_and_download_it(): void
    {
        $lecturer = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student');

        $response = $this->actingAs($lecturer)->post('/api/resource-library/materials', [
            'title' => 'Intro to Algorithms',
            'category' => 'textbook',
            'course_code' => 'CSC301',
            'file' => UploadedFile::fake()->create('algorithms.pdf', 500, 'application/pdf'),
        ])->assertCreated();

        $id = $response->json('id');
        Storage::disk('s3')->assertExists(LibraryResource::findOrFail($id)->storage_path);

        $this->actingAs($student)->getJson('/api/resource-library/materials')
            ->assertOk()
            ->assertJsonPath('0.title', 'Intro to Algorithms')
            ->assertJsonPath('0.category', 'textbook')
            ->assertJsonPath('0.uploaded_by', $lecturer->name);

        $this->actingAs($student)->get("/api/resource-library/materials/{$id}/download")->assertOk();
    }

    public function test_a_student_cannot_upload_a_resource(): void
    {
        $student = $this->userWithRole('student');

        $this->actingAs($student)->post('/api/resource-library/materials', [
            'title' => 'Past Paper 2025',
            'category' => 'past_exam_paper',
            'file' => UploadedFile::fake()->create('paper.pdf', 100, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_uploading_requires_a_recognised_category(): void
    {
        $lecturer = $this->userWithRole('lecturer');

        $this->actingAs($lecturer)->post('/api/resource-library/materials', [
            'title' => 'Mystery file',
            'category' => 'not-a-real-category',
            'file' => UploadedFile::fake()->create('file.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('category');
    }

    public function test_the_uploader_can_delete_their_own_resource(): void
    {
        $lecturer = $this->userWithRole('lecturer');
        $resource = LibraryResource::create([
            'title' => 'Reading list', 'category' => 'reading_material', 'storage_path' => 'library-resources/file.pdf', 'uploaded_by' => $lecturer->id,
        ]);
        Storage::disk('s3')->put($resource->storage_path, 'bytes');

        $this->actingAs($lecturer)->deleteJson("/api/resource-library/materials/{$resource->id}")->assertNoContent();

        $this->assertModelMissing($resource);
        Storage::disk('s3')->assertMissing('library-resources/file.pdf');
    }

    public function test_a_different_lecturer_cannot_delete_someone_elses_resource(): void
    {
        $owner = $this->userWithRole('lecturer');
        $otherLecturer = $this->userWithRole('lecturer');
        $resource = LibraryResource::create([
            'title' => 'Reading list', 'category' => 'reading_material', 'storage_path' => 'library-resources/file.pdf', 'uploaded_by' => $owner->id,
        ]);

        $this->actingAs($otherLecturer)->deleteJson("/api/resource-library/materials/{$resource->id}")->assertForbidden();
    }

    public function test_an_admin_can_delete_any_resource(): void
    {
        $owner = $this->userWithRole('lecturer');
        $admin = $this->userWithRole('university-admin');
        $resource = LibraryResource::create([
            'title' => 'Reading list', 'category' => 'reading_material', 'storage_path' => 'library-resources/file.pdf', 'uploaded_by' => $owner->id,
        ]);

        $this->actingAs($admin)->deleteJson("/api/resource-library/materials/{$resource->id}")->assertNoContent();
    }

    public function test_materials_can_be_filtered_by_category(): void
    {
        $lecturer = $this->userWithRole('lecturer');
        LibraryResource::create(['title' => 'A Textbook', 'category' => 'textbook', 'storage_path' => 'x', 'uploaded_by' => $lecturer->id]);
        LibraryResource::create(['title' => 'A Paper', 'category' => 'past_exam_paper', 'storage_path' => 'y', 'uploaded_by' => $lecturer->id]);

        $this->actingAs($lecturer)->getJson('/api/resource-library/materials?category=textbook')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'A Textbook');
    }
}
