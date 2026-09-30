<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\TeachingAssignment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CourseCopyController extends Controller
{
    /**
     * Builds next term's offering from an existing one: modules, items (with their files), assignments with rubrics, and
     * quizzes with questions. The new offering is unpublished; assignments and quizzes come across unpublished with their
     * dates moved by the same distance as the two terms' start dates, so the teacher reviews them before opening them up.
     * Students, submissions, attempts, announcements, and discussions are never copied.
     */
    public function copy(Request $request, CourseOffering $offering): JsonResponse
    {
        abort_unless($request->user()->can('manage-courses'), 403);
        $data = $request->validate([
            'academic_term_id' => ['required', 'exists:academic_terms,id'],
            'section' => ['required', 'string', 'max:100'],
            'copy_teachers' => ['sometimes', 'boolean'],
        ]);
        if (CourseOffering::where('course_id', $offering->course_id)->where('academic_term_id', $data['academic_term_id'])->where('section', $data['section'])->exists()) {
            throw ValidationException::withMessages(['section' => 'That course already has this section in the chosen term.']);
        }

        $offering->load('term', 'modules.items', 'assignments.rubricCriteria.levels', 'quizzes.questions.options', 'teachers');
        $days = (int) round(Carbon::parse($offering->term->starts_on)->diffInDays(Carbon::parse(AcademicTerm::findOrFail($data['academic_term_id'])->starts_on), false));
        $copiedFiles = [];

        try {
            [$copy, $counts] = DB::transaction(function () use ($offering, $data, $days, $request, &$copiedFiles) {
                $copy = CourseOffering::create(['course_id' => $offering->course_id, 'academic_term_id' => $data['academic_term_id'], 'section' => $data['section'], 'published' => false, 'capacity' => $offering->capacity, 'self_enrolment' => $offering->self_enrolment]);
                $counts = ['modules' => 0, 'items' => 0, 'files' => 0, 'assignments' => 0, 'rubric_criteria' => 0, 'quizzes' => 0, 'questions' => 0];

                foreach ($offering->modules as $module) {
                    $newModule = $copy->modules()->create($module->only(['title', 'position', 'published']));
                    $counts['modules']++;
                    foreach ($module->items as $item) {
                        $attributes = $item->only(['title', 'type', 'body', 'position', 'published']);
                        if ($item->storage_path) {
                            $target = 'course-files/'.$copy->id.'/'.Str::random(40).(($ext = pathinfo($item->storage_path, PATHINFO_EXTENSION)) ? '.'.$ext : '');
                            try {
                                $copied = Storage::disk('s3')->copy($item->storage_path, $target);
                            } catch (Throwable) {
                                $copied = false; // depending on the disk's settings a failed copy returns false or throws
                            }
                            if (! $copied) {
                                throw ValidationException::withMessages(['offering' => "The file for \"{$item->title}\" is missing from storage, so the course cannot be copied."]);
                            }
                            $copiedFiles[] = $target;
                            $attributes['storage_path'] = $target;
                            $counts['files']++;
                        }
                        $newModule->items()->create($attributes);
                        $counts['items']++;
                    }
                }
                foreach ($offering->assignments as $assignment) {
                    $newAssignment = $copy->assignments()->create($assignment->only(['title', 'instructions', 'max_score', 'allow_late_submissions', 'allow_resubmission', 'is_group_assignment']) + ['due_at' => $assignment->due_at->addDays($days), 'published' => false]);
                    $counts['assignments']++;
                    foreach ($assignment->rubricCriteria as $criterion) {
                        $newCriterion = $newAssignment->rubricCriteria()->create($criterion->only(['title', 'description', 'max_points', 'position']));
                        $counts['rubric_criteria']++;
                        foreach ($criterion->levels as $level) {
                            $newCriterion->levels()->create($level->only(['title', 'description', 'points', 'position']));
                        }
                    }
                }
                foreach ($offering->quizzes as $quiz) {
                    $newQuiz = $copy->quizzes()->create($quiz->only(['title', 'instructions', 'time_limit_minutes', 'max_attempts', 'is_practice']) + [
                        'opens_at' => $quiz->opens_at?->addDays($days), 'due_at' => $quiz->due_at->addDays($days), 'published' => false,
                    ]);
                    $counts['quizzes']++;
                    foreach ($quiz->questions->sortBy(['position', 'id']) as $question) {
                        $newQuestion = $newQuiz->questions()->create($question->only(['type', 'prompt', 'points', 'position']));
                        $newQuestion->options()->createMany($question->options->map->only(['text', 'is_correct', 'position'])->all());
                        $counts['questions']++;
                    }
                }
                if ($request->boolean('copy_teachers')) {
                    foreach ($offering->teachers as $teacher) {
                        TeachingAssignment::create(['course_offering_id' => $copy->id, 'user_id' => $teacher->user_id]);
                    }
                }
                activity()->causedBy($request->user())->performedOn($copy)->withProperties(['copied_from' => $offering->id] + $counts)->log('offering copied');

                return [$copy, $counts];
            });
        } catch (Throwable $e) {
            // The database rolled back; remove the files already duplicated so nothing is left orphaned.
            if ($copiedFiles !== []) {
                Storage::disk('s3')->delete($copiedFiles);
            }
            throw $e;
        }

        return response()->json(['offering' => $copy->load('course', 'term'), 'copied' => $counts, 'date_shift_days' => $days], 201);
    }
}
