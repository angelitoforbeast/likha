<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemClassRule extends Model
{
    protected $table = 'item_class_rules';

    protected $fillable = [
        'class_key',
        'label',
        'badge_tailwind',
        'sort_order',
        'rule_type',
        'min_velocity',
        'window_days',
        'alive_min',
        'alive_window',
        'age_threshold',
        'is_fixed',
        'is_active',
    ];

    protected $casts = [
        'min_velocity'  => 'float',
        'window_days'   => 'int',
        'alive_min'     => 'float',
        'alive_window'  => 'int',
        'age_threshold' => 'int',
        'sort_order'    => 'int',
        'is_fixed'      => 'bool',
        'is_active'     => 'bool',
    ];

    public const TYPE_AGE_LT        = 'age_lt';
    public const TYPE_VELOCITY_TIER = 'velocity_tier';
    public const TYPE_CATCH_ALL     = 'catch_all';

    public static function ordered()
    {
        return self::where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Palette of Tailwind badge classes for the CEO to pick from.
     */
    public static function palette(): array
    {
        return [
            'bg-purple-600 text-white'      => 'Purple',
            'bg-indigo-600 text-white'      => 'Indigo',
            'bg-blue-500 text-white'        => 'Blue',
            'bg-cyan-600 text-white'        => 'Cyan',
            'bg-teal-600 text-white'        => 'Teal',
            'bg-green-600 text-white'       => 'Green',
            'bg-lime-500 text-gray-900'     => 'Lime',
            'bg-yellow-400 text-gray-900'   => 'Yellow',
            'bg-amber-600 text-white'       => 'Amber',
            'bg-orange-400 text-white'      => 'Orange',
            'bg-red-600 text-white'         => 'Red',
            'bg-rose-700 text-white'        => 'Rose',
            'bg-pink-500 text-white'        => 'Pink',
            'bg-slate-700 text-white'       => 'Slate',
            'bg-gray-500 text-white'        => 'Gray',
        ];
    }
}
