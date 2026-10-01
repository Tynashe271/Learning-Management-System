<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Services\CourseAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Roadmap item 20: class-level learning analytics for one course, for that course's own managers only. */
class CourseAnalyticsController extends Controller
{
    public function show(Request $request, CourseOffering $offering, CourseAnalytics $analytics): JsonResponse
    {
        $this->authorize('manage', $offering);

        return response()->json($analytics->report($offering));
    }
}
