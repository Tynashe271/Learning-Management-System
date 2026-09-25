<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemCompletion extends Model
{
    protected $fillable = ['learning_item_id', 'user_id'];
}
