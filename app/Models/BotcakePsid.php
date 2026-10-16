<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotcakePsid extends Model
{
    protected $table = 'botcake_psids';

    protected $fillable = [
        'setting_id',
        'page',
        'fb_name',
        'psid',
        'sheet_date',
        'status',
        'first_seen_at',
        'matched_at',
        'matched_rows',
        'last_checked_at',
        'source',
        'phone_numbers',
        'subscribed_at',
        'last_interactive_at',
        'botcake_customer_id',
        'last_seen_at',
        'ad_id',
        'ad_ids',
        'name_key',
        'phone_key',
    ];

    protected $casts = [
        'first_seen_at'   => 'datetime',
        'matched_at'      => 'datetime',
        'last_checked_at' => 'datetime',
        'phone_numbers'       => 'array',
        'subscribed_at'       => 'datetime',
        'last_interactive_at' => 'datetime',
        'last_seen_at'        => 'datetime',
        'ad_ids'              => 'array',
    ];

    protected static function booted(): void
    {
        // Ang API rows ay may naka-normalise na pangalan at unang phone para sa SQL ng psid-coverage page.
        static::saving(function (BotcakePsid $psid) {
            if ($psid->source !== 'api') return;

            $psid->name_key = mb_substr(\App\Services\BotcakeCustomerMatcher::normName($psid->fb_name), 0, 255) ?: null;
            $psid->phone_key = null;
            foreach ((array) $psid->phone_numbers as $ph) {
                $p = \App\Services\BotcakeCustomerMatcher::normPhone(is_scalar($ph) ? (string) $ph : '');
                if ($p !== '') {
                    $psid->phone_key = $p;
                    break;
                }
            }
        });
    }
}
