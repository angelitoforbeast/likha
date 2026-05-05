<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Staging table para sa V2 batch consolidation.
 * Per-file workers bulk-INSERT dito; ConsolidateAndMergeJntV2Run merges
 * the consolidated output sa final from_jnts_2.
 */
class FromJnt2Staging extends Model
{
    protected $table = 'from_jnts_2_staging';

    public $timestamps = false; // only parsed_at, walang created_at/updated_at

    protected $fillable = [
        'bulk_run_id',
        'upload_log_id',
        'submission_time',
        'waybill_number',
        'receiver',
        'receiver_cellphone',
        'sender',
        'item_name',
        'cod',
        'remarks',
        'status',
        'signingtime',
        'province',
        'city',
        'barangay',
        'total_shipping_cost',
        'rts_reason',
        'parsed_at',
    ];

    protected $casts = [
        'submission_time'     => 'datetime',
        'signingtime'         => 'datetime',
        'parsed_at'           => 'datetime',
        'total_shipping_cost' => 'decimal:2',
    ];
}
