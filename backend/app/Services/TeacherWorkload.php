<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\GradeRecord;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Models\TeachingAssignment;
use App\Models\User;

/**
 * What each lecturer or teaching assistant is carrying right now: how many offerings, how much of their class's
 * work is still waiting on them, and how long students are waiting for feedback. The institution-wide counterpart
 * to a course's own response-time figure in CourseAnalytics, rolled up per teacher instead of per course.
 */
class TeacherWorkload
{
    public function report(): array
    {
        $teachers = User::role(['lecturer', 'teaching-assistant'])->orderBy('name')->get(['id', 'name', 'email']);

        $offeringRows = TeachingAssignment::query()
            ->join('course_offerings', 'course_offerings.id', '=', 'teaching_assignments.course_offering_id')
            ->join('courses', 'courses.id', '=', 'course_offerings.course_id')
            ->join('academic_terms', 'academic_terms.id', '=', 'course_offerings.academic_term_id')
            ->get([
                'teaching_assignments.user_id', 'course_offerings.id as offering_id', 'courses.code', 'courses.title',
                'course_offerings.section', 'academic_terms.name as term',
            ])
            ->groupBy('user_id');

        return $teachers->map(function ($teacher) use ($offeringRows) {
            $rows = $offeringRows->get($teacher->id, collect());
            $offeringIds = $rows->pluck('offering_id');

            [$unmarkedSubmissions, $avgHours, $gradedCount] = $this->submissionStats($offeringIds);

            return [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'email' => $teacher->email,
                'offerings' => $rows->map(fn ($r) => [
                    'id' => $r->offering_id, 'code' => $r->code, 'title' => $r->title, 'term' => $r->term, 'section' => $r->section,
                ])->values()->all(),
                'offerings_count' => $rows->count(),
                'unmarked_submissions' => $unmarkedSubmissions,
                'unmarked_essay_answers' => $this->unmarkedEssayAnswers($offeringIds),
                'avg_feedback_hours' => $avgHours,
                'feedback_count' => $gradedCount,
            ];
        })->values()->all();
    }

    /** @return array{0: int, 1: ?float, 2: int} unmarked submissions, average hours to a first published grade, how many were graded */
    private function submissionStats($offeringIds): array
    {
        $assignmentIds = Assignment::whereIn('course_offering_id', $offeringIds)->pluck('id');
        $submissions = Submission::whereIn('assignment_id', $assignmentIds)->whereNotNull('submitted_at')->get(['id', 'submitted_at']);
        if ($submissions->isEmpty()) {
            return [0, null, 0];
        }

        $firstPublished = GradeRecord::whereIn('submission_id', $submissions->pluck('id'))->where('status', 'published')
            ->orderBy('id')->get(['id', 'submission_id', 'created_at'])->groupBy('submission_id')->map(fn ($g) => $g->first());

        $hours = $submissions->map(function ($s) use ($firstPublished) {
            $grade = $firstPublished->get($s->id);

            return $grade ? $s->submitted_at->diffInMinutes($grade->created_at) / 60 : null;
        })->filter(fn ($h) => $h !== null);

        $unmarked = $submissions->reject(fn ($s) => $firstPublished->has($s->id))->count();

        return [$unmarked, $hours->isEmpty() ? null : round($hours->avg(), 1), $hours->count()];
    }

    private function unmarkedEssayAnswers($offeringIds): int
    {
        $quizIds = Quiz::whereIn('course_offering_id', $offeringIds)->pluck('id');
        $attemptIds = QuizAttempt::whereIn('quiz_id', $quizIds)->pluck('id');

        return QuizAnswer::whereIn('quiz_attempt_id', $attemptIds)->where('needs_manual_grading', true)->count();
    }
}
