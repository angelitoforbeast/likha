<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;

/** Isang pagbabago sa registry (ng AI o ng user): ano ang dati, ano ang bago. */
class Change extends Model
{
    protected $table = 'br_changes';

    protected $fillable = [
        'user_id', 'target_type', 'target_id', 'action', 'label', 'before', 'after', 'actor',
        'agent_id', 'meeting_id', 'message_id', 'undone_at',
    ];

    protected $casts = ['before' => 'array', 'after' => 'array', 'undone_at' => 'datetime'];

    // May encrypted na account number sa before/after — hindi ito direktang ibinabalik sa browser.
    protected $hidden = ['before', 'after'];
}
