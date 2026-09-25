<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    protected $fillable = ['course_offering_id', 'title', 'instructions', 'due_at', 'max_score', 'published'];

    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'published' => 'boolean'];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function rubricCriteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class);
    }
}
