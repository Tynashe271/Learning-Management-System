<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One dated, timed entry a student logs toward a competency, with an optional photo or video of the work. */
class LogbookEntry extends Model
{
    protected $fillable = ['competency_id', 'user_id', 'activity_date', 'hours', 'description', 'evidence_path', 'reviewed_at', 'reviewed_by', 'reviewer_comment'];

    protected function casts(): array
    {
        return ['activity_date' => 'date', 'hours' => 'decimal:2', 'reviewed_at' => 'immutable_datetime'];
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
