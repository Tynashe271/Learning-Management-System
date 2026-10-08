<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Services\Engagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Roadmap item 18: engagement and motivation, built from real data rather than a separate tracked game layer. */
class EngagementController extends Controller
{
    public function offering(Request $request, CourseOffering $offering, Engagement $engagement): JsonResponse
    {
        $this->authorize('manage', $offering);

        return response()->json(['students' => $engagement->offeringParticipation($offering)]);
    }
}
