<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RubricCriterion extends Model
{
    protected $fillable = ['assignment_id', 'title', 'description', 'max_points', 'position'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** Descriptions of what performance earns which marks, in the order the teacher wrote them. */
    public function levels(): HasMany
    {
        return $this->hasMany(RubricLevel::class)->orderBy('position')->orderBy('id');
    }
}
