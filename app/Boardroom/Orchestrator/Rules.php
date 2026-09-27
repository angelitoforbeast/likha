<?php

namespace App\Boardroom\Orchestrator;

use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Turn;

/**
 * Mga transition na pinapayagan ng backend. Ang moderator ay NAGMUMUNGKAHI lang ng susunod na hakbang;
 * dito tinitingnan kung pwede talaga (limit sa call, cycle, at dami ng tanong).
 */
class Rules
{
    public function __construct(private Budget $budget)
    {
    }

    /** Ilang targeted question na ang naitanong sa cycle na ito. */
    public function asksInCycle(Meeting $m): int
    {
        return Turn::where('meeting_id', $m->id)
            ->where('purpose', 'answer')
            ->where('cycle', $m->cycle)
            ->count();
    }

    /** @return array<int, string> mga action na pwedeng piliin ng moderator NGAYON */
    public function allowedActions(Meeting $m): array
    {
        $allowed      = [];
        $contributors = $m->membersOfType('contributor');

        if ($contributors
            && $this->asksInCycle($m) < (int) config('boardroom.limits.max_asks_per_cycle', 4)
            && $this->budget->deny($m, [$contributors[0]], true) === null) {
            $allowed[] = 'ask_agent';
        }

        if ($contributors && $this->budget->deny($m, $contributors, true) === null) {
            $allowed[] = 'request_revision';
        }

        return array_merge($allowed, ['finalize', 'needs_user_input', 'blocked']);
    }
}
