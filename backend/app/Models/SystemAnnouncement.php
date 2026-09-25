<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SystemAnnouncement extends Model
{
    public const SEVERITIES = ['info', 'warning', 'critical'];

    protected $fillable = ['title', 'body', 'severity', 'audience', 'starts_at', 'ends_at', 'created_by'];

    protected function casts(): array
    {
        return ['audience' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** Announcements whose time has come and not yet gone. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** @param  list<string>  $roles */
    public function isFor(array $roles): bool
    {
        return empty($this->audience) || array_intersect($this->audience, $roles) !== [];
    }
}
