<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeSetting extends Model
{
    use HasFactory;

    protected $table = 'fee_settings';

    protected $fillable = [
        'setting_key',
        'setting_value',
        'effective_date',
        'description',
        'host_scope',
    ];

    protected $casts = [
        'setting_value'  => 'decimal:6',
        'effective_date' => 'date',
    ];

    /**
     * Get the effective rate for a given key, host, and date.
     * Returns the setting with the latest effective_date <= $date.
     */
    public static function getRate(string $key, string $host, string $date): ?float
    {
        $scope = 'likha'; // default
        if (str_contains(strtolower($host), 'incepxion')) {
            $scope = 'incepxion';
        } elseif (str_contains(strtolower($host), 'likha')) {
            $scope = 'likha';
        }

        $setting = static::where('setting_key', $key)
            ->where('host_scope', $scope)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->first();

        return $setting ? (float) $setting->setting_value : null;
    }

    /**
     * Get all settings for a given host scope, ordered by key and effective date.
     */
    public static function forHost(string $host)
    {
        $scope = 'likha';
        if (str_contains(strtolower($host), 'incepxion')) {
            $scope = 'incepxion';
        } elseif (str_contains(strtolower($host), 'likha')) {
            $scope = 'likha';
        }

        return static::where('host_scope', $scope)
            ->orderBy('setting_key')
            ->orderByDesc('effective_date')
            ->get();
    }
}
