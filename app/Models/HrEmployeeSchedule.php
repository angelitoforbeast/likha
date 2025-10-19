<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrEmployeeSchedule extends Model
{
    protected $table = 'hr_employee_schedules';

    protected $fillable = [
        'zk_user_id',
        'shift_label',
        'time_in_default',
        'time_out_default',
        'break_start',
        'break_end',
        'grace_period_minutes',
        'effective_start',
        'effective_end',
        'is_active',
        'remarks',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'effective_start'  => 'date',
        'effective_end'    => 'date',
        // times are strings by default; that’s fine for cross-DB
    ];

    /**
     * Get the schedule active for a given user and date (Y-m-d).
     * Picks the most recent effective_start that is <= date,
     * and effective_end is NULL or >= date, and is_active = true.
     */
    public static function activeFor(string $zkUserId, string $date): ?self
    {
        return static::query()
            ->where('zk_user_id', $zkUserId)
            ->where('is_active', true)
            ->whereDate('effective_start', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_end')
                  ->orWhereDate('effective_end', '>=', $date);
            })
            ->orderByDesc('effective_start')
            ->first();
    }
}
