<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FromGsheet extends Model
{
    protected $table = 'fromgsheet';

    protected $fillable = [
        'column1',
        'column2',
        'column3',
        'column4',
    ];
}
