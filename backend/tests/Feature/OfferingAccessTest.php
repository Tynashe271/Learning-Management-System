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

class OfferingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_read_another_offering_even_with_the_student_role(): void
    {
        $this->seed(DatabaseSeeder::class);
        $student = User::factory()->create();
        $student->assignRole('student');
        $term = AcademicTerm::create(['name' => 'Term 1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
        $course = Course::create(['code' => 'CSC101', 'title' => 'Computing']);
        $first = CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'A', 'published' => true]);
        $second = CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'B', 'published' => true]);
        Enrolment::create(['course_offering_id' => $first->id, 'user_id' => $student->id, 'status' => 'active']);

        $this->actingAs($student)->getJson('/api/offerings/'.$first->id)->assertOk();
        $this->actingAs($student)->getJson('/api/offerings/'.$second->id)->assertForbidden();
    }

    public function test_lecturer_can_manage_only_the_assigned_offering(): void
    {
        $this->seed(DatabaseSeeder::class);
        $lecturer = User::factory()->create();
        $lecturer->assignRole('lecturer');
        $term = AcademicTerm::create(['name' => 'Term 1', 'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
        $course = Course::create(['code' => 'CSC101', 'title' => 'Computing']);
        $first = CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'A']);
        $second = CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => 'B']);
        TeachingAssignment::create(['course_offering_id' => $first->id, 'user_id' => $lecturer->id]);

        $this->actingAs($lecturer)->postJson('/api/offerings/'.$first->id.'/modules', ['title' => 'Week 1'])->assertCreated();
        $this->actingAs($lecturer)->postJson('/api/offerings/'.$second->id.'/modules', ['title' => 'Week 1'])->assertForbidden();
    }
}
