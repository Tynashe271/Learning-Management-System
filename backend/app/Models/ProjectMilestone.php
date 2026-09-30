<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMilestone extends Model
{
    protected $fillable = ['project_id', 'title', 'due_on', 'completed_at', 'notes'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'completed_at' => 'immutable_datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
