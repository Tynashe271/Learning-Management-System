<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMeeting extends Model
{
    protected $fillable = ['project_id', 'occurred_on', 'notes', 'logged_by'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
