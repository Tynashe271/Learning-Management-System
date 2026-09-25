<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    public const STATUSES = ['present', 'late', 'absent', 'excused'];

    protected $fillable = ['class_session_id', 'user_id', 'status', 'note', 'marked_by', 'source'];
}
