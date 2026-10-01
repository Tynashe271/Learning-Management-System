<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizAttempt extends Model
{
    /** Seconds allowed past the deadline for network latency before an attempt is closed unanswered. */
    public const GRACE_SECONDS = 60;

    protected $fillable = ['quiz_id', 'user_id', 'started_at', 'submitted_at', 'score', 'max_score', 'draft_answers'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'draft_answers' => 'array',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }

    /** The questions drawn for this attempt, in order. Empty when the quiz uses its full pool for every attempt. */
    public function attemptQuestions(): HasMany
    {
        return $this->hasMany(QuizAttemptQuestion::class)->orderBy('position');
    }

    /** The questions this attempt is actually built from: its own random draw if it has one, otherwise the quiz's full pool. */
    public function questionSet(): \Illuminate\Support\Collection
    {
        $drawn = $this->attemptQuestions()->with('question.options')->get()->map(fn (QuizAttemptQuestion $aq) => $aq->question);

        return $drawn->isNotEmpty() ? $drawn : $this->quiz->questions()->ordered()->with('options')->get();
    }

    /** The earlier of the quiz deadline and the per-attempt time limit. */
    public function deadline(): CarbonImmutable
    {
        $deadline = $this->quiz->due_at;
        if ($this->quiz->time_limit_minutes) {
            $minutes = $this->quiz->time_limit_minutes * (1 + $this->extraTimePercent() / 100);
            $limit = $this->started_at->addMinutes((int) ceil($minutes));
            $deadline = $limit->lessThan($deadline) ? $limit : $deadline;
        }

        return $deadline;
    }

    /** The student's documented extra-time accommodation for this course, as a percentage added to the time limit (0 if none). */
    private function extraTimePercent(): int
    {
        return Accommodation::where('course_offering_id', $this->quiz->course_offering_id)->where('user_id', $this->user_id)->value('extra_time_percent') ?? 0;
    }

    public function isExpired(): bool
    {
        return $this->submitted_at === null && now()->greaterThan($this->deadline()->addSeconds(self::GRACE_SECONDS));
    }

    /** This attempt's own total, which may be less than the quiz's full total when it only drew a subset of questions. */
    public function totalPoints(): int
    {
        $drawn = $this->attemptQuestions()->with('question')->get();

        return (int) ($drawn->isNotEmpty() ? $drawn->sum(fn (QuizAttemptQuestion $aq) => $aq->question->points) : $this->quiz->totalPoints());
    }
}
