<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's own tracked support request - a sign-in problem, a submission failure, or anything else - for an administrator to triage. */
class SupportRequest extends Model
{
    public const CATEGORIES = ['sign_in', 'submission', 'technical', 'other'];

    public const STATUSES = ['open', 'in_progress', 'resolved'];

    protected $fillable = ['user_id', 'category', 'subject', 'description', 'status', 'assigned_to', 'resolution_note', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
