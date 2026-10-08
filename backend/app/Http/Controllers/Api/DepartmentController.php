<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Faculties and departments: the categories courses belong to, so the catalogue and reports can be grouped. */
class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCatalog($request, false);
        $data = $request->validate(['archived' => ['sometimes', Rule::in(['exclude', 'include', 'only'])]]);
        $query = Department::withCount('courses')->orderBy('name');
        match ($data['archived'] ?? 'exclude') {
            'exclude' => $query->whereNull('archived_at'),
            'only' => $query->whereNotNull('archived_at'),
            default => null,
        };

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCatalog($request);
        $this->normaliseCode($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:departments,code'], 'name' => ['required', 'string', 'max:255']]);
        $department = Department::create(['code' => strtoupper($data['code']), 'name' => $data['name']]);
        activity()->causedBy($request->user())->performedOn($department)->log('department created');

        return response()->json($department, 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $this->authorizeCatalog($request);
        $this->normaliseCode($request);
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('departments', 'code')->ignore($department->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'archived' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }
        if (array_key_exists('archived', $data)) {
            $data['archived_at'] = $data['archived'] ? ($department->archived_at ?? now()) : null;
            unset($data['archived']);
        }
        $department->update($data);
        activity()->causedBy($request->user())->performedOn($department)->withProperties($data)->log('department changed');

        return response()->json($department);
    }

    /** Only a department that has never had a course can be removed; otherwise archive it. */
    public function destroy(Request $request, Department $department): JsonResponse
    {
        $this->authorizeCatalog($request);
        if ($department->courses()->exists()) {
            throw ValidationException::withMessages(['department' => 'This department has courses, so it cannot be deleted. Archive it instead.']);
        }
        $department->delete();
        activity()->causedBy($request->user())->withProperties(['code' => $department->code])->log('department deleted');

        return response()->json(['message' => 'Department deleted.']);
    }

    /** Codes are stored in capitals, so "sci" and "SCI" must be caught as the same code before the uniqueness check. */
    private function normaliseCode(Request $request): void
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }
    }

    private function authorizeCatalog(Request $request, bool $write = true): void
    {
        $user = $request->user();
        abort_unless($user->can('manage-courses') || (! $write && ($user->can('manage-enrolments') || $user->can('submit-assignments'))), 403);
    }
}
