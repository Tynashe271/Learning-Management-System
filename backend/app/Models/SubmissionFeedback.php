<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A grader's comment on a submission as it stood at a given version, given before the student's final grade. */
class SubmissionFeedback extends Model
{
    protected $fillable = ['submission_id', 'version', 'author_id', 'body'];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
