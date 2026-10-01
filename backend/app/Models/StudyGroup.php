<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A student-organised study group for a course - open to any actively enrolled student to join, unlike a lecturer's assignment group. */
class StudyGroup extends Model
{
    protected $fillable = ['course_offering_id', 'name', 'description', 'created_by', 'max_members'];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'study_group_members')->withTimestamps();
    }
}
