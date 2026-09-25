<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\TeachingAssignment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function offering(string $section = 'A', bool $published = true): CourseOffering
    {
        $term = AcademicTerm::firstOrCreate(['name' => 'Term 1'], ['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
        $course = Course::firstOrCreate(['code' => 'CSC101'], ['title' => 'Computing']);

        return CourseOffering::create(['course_id' => $course->id, 'academic_term_id' => $term->id, 'section' => $section, 'published' => $published]);
    }

    private function enrol(CourseOffering $offering, User $user, string $status = 'active'): void
    {
        Enrolment::create(['course_offering_id' => $offering->id, 'user_id' => $user->id, 'status' => $status]);
    }

    public function test_assigned_lecturer_posts_and_enrolled_students_are_notified(): void
    {
        $lecturer = $this->userWithRole('lecturer');
        $enrolled = $this->userWithRole('student');
        $withdrawn = $this->userWithRole('student');
        $outsider = $this->userWithRole('student');
        $offering = $this->offering();
        TeachingAssignment::create(['course_offering_id' => $offering->id, 'user_id' => $lecturer->id]);
        $this->enrol($offering, $enrolled);
        $this->enrol($offering, $withdrawn, 'withdrawn');

        $this->actingAs($lecturer)->postJson('/api/offerings/'.$offering->id.'/announcements', ['title' => 'Welcome', 'body' => 'Read chapter 1.'])
            ->assertCreated()->assertJsonPath('author.id', $lecturer->id);

        $this->assertCount(1, $enrolled->notifications);
        $this->assertSame('Welcome', $enrolled->notifications->first()->data['title']);
        $this->assertCount(0, $withdrawn->notifications);
        $this->assertCount(0, $outsider->notifications);
    }

    public function test_unpublished_offering_does_not_notify_students(): void
    {
        $lecturer = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student');
        $offering = $this->offering('A', false);
        TeachingAssignment::create(['course_offering_id' => $offering->id, 'user_id' => $lecturer->id]);
        $this->enrol($offering, $student);

        $this->actingAs($lecturer)->postJson('/api/offerings/'.$offering->id.'/announcements', ['title' => 'Draft', 'body' => 'Not yet.'])->assertCreated();

        $this->assertCount(0, $student->notifications);
    }

    public function test_only_offering_managers_can_post_or_delete(): void
    {
        $lecturer = $this->userWithRole('lecturer');
        $otherLecturer = $this->userWithRole('lecturer');
        $student = $this->userWithRole('student');
        $offering = $this->offering();
        TeachingAssignment::create(['course_offering_id' => $offering->id, 'user_id' => $lecturer->id]);
        $this->enrol($offering, $student);
        $url = '/api/offerings/'.$offering->id.'/announcements';
        $payload = ['title' => 'Hi', 'body' => 'There'];

        $this->actingAs($student)->postJson($url, $payload)->assertForbidden();
        $this->actingAs($otherLecturer)->postJson($url, $payload)->assertForbidden();
        $id = $this->actingAs($lecturer)->postJson($url, $payload)->assertCreated()->json('id');

        $this->actingAs($student)->deleteJson('/api/announcements/'.$id)->assertForbidden();
        $this->actingAs($otherLecturer)->deleteJson('/api/announcements/'.$id)->assertForbidden();
        $this->actingAs($lecturer)->deleteJson('/api/announcements/'.$id)->assertOk();
        $this->assertDatabaseMissing('announcements', ['id' => $id]);
    }

    public function test_only_people_who_can_view_the_offering_can_read_announcements(): void
    {
        $lecturer = $this->userWithRole('lecturer');
        $enrolled = $this->userWithRole('student');
        $outsider = $this->userWithRole('student');
        $offering = $this->offering();
        TeachingAssignment::create(['course_offering_id' => $offering->id, 'user_id' => $lecturer->id]);
        $this->enrol($offering, $enrolled);
        $offering->announcements()->create(['user_id' => $lecturer->id, 'title' => 'First', 'body' => 'a']);
        $offering->announcements()->create(['user_id' => $lecturer->id, 'title' => 'Second', 'body' => 'b']);
        $url = '/api/offerings/'.$offering->id.'/announcements';

        $this->actingAs($enrolled)->getJson($url)->assertOk()->assertJsonPath('data.0.title', 'Second')->assertJsonCount(2, 'data');
        $this->actingAs($outsider)->getJson($url)->assertForbidden();
    }

    public function test_summary_counts_enrolled_students_submissions_and_published_grades(): void
    {
        $lecturer = $this->userWithRole('lecturer');
        $one = $this->userWithRole('student');
        $two = $this->userWithRole('student');
        $three = $this->userWithRole('student');
        $offering = $this->offering();
        TeachingAssignment::create(['course_offering_id' => $offering->id, 'user_id' => $lecturer->id]);
        foreach ([$one, $two, $three] as $student) {
            $this->enrol($offering, $student);
        }
        $assignment = Assignment::create(['course_offering_id' => $offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
        $graded = $assignment->submissions()->create(['user_id' => $one->id, 'body' => 'x', 'submitted_at' => now()]);
        $draftOnly = $assignment->submissions()->create(['user_id' => $two->id, 'body' => 'y', 'submitted_at' => now()]);
        $graded->gradeRecords()->create(['graded_by' => $lecturer->id, 'score' => 80, 'status' => 'published']);
        $draftOnly->gradeRecords()->create(['graded_by' => $lecturer->id, 'score' => 60, 'status' => 'draft']);

        $this->actingAs($lecturer)->getJson('/api/offerings/'.$offering->id.'/summary')
            ->assertOk()
            ->assertJsonPath('enrolled', 3)
            ->assertJsonPath('assignments.0.submissions', 2)
            ->assertJsonPath('assignments.0.graded', 1)
            ->assertJsonPath('assignments.0.awaiting_submission', 1);
        $this->actingAs($one)->getJson('/api/offerings/'.$offering->id.'/summary')->assertForbidden();
    }
}
