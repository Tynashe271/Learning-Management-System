<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The academic structure: terms (with academic year, registration period, add/drop deadline), courses (with department, credits
 * and level) and offerings (with capacity, self-enrolment and archiving). Everything here needs the catalogue permission.
 */
class AcademicStructureController extends Controller
{
    // ---- terms --------------------------------------------------------------------------------------------------------

    public function storeTerm(Request $request): JsonResponse
    {
        $this->authorizeCatalog($request);
        $data = $request->validate($this->termRules(true));
        $this->assertTermDates($data);

        $term = DB::transaction(function () use ($data) {
            if (! empty($data['is_current'])) {
                AcademicTerm::where('is_current', true)->update(['is_current' => false]);
            }

            return AcademicTerm::create($data);
        });
        activity()->causedBy($request->user())->performedOn($term)->withProperties($data)->log('term created');

        return response()->json($term, 201);
    }

    public function updateTerm(Request $request, AcademicTerm $term): JsonResponse
    {
        $this->authorizeCatalog($request);
        $data = $request->validate($this->termRules(false));
        $this->assertTermDates($data + $term->only(['starts_on', 'ends_on', 'registration_opens_on', 'registration_closes_on', 'add_drop_deadline']));

        DB::transaction(function () use ($term, $data) {
            if (! empty($data['is_current'])) {
                AcademicTerm::where('is_current', true)->where('id', '!=', $term->id)->update(['is_current' => false]);
            }
            $term->update($data);
        });
        activity()->causedBy($request->user())->performedOn($term)->withProperties($data)->log('term changed');

        return response()->json($term->fresh());
    }

    /** Closes (or reopens) every offering of a term in one step, for the end of a semester. Nothing is deleted. */
    public function archiveTerm(Request $request, AcademicTerm $term): JsonResponse
    {
        $this->authorizeCatalog($request);
        $archive = $request->validate(['archived' => ['required', 'boolean']])['archived'];
        $count = CourseOffering::where('academic_term_id', $term->id)->when($archive, fn ($q) => $q->whereNull('archived_at'), fn ($q) => $q->whereNotNull('archived_at'))
            ->update(['archived_at' => $archive ? now() : null]);
        if ($archive) {
            $term->update(['is_current' => false]);
        }
        activity()->causedBy($request->user())->performedOn($term)->withProperties(['archived' => $archive, 'offerings' => $count])->log($archive ? 'term archived' : 'term reopened');

        return response()->json(['archived' => $archive, 'offerings' => $count]);
    }

    // ---- courses ------------------------------------------------------------------------------------------------------

    public function storeCourse(Request $request): JsonResponse
    {
        $this->authorizeCatalog($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:courses,code'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ] + $this->courseDetailRules());
        $course = Course::create($data);
        activity()->causedBy($request->user())->performedOn($course)->log('course created');

        return response()->json($course->load('department:id,code,name'), 201);
    }

    public function updateCourse(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCatalog($request);
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('courses', 'code')->ignore($course->id)],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'archived' => ['sometimes', 'boolean'],
        ] + $this->courseDetailRules());
        if (array_key_exists('archived', $data)) {
            $data['archived_at'] = $data['archived'] ? ($course->archived_at ?? now()) : null;
            unset($data['archived']);
        }
        $course->update($data);
        activity()->causedBy($request->user())->performedOn($course)->withProperties($data)->log('course changed');

        return response()->json($course->fresh()->load('department:id,code,name'));
    }

    // ---- offerings ----------------------------------------------------------------------------------------------------

    public function storeOffering(Request $request): JsonResponse
    {
        $this->authorizeCatalog($request);
        $data = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'academic_term_id' => ['required', 'exists:academic_terms,id'],
            'section' => ['required', 'string', 'max:100'],
            'published' => ['sometimes', 'boolean'],
        ] + $this->offeringDetailRules());
        if (Course::whereKey($data['course_id'])->whereNotNull('archived_at')->exists()) {
            throw ValidationException::withMessages(['course_id' => 'This course is archived, so it cannot be offered. Restore it first.']);
        }
        if (CourseOffering::where('course_id', $data['course_id'])->where('academic_term_id', $data['academic_term_id'])->where('section', $data['section'])->exists()) {
            throw ValidationException::withMessages(['section' => 'That course already has this section in the chosen term.']);
        }
        $offering = CourseOffering::create($data);
        activity()->causedBy($request->user())->performedOn($offering)->log('offering created');

        return response()->json($offering, 201);
    }

    public function updateOffering(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorizeCatalog($request);
        $data = $request->validate([
            'published' => ['sometimes', 'boolean'],
            'section' => ['sometimes', 'string', 'max:100', Rule::unique('course_offerings', 'section')
                ->where('course_id', $offering->course_id)->where('academic_term_id', $offering->academic_term_id)->ignore($offering->id)],
            'archived' => ['sometimes', 'boolean'],
        ] + $this->offeringDetailRules());
        if ($data === []) {
            throw ValidationException::withMessages(['offering' => 'Nothing to change.']);
        }
        if (isset($data['capacity']) && $data['capacity'] < $offering->activeEnrolmentCount()) {
            throw ValidationException::withMessages(['capacity' => "There are already {$offering->activeEnrolmentCount()} students enrolled, so the capacity cannot be lower than that."]);
        }
        if (array_key_exists('archived', $data)) {
            $data['archived_at'] = $data['archived'] ? ($offering->archived_at ?? now()) : null;
            unset($data['archived']);
        }
        $offering->update($data);
        activity()->causedBy($request->user())->performedOn($offering)->withProperties($data)->log('offering changed');

        return response()->json($offering->fresh());
    }

    // ---- shared -------------------------------------------------------------------------------------------------------

    private function authorizeCatalog(Request $request): void
    {
        abort_unless($request->user()->can('manage-courses'), 403);
    }

    /** @return array<string, array<int, mixed>> */
    private function termRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'starts_on' => [$required, 'date'],
            'ends_on' => [$required, 'date'],
            'academic_year' => ['sometimes', 'nullable', 'string', 'max:20'],
            'registration_opens_on' => ['sometimes', 'nullable', 'date'],
            'registration_closes_on' => ['sometimes', 'nullable', 'date'],
            'add_drop_deadline' => ['sometimes', 'nullable', 'date'],
            'is_current' => ['sometimes', 'boolean'],
        ];
    }

    /** @param  array<string, mixed>  $d */
    private function assertTermDates(array $d): void
    {
        $date = fn (string $key) => isset($d[$key]) ? Carbon::parse($d[$key])->startOfDay() : null;
        [$starts, $ends, $opens, $closes, $drop] = array_map($date, ['starts_on', 'ends_on', 'registration_opens_on', 'registration_closes_on', 'add_drop_deadline']);
        if ($starts && $ends && $ends->lessThanOrEqualTo($starts)) {
            throw ValidationException::withMessages(['ends_on' => 'The term must end after it starts.']);
        }
        if ($opens && $closes && $closes->lessThan($opens)) {
            throw ValidationException::withMessages(['registration_closes_on' => 'Registration must close on or after the day it opens.']);
        }
        if (($opens xor $closes) === true) {
            throw ValidationException::withMessages(['registration_closes_on' => 'Give both the day registration opens and the day it closes, or neither.']);
        }
        if ($closes && $ends && $closes->greaterThan($ends)) {
            throw ValidationException::withMessages(['registration_closes_on' => 'Registration cannot close after the term ends.']);
        }
        if ($drop && $starts && $ends && ($drop->lessThan($starts->copy()->subYear()) || $drop->greaterThan($ends))) {
            throw ValidationException::withMessages(['add_drop_deadline' => 'The add/drop deadline must fall within the term (or before it starts).']);
        }
    }

    /** @return array<string, array<int, mixed>> */
    private function courseDetailRules(): array
    {
        return [
            'department_id' => ['sometimes', 'nullable', Rule::exists('departments', 'id')->whereNull('archived_at')],
            'credits' => ['sometimes', 'nullable', 'integer', 'between:1,60'],
            'level' => ['sometimes', 'nullable', Rule::in(Course::LEVELS)],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function offeringDetailRules(): array
    {
        return [
            'capacity' => ['sometimes', 'nullable', 'integer', 'between:1,10000'],
            'self_enrolment' => ['sometimes', 'boolean'],
        ];
    }
}
