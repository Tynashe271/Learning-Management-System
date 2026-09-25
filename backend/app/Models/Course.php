<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Course extends Model
{
    public const LEVELS = ['certificate', 'diploma', 'undergraduate', 'postgraduate', 'doctoral'];

    protected $fillable = ['code', 'title', 'description', 'department_id', 'credits', 'level', 'archived_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'credits' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
