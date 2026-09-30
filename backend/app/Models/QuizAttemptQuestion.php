<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which question, in which order, a particular attempt drew from the quiz's pool - fixed once the attempt starts. */
class QuizAttemptQuestion extends Model
{
    protected $fillable = ['quiz_attempt_id', 'quiz_question_id', 'position'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }
}
