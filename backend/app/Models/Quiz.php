<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    protected $fillable = ['course_offering_id', 'course_module_id', 'title', 'instructions', 'opens_at', 'due_at', 'time_limit_minutes', 'max_attempts', 'published', 'is_practice', 'questions_per_attempt', 'is_open_book'];

    protected function casts(): array
    {
        return ['opens_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'published' => 'boolean', 'is_practice' => 'boolean', 'is_open_book' => 'boolean'];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function targetedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'quiz_targets')->withTimestamps();
    }

    /** No targets set means visible to the whole class. */
    public function isVisibleTo(User $user): bool
    {
        return ! $this->targetedUsers()->exists() || $this->targetedUsers()->where('users.id', $user->id)->exists();
    }

    public function questions(): HasMany
    {
        // Unordered on purpose: aggregates on an ordered relation fail on PostgreSQL. Use QuizQuestion::ordered().
        return $this->hasMany(QuizQuestion::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function totalPoints(): int
    {
        return (int) $this->questions()->sum('points');
    }
}
