<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\ClassSession;
use App\Models\Enrolment;
use App\Models\Quiz;
use App\Models\TeachingAssignment;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * A signed-in person's own schedule: the classes on their timetable and the assignments/quizzes due, in one merged,
 * time-ordered list. Used by the dashboard and the personal calendar feed — deliberately separate from
 * DigestBuilder, which has its own similar-looking deadline query for the summary email; keeping them apart means a
 * change to one can never silently break the other.
 */
class AgendaBuilder
{
    /**
     * @return array{sessions: list<array<string, mixed>>, deadlines: list<array<string, mixed>>}
     */
    public function build(User $user, int $days, ?CarbonInterface $from = null): array
    {
        $from ??= now();
        $until = $from->copy()->addDays($days);
        $taught = TeachingAssignment::where('user_id', $user->id)->pluck('course_offering_id');
        $enrolled = Enrolment::where('user_id', $user->id)->where('status', 'active')
            ->whereHas('offering', fn ($q) => $q->where('published', true))->pluck('course_offering_id');
        $offerings = $taught->merge($enrolled)->unique();

        $deadlines = collect($this->due($user, $enrolled, $from, $until))->concat($this->owned($taught, $from, $until))->sortBy('at')->values()->all();

        return [
            'sessions' => $this->sessions($offerings, $from, $until),
            'deadlines' => $deadlines,
        ];
    }

    /** @param  \Illuminate\Support\Collection<int, int>  $offerings */
    private function sessions($offerings, CarbonInterface $from, CarbonInterface $until): array
    {
        if ($offerings->isEmpty()) {
            return [];
        }

        return ClassSession::with('offering.course:id,code')->whereIn('course_offering_id', $offerings)
            ->whereBetween('starts_at', [$from, $until])->orderBy('starts_at')->get()
            ->map(fn (ClassSession $s) => [
                'type' => 'session',
                'title' => $s->title,
                'course' => $s->offering->course->code,
                'at' => $s->starts_at->toIso8601String(),
                'ends_at' => $s->ends_at->toIso8601String(),
                'location' => $s->location,
                'join_url' => $s->join_url,
            ])->values()->all();
    }

    /** A student's own unsubmitted assignments and un-attempted quizzes that are already past due. */
    public function missing(User $user): array
    {
        $enrolled = Enrolment::where('user_id', $user->id)->where('status', 'active')
            ->whereHas('offering', fn ($q) => $q->where('published', true))->pluck('course_offering_id');
        if ($enrolled->isEmpty()) {
            return [];
        }
        $now = now();
        $assignments = Assignment::with('offering.course:id,code')->whereIn('course_offering_id', $enrolled)->where('published', true)
            ->where('due_at', '<', $now)->whereDoesntHave('submissions', fn ($q) => $q->where('user_id', $user->id))
            ->where(fn ($q) => $q->whereDoesntHave('targetedUsers')->orWhereHas('targetedUsers', fn ($t) => $t->where('users.id', $user->id)))->get()
            ->map(fn ($a) => ['type' => 'assignment', 'id' => $a->id, 'title' => $a->title, 'course' => $a->offering->course->code, 'at' => $a->due_at->toIso8601String()]);
        $quizzes = Quiz::with('offering.course:id,code')->whereIn('course_offering_id', $enrolled)->where('published', true)
            ->where('due_at', '<', $now)->whereDoesntHave('attempts', fn ($q) => $q->where('user_id', $user->id)->whereNotNull('submitted_at'))
            ->where(fn ($q) => $q->whereDoesntHave('targetedUsers')->orWhereHas('targetedUsers', fn ($t) => $t->where('users.id', $user->id)))->get()
            ->map(fn ($q) => ['type' => 'quiz', 'id' => $q->id, 'title' => $q->title, 'course' => $q->offering->course->code, 'at' => $q->due_at->toIso8601String()]);

        return $assignments->concat($quizzes)->sortBy('at')->values()->all();
    }

    /** A student's own unsubmitted assignments and un-attempted quizzes due in the window. */
    private function due(User $user, $offerings, CarbonInterface $from, CarbonInterface $until): array
    {
        if ($offerings->isEmpty()) {
            return [];
        }
        $assignments = Assignment::with('offering.course:id,code')->whereIn('course_offering_id', $offerings)->where('published', true)
            ->whereBetween('due_at', [$from, $until])->whereDoesntHave('submissions', fn ($q) => $q->where('user_id', $user->id))
            ->where(fn ($q) => $q->whereDoesntHave('targetedUsers')->orWhereHas('targetedUsers', fn ($t) => $t->where('users.id', $user->id)))->get()
            ->map(fn ($a) => ['type' => 'assignment', 'id' => $a->id, 'title' => $a->title, 'course' => $a->offering->course->code, 'at' => $a->due_at->toIso8601String()]);
        $quizzes = Quiz::with('offering.course:id,code')->whereIn('course_offering_id', $offerings)->where('published', true)
            ->whereBetween('due_at', [$from, $until])->whereDoesntHave('attempts', fn ($q) => $q->where('user_id', $user->id)->whereNotNull('submitted_at'))
            ->where(fn ($q) => $q->whereDoesntHave('targetedUsers')->orWhereHas('targetedUsers', fn ($t) => $t->where('users.id', $user->id)))->get()
            ->map(fn ($q) => ['type' => 'quiz', 'id' => $q->id, 'title' => $q->title, 'course' => $q->offering->course->code, 'at' => $q->due_at->toIso8601String()]);

        return $assignments->concat($quizzes)->sortBy('at')->values()->all();
    }

    /** For teaching staff: their own assignments/quizzes due in the window, so they know what is about to need grading. */
    private function owned($offerings, CarbonInterface $from, CarbonInterface $until): array
    {
        if ($offerings->isEmpty()) {
            return [];
        }
        $assignments = Assignment::with('offering.course:id,code')->whereIn('course_offering_id', $offerings)->where('published', true)
            ->whereBetween('due_at', [$from, $until])->get()
            ->map(fn ($a) => ['type' => 'assignment', 'id' => $a->id, 'title' => $a->title, 'course' => $a->offering->course->code, 'at' => $a->due_at->toIso8601String()]);
        $quizzes = Quiz::with('offering.course:id,code')->whereIn('course_offering_id', $offerings)->where('published', true)
            ->whereBetween('due_at', [$from, $until])->get()
            ->map(fn ($q) => ['type' => 'quiz', 'id' => $q->id, 'title' => $q->title, 'course' => $q->offering->course->code, 'at' => $q->due_at->toIso8601String()]);

        return $assignments->concat($quizzes)->sortBy('at')->values()->all();
    }
}
