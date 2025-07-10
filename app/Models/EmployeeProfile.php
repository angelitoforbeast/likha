<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'employee_code',
        'position',
        'department',
        'employment_type',
        'salary',
        'date_hired',
        'birthdate',
        'gender',
        'address',
        'contact_number',
        'emergency_contact_name',
        'emergency_contact_number',
        'sss_number',
        'tin_number',
        'philhealth_number',
        'pagibig_number',
        'bank_account',
        'status',
        'remarks',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
