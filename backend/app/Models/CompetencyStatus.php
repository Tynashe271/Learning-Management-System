<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's current standing on one competency: not_started, developing, or competent. */
class CompetencyStatus extends Model
{
    protected $fillable = ['competency_id', 'user_id', 'status', 'updated_by'];

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
