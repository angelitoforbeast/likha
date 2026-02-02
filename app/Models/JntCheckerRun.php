<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JntCheckerRun extends Model
{
    protected $table = 'jnt_checker_runs';

    protected $fillable = [
        'status',
        'filter_date_start',
        'filter_date_end',
        'payload',
        'matched_count',
        'not_matched_count',
        'not_in_excel_count',
        'mapping_missing_count',
        'skipped_cancel_count',
        'processed_files_count',
        'updatable_count',
        'perfect_match',
        'error',
        'created_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'payload'          => 'array',
        'perfect_match'    => 'boolean',
        'filter_date_start'=> 'date',
        'filter_date_end'  => 'date',
        'started_at'       => 'datetime',
        'finished_at'      => 'datetime',
    ];

    public function files()
    {
        return $this->hasMany(\App\Models\JntCheckerRunFile::class, 'run_id');
    }

    public function items()
    {
        return $this->hasMany(\App\Models\JntCheckerRunItem::class, 'run_id');
    }

    public function extra()
    {
        return $this->hasOne(\App\Models\JntCheckerRunExtra::class, 'run_id');
    }
}
