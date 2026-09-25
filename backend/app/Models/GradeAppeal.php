<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeAppeal extends Model
{
    public const STATUSES = ['open', 'upheld', 'rejected'];

    protected $fillable = ['submission_id', 'user_id', 'grade_record_id', 'reason', 'status', 'response', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'immutable_datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function gradeRecord(): BelongsTo
    {
        return $this->belongsTo(GradeRecord::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
