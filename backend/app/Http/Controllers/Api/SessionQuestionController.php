<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\Enrolment;
use App\Models\SessionQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A live question queue for a scheduled class: students ask, everyone in the session sees the queue, the teacher marks a question answered. */
class SessionQuestionController extends Controller
{
    public function store(Request $request, ClassSession $session): JsonResponse
    {
        $this->assertCanParticipate($request, $session);
        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);
        $question = $session->questions()->create($data + ['user_id' => $request->user()->id]);

        return response()->json($this->present($question->load('user:id,name')), 201);
    }

    /** Visible to the teacher and to any actively-enrolled student of the offering — a live, shared queue. */
    public function index(Request $request, ClassSession $session): JsonResponse
    {
        $user = $request->user();
        $manages = $user->can('manage', $session->offering);
        if (! $manages) {
            $this->assertCanParticipate($request, $session);
        }
        $questions = $session->questions()->with('user:id,name')->orderBy('id')->get();

        return response()->json($questions->map(fn (SessionQuestion $q) => $this->present($q))->values());
    }

    /** Toggles answered so a teacher can undo a mistaken tap. */
    public function markAnswered(Request $request, SessionQuestion $question): JsonResponse
    {
        $this->authorize('manage', $question->session->offering);
        $question->update(['answered_at' => $question->answered_at ? null : now()]);

        return response()->json($this->present($question->load('user:id,name')));
    }

    public function destroy(Request $request, SessionQuestion $question): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->id === $question->user_id || $user->can('manage', $question->session->offering), 403);
        $question->delete();

        return response()->json(['message' => 'Question deleted.']);
    }

    /** @return array<string, mixed> */
    private function present(SessionQuestion $question): array
    {
        return ['id' => $question->id, 'body' => $question->body, 'answered_at' => $question->answered_at, 'user' => $question->user->only(['id', 'name'])];
    }

    private function assertCanParticipate(Request $request, ClassSession $session): void
    {
        $user = $request->user();
        abort_unless(Enrolment::where('course_offering_id', $session->course_offering_id)->where('user_id', $user->id)->where('status', 'active')->exists(), 403);
        abort_unless($session->offering->published && $session->offering->archived_at === null, 403);
    }
}
