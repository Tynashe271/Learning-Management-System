<?php

namespace Tests\Concerns;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\TeachingAssignment;
use App\Models\User;

trait BuildsCourses
{
    protected function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->create($attributes)->assignRole($role);
    }

    protected function offering(string $section = 'A', bool $published = true): CourseOffering
    {
        $term = AcademicTerm::firstOrCreate(['name' => 'Term 1'], ['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
        $course = Course::firstOrCreate(['code' => 'CSC101'], ['title' => 'Computing']);

        return CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => $section, 'published' => $published]);
    }

    protected function enrol(CourseOffering $offering, User $user, string $status = 'active'): void
    {
        Enrolment::create(['course_offering_id' => $offering->id, 'user_id' => $user->id, 'status' => $status]);
    }

    protected function teach(CourseOffering $offering, User $user): void
    {
        TeachingAssignment::create(['course_offering_id' => $offering->id, 'user_id' => $user->id]);
    }
}
