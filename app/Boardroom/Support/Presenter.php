<?php

namespace App\Boardroom\Support;

use App\Boardroom\Capabilities\CapabilityRegistry;
use App\Models\Boardroom\Agent;
use App\Models\Boardroom\Decision;
use App\Models\Boardroom\Issue;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Message;
use App\Models\Boardroom\Turn;

/**
 * Ang TANGING daan ng data papunta sa browser. Walang secret na dumadaan dito:
 * ang credential ay laging naka-mask, at ang encrypted value ay hindi kailanman isinasama.
 */
class Presenter
{
    public static function agent(Agent $a, CapabilityRegistry $registry): array
    {
        $credential = $a->credential();
        $resolved   = $registry->resolve($a->provider, (string) $a->model, (array) ($a->settings ?: []));
        $cap        = $resolved['capability'];

        $flags = [];
        if (! $a->enabled) {
            $flags[] = ['level' => 'info', 'text' => 'Naka-disable ang role na ito.'];
        }
        if (! $credential) {
            $flags[] = ['level' => 'error', 'text' => "Walang API key para sa {$a->provider}."];
        }
        if (trim((string) $a->model) === '') {
            $flags[] = ['level' => 'error', 'text' => 'Walang napiling model.'];
        } elseif (! $cap['known']) {
            $flags[] = ['level' => 'warn', 'text' => 'Hindi kilala ang model na ito (manual ID) — UNVERIFIED. Walang advanced parameter na ipapadala hangga\'t hindi ito nailalagay at nave-verify sa registry.'];
        } elseif (! $cap['verified']) {
            $flags[] = ['level' => 'warn', 'text' => 'UNVERIFIED pa ang model na ito sa registry — walang advanced parameter (effort, thinking) na ipapadala.'];
        }
        foreach ($resolved['dropped'] as $d) {
            if (in_array($d['reason'], ['model_unknown', 'model_unverified'], true)) {
                continue;
            }
            $flags[] = ['level' => 'warn', 'text' => self::droppedText($d)];
        }

        return [
            'id'           => $a->id,
            'handle'       => $a->handle,
            'display_name' => $a->display_name,
            'role_type'    => $a->role_type,
            'description'  => (string) $a->description,
            'instructions' => (string) $a->instructions,
            'provider'     => $a->provider,
            'model'        => (string) $a->model,
            'settings'     => (object) ($a->settings ?: []),
            'enabled'      => (bool) $a->enabled,
            'archived'     => $a->archived_at !== null,
            'sort_order'   => (int) $a->sort_order,
            'credential'   => $credential?->masked(),
            'capability'   => $cap,
            'will_send'    => $resolved['applied'],
            'flags'        => $flags,
        ];
    }

    public static function droppedText(array $d): string
    {
        $value = is_scalar($d['value']) ? (string) $d['value'] : json_encode($d['value']);

        return match ($d['reason']) {
            'unsupported_effort'        => "Hindi supported ng model ang effort \"{$value}\" — hindi ito ipapadala.",
            'unsupported_thinking_mode' => "Hindi supported ng model ang thinking mode \"{$value}\" — hindi ito ipapadala.",
            'clamped_to_model_limit'    => "Lampas sa limit ng model ang max output tokens ({$value}) — ibababa sa limit.",
            'thinking_disabled_not_allowed_at_this_effort' => 'Hindi pwede ang thinking=disabled sa napiling effort — hindi ipapadala ang thinking setting.',
            'effort_requires_thinking_enabled'             => "May bisa lang ang effort kapag naka-enable ang thinking — hindi ipapadala ang effort \"{$value}\".",
            'max_output_tokens_too_small_for_thinking_budget' => 'Masyadong maliit ang max output tokens para sa thinking budget — hindi ipapadala ang thinking.',
            default => "Hindi ipapadala ang {$d['key']} ({$d['reason']}).",
        };
    }

    public static function meetingRow(Meeting $m): array
    {
        return [
            'id'         => $m->id,
            'project_id' => $m->project_id,
            'title'      => $m->title,
            'status'     => $m->status,
            'phase'      => $m->phase,
            'updated_at' => optional($m->updated_at)->toIso8601String(),
        ];
    }

    /** Buong estado ng isang meeting para sa chat view. */
    public static function meeting(Meeting $m, int $afterMessageId = 0): array
    {
        $members = [];
        $byId    = [];
        foreach ($m->agents_snapshot ?: [] as $a) {
            $row = [
                'agent_id' => (int) $a['agent_id'], 'handle' => $a['handle'], 'display_name' => $a['display_name'],
                'role_type' => $a['role_type'], 'provider' => $a['provider'], 'model' => $a['model'],
                'settings' => (object) ($a['settings'] ?? []),
            ];
            $members[] = $row;
            $byId[$row['agent_id']] = $row;
        }
        if (! $members) {
            // Draft pa: ipakita ang kasalukuyang membership.
            $ids = \DB::table('br_meeting_agents')->where('meeting_id', $m->id)->orderBy('sort_order')->pluck('agent_id');
            foreach (Agent::whereIn('id', $ids)->get() as $a) {
                $row = [
                    'agent_id' => $a->id, 'handle' => $a->handle, 'display_name' => $a->display_name, 'role_type' => $a->role_type,
                    'provider' => $a->provider, 'model' => $a->model, 'settings' => (object) ($a->settings ?: []),
                ];
                $members[] = $row;
                $byId[$a->id] = $row;
            }
        }

        $codes = Issue::where('meeting_id', $m->id)->pluck('code', 'id');

        $rows = Message::where('meeting_id', $m->id)->where('id', '>', $afterMessageId)->orderBy('id')->get();

        // Kasalukuyang estado ng mga aral na natutunan sa meeting na ito (para sa I-undo / Ibalik sa chat)
        $lessonIds = $rows->where('kind', 'lesson')->map(fn (Message $x) => (int) ($x->meta['lesson_id'] ?? 0))->filter()->all();
        $lessons   = $lessonIds
            ? \App\Models\Boardroom\Lesson::where('user_id', $m->user_id)->whereIn('id', $lessonIds)->pluck('status', 'id')
            : collect();

        $messages = $rows
            ->map(function (Message $msg) use ($byId, $codes, $lessons) {
                $author   = $msg->agent_id ? ($byId[$msg->agent_id] ?? null) : null;
                $lessonId = $msg->kind === 'lesson' ? (int) ($msg->meta['lesson_id'] ?? 0) : 0;

                return [
                    'lesson'              => $lessonId ? ['id' => $lessonId, 'status' => $lessons[$lessonId] ?? 'deleted'] : null,
                    'id'                  => $msg->id,
                    'author_type'         => $msg->author_type,
                    'agent_id'            => $msg->agent_id,
                    'handle'              => $author['handle'] ?? null,
                    'display_name'        => $author['display_name'] ?? null,
                    'role_type'           => $author['role_type'] ?? null,
                    'recipient_handle'    => $msg->recipient_agent_id ? ($byId[$msg->recipient_agent_id]['handle'] ?? null) : null,
                    'reply_to_message_id' => $msg->reply_to_message_id,
                    'issue_code'          => $msg->issue_id ? ($codes[$msg->issue_id] ?? null) : null,
                    'kind'                => $msg->kind,
                    'cycle'               => (int) $msg->cycle,
                    'body'                => (string) $msg->body,
                    'provider'            => $msg->provider,
                    'model'               => $msg->model,
                    'truncated'           => (bool) ($msg->meta['truncated'] ?? false),
                    'repaired'            => (bool) ($msg->meta['repaired'] ?? false),
                    'status'              => 'completed',
                    'created_at'          => optional($msg->created_at)->toIso8601String(),
                ];
            })->values()->all();

        // Mga turn na wala pang message: queued / generating / paused / failed / stopped.
        $turns   = Turn::where('meeting_id', $m->id)->orderBy('id')->get();
        $pending = [];
        $usage   = [];
        foreach ($turns as $t) {
            $who = $byId[$t->agent_id] ?? ['handle' => 'AGENT', 'display_name' => 'Agent', 'role_type' => null];

            $u = &$usage[$t->agent_id];
            $u ??= [
                'agent_id' => (int) $t->agent_id, 'handle' => $who['handle'], 'provider' => $t->provider, 'model' => $t->model,
                'calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'tokens_reasoning' => 0, 'est_cost_usd' => 0.0, 'cost_known' => true,
            ];
            $u['calls']            += (int) $t->requests;
            $u['tokens_in']        += (int) $t->tokens_in;
            $u['tokens_out']       += (int) $t->tokens_out;
            $u['tokens_reasoning'] += (int) $t->tokens_reasoning;
            if ($t->est_cost_usd === null) {
                if ((int) $t->tokens_in + (int) $t->tokens_out > 0) {
                    $u['cost_known'] = false;
                }
            } else {
                $u['est_cost_usd'] = round($u['est_cost_usd'] + (float) $t->est_cost_usd, 6);
            }
            unset($u);

            $superseded = $t->status === 'failed' && $t->error_code === 'invalid_structured_output'
                && $turns->contains(fn ($c) => $c->parent_turn_id === $t->id && in_array($c->status, ['queued', 'generating', 'paused', 'completed'], true));

            // Ang nabigong turn ng tanong ng user ay may paalala na sa chat at hindi nire-retry — huwag nang ipakita ulit.
            $shown = $t->isQuestion() ? ['queued', 'generating', 'paused'] : ['queued', 'generating', 'paused', 'failed'];

            if (in_array($t->status, $shown, true) && ! $superseded) {
                $pending[] = [
                    'turn_id'       => $t->id,
                    'agent_id'      => (int) $t->agent_id,
                    'handle'        => $who['handle'],
                    'display_name'  => $who['display_name'],
                    'role_type'     => $who['role_type'],
                    'purpose'       => $t->purpose,
                    'status'        => $t->status,
                    'provider'      => $t->provider,
                    'model'         => $t->model,
                    'error_code'    => $t->error_code,
                    'error_message' => $t->error_message,
                    'retryable'     => (bool) $t->retryable,
                    'attempts'      => (int) $t->attempts,
                    'started_at'    => optional($t->started_at)->toIso8601String(),
                ];
            }
        }

        return [
            'meeting' => [
                'id'                      => $m->id,
                'project_id'              => $m->project_id,
                'title'                   => $m->title,
                'objective'               => $m->objective,
                'constraints'             => (string) $m->constraints,
                'status'                  => $m->status,
                'phase'                   => $m->phase,
                'cycle'                   => (int) $m->cycle,
                'max_cycles'              => (int) $m->max_cycles,
                'max_calls'               => (int) $m->max_calls,
                'calls_used'              => (int) $m->calls_used,
                'reserved_calls'          => (int) $m->reserved_calls,
                'max_total_output_tokens' => $m->max_total_output_tokens,
                'spend_limit_usd'         => $m->spend_limit_usd,
                'tokens_in'               => (int) $m->tokens_in,
                'tokens_out'              => (int) $m->tokens_out,
                'est_cost_usd'            => (float) $m->est_cost_usd,
                'unpriced_calls'          => (int) $m->unpriced_calls,
                // Mga tanong ng user: hiwalay na bilang, hindi kasama sa mga limit ng meeting
                'question_calls'          => (int) $m->question_calls,
                'question_tokens_in'      => (int) $m->question_tokens_in,
                'question_tokens_out'     => (int) $m->question_tokens_out,
                'question_cost_usd'       => (float) $m->question_cost_usd,
                'question_unpriced_calls' => (int) $m->question_unpriced_calls,
                'stop_reason'             => $m->stop_reason,
                'last_error'              => $m->last_error,
                'final'                   => $m->final,
                'started_at'              => optional($m->started_at)->toIso8601String(),
                'finished_at'             => optional($m->finished_at)->toIso8601String(),
            ],
            'members'   => $members,
            'messages'  => $messages,
            'pending'   => $pending,
            'issues'    => Issue::where('meeting_id', $m->id)->orderBy('id')->get()->map(fn (Issue $i) => [
                'id' => $i->id, 'code' => $i->code, 'title' => $i->title, 'detail' => (string) $i->detail,
                'severity' => $i->severity, 'status' => $i->status, 'cycle' => (int) $i->cycle,
                'target' => $i->assigned_agent_id ? ($byId[$i->assigned_agent_id]['handle'] ?? null) : null,
                'resolution' => (string) $i->resolution,
            ])->values()->all(),
            'decisions' => Decision::where('meeting_id', $m->id)->orderBy('id')->get()->map(fn (Decision $d) => [
                'id' => $d->id, 'title' => $d->title, 'detail' => (string) $d->detail, 'status' => $d->status,
                'decided_at' => optional($d->decided_at)->toIso8601String(),
            ])->values()->all(),
            'usage'     => array_values($usage),
        ];
    }
}
