<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\GradeAppeal;
use App\Models\Submission;
use App\Notifications\AppealFiled;
use App\Notifications\AppealResolved;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A student can challenge a published assignment grade once, within a window after it was published. Staff review it, and
 * either revise the grade through the normal grading route (then uphold the appeal) or reject it with an explanation.
 */
class AppealController extends Controller
{
    public function store(Request $request, Submission $submission): JsonResponse
    {
        abort_unless($submission->user_id === $request->user()->id, 403);
        $assignment = $submission->assignment;
        $this->authorize('view', $assignment);
        $data = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:5000']]);

        $grade = $submission->gradeRecords()->where('status', 'published')->latest('id')->first();
        if (! $grade) {
            throw ValidationException::withMessages(['appeal' => 'There is no published grade to appeal yet.']);
        }
        $days = (int) config('lms.appeals.window_days');
        if ($grade->created_at->toImmutable()->addDays($days)->isPast()) {
            throw ValidationException::withMessages(['appeal' => "The appeal window has closed ({$days} days after the grade was published)."]);
        }
        $already = ValidationException::withMessages(['appeal' => 'This grade has already been appealed.']);
        if (GradeAppeal::where('submission_id', $submission->id)->exists()) {
            throw $already;
        }
        try {
            $appeal = GradeAppeal::create(['submission_id' => $submission->id, 'user_id' => $request->user()->id, 'grade_record_id' => $grade->id, 'reason' => $data['reason'], 'status' => 'open']);
        } catch (UniqueConstraintViolationException) {
            throw $already;
        }
        activity()->causedBy($request->user())->performedOn($appeal)->withProperties(['assignment_id' => $assignment->id])->log('grade appeal filed');
        foreach ($assignment->offering->teachers()->with('user')->get() as $teacher) {
            $teacher->user?->notify(AppealFiled::for($appeal, $assignment->title, $assignment->id));
        }

        return response()->json($appeal, 201);
    }

    /** The signed-in student's own appeals. */
    public function mine(Request $request): JsonResponse
    {
        return response()->json(GradeAppeal::where('user_id', $request->user()->id)->with('submission.assignment:id,title,course_offering_id')->latest('id')->paginate(20));
    }

    /** Appeals in one offering, open ones first. `?status=open|upheld|rejected` filters. */
    public function forOffering(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $filter = $request->validate(['status' => ['sometimes', Rule::in(GradeAppeal::STATUSES)]]);

        return response()->json(
            GradeAppeal::whereHas('submission.assignment', fn ($q) => $q->where('course_offering_id', $offering->id))
                ->when(isset($filter['status']), fn ($q) => $q->where('status', $filter['status']))
                ->with('student:id,name,email', 'submission.assignment:id,title')
                ->orderByRaw("case when status = 'open' then 0 else 1 end")->latest('id')->paginate(20)
        );
    }

    public function show(Request $request, GradeAppeal $appeal): JsonResponse
    {
        $offering = $appeal->submission->assignment->offering;
        abort_unless($appeal->user_id === $request->user()->id || $request->user()->can('manage', $offering), 403);

        return response()->json($appeal->load([
            'student:id,name,email',
            'resolver:id,name',
            'submission.assignment:id,title,max_score,course_offering_id',
            'submission.gradeRecords' => fn ($q) => $q->where('status', 'published')->orderBy('id'),
        ]));
    }

    public function resolve(Request $request, GradeAppeal $appeal): JsonResponse
    {
        $submission = $appeal->submission;
        $assignment = $submission->assignment;
        $this->authorize('manage', $assignment->offering);
        abort_unless($request->user()->can('resolve-appeals'), 403);
        $data = $request->validate(['outcome' => ['required', Rule::in(['upheld', 'rejected'])], 'response' => ['required', 'string', 'min:10', 'max:5000']]);
        if ($appeal->status !== 'open') {
            throw ValidationException::withMessages(['appeal' => 'This appeal has already been decided.']);
        }

        // Grade records only ever grow, so a higher id means a grade published after the one being challenged.
        $revised = $submission->gradeRecords()->where('status', 'published')->where('id', '>', $appeal->grade_record_id)->exists();
        if ($data['outcome'] === 'upheld' && ! $revised) {
            throw ValidationException::withMessages(['outcome' => 'Record and publish the revised grade first (with a change reason), then uphold the appeal.']);
        }
        if ($data['outcome'] === 'rejected' && $revised) {
            throw ValidationException::withMessages(['outcome' => 'The grade was changed after the appeal was filed, so it cannot be rejected. Uphold it instead.']);
        }

        $appeal->update(['status' => $data['outcome'], 'response' => $data['response'], 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);
        activity()->causedBy($request->user())->performedOn($appeal)->withProperties(['outcome' => $data['outcome']])->log('grade appeal decided');
        $appeal->student->notify(AppealResolved::for($appeal, $assignment->title));

        return response()->json($appeal->fresh());
    }

    /** A student can take back an appeal while it is still open, and file it again (with the window still running). */
    public function withdraw(Request $request, GradeAppeal $appeal): JsonResponse
    {
        abort_unless($appeal->user_id === $request->user()->id, 403);
        if ($appeal->status !== 'open') {
            throw ValidationException::withMessages(['appeal' => 'A decided appeal cannot be withdrawn.']);
        }
        $appeal->delete();
        activity()->causedBy($request->user())->withProperties(['appeal_id' => $appeal->id, 'submission_id' => $appeal->submission_id])->log('grade appeal withdrawn');

        return response()->json(['message' => 'Appeal withdrawn.']);
    }
}
