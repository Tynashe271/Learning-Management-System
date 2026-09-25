<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\GradeRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RubricController extends Controller
{
    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('view', $assignment);

        return response()->json($this->criteria($assignment));
    }

    /**
     * Replaces the whole rubric. The criteria's points must add up to the assignment's maximum score. Each criterion may
     * also list levels (for example "Excellent: 9-10 points, a clear thesis with evidence") that graders can pick from.
     */
    public function replace(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('manage', $assignment->offering);
        $this->assertUngraded($assignment);
        $data = $request->validate([
            'criteria' => ['required', 'array', 'min:1', 'max:20'],
            'criteria.*.title' => ['required', 'string', 'max:255'],
            'criteria.*.description' => ['nullable', 'string', 'max:2000'],
            'criteria.*.max_points' => ['required', 'integer', 'min:1', 'max:1000'],
            'criteria.*.levels' => ['sometimes', 'array', 'max:10'],
            'criteria.*.levels.*.title' => ['required', 'string', 'max:255'],
            'criteria.*.levels.*.description' => ['nullable', 'string', 'max:2000'],
            'criteria.*.levels.*.points' => ['required', 'integer', 'min:0'],
        ]);
        $total = array_sum(array_column($data['criteria'], 'max_points'));
        if ($total !== (int) $assignment->max_score) {
            throw ValidationException::withMessages(['criteria' => "The criteria must add up to the assignment's {$assignment->max_score} points; they add up to {$total}."]);
        }
        foreach (array_values($data['criteria']) as $i => $criterion) {
            foreach (array_values($criterion['levels'] ?? []) as $j => $level) {
                if ($level['points'] > $criterion['max_points']) {
                    throw ValidationException::withMessages(["criteria.{$i}.levels.{$j}.points" => "A level cannot be worth more than the criterion's {$criterion['max_points']} points."]);
                }
            }
        }

        DB::transaction(function () use ($assignment, $data) {
            $assignment->rubricCriteria()->delete();
            foreach (array_values($data['criteria']) as $position => $criterion) {
                $levels = $criterion['levels'] ?? [];
                unset($criterion['levels']);
                $created = $assignment->rubricCriteria()->create($criterion + ['position' => $position]);
                foreach (array_values($levels) as $order => $level) {
                    $created->levels()->create($level + ['position' => $order]);
                }
            }
        });
        activity()->causedBy($request->user())->performedOn($assignment)->withProperties(['criteria' => count($data['criteria'])])->log('rubric saved');

        return response()->json($this->criteria($assignment));
    }

    public function destroy(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('manage', $assignment->offering);
        $this->assertUngraded($assignment);
        $assignment->rubricCriteria()->delete();
        activity()->causedBy($request->user())->performedOn($assignment)->log('rubric removed');

        return response()->json(['message' => 'Rubric removed.']);
    }

    private function criteria(Assignment $assignment)
    {
        return $assignment->rubricCriteria()->with('levels:id,rubric_criterion_id,title,description,points,position')
            ->orderBy('position')->orderBy('id')->get(['id', 'title', 'description', 'max_points', 'position']);
    }

    /** Once marks exist they refer to the criteria, so the rubric is frozen. */
    private function assertUngraded(Assignment $assignment): void
    {
        if (GradeRecord::whereIn('submission_id', $assignment->submissions()->select('id'))->exists()) {
            throw ValidationException::withMessages(['rubric' => 'Grades have already been recorded, so the rubric can no longer change.']);
        }
    }
}
