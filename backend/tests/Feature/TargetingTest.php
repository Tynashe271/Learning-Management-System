<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class TargetingTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student');
        $this->ben = $this->userWithRole('student');
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->enrol($this->offering, $this->ben);
    }

    public function test_a_manager_can_target_an_assignment_to_specific_students(): void
    {
        $assignment = $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/assignments', [
            'title' => 'For Ada only', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true, 'target_user_ids' => [$this->ada->id],
        ])->assertCreated()->assertJsonPath('target_user_ids', [$this->ada->id])->json();

        $adaSees = $this->actingAs($this->ada)->getJson('/api/offerings/'.$this->offering->id)->assertOk();
        $this->assertTrue(collect($adaSees->json('assignments'))->contains('id', $assignment['id']));

        $benSees = $this->actingAs($this->ben)->getJson('/api/offerings/'.$this->offering->id)->assertOk();
        $this->assertFalse(collect($benSees->json('assignments'))->contains('id', $assignment['id']));

        $lecturerSees = $this->actingAs($this->lecturer)->getJson('/api/offerings/'.$this->offering->id)->assertOk();
        $seen = collect($lecturerSees->json('assignments'))->firstWhere('id', $assignment['id']);
        $this->assertSame([$this->ada->id], $seen['target_user_ids']);
    }

    public function test_a_non_targeted_student_cannot_view_or_submit_a_targeted_assignment(): void
    {
        $assignment = $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/assignments', [
            'title' => 'For Ada only', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true, 'target_user_ids' => [$this->ada->id],
        ])->json();

        $this->actingAs($this->ben)->getJson('/api/assignments/'.$assignment['id'])->assertForbidden();
        $this->actingAs($this->ben)->postJson('/api/assignments/'.$assignment['id'].'/submissions', ['body' => 'Not for me.'])->assertForbidden();

        $this->actingAs($this->ada)->getJson('/api/assignments/'.$assignment['id'])->assertOk();
        $this->actingAs($this->ada)->postJson('/api/assignments/'.$assignment['id'].'/submissions', ['body' => 'My work.'])->assertCreated();
    }

    public function test_a_manager_can_target_a_quiz_and_a_non_targeted_student_cannot_take_it(): void
    {
        $quiz = $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/quizzes', [
            'title' => 'For Ben only', 'due_at' => now()->addDay(), 'published' => true, 'target_user_ids' => [$this->ben->id],
        ])->assertCreated()->assertJsonPath('target_user_ids', [$this->ben->id])->json();
        $this->actingAs($this->lecturer)->postJson('/api/quizzes/'.$quiz['id'].'/questions', [
            'type' => 'true_false', 'prompt' => 'Is this for Ben?', 'points' => 1, 'correct' => true,
        ])->assertCreated();

        $this->actingAs($this->ada)->getJson('/api/quizzes/'.$quiz['id'])->assertForbidden();
        $this->actingAs($this->ada)->postJson('/api/quizzes/'.$quiz['id'].'/attempts')->assertForbidden();

        $this->actingAs($this->ben)->getJson('/api/quizzes/'.$quiz['id'])->assertOk();
        $this->actingAs($this->ben)->postJson('/api/quizzes/'.$quiz['id'].'/attempts')->assertCreated();
    }

    public function test_target_user_ids_must_be_actively_enrolled_students_of_the_offering(): void
    {
        $outsider = $this->userWithRole('student');
        $withdrawn = $this->userWithRole('student');
        $this->enrol($this->offering, $withdrawn, 'withdrawn');

        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/assignments', [
            'title' => 'Bad target', 'due_at' => now()->addDay(), 'max_score' => 10, 'target_user_ids' => [$outsider->id],
        ])->assertJsonValidationErrors('target_user_ids.0');

        $this->actingAs($this->lecturer)->postJson('/api/offerings/'.$this->offering->id.'/assignments', [
            'title' => 'Bad target', 'due_at' => now()->addDay(), 'max_score' => 10, 'target_user_ids' => [$withdrawn->id],
        ])->assertJsonValidationErrors('target_user_ids.0');
    }

    public function test_updating_targets_replaces_the_previous_set_and_clearing_it_returns_to_the_whole_class(): void
    {
        $assignment = $this->offering->assignments()->create(['title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 10, 'published' => true]);
        $assignment->targetedUsers()->sync([$this->ada->id]);

        $this->actingAs($this->lecturer)->patchJson('/api/assignments/'.$assignment->id, ['target_user_ids' => [$this->ben->id]])
            ->assertOk()->assertJsonPath('target_user_ids', [$this->ben->id]);
        $this->assertFalse($assignment->fresh()->isVisibleTo($this->ada));
        $this->assertTrue($assignment->fresh()->isVisibleTo($this->ben));

        $this->actingAs($this->lecturer)->patchJson('/api/assignments/'.$assignment->id, ['target_user_ids' => []])->assertOk()->assertJsonPath('target_user_ids', []);
        $this->assertTrue($assignment->fresh()->isVisibleTo($this->ada));
        $this->assertTrue($assignment->fresh()->isVisibleTo($this->ben));
    }

    public function test_a_targeted_assignment_never_appears_as_due_or_missing_for_a_student_it_was_not_assigned_to(): void
    {
        $assignment = $this->offering->assignments()->create(['title' => 'For Ada only', 'due_at' => now()->addHour(), 'max_score' => 10, 'published' => true]);
        $assignment->targetedUsers()->sync([$this->ada->id]);

        $adaAgenda = $this->actingAs($this->ada)->getJson('/api/me/agenda')->assertOk();
        $this->assertTrue(collect($adaAgenda->json('deadlines'))->contains('id', $assignment->id));

        $benAgenda = $this->actingAs($this->ben)->getJson('/api/me/agenda')->assertOk();
        $this->assertFalse(collect($benAgenda->json('deadlines'))->contains('id', $assignment->id));
    }
}
