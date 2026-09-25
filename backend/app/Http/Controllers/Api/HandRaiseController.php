<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\Enrolment;
use App\Models\HandRaise;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A student raising a hand during a live class, and the teacher's live view of who currently has one up. */
class HandRaiseController extends Controller
{
    public function raise(Request $request, ClassSession $session): JsonResponse
    {
        $this->assertCanParticipate($request, $session);
        HandRaise::updateOrCreate(
            ['class_session_id' => $session->id, 'user_id' => $request->user()->id],
            ['raised_at' => now()],
        );

        return response()->json(['message' => 'Hand raised.']);
    }

    public function lower(Request $request, ClassSession $session): JsonResponse
    {
        $this->assertCanParticipate($request, $session);
        HandRaise::where('class_session_id', $session->id)->where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Hand lowered.']);
    }

    /** The teacher's live list: who currently has a hand up, oldest first. */
    public function index(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('manage', $session->offering);
        $hands = $session->handRaises()->with('user:id,name')->orderBy('raised_at')->get()
            ->map(fn (HandRaise $h) => ['user' => $h->user->only(['id', 'name']), 'raised_at' => $h->raised_at]);

        return response()->json($hands->values());
    }

    /** The teacher clears one student's hand, e.g. after calling on them. */
    public function clear(Request $request, ClassSession $session, User $user): JsonResponse
    {
        $this->authorize('manage', $session->offering);
        HandRaise::where('class_session_id', $session->id)->where('user_id', $user->id)->delete();

        return response()->json(['message' => 'Hand cleared.']);
    }

    private function assertCanParticipate(Request $request, ClassSession $session): void
    {
        $user = $request->user();
        abort_unless(Enrolment::where('course_offering_id', $session->course_offering_id)->where('user_id', $user->id)->where('status', 'active')->exists(), 403);
        abort_unless($session->offering->published && $session->offering->archived_at === null, 403);
    }
}
