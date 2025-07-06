<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class UserRole extends Model
{
    protected $fillable = ['user_id', 'role_name'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
