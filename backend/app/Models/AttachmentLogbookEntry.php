<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One week's worked hours and activities during an attachment, with optional evidence. */
class AttachmentLogbookEntry extends Model
{
    protected $fillable = ['placement_id', 'week_ending', 'hours', 'activities', 'evidence_path'];

    protected function casts(): array
    {
        return ['week_ending' => 'date', 'hours' => 'decimal:2'];
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(AttachmentPlacement::class, 'placement_id');
    }
}
