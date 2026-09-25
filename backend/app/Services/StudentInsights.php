<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\CourseModule;
use App\Models\Enrolment;
use App\Models\GradeRecord;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Models\User;

/** Grade-derived signals for a student's own dashboard: recent feedback, and topics their marks say need review. */
class StudentInsights
{
    private const WEAK_TOPIC_THRESHOLD = 0.7;

    /** The last few published grades that carry feedback text, newest first. */
    public function recentFeedback(User $user, int $limit = 5): array
    {
        return GradeRecord::with('submission.assignment.offering.course:id,code')
            ->where('status', 'published')->whereNotNull('feedback')->where('feedback', '!=', '')
            ->whereHas('submission', fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')->limit($limit)->get()
            ->map(fn (GradeRecord $g) => [
                'assignment' => $g->submission->assignment->title,
                'course' => $g->submission->assignment->offering->course->code,
                'score' => (float) $g->score,
                'max_score' => (float) $g->submission->assignment->max_score,
                'feedback' => $g->feedback,
                'at' => $g->created_at->toIso8601String(),
            ])->values()->all();
    }

    /**
     * Topics (modules) where this student's own average score, across the assignments and quizzes marked under that
     * topic, falls below the threshold. Only topics with at least one graded item are considered — there is no such
     * thing as a "weak" topic with no data yet. Scoring mirrors the gradebook: an assignment counts its latest
     * published grade, a quiz counts its best submitted attempt.
     */
    public function weakTopics(User $user): array
    {
        $offerings = Enrolment::where('user_id', $user->id)->where('status', 'active')
            ->whereHas('offering', fn ($q) => $q->where('published', true))->pluck('course_offering_id');
        if ($offerings->isEmpty()) {
            return [];
        }

        $percentsByModule = [];

        $assignments = Assignment::whereIn('course_offering_id', $offerings)->where('published', true)->whereNotNull('course_module_id')->get(['id', 'course_module_id', 'max_score']);
        if ($assignments->isNotEmpty()) {
            $submissions = Submission::whereIn('assignment_id', $assignments->pluck('id'))->where('user_id', $user->id)->get(['id', 'assignment_id']);
            $grades = GradeRecord::whereIn('submission_id', $submissions->pluck('id'))->where('status', 'published')->orderBy('id')->get()->keyBy('submission_id');
            foreach ($submissions as $submission) {
                $grade = $grades->get($submission->id);
                if (! $grade) {
                    continue;
                }
                $assignment = $assignments->firstWhere('id', $submission->assignment_id);
                if ($assignment && (float) $assignment->max_score > 0) {
                    $percentsByModule[$assignment->course_module_id][] = (float) $grade->score / (float) $assignment->max_score;
                }
            }
        }

        $quizzes = Quiz::whereIn('course_offering_id', $offerings)->where('published', true)->whereNotNull('course_module_id')->withSum('questions as total_points', 'points')->get(['id', 'course_module_id']);
        if ($quizzes->isNotEmpty()) {
            $best = QuizAttempt::whereIn('quiz_id', $quizzes->pluck('id'))->where('user_id', $user->id)->whereNotNull('submitted_at')
                ->selectRaw('quiz_id, max(score) as best_score')->groupBy('quiz_id')->pluck('best_score', 'quiz_id');
            foreach ($quizzes as $quiz) {
                $score = $best->get($quiz->id);
                if ($score === null || (float) $quiz->total_points <= 0) {
                    continue;
                }
                $percentsByModule[$quiz->course_module_id][] = (float) $score / (float) $quiz->total_points;
            }
        }

        if ($percentsByModule === []) {
            return [];
        }
        $modules = CourseModule::whereIn('id', array_keys($percentsByModule))->get(['id', 'course_offering_id', 'title']);

        return $modules->map(function (CourseModule $module) use ($percentsByModule) {
            $percents = $percentsByModule[$module->id];

            return ['offering_id' => $module->course_offering_id, 'module_id' => $module->id, 'title' => $module->title, 'percent' => round(array_sum($percents) / count($percents) * 100, 1)];
        })->filter(fn ($t) => $t['percent'] < self::WEAK_TOPIC_THRESHOLD * 100)->sortBy('percent')->values()->all();
    }
}
