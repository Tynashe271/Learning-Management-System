<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAnswer extends Model
{
    protected $fillable = ['quiz_attempt_id', 'quiz_question_id', 'response', 'is_correct', 'points', 'needs_manual_grading'];

    protected function casts(): array
    {
        return ['response' => 'array', 'is_correct' => 'boolean', 'points' => 'decimal:2', 'needs_manual_grading' => 'boolean'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }
}
