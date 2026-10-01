<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\IntegrityCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The lecturer's investigation workspace for academic-integrity concerns, separate from a grade appeal. Plagiarism/similarity screening and submission version history live elsewhere already. */
class IntegrityController extends Controller
{
    public function index(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);

        return response()->json($offering->integrityCases()->with('student:id,name,email', 'reporter:id,name')->orderByDesc('id')->get());
    }

    public function store(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('enrolments', 'user_id')->where('course_offering_id', $offering->id)->where('status', 'active')],
            'submission_id' => ['nullable', 'integer', Rule::exists('submissions', 'id')->where('user_id', $request->input('user_id'))],
            'description' => ['required', 'string', 'max:4000'],
        ]);
        $case = $offering->integrityCases()->create($data + ['reported_by' => $request->user()->id, 'status' => 'open']);
        activity()->causedBy($request->user())->performedOn($case)->log('academic integrity case opened');

        return response()->json($case->load('student:id,name,email', 'reporter:id,name'), 201);
    }

    public function update(Request $request, IntegrityCase $case): JsonResponse
    {
        $this->authorize('manage', $case->offering);
        $data = $request->validate([
            'description' => ['sometimes', 'string', 'max:4000'],
            'status' => ['sometimes', Rule::in(IntegrityCase::STATUSES)],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);
        if (isset($data['status']) && $data['status'] !== 'open') {
            $data['resolved_at'] = now();
        } elseif (($data['status'] ?? null) === 'open') {
            $data['resolved_at'] = null;
        }
        $case->update($data);
        activity()->causedBy($request->user())->performedOn($case)->withProperties(['status' => $case->status])->log('academic integrity case updated');

        return response()->json($case->load('student:id,name,email', 'reporter:id,name'));
    }
}
