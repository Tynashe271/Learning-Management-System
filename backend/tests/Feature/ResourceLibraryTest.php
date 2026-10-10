<?php

namespace Tests\Feature;

use App\Models\CourseModule;
use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class ResourceLibraryTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $source;

    private CourseOffering $destination;

    private User $owner;

    private User $otherLecturer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->seed(DatabaseSeeder::class);
        $this->source = $this->offering('A');
        $this->destination = $this->offering('B');
        $this->owner = $this->userWithRole('lecturer');
        $this->otherLecturer = $this->userWithRole('lecturer');
        $this->teach($this->source, $this->owner);
        $this->teach($this->destination, $this->otherLecturer);
    }

    private function module(bool $shared = true): CourseModule
    {
        return $this->source->modules()->create(['title' => 'Week 1', 'position' => 1, 'published' => true, 'is_shared' => $shared]);
    }

    public function test_only_the_owning_teacher_can_share_a_topic(): void
    {
        $module = $this->module(false);

        $this->actingAs($this->otherLecturer)->patchJson('/api/modules/'.$module->id, ['is_shared' => true])->assertForbidden();
        $this->actingAs($this->owner)->patchJson('/api/modules/'.$module->id, ['is_shared' => true])->assertOk()->assertJsonPath('is_shared', true);
        $this->assertTrue($module->refresh()->is_shared);
    }

    public function test_the_library_lists_only_shared_topics_with_their_course_and_teachers(): void
    {
        $this->module(true);
        $this->source->modules()->create(['title' => 'Not shared', 'position' => 2, 'published' => true, 'is_shared' => false]);

        $response = $this->actingAs($this->otherLecturer)->getJson('/api/resource-library/modules')->assertOk();

        $response->assertJsonCount(1)->assertJsonPath('0.title', 'Week 1')->assertJsonPath('0.teachers.0', $this->owner->name);
    }

    public function test_a_student_cannot_browse_the_library(): void
    {
        $this->module(true);

        $this->actingAs($this->userWithRole('student'))->getJson('/api/resource-library/modules')->assertForbidden();
    }

    public function test_importing_a_shared_topic_copies_its_items_and_files_into_a_course_the_caller_manages(): void
    {
        Storage::disk('s3')->put('course-files/1/original.pdf', 'PDF-BYTES');
        $module = $this->module(true);
        $module->items()->create(['title' => 'Notes', 'type' => 'file', 'storage_path' => 'course-files/1/original.pdf', 'position' => 1, 'published' => true]);
        $module->items()->create(['title' => 'Reading', 'type' => 'link', 'body' => 'https://example.edu/reading', 'position' => 2, 'published' => true]);

        $response = $this->actingAs($this->otherLecturer)
            ->postJson('/api/resource-library/modules/'.$module->id.'/import', ['destination_offering_id' => $this->destination->id])
            ->assertCreated();

        $newModule = CourseModule::findOrFail($response->json('id'));
        $this->assertSame($this->destination->id, $newModule->course_offering_id);
        $this->assertFalse($newModule->is_shared);
        $this->assertSame(['Notes', 'Reading'], $newModule->items()->orderBy('position')->pluck('title')->all());
        $newFilePath = $newModule->items()->where('type', 'file')->value('storage_path');
        $this->assertNotSame('course-files/1/original.pdf', $newFilePath);
        Storage::disk('s3')->assertExists($newFilePath);
        $this->assertDatabaseHas('activity_log', ['description' => 'resource imported']);
    }

    public function test_importing_into_an_offering_the_caller_does_not_manage_is_refused(): void
    {
        $module = $this->module(true);

        $this->actingAs($this->userWithRole('lecturer'))
            ->postJson('/api/resource-library/modules/'.$module->id.'/import', ['destination_offering_id' => $this->destination->id])
            ->assertForbidden();
    }

    public function test_importing_a_topic_that_is_not_shared_is_refused(): void
    {
        $module = $this->module(false);

        $this->actingAs($this->otherLecturer)
            ->postJson('/api/resource-library/modules/'.$module->id.'/import', ['destination_offering_id' => $this->destination->id])
            ->assertUnprocessable()->assertJsonValidationErrors('module');
    }
}
