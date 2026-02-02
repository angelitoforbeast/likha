<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JntCheckerRunItem extends Model
{
    protected $table = 'jnt_checker_run_items';

    protected $fillable = [
        'run_id',
        'file_id',
        'source_file',
        'order_status',
        'sender',
        'page',
        'receiver',
        'item',
        'cod',
        'waybill',
        'matched',
        'matched_id',
        'is_mapping_missing',
    ];

    protected $casts = [
        'matched' => 'boolean',
        'is_mapping_missing' => 'boolean',
    ];

    public function run()
    {
        return $this->belongsTo(JntCheckerRun::class, 'run_id');
    }
}
