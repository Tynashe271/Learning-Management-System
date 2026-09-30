<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A student's industrial attachment (work placement) for one course offering: where, with whom, and their supervisor's eventual assessment. */
class AttachmentPlacement extends Model
{
    protected $fillable = ['course_offering_id', 'user_id', 'organisation', 'supervisor_name', 'supervisor_email', 'objectives', 'starts_on', 'ends_on', 'supervisor_rating', 'supervisor_comment', 'supervisor_submitted_at'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'supervisor_submitted_at' => 'immutable_datetime'];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function logbookEntries(): HasMany
    {
        return $this->hasMany(AttachmentLogbookEntry::class, 'placement_id');
    }

    public function feedbackTokens(): HasMany
    {
        return $this->hasMany(AttachmentFeedbackToken::class, 'placement_id');
    }
}
