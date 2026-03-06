<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhoneWhitelist extends Model
{
    use HasFactory;

    protected $table = 'phone_whitelist';

    protected $fillable = [
        'phone_number',
        'reason',
        'host_scope',
        'created_by',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all whitelisted phone numbers for a given host as a flat array.
     */
    public static function phonesForHost(string $host): array
    {
        $scope = str_contains(strtolower($host), 'incepxion') ? 'incepxion' : 'likha';

        return static::where('host_scope', $scope)
            ->pluck('phone_number')
            ->map(fn($p) => trim($p))
            ->filter(fn($p) => $p !== '')
            ->values()
            ->all();
    }
}
