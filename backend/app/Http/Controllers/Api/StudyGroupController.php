<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\StudyGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Student-organised study groups: any actively enrolled student can start one or join one, unlike a lecturer's assignment groups (item 6c). */
class StudyGroupController extends Controller
{
    public function index(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);
        $groups = $offering->studyGroups()->with('members:id,name', 'creator:id,name')->orderBy('id')->get();

        return response()->json($groups->map(fn (StudyGroup $g) => [
            'id' => $g->id,
            'name' => $g->name,
            'description' => $g->description,
            'max_members' => $g->max_members,
            'creator' => $g->creator->only(['id', 'name']),
            'members' => $g->members->map->only(['id', 'name'])->values(),
            'my_member' => $g->members->contains('id', $request->user()->id),
        ])->values());
    }

    public function store(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->assertEnrolled($request, $offering);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'max_members' => ['nullable', 'integer', 'min:2', 'max:50'],
        ]);
        $group = $offering->studyGroups()->create($data + ['created_by' => $request->user()->id]);
        $group->members()->attach($request->user()->id);

        return response()->json($group->load('members:id,name', 'creator:id,name'), 201);
    }

    public function destroy(Request $request, StudyGroup $group): JsonResponse
    {
        abort_unless($group->created_by === $request->user()->id || $request->user()->can('manage', $group->offering), 403);
        $group->delete();

        return response()->json(['message' => 'Study group deleted.']);
    }

    public function join(Request $request, StudyGroup $group): JsonResponse
    {
        $this->assertEnrolled($request, $group->offering);
        if ($group->members()->where('users.id', $request->user()->id)->exists()) {
            return response()->json($group->load('members:id,name'));
        }
        if ($group->max_members && $group->members()->count() >= $group->max_members) {
            throw ValidationException::withMessages(['group' => 'This study group is full.']);
        }
        $group->members()->attach($request->user()->id);

        return response()->json($group->load('members:id,name'));
    }

    public function leave(Request $request, StudyGroup $group): JsonResponse
    {
        $group->members()->detach($request->user()->id);

        return response()->json(['message' => 'Left the study group.']);
    }

    private function assertEnrolled(Request $request, CourseOffering $offering): void
    {
        abort_unless(Enrolment::where('course_offering_id', $offering->id)->where('user_id', $request->user()->id)->where('status', 'active')->exists(), 403);
    }
}
