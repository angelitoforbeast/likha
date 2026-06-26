<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Address Keyword Blacklist — mga salitang hinahanap sa ADDRESS (Line 1).
 * Kapag may tumama (partial, case-insensitive) → invalid/TO FIX ang ADDRESS.
 * Host-scoped (likha vs incepxion), tulad ng ibang validation lists.
 */
class AddressKeywordBlacklist extends Model
{
    protected $table = 'address_keyword_blacklist';

    protected $fillable = [
        'keyword',
        'reason',
        'host_scope',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
