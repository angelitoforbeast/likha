<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZkAttendanceProcessed extends Model
{
    protected $table = 'zk_attendance_processed';

    protected $fillable = [
        'zk_user_id',
        'date',
        'time_in',
        'lunch_out',
        'lunch_in',
        'time_out',
        'work_hours',
        'upload_batch',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
