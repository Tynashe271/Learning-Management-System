<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\CourseOffering;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Extended-time assessment accommodations (roadmap item 17): a documented percentage added to every timed quiz's time limit for one student in one course, set once rather than quiz by quiz. */
class AccommodationController extends Controller
{
    public function index(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);

        return response()->json($offering->accommodations()->with('student:id,name,email', 'author:id,name')->orderBy('id')->get());
    }

    public function store(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('enrolments', 'user_id')->where('course_offering_id', $offering->id)->where('status', 'active')],
            'extra_time_percent' => ['required', 'integer', 'min:1', 'max:300'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $accommodation = $offering->accommodations()->updateOrCreate(['user_id' => $data['user_id']], $data + ['created_by' => $request->user()->id]);

        return response()->json($accommodation->load('student:id,name,email', 'author:id,name'), 201);
    }

    public function destroy(Request $request, Accommodation $accommodation): JsonResponse
    {
        $this->authorize('manage', $accommodation->offering);
        $accommodation->delete();

        return response()->json(['message' => 'Accommodation removed.']);
    }
}
