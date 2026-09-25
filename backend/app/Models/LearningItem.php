<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningItem extends Model
{
    protected $fillable = ['course_module_id', 'title', 'type', 'body', 'storage_path', 'position', 'published'];

    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }
}
