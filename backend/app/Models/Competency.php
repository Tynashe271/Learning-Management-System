<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A practical skill a course tracks, e.g. "Solder a joint" or "Aseptic technique". */
class Competency extends Model
{
    public const STATUSES = ['not_started', 'developing', 'competent'];

    protected $fillable = ['course_offering_id', 'title', 'description'];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(CompetencyStatus::class);
    }

    public function logbookEntries(): HasMany
    {
        return $this->hasMany(LogbookEntry::class);
    }
}
