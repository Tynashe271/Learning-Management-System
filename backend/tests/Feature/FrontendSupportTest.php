<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

/** The few endpoints and fields that exist so a frontend can show the right screens to the right person. */
class FrontendSupportTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_me_lists_the_roles_and_permissions(): void
    {
        $student = $this->userWithRole('student');
        $lecturer = $this->userWithRole('lecturer');

        $this->actingAs($student)->getJson('/api/me')->assertOk()->assertJsonPath('permissions', ['submit-assignments'])->assertJsonPath('roles.0.name', 'student');
        $this->actingAs($lecturer)->getJson('/api/me')->assertOk()->assertJsonPath('permissions', ['grade-submissions', 'resolve-appeals', 'teach-courses']);
        $this->actingAs($lecturer)->patchJson('/api/me', ['name' => 'Dr Lee'])->assertOk()->assertJsonPath('name', 'Dr Lee')->assertJsonStructure(['permissions']);
    }

    public function test_an_offering_says_who_teaches_it_and_whether_the_caller_can_manage_it(): void
    {
        $offering = $this->offering();
        $teacher = $this->userWithRole('lecturer', ['name' => 'Dr Lee']);
        $other = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student');
        $this->teach($offering, $teacher);
        $this->enrol($offering, $student);

        $this->actingAs($teacher)->getJson("/api/offerings/{$offering->id}")->assertOk()
            ->assertJsonPath('abilities.manage', true)->assertJsonPath('teachers.0.user.name', 'Dr Lee');
        $this->actingAs($student)->getJson("/api/offerings/{$offering->id}")->assertOk()
            ->assertJsonPath('abilities.manage', false)->assertJsonPath('teachers.0.user.name', 'Dr Lee');
        $this->actingAs($other)->getJson("/api/offerings/{$offering->id}")->assertForbidden();
        $this->actingAs($this->userWithRole('super-admin'))->getJson("/api/offerings/{$offering->id}")->assertJsonPath('abilities.manage', true);
    }

    public function test_a_student_can_list_only_their_teachers_and_classmates_and_never_sees_emails(): void
    {
        $offering = $this->offering('A');
        $elsewhere = $this->offering('B');
        $me = $this->userWithRole('student', ['name' => 'Me']);
        $classmate = $this->userWithRole('student', ['name' => 'Classmate']);
        $stranger = $this->userWithRole('student', ['name' => 'Stranger']);
        $teacher = $this->userWithRole('lecturer', ['name' => 'Teacher']);
        $withdrawn = $this->userWithRole('student', ['name' => 'Withdrawn']);
        $this->enrol($offering, $me);
        $this->enrol($offering, $classmate);
        $this->enrol($offering, $withdrawn, 'withdrawn');
        $this->enrol($elsewhere, $stranger);
        $this->teach($offering, $teacher);

        $response = $this->actingAs($me)->getJson('/api/contacts')->assertOk();
        $this->assertEqualsCanonicalizing(['Classmate', 'Teacher'], collect($response->json('data'))->pluck('name')->all());
        $this->assertArrayNotHasKey('email', $response->json('data.0'));
        $this->assertSame('student', collect($response->json('data'))->firstWhere('name', 'Classmate')['roles'][0]['name']);

        $this->actingAs($me)->getJson('/api/contacts?q=class')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($me)->getJson('/api/contacts?q=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_staff_who_manage_enrolments_can_list_anyone_with_emails_and_inactive_people_are_left_out(): void
    {
        $registrar = $this->userWithRole('registrar');
        $active = $this->userWithRole('student', ['name' => 'Active']);
        $this->userWithRole('student', ['name' => 'Gone', 'is_active' => false]);

        $names = collect($this->actingAs($registrar)->getJson('/api/contacts')->assertOk()->json('data'));
        $this->assertTrue($names->contains('name', 'Active'));
        $this->assertFalse($names->contains('name', 'Gone'));
        $this->assertFalse($names->contains('id', $registrar->id));
        $this->assertSame($active->email, $names->firstWhere('name', 'Active')['email']);
    }

    public function test_one_assignment_can_be_opened_by_its_id_with_the_callers_abilities(): void
    {
        $offering = $this->offering();
        $teacher = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student');
        $outsider = $this->userWithRole('student');
        $this->teach($offering, $teacher);
        $this->enrol($offering, $student);
        $open = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 50, 'published' => true]);
        $draft = $offering->assignments()->create(['title' => 'Draft', 'due_at' => now()->addDay(), 'max_score' => 50, 'published' => false]);
        $late = $offering->assignments()->create(['title' => 'Late', 'due_at' => now()->subDay(), 'max_score' => 50, 'published' => true]);

        $this->actingAs($student)->getJson("/api/assignments/{$open->id}")->assertOk()
            ->assertJsonPath('title', 'Essay')->assertJsonPath('offering.course.code', 'CSC101')->assertJsonPath('offering.id', $offering->id)
            ->assertJsonPath('abilities', ['manage' => false, 'grade' => false, 'submit' => true]);
        $this->actingAs($student)->getJson("/api/assignments/{$late->id}")->assertOk()->assertJsonPath('abilities.submit', false);
        $this->actingAs($teacher)->getJson("/api/assignments/{$open->id}")->assertOk()->assertJsonPath('abilities', ['manage' => true, 'grade' => true, 'submit' => false]);
        $this->actingAs($student)->getJson("/api/assignments/{$draft->id}")->assertForbidden();
        $this->actingAs($outsider)->getJson("/api/assignments/{$open->id}")->assertForbidden();
    }

    public function test_the_submission_list_names_each_student(): void
    {
        $offering = $this->offering();
        $teacher = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student', ['name' => 'Ada Lovelace']);
        $this->teach($offering, $teacher);
        $this->enrol($offering, $student);
        $assignment = $offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 50, 'published' => true]);
        $assignment->submissions()->create(['user_id' => $student->id, 'body' => 'My essay', 'submitted_at' => now()]);

        $this->actingAs($teacher)->getJson("/api/assignments/{$assignment->id}/submissions")->assertOk()
            ->assertJsonPath('data.0.user.name', 'Ada Lovelace')->assertJsonPath('data.0.user.email', $student->email);
    }

    public function test_contacts_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/contacts')->assertUnauthorized();
    }
}
