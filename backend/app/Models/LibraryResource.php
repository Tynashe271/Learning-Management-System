<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryResource extends Model
{
    public const CATEGORIES = ['textbook', 'past_exam_paper', 'reading_material'];

    protected $fillable = ['title', 'category', 'course_code', 'description', 'storage_path', 'uploaded_by'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
