<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One student's assignment to review a classmate's submission, kept anonymous to everyone but the reviewer. */
class PeerReview extends Model
{
    protected $fillable = ['assignment_id', 'reviewer_id', 'submission_id', 'body', 'submitted_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
