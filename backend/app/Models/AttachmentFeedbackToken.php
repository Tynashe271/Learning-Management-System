<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-time, expiring link handed to a workplace supervisor (who has no LMS account) to submit their assessment. */
class AttachmentFeedbackToken extends Model
{
    protected $fillable = ['placement_id', 'token_hash', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime'];
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(AttachmentPlacement::class, 'placement_id');
    }

    public function isValid(): bool
    {
        return $this->used_at === null && now()->lessThan($this->expires_at);
    }
}
