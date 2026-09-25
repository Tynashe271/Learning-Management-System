<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\TeachingAssignment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->create($attributes)->assignRole($role);
    }

    private function offering(string $section = 'A', bool $published = true): CourseOffering
    {
        $term = AcademicTerm::firstOrCreate(['name' => 'Term 1'], ['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
        $course = Course::firstOrCreate(['code' => 'CSC101'], ['title' => 'Computing']);

        return CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => $section, 'published' => $published]);
    }

    public function test_registrar_can_search_and_filter_users(): void
    {
        $registrar = $this->userWithRole('registrar');
        $this->userWithRole('student', ['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
        $this->userWithRole('student', ['name' => 'Alan Turing', 'email' => 'alan@example.com']);
        $this->userWithRole('lecturer', ['name' => 'Grace Lecturer', 'email' => 'gl@example.com']);

        $this->actingAs($registrar)->getJson('/api/users?role=student&q=GRACE')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'grace@example.com');
    }

    public function test_user_search_treats_wildcards_literally(): void
    {
        $admin = $this->userWithRole('university-admin');
        $this->userWithRole('student', ['name' => 'Plain Name', 'email' => 'plain@example.com']);
        $this->userWithRole('student', ['name' => 'a_b', 'email' => 'underscore@example.com']);
        $this->userWithRole('student', ['name' => 'axb', 'email' => 'letter@example.com']);

        $this->actingAs($admin)->getJson('/api/users?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/users?q=a_b')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'underscore@example.com');
        $this->actingAs($admin)->getJson('/api/users?q=!')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_students_and_lecturers_cannot_list_users_terms_or_courses(): void
    {
        foreach (['student', 'lecturer'] as $role) {
            $user = $this->userWithRole($role);
            foreach (['/api/users', '/api/terms', '/api/courses'] as $url) {
                $this->actingAs($user)->getJson($url)->assertForbidden();
            }
        }
    }

    public function test_admin_can_list_terms_and_courses(): void
    {
        $admin = $this->userWithRole('university-admin');
        $this->offering();

        $this->actingAs($admin)->getJson('/api/terms')->assertOk()->assertJsonPath('data.0.name', 'Term 1');
        $this->actingAs($admin)->getJson('/api/courses')->assertOk()->assertJsonPath('data.0.code', 'CSC101');
    }

    public function test_registrar_can_list_offerings_and_read_the_roster_to_manage_enrolments(): void
    {
        $registrar = $this->userWithRole('registrar');
        $student = $this->userWithRole('student');
        $lecturer = $this->userWithRole('lecturer');
        $offering = $this->offering();
        Enrolment::create(['course_offering_id' => $offering->id, 'user_id' => $student->id, 'status' => 'active']);
        TeachingAssignment::create(['course_offering_id' => $offering->id, 'user_id' => $lecturer->id]);

        $this->actingAs($registrar)->getJson('/api/offerings')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($registrar)->getJson('/api/offerings/'.$offering->id.'/roster')
            ->assertOk()
            ->assertJsonPath('enrolments.0.user.id', $student->id)
            ->assertJsonPath('teachers.0.user.id', $lecturer->id)
            ->assertJsonMissingPath('enrolments.0.user.password');
    }

    public function test_roster_is_limited_to_managers_of_that_offering(): void
    {
        $lecturer = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student');
        $mine = $this->offering('A');
        $other = $this->offering('B');
        TeachingAssignment::create(['course_offering_id' => $mine->id, 'user_id' => $lecturer->id]);
        Enrolment::create(['course_offering_id' => $mine->id, 'user_id' => $student->id, 'status' => 'active']);

        $this->actingAs($lecturer)->getJson('/api/offerings/'.$mine->id.'/roster')->assertOk();
        $this->actingAs($lecturer)->getJson('/api/offerings/'.$other->id.'/roster')->assertForbidden();
        $this->actingAs($student)->getJson('/api/offerings/'.$mine->id.'/roster')->assertForbidden();
    }
}
