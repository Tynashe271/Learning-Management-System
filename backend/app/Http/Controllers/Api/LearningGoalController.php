<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningGoal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A student's own personal learning goals (roadmap item 18) - always self-managed, never visible to or set by anyone else. */
class LearningGoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(LearningGoal::where('user_id', $request->user()->id)->orderBy('completed_at')->orderBy('target_date')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'target_date' => ['nullable', 'date'],
        ]);
        $goal = LearningGoal::create($data + ['user_id' => $request->user()->id]);

        return response()->json($goal, 201);
    }

    public function update(Request $request, LearningGoal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'target_date' => ['sometimes', 'nullable', 'date'],
            'completed' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('completed', $data)) {
            $data['completed_at'] = $data['completed'] ? now() : null;
            unset($data['completed']);
        }
        $goal->update($data);

        return response()->json($goal);
    }

    public function destroy(Request $request, LearningGoal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
        $goal->delete();

        return response()->json(['message' => 'Goal removed.']);
    }
}
