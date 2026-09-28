<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;

/** Isang aral sa playbook ng isang role. */
class Lesson extends Model
{
    protected $table = 'br_lessons';

    public const STATUSES = ['active', 'disabled', 'replaced'];

    protected $fillable = [
        'agent_id', 'user_id', 'project_id', 'meeting_id', 'message_id', 'applies_when', 'rule',
        'status', 'replaced_by_id', 'source',
    ];

    /** Ang aral bilang isang linya ng text. */
    public function line(): string
    {
        $when = trim((string) $this->applies_when);

        return ($when !== '' ? rtrim($when, " .:") . ': ' : '') . trim((string) $this->rule);
    }
}
