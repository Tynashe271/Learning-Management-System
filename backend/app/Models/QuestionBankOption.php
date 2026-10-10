<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionBankOption extends Model
{
    protected $fillable = ['question_bank_item_id', 'text', 'is_correct', 'position'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(QuestionBankItem::class, 'question_bank_item_id');
    }
}
