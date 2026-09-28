<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    protected $table = 'br_meetings';

    // Mga status na HINDI na pwedeng mag-schedule ng bagong turn ang orchestrator.
    public const HALTED = ['draft', 'paused', 'stopped', 'completed', 'failed', 'needs_input', 'blocked'];

    protected $fillable = [
        'uuid', 'project_id', 'user_id', 'group_id', 'title', 'objective', 'constraints', 'status', 'phase',
        'cycle', 'max_cycles', 'max_calls', 'calls_used', 'reserved_calls', 'max_total_output_tokens',
        'spend_limit_usd', 'tokens_in', 'tokens_out', 'est_cost_usd', 'unpriced_calls', 'agents_snapshot',
        'brief_snapshot', 'final', 'stop_reason', 'last_error', 'started_at', 'finished_at',
        'question_calls', 'question_tokens_in', 'question_tokens_out', 'question_cost_usd', 'question_unpriced_calls',
    ];

    protected $casts = [
        'agents_snapshot' => 'array',
        'brief_snapshot'  => 'array',
        'final'           => 'array',
        'started_at'      => 'datetime',
        'finished_at'     => 'datetime',
        'spend_limit_usd' => 'float',
        'est_cost_usd'    => 'float',
        'question_cost_usd' => 'float',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function turns(): HasMany
    {
        return $this->hasMany(Turn::class, 'meeting_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'meeting_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'meeting_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class, 'meeting_id');
    }

    /** Snapshot ng isang role sa run na ito (null = hindi kasali sa room). */
    public function member(int $agentId): ?array
    {
        foreach ($this->agents_snapshot ?: [] as $a) {
            if ((int) ($a['agent_id'] ?? 0) === $agentId) return $a;
        }
        return null;
    }

    /** @return array<int, array> mga member ayon sa role type */
    public function membersOfType(string $type): array
    {
        return array_values(array_filter($this->agents_snapshot ?: [], fn ($a) => ($a['role_type'] ?? '') === $type));
    }

    public function moderator(): ?array
    {
        return $this->membersOfType('moderator')[0] ?? null;
    }

    public function memberByHandle(string $handle): ?array
    {
        foreach ($this->agents_snapshot ?: [] as $a) {
            if (strcasecmp((string) ($a['handle'] ?? ''), $handle) === 0) return $a;
        }
        return null;
    }

    /** Ilang model call pa ang pwedeng i-schedule (hindi pa bawas ang reserba para sa final). */
    public function callsRemaining(): int
    {
        return max(0, (int) $this->max_calls - (int) $this->calls_used - (int) $this->reserved_calls);
    }
}
