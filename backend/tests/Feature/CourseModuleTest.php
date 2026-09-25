<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class CourseModuleTest extends TestCase
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

    public function test_a_topic_can_require_another_topic_be_completed_first(): void
    {
        $week1 = $this->offering->modules()->create(['title' => 'Week 1', 'position' => 0, 'published' => true]);
        $week2 = $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/modules', [
            'title' => 'Week 2', 'position' => 1, 'published' => true, 'prerequisite_module_id' => $week1->id,
        ])->assertCreated()->assertJsonPath('prerequisite_module_id', $week1->id)->json('id');

        $shown = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id)->assertOk();
        $this->assertSame($week1->id, collect($shown->json('modules'))->firstWhere('id', $week2)['prerequisite_module_id']);
    }

    public function test_a_prerequisite_must_belong_to_the_same_offering_and_cannot_be_the_topic_itself(): void
    {
        $other = $this->offering('B');
        $foreignModule = $other->modules()->create(['title' => 'Elsewhere', 'position' => 0, 'published' => true]);
        $module = $this->offering->modules()->create(['title' => 'Week 1', 'position' => 0, 'published' => true]);

        $this->actingAs($this->lecturer)->patchJson('/api/modules/'.$module->id, ['prerequisite_module_id' => $foreignModule->id])->assertJsonValidationErrors('prerequisite_module_id');
        $this->actingAs($this->lecturer)->patchJson('/api/modules/'.$module->id, ['prerequisite_module_id' => $module->id])->assertJsonValidationErrors('prerequisite_module_id');
    }

    public function test_a_prerequisite_chain_cannot_loop_back_on_itself(): void
    {
        $a = $this->offering->modules()->create(['title' => 'A', 'position' => 0, 'published' => true]);
        $b = $this->offering->modules()->create(['title' => 'B', 'position' => 1, 'published' => true, 'prerequisite_module_id' => $a->id]);
        $c = $this->offering->modules()->create(['title' => 'C', 'position' => 2, 'published' => true, 'prerequisite_module_id' => $b->id]);

        // C already needs B which needs A. Making A need C would loop the whole chain back on itself.
        $this->actingAs($this->lecturer)->patchJson('/api/modules/'.$a->id, ['prerequisite_module_id' => $c->id])->assertJsonValidationErrors('prerequisite_module_id');
        // A short two-topic loop is refused the same way.
        $this->actingAs($this->lecturer)->patchJson('/api/modules/'.$a->id, ['prerequisite_module_id' => $b->id])->assertJsonValidationErrors('prerequisite_module_id');
    }

    public function test_removing_a_prerequisite_topic_un_gates_the_one_that_needed_it(): void
    {
        $week1 = $this->offering->modules()->create(['title' => 'Week 1', 'position' => 0, 'published' => true]);
        $week2 = $this->offering->modules()->create(['title' => 'Week 2', 'position' => 1, 'published' => true, 'prerequisite_module_id' => $week1->id]);

        $this->actingAs($this->lecturer)->deleteJson('/api/modules/'.$week1->id)->assertOk();

        $this->assertNull($week2->fresh()->prerequisite_module_id);
    }
}
