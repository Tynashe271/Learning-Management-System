<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A lecturer's record of investigating a possible academic-integrity concern, separate from a grade appeal. */
class IntegrityCase extends Model
{
    public const STATUSES = ['open', 'upheld', 'dismissed'];

    protected $fillable = ['course_offering_id', 'user_id', 'submission_id', 'reported_by', 'description', 'status', 'outcome', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'immutable_datetime'];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
