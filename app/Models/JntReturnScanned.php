<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JntReturnScanned extends Model
{
    use HasFactory;

    // Table name (optional kasi Laravel auto-detects plural -> jnt_return_scanneds,
    // so we define it explicitly para walang issue)
    protected $table = 'jnt_return_scanned';

    // Fillable fields
    protected $fillable = [
        'waybill_number',
        'scanned_at',
        'scanned_by',
        'status_at_scan',
        'remarks',
    ];

    // Dates casting
    protected $casts = [
        'scanned_at' => 'datetime',
    ];
}
