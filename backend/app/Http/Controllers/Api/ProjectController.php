<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Project;
use App\Models\ProjectMeeting;
use App\Models\ProjectMilestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The research and project workspace: a student's topic, its supervisor, milestones and meeting records. Draft chapters, similarity screening and rubric-based marking reuse the existing assignment, similarity and rubric features rather than duplicating them - create an assignment scoped to the project for those. */
class ProjectController extends Controller
{
    public function index(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);

        return response()->json($offering->projects()->with('student:id,name,email', 'supervisor:id,name')->orderBy('id')->get());
    }

    public function store(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);
        abort_unless($offering->enrolments()->where('user_id', $request->user()->id)->where('status', 'active')->exists(), 403);
        if ($offering->projects()->where('user_id', $request->user()->id)->exists()) {
            throw ValidationException::withMessages(['title' => 'You already have a project topic for this course.']);
        }
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:4000']]);

        return response()->json($offering->projects()->create($data + ['user_id' => $request->user()->id, 'status' => 'proposed']), 201);
    }

    public function mine(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);

        return response()->json($offering->projects()->with('supervisor:id,name')->where('user_id', $request->user()->id)->first());
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $manages = $request->user()->can('manage', $project->offering);
        abort_unless($manages || $project->user_id === $request->user()->id, 403);

        if ($manages) {
            $data = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
                'status' => ['sometimes', Rule::in(Project::STATUSES)],
                'supervisor_id' => ['sometimes', 'nullable', 'integer', Rule::exists('teaching_assignments', 'user_id')->where('course_offering_id', $project->course_offering_id)],
            ]);
        } else {
            abort_unless($project->status === 'proposed', 422, 'This topic has already been reviewed and can no longer be edited.');
            $data = $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
                'status' => ['prohibited'],
                'supervisor_id' => ['prohibited'],
            ]);
        }
        $project->update($data);

        return response()->json($project->load('supervisor:id,name'));
    }

    public function storeMilestone(Request $request, Project $project): JsonResponse
    {
        $this->assertSupervises($request, $project);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'due_on' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);

        return response()->json($project->milestones()->create($data), 201);
    }

    public function updateMilestone(Request $request, ProjectMilestone $milestone): JsonResponse
    {
        $this->assertSupervises($request, $milestone->project);
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:255'], 'due_on' => ['sometimes', 'date'], 'notes' => ['sometimes', 'nullable', 'string', 'max:2000'], 'completed' => ['sometimes', 'boolean']]);
        if (array_key_exists('completed', $data)) {
            $data['completed_at'] = $data['completed'] ? now() : null;
            unset($data['completed']);
        }
        $milestone->update($data);

        return response()->json($milestone);
    }

    public function indexMilestones(Request $request, Project $project): JsonResponse
    {
        $this->assertProjectAccess($request, $project);

        return response()->json($project->milestones()->orderBy('due_on')->get());
    }

    public function storeMeeting(Request $request, Project $project): JsonResponse
    {
        $this->assertProjectAccess($request, $project);
        $data = $request->validate(['occurred_on' => ['required', 'date', 'before_or_equal:today'], 'notes' => ['required', 'string', 'max:4000']]);

        return response()->json($project->meetings()->create($data + ['logged_by' => $request->user()->id])->load('user:id,name'), 201);
    }

    public function indexMeetings(Request $request, Project $project): JsonResponse
    {
        $this->assertProjectAccess($request, $project);

        return response()->json($project->meetings()->with('user:id,name')->orderBy('occurred_on')->get());
    }

    /** The student, their supervisor, or any manager of the offering. */
    private function assertProjectAccess(Request $request, Project $project): void
    {
        $user = $request->user();
        abort_unless($user->id === $project->user_id || $user->id === $project->supervisor_id || $user->can('manage', $project->offering), 403);
    }

    /** The project's assigned supervisor, or any manager of the offering. */
    private function assertSupervises(Request $request, Project $project): void
    {
        $user = $request->user();
        abort_unless($user->id === $project->supervisor_id || $user->can('manage', $project->offering), 403);
    }
}
