<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\CourseOffering;
use App\Models\DiscussionPost;
use App\Models\DiscussionThread;
use App\Models\Enrolment;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Models\User;

/**
 * Real, derived class-participation points (roadmap item 18) for a course's managers - nothing here is a separate
 * tracked event stream, it is all computed from data the app already has.
 */
class Engagement
{
    /** Points awarded for one participation action in a course, documented here since nothing else states them. */
    private const POINTS = ['attendance' => 1, 'discussion_thread' => 2, 'discussion_post' => 1, 'quiz_attempt' => 1, 'assignment_submission' => 2];

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
}
