<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competency;
use App\Models\CompetencyStatus;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\LogbookEntry;
use App\Rules\CleanFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** The Practical Skills Passport: a course's trackable competencies, each student's status on them, and a digital logbook of practical activity with optional evidence. */
class CompetencyController extends Controller
{
    public function index(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);
        $user = $request->user();
        $manages = $user->can('manage', $offering);
        $competencies = $offering->competencies()->orderBy('id')->get();
        if (! $manages) {
            $statuses = CompetencyStatus::whereIn('competency_id', $competencies->pluck('id'))->where('user_id', $user->id)->get()->keyBy('competency_id');
            $hours = LogbookEntry::whereIn('competency_id', $competencies->pluck('id'))->where('user_id', $user->id)->selectRaw('competency_id, sum(hours) as total')->groupBy('competency_id')->pluck('total', 'competency_id');
            $competencies->each(function (Competency $c) use ($statuses, $hours) {
                $c->my_status = $statuses->get($c->id)?->status ?? 'not_started';
                $c->my_hours = (float) ($hours->get($c->id) ?? 0);
            });
        }

        return response()->json($competencies);
    }

    public function store(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string']]);

        return response()->json($offering->competencies()->create($data), 201);
    }

    public function update(Request $request, Competency $competency): JsonResponse
    {
        $this->authorize('manage', $competency->offering);
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:255'], 'description' => ['sometimes', 'nullable', 'string']]);
        $competency->update($data);

        return response()->json($competency);
    }

    public function destroy(Request $request, Competency $competency): JsonResponse
    {
        $this->authorize('manage', $competency->offering);
        abort_if($competency->logbookEntries()->exists(), 422, 'This competency already has logbook entries and cannot be deleted.');
        $competency->delete();

        return response()->json(['message' => 'Competency deleted.']);
    }

    /** A student logs one dated, timed activity toward a competency; a first entry moves it from not_started to developing. */
    public function storeLogbookEntry(Request $request, Competency $competency): JsonResponse
    {
        $this->assertEnrolled($request, $competency);
        $data = $request->validate([
            'activity_date' => ['required', 'date', 'before_or_equal:today'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'description' => ['required', 'string', 'max:2000'],
            'evidence' => ['bail', 'nullable', 'file', 'max:'.(int) config('lms.limits.upload_mb') * 1024, 'mimes:'.config('lms.upload_mimes'), new CleanFile],
        ]);
        $path = $request->hasFile('evidence') ? $request->file('evidence')->store('logbook/'.$competency->id.'/'.$request->user()->id, 's3') : null;
        $entry = $competency->logbookEntries()->create([
            'user_id' => $request->user()->id,
            'activity_date' => $data['activity_date'],
            'hours' => $data['hours'],
            'description' => $data['description'],
            'evidence_path' => $path,
        ]);
        $status = CompetencyStatus::firstOrCreate(['competency_id' => $competency->id, 'user_id' => $request->user()->id], ['status' => 'developing']);
        if ($status->status === 'not_started') {
            $status->update(['status' => 'developing']);
        }

        return response()->json($entry, 201);
    }

    /** The caller's own entries, or (for a manager) every student's - optionally narrowed to one with ?user_id=. */
    public function indexLogbookEntries(Request $request, Competency $competency): JsonResponse
    {
        $manages = $request->user()->can('manage', $competency->offering);
        if (! $manages) {
            $this->assertEnrolled($request, $competency);
        }
        $query = $competency->logbookEntries()->with('user:id,name')->orderBy('activity_date')->orderBy('id');
        if ($manages) {
            $userId = $request->validate(['user_id' => ['sometimes', 'integer']])['user_id'] ?? null;
            if ($userId) {
                $query->where('user_id', $userId);
            }
        } else {
            $query->where('user_id', $request->user()->id);
        }

        return response()->json($query->get());
    }

    /** A supervisor/lecturer reviews one entry and sets the student's overall status on that competency. */
    public function reviewLogbookEntry(Request $request, LogbookEntry $entry): JsonResponse
    {
        $this->authorize('manage', $entry->competency->offering);
        $data = $request->validate([
            'status' => ['required', Rule::in(['developing', 'competent'])],
            'reviewer_comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $entry->update(['reviewed_at' => now(), 'reviewed_by' => $request->user()->id, 'reviewer_comment' => $data['reviewer_comment'] ?? null]);
        CompetencyStatus::updateOrCreate(
            ['competency_id' => $entry->competency_id, 'user_id' => $entry->user_id],
            ['status' => $data['status'], 'updated_by' => $request->user()->id],
        );

        return response()->json($entry->load('reviewer:id,name'));
    }

    public function downloadEvidence(Request $request, LogbookEntry $entry)
    {
        abort_unless($entry->user_id === $request->user()->id || $request->user()->can('manage', $entry->competency->offering), 403);
        abort_unless($entry->evidence_path, 404);

        return Storage::disk('s3')->download($entry->evidence_path);
    }

    private function assertEnrolled(Request $request, Competency $competency): void
    {
        abort_unless(Enrolment::where('course_offering_id', $competency->course_offering_id)->where('user_id', $request->user()->id)->where('status', 'active')->exists(), 403);
    }
}
