<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\InterventionPlan;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\SupportCheckIn;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** The learning intervention centre: computed at-risk signals (missing work, a declining grade trend, inactivity) and a lecturer's record of reaching out. */
class InterventionController extends Controller
{
    /** Days since last sign-in before a student is flagged inactive. */
    private const INACTIVE_DAYS = 14;

    /** How many of a student's most recent published grades count as "recent" when checking for a decline. */
    private const TREND_WINDOW = 3;

    /** A drop of this many percentage points between the earlier and recent average counts as declining. */
    private const DECLINE_POINTS = 10;

    public function atRisk(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $students = Paging::activeStudents($offering)->with('user:id,name,email,last_login_at')->get()->pluck('user')->filter()->values();
        $assignments = $offering->assignments()->where('published', true)->orderBy('due_at')->get();
        $openPlans = InterventionPlan::where('course_offering_id', $offering->id)->where('status', 'open')->pluck('user_id');

        $rows = $students->map(fn (User $student) => [
            'user' => $student->only(['id', 'name', 'email']),
            'has_open_plan' => $openPlans->contains($student->id),
        ] + $this->signalsFor($student, $assignments))->values();

        return response()->json($rows);
    }

    /** @return array{missing_assignments: int, declining: bool, inactive_days: int|null, flagged: bool} */
    private function signalsFor(User $student, Collection $assignments): array
    {
        $percents = [];
        $missing = 0;
        foreach ($assignments as $assignment) {
            /** @var Assignment $assignment */
            $submission = Submission::where('assignment_id', $assignment->id)->where('user_id', $student->id)->first();
            if (! $submission) {
                if ($assignment->due_at->isPast()) {
                    $missing++;
                }

                continue;
            }
            $grade = $submission->gradeRecords()->where('status', 'published')->latest('id')->first();
            if ($grade && $assignment->max_score > 0) {
                $percents[] = (float) $grade->score / $assignment->max_score * 100;
            }
        }

        $declining = false;
        if (count($percents) >= 2) {
            $recentCount = min(self::TREND_WINDOW, intdiv(count($percents), 2));
            $recent = array_slice($percents, -$recentCount);
            $earlier = array_slice($percents, 0, count($percents) - $recentCount);
            if ($recentCount > 0 && count($earlier) > 0) {
                $declining = (array_sum($earlier) / count($earlier)) - (array_sum($recent) / count($recent)) >= self::DECLINE_POINTS;
            }
        }

        $inactiveDays = $student->last_login_at ? now()->diffInDays($student->last_login_at) : null;

        return [
            'missing_assignments' => $missing,
            'declining' => $declining,
            'inactive_days' => $inactiveDays,
            'flagged' => $missing > 0 || $declining || ($inactiveDays !== null && $inactiveDays >= self::INACTIVE_DAYS),
        ];
    }

    public function indexPlans(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $query = $offering->interventionPlans()->with('student:id,name', 'author:id,name')->orderByDesc('id');
        if ($userId = $request->integer('user_id')) {
            $query->where('user_id', $userId);
        }

        return response()->json($query->get());
    }

    public function storePlan(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('enrolments', 'user_id')->where('course_offering_id', $offering->id)->where('status', 'active')],
            'reason' => ['required', 'string', 'max:2000'],
            'action_plan' => ['nullable', 'string', 'max:2000'],
            'message_to_student' => ['nullable', 'string', 'max:1000'],
        ]);
        $message = $data['message_to_student'] ?? null;
        unset($data['message_to_student']);
        $plan = $offering->interventionPlans()->create($data + ['created_by' => $request->user()->id, 'status' => 'open']);
        if ($message) {
            $plan->student->notify(new SupportCheckIn($plan, $offering->course?->title ?? 'your course', $message));
        }

        return response()->json($plan->load('student:id,name', 'author:id,name'), 201);
    }

    public function updatePlan(Request $request, InterventionPlan $plan): JsonResponse
    {
        $this->authorize('manage', $plan->offering);
        $data = $request->validate([
            'reason' => ['sometimes', 'string', 'max:2000'],
            'action_plan' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['open', 'resolved'])],
            'message_to_student' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $message = $data['message_to_student'] ?? null;
        unset($data['message_to_student']);
        if (($data['status'] ?? null) === 'resolved') {
            $data['resolved_at'] = now();
        } elseif (($data['status'] ?? null) === 'open') {
            $data['resolved_at'] = null;
        }
        $plan->update($data);
        if ($message) {
            $plan->student->notify(new SupportCheckIn($plan, $plan->offering->course?->title ?? 'your course', $message));
        }

        return response()->json($plan->load('student:id,name', 'author:id,name'));
    }
}
