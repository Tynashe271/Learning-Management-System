<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A documented, per-student extra-time accommodation for timed quizzes in one course, e.g. "time and a half" (extra_time_percent = 50). */
class Accommodation extends Model
{
    protected $fillable = ['course_offering_id', 'user_id', 'extra_time_percent', 'notes', 'created_by'];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
