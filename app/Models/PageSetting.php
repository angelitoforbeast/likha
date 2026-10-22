<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Isang setting ng page (Page | Provider | Key | Value). Ang secret (hal. token) ay naka-encrypt sa value,
 * at hindi kasama ang value sa toArray / JSON: sa plainValue() lang ito nababasa, sa server.
 */
class PageSetting extends Model
{
    protected $table = 'page_settings';

    protected $fillable = [
        'page_name',
        'provider',
        'key',
        'value',
        'is_secret',
        'active',
        'replaced_at',
        'replaced_by',
        'created_by',
    ];

    protected $hidden = ['value'];

    protected $casts = [
        'is_secret'   => 'boolean',
        'active'      => 'boolean',
        'replaced_at' => 'datetime',
    ];

    /** Anumang sulat o bura ng row: ang naka-cache na settings ng page (PageSettings::get) ay binubura. */
    protected static function booted(): void
    {
        static::saved(fn (self $s) => \App\Services\PageSettings::forget((string) $s->page_name));
        static::deleted(fn (self $s) => \App\Services\PageSettings::forget((string) $s->page_name));
    }

    public function plainValue(): ?string
    {
        if ($this->value === null) return null;
        if (!$this->is_secret) return $this->value;

        try {
            return Crypt::decryptString($this->value);
        } catch (\Throwable $e) {
            return null; // naka-encrypt sa ibang key: hindi mabasa, parang walang laman
        }
    }

    /** Ang ipinapakita sa page: secret = huling 4 na character lang. */
    public function displayValue(): string
    {
        if (!$this->is_secret) return (string) $this->value;

        $plain = (string) $this->plainValue();
        return '••••' . (mb_strlen($plain) > 4 ? mb_substr($plain, -4) : '');
    }

    public function fullKey(): string
    {
        return $this->provider . '.' . $this->key;
    }
}
