<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\DirectMessage;
use App\Models\Enrolment;
use App\Models\GradeAppeal;
use App\Models\Quiz;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use App\Notifications\AppealFiled;
use App\Notifications\AppealResolved;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\GradePublished;
use Carbon\CarbonInterface;

/**
 * Works out what is worth telling one person about in a summary email. Counts and titles only: never marks, and never the
 * text of a private message. Returns null when there is nothing to say, so no empty emails are sent.
 */
class DigestBuilder
{
    private const LABELS = [
        AnnouncementPosted::class => 'New announcements',
        GradePublished::class => 'Grades published',
        AssignmentDueSoon::class => 'Assignment reminders',
        AppealFiled::class => 'Grade appeals filed',
        AppealResolved::class => 'Grade appeals decided',
    ];

    private const DEADLINE_DAYS = 7;

    /** @return array<string, mixed>|null */
    public function build(User $user, CarbonInterface $since): ?array
    {
        $summary = array_filter([
            'notifications' => $this->notifications($user, $since),
            'unread_messages' => $this->unreadMessages($user),
            'deadlines' => $this->deadlines($user),
            'to_grade' => $this->toGrade($user),
            'open_appeals' => $this->openAppeals($user),
        ], fn ($value) => $value !== null && $value !== [] && $value !== 0);

        return $summary === [] ? null : $summary;
    }

    /** Updates still unread that arrived since the last summary, grouped by kind. */
    private function notifications(User $user, CarbonInterface $since): ?array
    {
        // Timestamps have one-second precision. Inclusive means an update from the very second of the last summary may
        // be repeated once, which beats silently missing one that arrived just after it was built.
        $rows = $user->unreadNotifications()->where('created_at', '>=', $since)->latest()->get(['type', 'data', 'created_at']);
        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'total' => $rows->count(),
            'groups' => $rows->groupBy('type')->map(fn ($group, $type) => [
                'label' => self::LABELS[$type] ?? 'Other updates',
                'count' => $group->count(),
                'titles' => $group->pluck('data.title')->filter()->unique()->take(5)->values()->all(),
            ])->values()->all(),
        ];
    }

    private function unreadMessages(User $user): ?array
    {
        $rows = DirectMessage::where('recipient_id', $user->id)->whereNull('read_at')->selectRaw('sender_id, count(*) as n')->groupBy('sender_id')->get();

        return $rows->isEmpty() ? null : ['count' => (int) $rows->sum('n'), 'people' => $rows->count()];
    }

    /** A student's unsubmitted assignments and untaken quizzes due within the next week. */
    private function deadlines(User $user): array
    {
        $offerings = Enrolment::where('user_id', $user->id)->where('status', 'active')
            ->whereHas('offering', fn ($q) => $q->where('published', true))->pluck('course_offering_id');
        if ($offerings->isEmpty()) {
            return [];
        }
        $now = now();
        $until = $now->copy()->addDays(self::DEADLINE_DAYS);

        $assignments = Assignment::with('offering.course:id,code')->whereIn('course_offering_id', $offerings)->where('published', true)
            ->whereBetween('due_at', [$now, $until])->whereDoesntHave('submissions', fn ($q) => $q->where('user_id', $user->id))->get()
            ->map(fn ($a) => ['type' => 'assignment', 'title' => $a->title, 'course' => $a->offering->course->code, 'due_at' => $a->due_at]);
        $quizzes = Quiz::with('offering.course:id,code')->whereIn('course_offering_id', $offerings)->where('published', true)
            ->whereBetween('due_at', [$now, $until])->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', $now))
            ->whereDoesntHave('attempts', fn ($q) => $q->where('user_id', $user->id)->whereNotNull('submitted_at'))->get()
            ->map(fn ($qz) => ['type' => 'quiz', 'title' => $qz->title, 'course' => $qz->offering->course->code, 'due_at' => $qz->due_at]);

        return $assignments->concat($quizzes)->sortBy('due_at')->take(10)->map(fn ($d) => ['due_at' => $d['due_at']->toIso8601String()] + $d)->values()->all();
    }

    /** For teaching staff: assignments in their offerings with work still waiting for a published grade. */
    private function toGrade(User $user): array
    {
        if (! $user->can('grade-submissions')) {
            return [];
        }
        $offerings = TeachingAssignment::where('user_id', $user->id)->pluck('course_offering_id');
        if ($offerings->isEmpty()) {
            return [];
        }

        return Assignment::with('offering.course:id,code')->whereIn('course_offering_id', $offerings)->where('published', true)
            ->withCount(['submissions as awaiting' => fn ($q) => $q->whereDoesntHave('gradeRecords', fn ($g) => $g->where('status', 'published'))])
            ->get()->filter(fn ($a) => $a->awaiting > 0)->sortByDesc('awaiting')
            ->map(fn ($a) => ['assignment' => $a->title, 'course' => $a->offering->course->code, 'awaiting' => $a->awaiting])->values()->all();
    }

    private function openAppeals(User $user): int
    {
        if (! $user->can('resolve-appeals')) {
            return 0;
        }
        $offerings = TeachingAssignment::where('user_id', $user->id)->pluck('course_offering_id');
        if ($offerings->isEmpty()) {
            return 0;
        }

        return GradeAppeal::where('status', 'open')->whereHas('submission.assignment', fn ($q) => $q->whereIn('course_offering_id', $offerings))->count();
    }
}
