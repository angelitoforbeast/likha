<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinanceCategory extends Model
{
    use HasFactory;

    protected $table = 'finance_categories';

    protected $fillable = [
        'name',
        'type',
        'is_system',
        'host_scope',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function transactions()
    {
        return $this->hasMany(FinanceTransaction::class, 'category_id');
    }

    public static function forHost(string $host)
    {
        $scope = str_contains(strtolower($host), 'incepxion') ? 'incepxion' : 'likha';
        return static::where('host_scope', $scope)->orderBy('type')->orderBy('name')->get();
    }
}
