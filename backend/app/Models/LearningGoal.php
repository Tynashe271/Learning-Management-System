<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's own personal learning goal: free text and an optional target date, marked done by the student themselves. */
class LearningGoal extends Model
{
    protected $fillable = ['user_id', 'title', 'target_date', 'completed_at'];

    protected function casts(): array
    {
        return ['target_date' => 'date', 'completed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
