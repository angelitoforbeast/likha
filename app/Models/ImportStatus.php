<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportStatus extends Model
{
    // Allow mass assignment for these fields
    protected $fillable = ['job_name', 'is_complete'];
}