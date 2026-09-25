<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseModule extends Model
{
    protected $fillable = ['course_offering_id', 'title', 'position', 'published', 'prerequisite_module_id'];

    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prerequisite_module_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LearningItem::class);
    }
}
