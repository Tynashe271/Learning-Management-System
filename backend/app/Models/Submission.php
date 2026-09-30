<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submission extends Model
{
    protected $fillable = ['assignment_id', 'user_id', 'body', 'storage_path', 'submitted_at', 'late', 'late_explanation', 'version'];

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'late' => 'boolean'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gradeRecords(): HasMany
    {
        return $this->hasMany(GradeRecord::class);
    }

    /** Snapshots of earlier versions, taken just before each resubmission. */
    public function versions(): HasMany
    {
        return $this->hasMany(SubmissionVersion::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(SubmissionFeedback::class);
    }
}
