<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cogs extends Model
{
    protected $table = 'cogs';

    // Payagan ang mass assignment para sa lahat ng ginagamit natin
    protected $fillable = ['item_name', 'date', 'unit_cost', 'history_logs'];

    protected $casts = [
        'date' => 'date',
        'history_logs' => 'array',
        'unit_cost' => 'decimal:2',
    ];
}
