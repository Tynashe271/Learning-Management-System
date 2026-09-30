<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A snapshot of a submission's body and file, taken just before a resubmission replaced them. */
class SubmissionVersion extends Model
{
    protected $fillable = ['submission_id', 'version', 'body', 'storage_path', 'submitted_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
