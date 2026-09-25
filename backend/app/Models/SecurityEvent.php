<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['event', 'user_id', 'email_hash', 'ip', 'level', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
