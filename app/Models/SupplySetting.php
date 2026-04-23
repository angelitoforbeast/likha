<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplySetting extends Model
{
    protected $table = 'supply_settings';

    protected $fillable = [
        'key',
        'value',
        'label',
        'group',
        'data_type',
        'sort_order',
    ];

    /**
     * Cast `value` to correct type based on `data_type` column.
     */
    public function typedValue()
    {
        return match ($this->data_type) {
            'int'   => (int) $this->value,
            'float' => (float) $this->value,
            'bool'  => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $this->value,
        };
    }

    /**
     * Load all settings as a key=>typedValue map.
     */
    public static function asMap(): array
    {
        $out = [];
        foreach (self::all() as $row) {
            $out[$row->key] = $row->typedValue();
        }
        return $out;
    }
}
