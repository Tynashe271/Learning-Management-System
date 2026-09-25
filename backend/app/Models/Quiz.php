<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    protected $fillable = ['course_offering_id', 'course_module_id', 'title', 'instructions', 'opens_at', 'due_at', 'time_limit_minutes', 'max_attempts', 'published', 'is_practice'];

    protected function casts(): array
    {
        return ['opens_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'published' => 'boolean', 'is_practice' => 'boolean'];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
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
