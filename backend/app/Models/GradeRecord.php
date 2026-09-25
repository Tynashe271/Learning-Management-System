<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeRecord extends Model
{
    protected $fillable = ['submission_id', 'graded_by', 'score', 'status', 'feedback', 'change_reason', 'criteria_scores'];

    protected function casts(): array
    {
        return ['criteria_scores' => 'array'];
    }
}
