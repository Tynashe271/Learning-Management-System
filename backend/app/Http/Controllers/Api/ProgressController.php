<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\ItemCompletion;
use App\Models\LearningItem;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    public function complete(Request $request, LearningItem $item): JsonResponse
    {
        $this->authorizeStudent($request, $item);
        ItemCompletion::firstOrCreate(['learning_item_id' => $item->id, 'user_id' => $request->user()->id]);

        return response()->json(['completed' => true]);
    }

    public function uncomplete(Request $request, LearningItem $item): JsonResponse
    {
        $this->authorizeStudent($request, $item);
        ItemCompletion::where('learning_item_id', $item->id)->where('user_id', $request->user()->id)->delete();

        return response()->json(['completed' => false]);
    }

    /** Managers see every enrolled student's completion; a student sees their own. */
    public function progress(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);
        $itemIds = LearningItem::where('published', true)
            ->whereHas('module', fn ($q) => $q->where('course_offering_id', $offering->id)->where('published', true))
            ->pluck('id');
        $total = $itemIds->count();
        $percent = fn (int $done) => $total > 0 ? round($done / $total * 100, 2) : null;

        if ($request->user()->can('manage', $offering)) {
            [$enrolments, $meta] = Paging::page($request, Paging::activeStudents($offering));
            $people = $enrolments->pluck('user')->filter()->values();
            $counts = ItemCompletion::whereIn('learning_item_id', $itemIds)->whereIn('user_id', $people->pluck('id'))->selectRaw('user_id, count(*) as completed')->groupBy('user_id')->pluck('completed', 'user_id');
            $students = $people->map(fn ($u) => ['user' => $u->only(['id', 'name', 'email']), 'completed' => (int) ($counts[$u->id] ?? 0), 'percent' => $percent((int) ($counts[$u->id] ?? 0))])->values();

            return response()->json(['items_total' => $total, 'students' => $students, 'meta' => $meta]);
        }

        $done = ItemCompletion::where('user_id', $request->user()->id)->whereIn('learning_item_id', $itemIds)->pluck('learning_item_id');

        return response()->json(['items_total' => $total, 'completed' => $done->count(), 'percent' => $percent($done->count()), 'completed_item_ids' => $done->values()]);
    }

    /** Only actively enrolled students can tick off published material in a published offering. */
    private function authorizeStudent(Request $request, LearningItem $item): void
    {
        $module = $item->module;
        $offering = $module->offering;
        $this->authorize('view', $offering);
        abort_unless($item->published && $module->published, 404);
        abort_unless(Enrolment::where('course_offering_id', $offering->id)->where('user_id', $request->user()->id)->where('status', 'active')->exists(), 403);
    }
}
