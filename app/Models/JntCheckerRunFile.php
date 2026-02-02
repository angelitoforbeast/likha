<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JntCheckerRunFile extends Model
{
    protected $table = 'jnt_checker_run_files';

    protected $fillable = [
        'run_id',
        'original_name',
        'stored_path',
        'ext',
        'size',
        'processed_rows',
        'skipped_cancel_rows',
        'error',
    ];

    public function run()
    {
        return $this->belongsTo(JntCheckerRun::class, 'run_id');
    }
}
