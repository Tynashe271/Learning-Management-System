<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    protected $fillable = ['direct_message_id', 'original_name', 'storage_path', 'mime_type', 'size'];

    // The storage location is an implementation detail; files are only reachable through the authorized download route.
    protected $hidden = ['storage_path'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(DirectMessage::class, 'direct_message_id');
    }
}
