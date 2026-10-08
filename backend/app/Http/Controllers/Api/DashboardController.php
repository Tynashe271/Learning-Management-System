<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgendaBuilder;
use App\Services\StudentInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The data behind the personalised dashboard: a merged schedule and grade-derived insights. */
class DashboardController extends Controller
{
    public function agenda(Request $request, AgendaBuilder $agenda): JsonResponse
    {
        $days = (int) $request->query('days', 14);
        $days = max(1, min($days, 90));

        return response()->json($agenda->build($request->user(), $days));
    }

    public function insights(Request $request, AgendaBuilder $agenda, StudentInsights $insights): JsonResponse
    {
        abort_unless($request->user()->can('submit-assignments'), 403);

        return response()->json([
            'missing' => $agenda->missing($request->user()),
            'recent_feedback' => $insights->recentFeedback($request->user()),
            'weak_topics' => $insights->weakTopics($request->user()),
        ]);
    }

}
