<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotcakePsidImportRun extends Model
{
    protected $fillable = [
        'status',
        'cutoff_datetime',
        'current_setting_id',
        'current_gsheet_name',
        'current_sheet_name',
        'k1_value',
        'seed_row',
        'selected_start_row',
        'batch_no',
        'batch_start_row',
        'batch_end_row',
        'next_scan_from',
        'total_imported',
        'total_not_existing',
        'total_skipped',
        'total_waiting',
        'total_matched_from_waiting',
        'total_expired',
        'total_conflicts',
        'total_reorder_copies',
        'total_deleted_rows',
        'last_message',
        'last_error',
        'source',
        'page_name',
        'batches_read',
        'customers_new',
        'customers_updated',
        'seconds',
        'matched_by_name',
        'matched_by_phone',
        'ambiguous',
        'no_match',
        'stop_reason',
        'read_kind',
        'start_batch',
        'ad_filled',
        'run_key',
        'sync_state',
        'page_index',
        'current_page',
        'started_by',
        'finished_at',
        'subscribed_newest',
        'subscribed_oldest',
        'stop_date',
    ];

    protected $casts = [
        'sync_state'        => 'array',
        'finished_at'       => 'datetime',
        'subscribed_newest' => 'date',
        'subscribed_oldest' => 'date',
        'stop_date'         => 'date',
    ];

    public function events()
    {
        return $this->hasMany(BotcakePsidImportEvent::class, 'run_id');
    }
}
