<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\GradeRecord;
use App\Models\ItemCompletion;
use App\Models\LearningItem;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;

/**
 * Class-level learning analytics for a course's own managers (roadmap item 20). This is the course-level
 * counterpart to StudentInsights (which is one student's own view): completion, which topics the class as a
 * whole finds hardest, how each assessment performed, how quickly submissions get a published grade, and where
 * the class stands on its practical competencies. At-risk students (item 12), institution-wide usage and
 * department/enrolment reports already exist elsewhere - see AdminReportController and InterventionController.
 */
class CourseAnalytics
{
    public function report(CourseOffering $offering): array
    {
        $enrolledIds = Enrolment::where('course_offering_id', $offering->id)->where('status', 'active')->pluck('user_id');
        $assessments = $this->assessments($offering, $enrolledIds->count());

        return [
            'completion' => $this->completion($offering, $enrolledIds),
            'assessments' => $assessments,
            'weak_topics' => $this->topicAverages($assessments),
            'response_time' => $this->responseTime($offering),
            'competencies' => $this->competencies($offering),
        ];
    }

    /** The class's average share of published, non-hidden content each actively enrolled student has completed. */
    private function completion(CourseOffering $offering, $enrolledIds): array
    {
        if ($enrolledIds->isEmpty()) {
            return ['average_percent' => null, 'students' => 0];
        }
        $itemIds = LearningItem::where('published', true)
            ->whereHas('module', fn ($q) => $q->where('course_offering_id', $offering->id)->where('published', true))
            ->pluck('id');
        if ($itemIds->isEmpty()) {
            return ['average_percent' => null, 'students' => $enrolledIds->count()];
        }
        $counts = ItemCompletion::whereIn('learning_item_id', $itemIds)->whereIn('user_id', $enrolledIds)
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        $percents = $enrolledIds->map(fn ($id) => (int) ($counts[$id] ?? 0) / $itemIds->count() * 100);

        return ['average_percent' => round($percents->avg(), 1), 'students' => $enrolledIds->count()];
    }

    /** Average score, submission timing, and which topic (module) each assessment belongs to - assessment-performance trends and submission patterns together. */
    private function assessments(CourseOffering $offering, int $enrolled): array
    {
        $rows = [];

        $assignments = Assignment::where('course_offering_id', $offering->id)->where('published', true)->get();
        foreach ($assignments as $a) {
            $submissions = Submission::where('assignment_id', $a->id)->whereNotNull('submitted_at')->get(['id', 'late']);
            $published = GradeRecord::whereIn('submission_id', $submissions->pluck('id'))->where('status', 'published')->orderBy('id')->get()->groupBy('submission_id')->map(fn ($g) => $g->last());
            $percents = $published->map(fn ($g) => (float) $a->max_score > 0 ? (float) $g->score / (float) $a->max_score * 100 : null)->filter(fn ($p) => $p !== null);
            $rows[] = [
                'type' => 'assignment', 'id' => $a->id, 'title' => $a->title, 'module_id' => $a->course_module_id, 'due_at' => $a->due_at?->toIso8601String(),
                'average_percent' => $percents->isEmpty() ? null : round($percents->avg(), 1),
                'enrolled' => $enrolled, 'submitted' => $submissions->count(), 'on_time' => $submissions->where('late', false)->count(),
                'late' => $submissions->where('late', true)->count(), 'missing' => max(0, $enrolled - $submissions->count()),
            ];
        }

        $quizzes = Quiz::where('course_offering_id', $offering->id)->where('published', true)->where('is_practice', false)->withSum('questions as total_points', 'points')->get();
        foreach ($quizzes as $q) {
            $best = QuizAttempt::where('quiz_id', $q->id)->whereNotNull('submitted_at')->selectRaw('user_id, max(score) as best_score')->groupBy('user_id')->get();
            $percents = $best->map(fn ($b) => (float) $q->total_points > 0 ? (float) $b->best_score / (float) $q->total_points * 100 : null)->filter(fn ($p) => $p !== null);
            $rows[] = [
                'type' => 'quiz', 'id' => $q->id, 'title' => $q->title, 'module_id' => $q->course_module_id, 'due_at' => $q->due_at?->toIso8601String(),
                'average_percent' => $percents->isEmpty() ? null : round($percents->avg(), 1),
                'enrolled' => $enrolled, 'submitted' => $best->count(), 'on_time' => null, 'late' => null, 'missing' => max(0, $enrolled - $best->count()),
            ];
        }

        return collect($rows)->sortBy('due_at')->values()->all();
    }

    /** Topics (modules) ranked hardest first by the class's average score across their assessments - the course-level counterpart to a student's own weak topics. */
    private function topicAverages(array $assessments): array
    {
        return collect($assessments)->filter(fn ($a) => $a['module_id'] !== null && $a['average_percent'] !== null)
            ->groupBy('module_id')->map(fn ($items, $moduleId) => [
                'module_id' => (int) $moduleId,
                'average_percent' => round(collect($items)->avg('average_percent'), 1),
                'assessment_count' => $items->count(),
            ])->sortBy('average_percent')->values()->all();
    }

    /** How long, on average, a submission waits for its first published grade - lecturer response time. */
    private function responseTime(CourseOffering $offering): array
    {
        $assignmentIds = Assignment::where('course_offering_id', $offering->id)->pluck('id');
        $submissions = Submission::whereIn('assignment_id', $assignmentIds)->whereNotNull('submitted_at')->get(['id', 'submitted_at']);
        if ($submissions->isEmpty()) {
            return ['average_hours' => null, 'graded' => 0];
        }
        $firstPublished = GradeRecord::whereIn('submission_id', $submissions->pluck('id'))->where('status', 'published')->orderBy('id')->get()->groupBy('submission_id')->map(fn ($g) => $g->first());
        $hours = $submissions->map(function ($s) use ($firstPublished) {
            $grade = $firstPublished->get($s->id);

            return $grade ? $s->submitted_at->diffInMinutes($grade->created_at) / 60 : null;
        })->filter(fn ($h) => $h !== null);

        return ['average_hours' => $hours->isEmpty() ? null : round($hours->avg(), 1), 'graded' => $hours->count()];
    }

    /** Where the class stands on each tracked practical competency - the practical competency report. */
    private function competencies(CourseOffering $offering): array
    {
        return $offering->competencies()->withCount([
            'statuses as not_started_count' => fn ($q) => $q->where('status', 'not_started'),
            'statuses as developing_count' => fn ($q) => $q->where('status', 'developing'),
            'statuses as competent_count' => fn ($q) => $q->where('status', 'competent'),
        ])->get(['id', 'title'])->map(fn ($c) => [
            'id' => $c->id, 'title' => $c->title, 'not_started' => $c->not_started_count, 'developing' => $c->developing_count, 'competent' => $c->competent_count,
        ])->values()->all();
    }
}
