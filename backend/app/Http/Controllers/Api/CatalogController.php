<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    /** Terms, newest first. `?current=1` returns only the current term. */
    public function terms(Request $request): JsonResponse
    {
        $this->authorizeCatalog($request);
        $request->validate(['current' => ['sometimes', 'boolean']]);

        return response()->json(AcademicTerm::orderByDesc('starts_on')->when($request->boolean('current'), fn ($q) => $q->where('is_current', true))->paginate(50));
    }

    /** Courses by code. Filters: `q` (code or title), `department_id`, `level`, `archived` (exclude, include, only). */
    public function courses(Request $request): JsonResponse
    {
        $this->authorizeCatalog($request);
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'], 'department_id' => ['sometimes', 'integer'], 'level' => ['sometimes', Rule::in(Course::LEVELS)],
            'archived' => ['sometimes', Rule::in(['exclude', 'include', 'only'])],
        ]);
        $query = Course::with('department:id,code,name')->orderBy('code');
        match ($filters['archived'] ?? 'exclude') {
            'exclude' => $query->whereNull('archived_at'),
            'only' => $query->whereNotNull('archived_at'),
            default => null,
        };
        foreach (['department_id', 'level'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['q'])) {
            $like = '%'.strtolower(preg_replace('/[!%_]/', '!$0', $filters['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw("lower(code) like ? escape '!'", [$like])->orWhereRaw("lower(title) like ? escape '!'", [$like]));
        }

        return response()->json($query->paginate(50));
    }

    public function roster(Request $request, CourseOffering $offering): JsonResponse
    {
        abort_unless($request->user()->can('manage', $offering) || $request->user()->can('manage-enrolments'), 403);

        [$enrolments, $meta] = Paging::page($request, $offering->enrolments()->with('user:id,name,email')->orderBy('id'));

        return response()->json([
            'teachers' => $offering->teachers()->with('user:id,name,email')->get(),
            'enrolments' => $enrolments,
            'meta' => $meta,
        ]);
    }

    public function summary(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $enrolled = $offering->enrolments()->where('status', 'active')->count();
        $assignments = $offering->assignments()
            ->withCount([
                'submissions',
                'submissions as graded_count' => fn ($q) => $q->whereHas('gradeRecords', fn ($g) => $g->where('status', 'published')),
            ])
            ->orderBy('due_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'due_at' => $a->due_at,
                'published' => $a->published,
                'max_score' => $a->max_score,
                'submissions' => $a->submissions_count,
                'graded' => $a->graded_count,
                'awaiting_submission' => max(0, $enrolled - $a->submissions_count),
            ]);

        $quizzes = $offering->quizzes()->orderBy('due_at')->get()->map(fn ($q) => [
            'id' => $q->id,
            'title' => $q->title,
            'due_at' => $q->due_at,
            'published' => $q->published,
            'students_attempted' => $q->attempts()->whereNotNull('submitted_at')->distinct()->count('user_id'),
        ]);

        return response()->json(['enrolled' => $enrolled, 'assignments' => $assignments, 'quizzes' => $quizzes]);
    }

    private function authorizeCatalog(Request $request): void
    {
        abort_unless($request->user()->can('manage-courses') || $request->user()->can('manage-enrolments'), 403);
    }
}
