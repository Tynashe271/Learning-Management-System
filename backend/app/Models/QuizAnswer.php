<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizAnswer extends Model
{
    protected $fillable = ['quiz_attempt_id', 'quiz_question_id', 'response', 'is_correct', 'points'];

    protected function casts(): array
    {
        return ['response' => 'array', 'is_correct' => 'boolean', 'points' => 'decimal:2'];
    }
}
