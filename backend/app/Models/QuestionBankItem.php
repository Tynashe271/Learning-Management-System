<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One reusable question in a lecturer's own question bank, copied into a quiz's real questions when used. */
class QuestionBankItem extends Model
{
    protected $fillable = ['owner_id', 'course_offering_id', 'type', 'prompt', 'points', 'tags'];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionBankOption::class)->orderBy('position')->orderBy('id');
    }
}
