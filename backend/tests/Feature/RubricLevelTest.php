<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\RubricLevel;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCourses;
use Tests\TestCase;

class RubricLevelTest extends TestCase
{
    use BuildsCourses, RefreshDatabase;

    private CourseOffering $offering;

    private User $lecturer;

    private User $ada;

    private Assignment $essay;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->offering = $this->offering();
        $this->lecturer = $this->userWithRole('lecturer');
        $this->ada = $this->userWithRole('student');
        $this->teach($this->offering, $this->lecturer);
        $this->enrol($this->offering, $this->ada);
        $this->essay = Assignment::create(['course_offering_id' => $this->offering->id, 'title' => 'Essay', 'due_at' => now()->addDay(), 'max_score' => 100, 'published' => true]);
        $this->submission = $this->essay->submissions()->create(['user_id' => $this->ada->id, 'body' => 'My essay', 'submitted_at' => now()]);
    }

    private function rubric(): array
    {
        return ['criteria' => [
            ['title' => 'Argument', 'max_points' => 60, 'levels' => [
                ['title' => 'Excellent', 'description' => 'A clear thesis backed by evidence', 'points' => 60],
                ['title' => 'Good', 'description' => 'A thesis, with thin evidence', 'points' => 45],
                ['title' => 'Basic', 'points' => 30],
                ['title' => 'Missing', 'points' => 0],
            ]],
            ['title' => 'Style', 'max_points' => 40, 'levels' => [
                ['title' => 'Excellent', 'points' => 40],
                ['title' => 'Fine', 'points' => 25],
            ]],
        ]];
    }

    private function save(?array $rubric = null)
    {
        return $this->actingAs($this->lecturer)->putJson('/api/assignments/'.$this->essay->id.'/rubric', $rubric ?? $this->rubric());
    }

    /** @return array{argument: array<string, int>, style: array<string, int>} level ids keyed by title */
    private function levelIds(): array
    {
        $criteria = $this->essay->rubricCriteria()->orderBy('position')->get();

        return [
            'argument' => $criteria[0]->levels()->pluck('id', 'title')->all(),
            'style' => $criteria[1]->levels()->pluck('id', 'title')->all(),
            'criteria' => $criteria->pluck('id')->all(),
        ];
    }

    private function grade(array $criteria, string $status = 'published')
    {
        return $this->actingAs($this->lecturer)->postJson('/api/submissions/'.$this->submission->id.'/grades', ['status' => $status, 'criteria' => $criteria]);
    }

    public function test_criteria_can_describe_what_each_level_looks_like(): void
    {
        $response = $this->save()->assertOk();

        $response->assertJsonCount(2)->assertJsonCount(4, '0.levels')->assertJsonPath('0.levels.0.title', 'Excellent')->assertJsonPath('0.levels.0.description', 'A clear thesis backed by evidence')
            ->assertJsonPath('0.levels.1.points', 45)->assertJsonPath('0.levels.3.title', 'Missing')->assertJsonCount(2, '1.levels');
        $this->actingAs($this->ada)->getJson('/api/assignments/'.$this->essay->id.'/rubric')->assertOk()->assertJsonPath('0.levels.1.description', 'A thesis, with thin evidence');
    }

    public function test_levels_are_optional(): void
    {
        $this->save(['criteria' => [['title' => 'Everything', 'max_points' => 100]]])->assertOk()->assertJsonCount(0, '0.levels');
    }

    public function test_a_level_cannot_be_worth_more_than_its_criterion(): void
    {
        $rubric = $this->rubric();
        $rubric['criteria'][1]['levels'][0]['points'] = 41;

        $this->save($rubric)->assertJsonValidationErrors('criteria.1.levels.0.points');
        $this->assertSame(0, RubricLevel::count());
    }

    public function test_level_details_are_validated(): void
    {
        $noTitle = $this->rubric();
        $noTitle['criteria'][0]['levels'][0]['title'] = '';
        $negative = $this->rubric();
        $negative['criteria'][0]['levels'][1]['points'] = -1;
        $tooMany = $this->rubric();
        $tooMany['criteria'][0]['levels'] = array_map(fn ($i) => ['title' => "L{$i}", 'points' => 1], range(1, 11));

        $this->save($noTitle)->assertJsonValidationErrors('criteria.0.levels.0.title');
        $this->save($negative)->assertJsonValidationErrors('criteria.0.levels.1.points');
        $this->save($tooMany)->assertJsonValidationErrors('criteria.0.levels');
        $this->assertSame(0, $this->essay->rubricCriteria()->count());
    }

    public function test_replacing_a_rubric_replaces_its_levels(): void
    {
        $this->save()->assertOk();
        $this->assertSame(6, RubricLevel::count());

        $this->save(['criteria' => [['title' => 'One', 'max_points' => 100, 'levels' => [['title' => 'Only', 'points' => 100]]]]])->assertOk();

        $this->assertSame(1, RubricLevel::count());
    }

    public function test_grading_by_level_uses_that_levels_points(): void
    {
        $this->save();
        $ids = $this->levelIds();

        $grade = $this->grade([
            ['criterion_id' => $ids['criteria'][0], 'level_id' => $ids['argument']['Good'], 'comment' => 'Needs more evidence'],
            ['criterion_id' => $ids['criteria'][1], 'level_id' => $ids['style']['Fine']],
        ])->assertCreated();

        $this->assertEquals(70, $grade->json('score'));
        $grade->assertJsonPath('criteria_scores.0.level_title', 'Good')->assertJsonPath('criteria_scores.0.points', 45)->assertJsonPath('criteria_scores.0.level_id', $ids['argument']['Good'])->assertJsonPath('criteria_scores.1.level_title', 'Fine');
        $mine = $this->actingAs($this->ada)->getJson('/api/assignments/'.$this->essay->id.'/my-grade')->assertOk();
        $mine->assertJsonPath('grade.criteria_scores.0.level_title', 'Good')->assertJsonPath('grade.criteria_scores.0.comment', 'Needs more evidence');
    }

    public function test_a_grader_can_still_give_points_that_match_no_level(): void
    {
        $this->save();
        $ids = $this->levelIds();

        $grade = $this->grade([
            ['criterion_id' => $ids['criteria'][0], 'level_id' => $ids['argument']['Excellent']],
            ['criterion_id' => $ids['criteria'][1], 'points' => 33],
        ])->assertCreated();

        $this->assertEquals(93, $grade->json('score'));
        $grade->assertJsonPath('criteria_scores.1.level_id', null)->assertJsonPath('criteria_scores.1.level_title', null)->assertJsonPath('criteria_scores.1.points', 33);
    }

    public function test_points_sent_with_a_level_must_agree_with_it(): void
    {
        $this->save();
        $ids = $this->levelIds();
        $style = ['criterion_id' => $ids['criteria'][1], 'level_id' => $ids['style']['Fine']];

        $this->grade([['criterion_id' => $ids['criteria'][0], 'level_id' => $ids['argument']['Good'], 'points' => 50], $style], 'draft')->assertJsonValidationErrors('criteria');
        $this->grade([['criterion_id' => $ids['criteria'][0], 'level_id' => $ids['argument']['Good'], 'points' => 45], $style], 'draft')->assertCreated();
    }

    public function test_a_level_from_a_different_criterion_or_that_does_not_exist_is_refused(): void
    {
        $this->save();
        $ids = $this->levelIds();
        $style = ['criterion_id' => $ids['criteria'][1], 'points' => 20];

        $this->grade([['criterion_id' => $ids['criteria'][0], 'level_id' => $ids['style']['Excellent']], $style], 'draft')->assertJsonValidationErrors('criteria');
        $this->grade([['criterion_id' => $ids['criteria'][0], 'level_id' => 999999], $style], 'draft')->assertJsonValidationErrors('criteria');
        $this->assertDatabaseCount('grade_records', 0);
    }

    public function test_every_criterion_needs_a_level_or_points(): void
    {
        $this->save();
        $ids = $this->levelIds();

        $this->grade([['criterion_id' => $ids['criteria'][0]], ['criterion_id' => $ids['criteria'][1], 'level_id' => $ids['style']['Fine']]], 'draft')->assertJsonValidationErrors('criteria.0.points');
    }

    public function test_the_levels_freeze_with_the_rubric_once_grading_starts(): void
    {
        $this->save();
        $ids = $this->levelIds();
        $this->grade([['criterion_id' => $ids['criteria'][0], 'level_id' => $ids['argument']['Good']], ['criterion_id' => $ids['criteria'][1], 'level_id' => $ids['style']['Fine']]])->assertCreated();

        $this->save(['criteria' => [['title' => 'New', 'max_points' => 100, 'levels' => [['title' => 'x', 'points' => 1]]]]])->assertJsonValidationErrors('rubric');

        $this->assertSame(6, RubricLevel::count());
    }

    public function test_levels_are_copied_with_the_course(): void
    {
        $this->save();
        $nextTerm = AcademicTerm::create(['name' => 'Term 2', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-01']);

        $copy = $this->actingAs($this->userWithRole('university-admin'))->postJson('/api/offerings/'.$this->offering->id.'/copy', ['academic_term_id' => $nextTerm->id, 'section' => 'A'])->assertCreated();

        $newAssignment = CourseOffering::findOrFail($copy->json('offering.id'))->assignments()->firstOrFail();
        $criteria = $newAssignment->rubricCriteria()->orderBy('position')->get();
        $this->assertSame(['Excellent', 'Good', 'Basic', 'Missing'], $criteria[0]->levels()->pluck('title')->all());
        $this->assertSame([60, 45, 30, 0], $criteria[0]->levels()->pluck('points')->all());
        $this->assertSame('A clear thesis backed by evidence', $criteria[0]->levels()->first()->description);
        $this->assertSame(6, RubricLevel::whereIn('rubric_criterion_id', $criteria->pluck('id'))->count());
        $this->assertSame(12, RubricLevel::count(), 'the original rubric keeps its own levels');
    }
}
