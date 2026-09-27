<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;

class Issue extends Model
{
    protected $table = 'br_issues';

    // Lahat maliban sa "resolved" ay itinuturing na hindi pa tapos.
    public const UNRESOLVED = ['open', 'answered', 'unresolved', 'needs_user_input', 'blocked'];

    protected $fillable = [
        'meeting_id', 'code', 'title', 'detail', 'severity', 'status', 'raised_by_agent_id',
        'assigned_agent_id', 'raised_message_id', 'cycle', 'resolution',
    ];
}
