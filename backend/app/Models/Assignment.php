<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    protected $fillable = ['course_offering_id', 'course_module_id', 'title', 'instructions', 'due_at', 'max_score', 'published', 'allow_late_submissions', 'allow_resubmission'];

    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'published' => 'boolean', 'allow_late_submissions' => 'boolean', 'allow_resubmission' => 'boolean'];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function targetedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'assignment_targets')->withTimestamps();
    }

    /** No targets set means visible to the whole class. */
    public function isVisibleTo(User $user): bool
    {
        return ! $this->targetedUsers()->exists() || $this->targetedUsers()->where('users.id', $user->id)->exists();
    }

    public function rubricCriteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class);
    }
}
