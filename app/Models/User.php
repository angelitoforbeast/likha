<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function userRole()
    {
        return $this->hasOne(\App\Models\UserRole::class, 'user_id');
    }
    public function profile()
{
    return $this->hasOne(EmployeeProfile::class);
}

    public function employeeProfile()
    {
        return $this->hasOne(\App\Models\EmployeeProfile::class, 'user_id');
    }
    protected static function booted()
{
    static::created(function ($user) {
        \App\Models\EmployeeProfile::create([
            'user_id' => $user->id,
            'name' => $user->name, // ← eto ang crucial na dagdag
            'employee_code' => 'EMP-' . str_pad($user->id, 3, '0', STR_PAD_LEFT),
            'status' => 'Active',
        ]);
    });
}


}
