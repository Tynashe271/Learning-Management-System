<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSession extends Model
{
    protected $fillable = ['course_offering_id', 'title', 'starts_at', 'ends_at', 'join_url', 'location'];

    // The check-in code is shown only to the teacher who opens check-in, never in a session listing.
    protected $hidden = ['checkin_code'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'checkin_opens_at' => 'immutable_datetime',
            'checkin_closes_at' => 'immutable_datetime',
        ];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function handRaises(): HasMany
    {
        return $this->hasMany(HandRaise::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(SessionQuestion::class);
    }

    public function checkinIsOpen(): bool
    {
        return $this->checkin_code !== null && $this->checkin_opens_at !== null && $this->checkin_closes_at !== null
            && now()->greaterThanOrEqualTo($this->checkin_opens_at) && now()->lessThanOrEqualTo($this->checkin_closes_at);
    }
}
