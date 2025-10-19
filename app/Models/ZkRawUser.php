<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZkRawUser extends Model
{
    protected $table = 'zk_raw_users';

    protected $fillable = [
        'zk_user_id',
        'name_raw',
        'name_clean',
        'upload_batch',
    ];
}
