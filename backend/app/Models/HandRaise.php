<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandRaise extends Model
{
    protected $table = 'session_hand_raises';

    protected $fillable = ['class_session_id', 'user_id', 'raised_at'];

    protected function casts(): array
    {
        return ['raised_at' => 'immutable_datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
