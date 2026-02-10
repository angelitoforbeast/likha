<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JntWaybillPrintRun extends Model
{
    protected $table = 'jnt_waybill_print_runs';

    protected $fillable = [
        'created_by','date','mode','filter_by','filter_value',
        'total','processed','ok_count','fail_count',
        'status','message','output_type','output_path',
        'started_at','finished_at',
    ];

    protected $casts = [
        'date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
