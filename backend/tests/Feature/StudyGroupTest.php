<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class StudyGroupTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private User $ben;

    private User $cy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student', ['name' => 'Ada Student']);
        $this->ben = $this->userWithRole('student', ['name' => 'Ben Student']);
        $this->cy = $this->userWithRole('student', ['name' => 'Cy Student']);
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->enrol($this->offering, $this->ben);
        $this->enrol($this->offering, $this->cy);
    }

    public function test_a_student_can_create_a_group_and_is_automatically_a_member(): void
    {
        $group = $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/study-groups', ['name' => 'Midterm crammers', 'max_members' => 3])
            ->assertCreated()->json();
        $this->assertCount(1, $group['members']);
        $this->assertSame('Ada Student', $group['members'][0]['name']);
    }

    public function test_another_student_can_join_and_leave(): void
    {
        $id = $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/study-groups', ['name' => 'Group'])->assertCreated()->json('id');

        $this->actingAs($this->ben)->postJson('/api/study-groups/'.$id.'/join')->assertOk()->assertJsonCount(2, 'members');
        $groups = $this->actingAs($this->ben)->getJson('/api/offerings/'.$this->offering->id.'/study-groups')->assertOk()->json();
        $this->assertTrue($groups[0]['my_member']);

        $this->actingAs($this->ben)->postJson('/api/study-groups/'.$id.'/leave')->assertOk();
        $groups = $this->actingAs($this->ben)->getJson('/api/offerings/'.$this->offering->id.'/study-groups')->assertOk()->json();
        $this->assertFalse($groups[0]['my_member']);
    }

    public function test_a_full_group_refuses_another_member(): void
    {
        $id = $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/study-groups', ['name' => 'Pair up', 'max_members' => 2])->assertCreated()->json('id');
        $this->actingAs($this->ben)->postJson('/api/study-groups/'.$id.'/join')->assertOk();

        $this->actingAs($this->cy)->postJson('/api/study-groups/'.$id.'/join')->assertUnprocessable()->assertJsonValidationErrors('group');
    }

    public function test_the_creator_or_a_manager_can_delete_a_group_but_a_member_cannot(): void
    {
        $id = $this->actingAs($this->ada)->postJson('/api/offerings/'.$this->offering->id.'/study-groups', ['name' => 'Group'])->assertCreated()->json('id');
        $this->actingAs($this->ben)->postJson('/api/study-groups/'.$id.'/join')->assertOk();

        $this->actingAs($this->ben)->deleteJson('/api/study-groups/'.$id)->assertForbidden();
        $this->actingAs($this->lecturer)->deleteJson('/api/study-groups/'.$id)->assertOk();
    }

    public function test_only_an_enrolled_student_can_create_a_group(): void
    {
        $outsider = $this->userWithRole('student');
        $this->actingAs($outsider)->postJson('/api/offerings/'.$this->offering->id.'/study-groups', ['name' => 'x'])->assertForbidden();
    }
}
