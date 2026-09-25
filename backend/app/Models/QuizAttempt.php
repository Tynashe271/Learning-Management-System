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

    protected $fillable = ['quiz_id', 'user_id', 'started_at', 'submitted_at', 'score', 'max_score'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
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

    /** The earlier of the quiz deadline and the per-attempt time limit. */
    public function deadline(): CarbonImmutable
    {
        $deadline = $this->quiz->due_at;
        if ($this->quiz->time_limit_minutes) {
            $limit = $this->started_at->addMinutes($this->quiz->time_limit_minutes);
            $deadline = $limit->lessThan($deadline) ? $limit : $deadline;
        }

        return $deadline;
    }

    public function isExpired(): bool
    {
        return $this->submitted_at === null && now()->greaterThan($this->deadline()->addSeconds(self::GRACE_SECONDS));
    }
}
