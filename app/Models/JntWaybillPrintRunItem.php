<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JntWaybillPrintRunItem extends Model
{
    protected $table = 'jnt_waybill_print_run_items';

    protected $fillable = [
        'run_id','macro_output_id','mailno','seq','status','error'
    ];
}
