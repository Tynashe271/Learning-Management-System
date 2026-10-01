<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\CompetencyStatus;
use App\Models\CourseOffering;
use App\Models\DiscussionPost;
use App\Models\DiscussionThread;
use App\Models\Enrolment;
use App\Models\ItemCompletion;
use App\Models\LearningItem;
use App\Models\QuizAttempt;
use App\Models\SecurityEvent;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Real, derived engagement signals (roadmap item 18) - nothing here is a separate tracked event stream, it is all
 * computed from data the app already has, so a badge can never say something happened that the record books disagree
 * with. Badges are encouragement, not an academic result (see ROADMAP.md item 18): they never feed a grade or the gradebook.
 */
class Engagement
{
    /** Points awarded for one participation action in a course, documented here since nothing else states them. */
    private const POINTS = ['attendance' => 1, 'discussion_thread' => 2, 'discussion_post' => 1, 'quiz_attempt' => 1, 'assignment_submission' => 2];

    private const MILESTONES = [25, 50, 75, 100];

    /** Consecutive calendar days (ending today or yesterday) with at least one successful sign-in, and the longest such run ever. */
    public function streak(User $user): array
    {
        $dates = SecurityEvent::where('user_id', $user->id)->where('event', 'login.success')
            ->selectRaw('DISTINCT DATE(created_at) as d')->pluck('d')
            ->map(fn ($d) => (string) $d)->sort()->values();

        if ($dates->isEmpty()) {
            return ['current_days' => 0, 'longest_days' => 0];
        }

        // String comparison of the next calendar day, not diffInDays(), since Carbon's diff methods return a float.
        $isNextDay = fn (string $day, string $next) => Carbon::parse($day)->addDay()->toDateString() === $next;

        $longest = 1;
        $run = 1;
        for ($i = 1; $i < $dates->count(); $i++) {
            $run = $isNextDay($dates[$i - 1], $dates[$i]) ? $run + 1 : 1;
            $longest = max($longest, $run);
        }

        $last = $dates->last();
        $current = 0;
        if (in_array($last, [Carbon::today()->toDateString(), Carbon::yesterday()->toDateString()], true)) {
            $current = 1;
            for ($i = $dates->count() - 1; $i > 0; $i--) {
                if ($isNextDay($dates[$i - 1], $dates[$i])) {
                    $current++;
                } else {
                    break;
                }
            }
        }

        return ['current_days' => $current, 'longest_days' => $longest];
    }

    /** Badges earned from real data: skills signed off, course-content milestones, and quizzes aced. Order is not meaningful. */
    public function badges(User $user): array
    {
        $badges = [];

        $competent = CompetencyStatus::where('user_id', $user->id)->where('status', 'competent')->with('competency:id,title')->get();
        foreach ($competent as $status) {
            if ($status->competency) {
                $badges[] = ['key' => "skill:{$status->competency_id}", 'kind' => 'skill', 'label' => "Skill signed off: {$status->competency->title}"];
            }
        }

        $offeringIds = Enrolment::where('user_id', $user->id)->where('status', 'active')->pluck('course_offering_id');
        $offerings = CourseOffering::whereIn('id', $offeringIds)->with('course:id,code')->get();
        foreach ($offerings as $offering) {
            $percent = $this->contentPercent($user, $offering);
            if ($percent === null) {
                continue;
            }
            foreach (self::MILESTONES as $threshold) {
                if ($percent >= $threshold) {
                    $badges[] = ['key' => "milestone:{$offering->id}:{$threshold}", 'kind' => 'milestone', 'label' => "{$threshold}% through {$offering->course?->code}"];
                }
            }
        }

        $aced = QuizAttempt::where('user_id', $user->id)->whereNotNull('submitted_at')->where('max_score', '>', 0)
            ->whereColumn('score', '=', 'max_score')->with('quiz:id,title')->get()->unique('quiz_id');
        foreach ($aced as $attempt) {
            if ($attempt->quiz) {
                $badges[] = ['key' => "quiz_ace:{$attempt->quiz_id}", 'kind' => 'quiz_ace', 'label' => "Perfect score: {$attempt->quiz->title}"];
            }
        }

        return $badges;
    }

    /** This student's own participation points in every course they are actively enrolled in. */
    public function myParticipation(User $user): array
    {
        $offeringIds = Enrolment::where('user_id', $user->id)->where('status', 'active')->pluck('course_offering_id');
        $offerings = CourseOffering::whereIn('id', $offeringIds)->with('course:id,code,title')->get();

        return $offerings->map(fn (CourseOffering $o) => [
            'offering_id' => $o->id,
            'course' => $o->course?->code,
            'points' => $this->participationPoints($user, $o),
        ])->values()->all();
    }

    /** Every actively enrolled student's participation points in one course, for the course's managers. */
    public function offeringParticipation(CourseOffering $offering): array
    {
        $students = Enrolment::where('course_offering_id', $offering->id)->where('status', 'active')->with('user:id,name,email')->get()->pluck('user')->filter();

        return $students->map(fn (User $u) => ['user' => $u->only(['id', 'name', 'email']), 'points' => $this->participationPoints($u, $offering)])->values()->all();
    }

    private function participationPoints(User $user, CourseOffering $offering): int
    {
        $sessionIds = ClassSession::where('course_offering_id', $offering->id)->pluck('id');
        $attendance = AttendanceRecord::where('user_id', $user->id)->whereIn('class_session_id', $sessionIds)->whereIn('status', ['present', 'late'])->count();
        $threads = DiscussionThread::where('course_offering_id', $offering->id)->where('user_id', $user->id)->count();
        $threadIds = DiscussionThread::where('course_offering_id', $offering->id)->pluck('id');
        $posts = DiscussionPost::where('user_id', $user->id)->whereIn('discussion_thread_id', $threadIds)->count();
        $quizAttempts = QuizAttempt::where('user_id', $user->id)->whereNotNull('submitted_at')->whereHas('quiz', fn ($q) => $q->where('course_offering_id', $offering->id))->count();
        $submissions = Submission::where('user_id', $user->id)->whereNotNull('submitted_at')->whereHas('assignment', fn ($q) => $q->where('course_offering_id', $offering->id))->count();

        return $attendance * self::POINTS['attendance'] + $threads * self::POINTS['discussion_thread'] + $posts * self::POINTS['discussion_post']
            + $quizAttempts * self::POINTS['quiz_attempt'] + $submissions * self::POINTS['assignment_submission'];
    }

    private function contentPercent(User $user, CourseOffering $offering): ?float
    {
        $itemIds = LearningItem::where('published', true)
            ->whereHas('module', fn ($q) => $q->where('course_offering_id', $offering->id)->where('published', true))
            ->pluck('id');
        $total = $itemIds->count();
        if ($total === 0) {
            return null;
        }
        $done = ItemCompletion::where('user_id', $user->id)->whereIn('learning_item_id', $itemIds)->count();

        return round($done / $total * 100, 2);
    }
}
