<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\HandRaise;
use App\Support\Paging;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Scheduled class meetings (in a room and/or online through a meeting link) and who attended them. */
class SessionController extends Controller
{
    public function index(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);
        $sessions = $offering->sessions()->orderBy('starts_at')->orderBy('id')->get();
        if ($request->user()->can('manage', $offering)) {
            return response()->json($sessions);
        }
        $mine = AttendanceRecord::where('user_id', $request->user()->id)->whereIn('class_session_id', $sessions->pluck('id'))->pluck('status', 'class_session_id');
        $myHands = HandRaise::where('user_id', $request->user()->id)->whereIn('class_session_id', $sessions->pluck('id'))->pluck('raised_at', 'class_session_id');

        return response()->json($sessions->map(fn ($s) => $s->toArray() + [
            'my_status' => $mine[$s->id] ?? null,
            'checkin_open' => $s->checkinIsOpen(),
            'my_hand_raised_at' => $myHands[$s->id] ?? null,
        ])->values());
    }

    public function store(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'join_url' => ['nullable', 'url:http,https', 'max:2048'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($offering->sessions()->create($data), 201);
    }

    public function update(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('manage', $session->offering);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
            'join_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        if (Carbon::parse($data['ends_at'] ?? $session->ends_at)->lessThanOrEqualTo(Carbon::parse($data['starts_at'] ?? $session->starts_at))) {
            throw ValidationException::withMessages(['ends_at' => 'The session must end after it starts.']);
        }
        $session->update($data);

        return response()->json($session);
    }

    public function destroy(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('manage', $session->offering);
        $marked = $session->attendance()->count();
        $session->delete();
        activity()->causedBy($request->user())->performedOn($session)->withProperties(['attendance_records_removed' => $marked])->log('class session deleted');

        return response()->json(['message' => 'Session deleted.']);
    }

    /** Every actively enrolled student with their mark for this session (null when not yet marked). */
    public function roll(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('manage', $session->offering);
        $marks = $session->attendance()->get()->keyBy('user_id');
        $roll = Enrolment::where('course_offering_id', $session->course_offering_id)->where('status', 'active')->with('user:id,name,email')->get()
            ->pluck('user')->filter()->sortBy(fn ($u) => mb_strtolower($u->name))
            ->map(fn ($u) => ['user' => $u->only(['id', 'name', 'email']), 'status' => $marks[$u->id]->status ?? null, 'note' => $marks[$u->id]->note ?? null, 'source' => $marks[$u->id]->source ?? null])->values();

        return response()->json($roll);
    }

    /** Marks or corrects attendance for one or many students at once. */
    public function mark(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('manage', $session->offering);
        $data = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.user_id' => ['required', 'integer'],
            'records.*.status' => ['required', Rule::in(AttendanceRecord::STATUSES)],
            'records.*.note' => ['nullable', 'string', 'max:500'],
        ]);
        $enrolled = Enrolment::where('course_offering_id', $session->course_offering_id)->where('status', 'active')->pluck('user_id')->all();
        if (array_diff(array_column($data['records'], 'user_id'), $enrolled) !== []) {
            throw ValidationException::withMessages(['records' => 'Attendance can only be recorded for students actively enrolled in this offering.']);
        }

        DB::transaction(function () use ($session, $data, $request) {
            foreach ($data['records'] as $record) {
                AttendanceRecord::updateOrCreate(
                    ['class_session_id' => $session->id, 'user_id' => $record['user_id']],
                    ['status' => $record['status'], 'note' => $record['note'] ?? null, 'marked_by' => $request->user()->id, 'source' => 'staff'],
                );
            }
        });
        activity()->causedBy($request->user())->performedOn($session)->withProperties(['records' => count($data['records'])])->log('attendance marked');

        return response()->json(['marked' => count($data['records'])]);
    }

    /**
     * Attendance totals: managers see every active student, a student sees their own.
     * Late counts as attended; excused sessions are left out of the percentage.
     */
    public function summary(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);
        $isManager = $request->user()->can('manage', $offering);
        $sessionIds = $offering->sessions()->pluck('id');
        // Managers get one page of students (`?page=`, `?per_page=`); only that page's records are read.
        $people = null;
        $meta = null;
        if ($isManager) {
            [$enrolments, $meta] = Paging::page($request, Paging::activeStudents($offering));
            $people = $enrolments->pluck('user')->filter()->values();
        }
        $records = AttendanceRecord::whereIn('class_session_id', $sessionIds)
            ->whereIn('user_id', $isManager ? $people->pluck('id') : [$request->user()->id])->get()->groupBy('user_id');

        $row = function (int $userId) use ($records) {
            $counts = collect(AttendanceRecord::STATUSES)->mapWithKeys(fn ($s) => [$s => ($records[$userId] ?? collect())->where('status', $s)->count()])->all();
            $counted = array_sum($counts) - $counts['excused'];

            return $counts + ['attended' => $counts['present'] + $counts['late'], 'percent' => $counted > 0 ? round(($counts['present'] + $counts['late']) / $counted * 100, 2) : null];
        };

        if (! $isManager) {
            return response()->json(['sessions_total' => $sessionIds->count()] + $row($request->user()->id));
        }
        $students = $people->map(fn ($u) => ['user' => $u->only(['id', 'name', 'email'])] + $row($u->id))->values();

        return response()->json(['sessions_total' => $sessionIds->count(), 'students' => $students, 'meta' => $meta]);
    }
}
