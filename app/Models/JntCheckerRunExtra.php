<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JntCheckerRunExtra extends Model
{
    protected $table = 'jnt_checker_run_extras';

    protected $fillable = [
        'run_id',
        'uploaded_files_json',
        'results_json',
    ];

    // Optional: auto-cast to array para madali gamitin
    protected $casts = [
        'uploaded_files_json' => 'array',
        'results_json'        => 'array',
    ];

    public function run()
    {
        return $this->belongsTo(JntCheckerRun::class, 'run_id');
    }
}
