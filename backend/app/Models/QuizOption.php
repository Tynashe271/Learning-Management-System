<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizOption extends Model
{
    protected $fillable = ['quiz_question_id', 'text', 'is_correct', 'position'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }
}
