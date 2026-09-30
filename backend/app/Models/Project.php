<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A student's research or capstone project topic for one course offering, from proposal through supervision. */
class Project extends Model
{
    public const STATUSES = ['proposed', 'approved', 'rejected'];

    protected $fillable = ['course_offering_id', 'user_id', 'title', 'description', 'status', 'supervisor_id'];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(ProjectMeeting::class);
    }
}
