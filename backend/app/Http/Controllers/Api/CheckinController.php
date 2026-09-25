<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\Enrolment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Self check-in: the teacher opens a short window and reads a six-digit code out in class; students enter it to mark
 * themselves present (or late). Teachers can always override a record afterwards.
 */
class CheckinController extends Controller
{
    private const DEFAULT_MINUTES = 15;

    public function open(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('manage', $session->offering);
        $minutes = $request->validate(['minutes' => ['sometimes', 'integer', 'min:1', 'max:240']])['minutes'] ?? self::DEFAULT_MINUTES;
        $session->forceFill([
            'checkin_code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'checkin_opens_at' => now(),
            'checkin_closes_at' => now()->addMinutes($minutes),
        ])->save();
        activity()->causedBy($request->user())->performedOn($session)->withProperties(['minutes' => $minutes])->log('attendance check-in opened');

        return response()->json($this->status($session));
    }

    public function close(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('manage', $session->offering);
        $session->forceFill(['checkin_closes_at' => now()])->save();

        return response()->json($this->status($session));
    }

    /** For the teacher's screen: is it open, what is the code, and how many students have checked in so far. */
    public function show(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('manage', $session->offering);

        return response()->json($this->status($session));
    }

    public function checkin(Request $request, ClassSession $session): JsonResponse
    {
        $user = $request->user();
        abort_unless(Enrolment::where('course_offering_id', $session->course_offering_id)->where('user_id', $user->id)->where('status', 'active')->exists(), 403);
        abort_unless($session->offering->published && $session->offering->archived_at === null, 403);
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);

        if (! $session->checkinIsOpen()) {
            throw ValidationException::withMessages(['code' => 'Check-in is not open for this session.']);
        }
        if (! hash_equals((string) $session->checkin_code, trim($data['code']))) {
            throw ValidationException::withMessages(['code' => 'That code is not right.']);
        }

        $record = AttendanceRecord::where('class_session_id', $session->id)->where('user_id', $user->id)->first();
        if ($record && $record->source === 'staff') {
            throw ValidationException::withMessages(['code' => 'Your attendance for this session has already been recorded by your teacher.']);
        }
        if (! $record) {
            $late = now()->greaterThan($session->starts_at->addMinutes((int) config('lms.checkin.late_after_minutes')));
            try {
                $record = AttendanceRecord::create([
                    'class_session_id' => $session->id, 'user_id' => $user->id, 'status' => $late ? 'late' : 'present',
                    'marked_by' => $user->id, 'source' => 'self',
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two taps at once: the first one won, so report what it recorded.
                $record = AttendanceRecord::where('class_session_id', $session->id)->where('user_id', $user->id)->firstOrFail();
            }
        }

        return response()->json(['status' => $record->status, 'session_id' => $session->id]);
    }

    /** @return array<string, mixed> */
    private function status(ClassSession $session): array
    {
        $open = $session->checkinIsOpen();

        return [
            'open' => $open,
            'code' => $open ? $session->checkin_code : null,
            'opens_at' => $session->checkin_opens_at,
            'closes_at' => $session->checkin_closes_at,
            'self_checked_in' => $session->attendance()->where('source', 'self')->count(),
        ];
    }
}
