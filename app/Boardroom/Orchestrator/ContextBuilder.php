<?php

namespace App\Boardroom\Orchestrator;

use App\Models\Boardroom\Decision;
use App\Models\Boardroom\Issue;
use App\Models\Boardroom\Knowledge;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Message;
use App\Models\Boardroom\Round;
use App\Models\Boardroom\Turn;

/**
 * Bumubuo ng context na AWTORISADONG makita ng isang role para sa isang turn.
 *
 * - Lahat ng message ay kinukuha gamit ang meeting_id ng turn — walang nababasa mula sa ibang room.
 * - Ang mga proposal ay gumagamit ng IISANG brief snapshot at hindi nakikita ang isa't isa.
 * - Magkakahiwalay ang: approved company knowledge, approved decisions, meeting history, at role instructions.
 * - Text lang ng mga message ang isinasama; walang reasoning object ng provider.
 */
class ContextBuilder
{
    public function __construct(private Rules $rules)
    {
    }

    /** @return array{system: string, input: string, schema: ?array, hash: string, purpose: string} */
    public function build(Meeting $m, Turn $turn): array
    {
        $member  = $m->member((int) $turn->agent_id);
        $members = $m->agents_snapshot ?: [];
        $purpose = $turn->purpose;
        $parent  = null;

        if ($purpose === 'repair') {
            $parent  = Turn::where('meeting_id', $m->id)->find($turn->parent_turn_id);
            $purpose = $parent?->purpose ?? 'route';
        }

        if ($purpose === 'proposal') {
            // Iisang snapshot para sa lahat ng contributor — hindi muling binubuo kada role.
            $input = (string) ($m->brief_snapshot['context'] ?? '') . "\n\n" . Prompts::task('proposal');
        } elseif ($purpose === 'brief') {
            $input = $this->knowledge($m)
                . $this->header($m)
                . $this->participants($m, (int) $turn->agent_id)
                . $this->userInstructions($m)
                . "\n" . Prompts::task('brief');
        } elseif ($turn->isQuestion() || ($parent && $parent->isQuestion())) {
            // Tanong ng user: parehong context ng room, pero malinaw kung ALING tanong ang sinasagot.
            $source   = $parent ?: $turn;
            $round    = $source->round_id ? Round::where('meeting_id', $m->id)->find($source->round_id) : null;
            $question = Message::where('meeting_id', $m->id)->find($round?->message_id ?? $source->reply_to_message_id);
            $ctx      = ['cycle' => max(1, (int) $source->cycle), 'max_cycles' => (int) ($round?->max_cycles ?? 1)];

            $input = $this->knowledge($m)
                . $this->header($m)
                . $this->participants($m, (int) $turn->agent_id)
                . $this->transcript($m)
                . $this->issues($m)
                . "## USER QUESTION\n[#" . ($question?->id ?? 0) . '] ' . $this->clip((string) ($question?->body ?? '')) . "\n\n"
                . Prompts::task($purpose, $ctx);
        } else {
            $ctx = ['cycle' => (int) $m->cycle];
            if ($purpose === 'route') {
                $ctx['allowed'] = $this->rules->allowedActions($m);
            }
            if ($purpose === 'final' && $m->stop_reason) {
                $ctx['early'] = Budget::reasonText((string) $m->stop_reason);
            }

            $input = $this->knowledge($m)
                . $this->header($m)
                . $this->participants($m, (int) $turn->agent_id)
                . $this->transcript($m)
                . $this->issues($m)
                . $this->limits($m)
                . "\n" . Prompts::task($purpose, $ctx);
        }

        if ($parent) {
            $bad = (string) ($parent->request_meta['raw_output'] ?? '');
            $input .= "\n\n" . Prompts::repair($purpose, (string) $parent->error_message, $this->clip($bad, 6000));
        }

        return [
            'system'  => Prompts::system($member, $members),
            'input'   => $input,
            'schema'  => in_array($purpose, Prompts::STRUCTURED, true) ? Prompts::schema($purpose) : null,
            'hash'    => hash('sha256', $input),
            'purpose' => $purpose,
        ];
    }

    /** Ginagawa MINSAN pagkatapos ng brief; ito mismo ang ipapadala sa lahat ng proposal. */
    public function briefSnapshot(Meeting $m, Message $brief): array
    {
        $author  = $m->member((int) $brief->agent_id);
        $context = $this->knowledge($m)
            . $this->header($m)
            . '## BRIEF (by ' . ($author['handle'] ?? 'CEO') . ")\n" . trim($brief->body) . "\n";

        return ['message_id' => $brief->id, 'context' => $context, 'hash' => hash('sha256', $context)];
    }

    private function header(Meeting $m): string
    {
        return "## MEETING\nTitle: {$m->title}\nObjective: " . trim((string) $m->objective) . "\n"
            . 'Constraints: ' . (trim((string) $m->constraints) ?: '(none given)') . "\n\n";
    }

    /** Knowledge at decisions na APRUBADO ng user — ng kumpanya at ng project na ito lang. */
    private function knowledge(Meeting $m): string
    {
        $out = '';

        $rows = Knowledge::where('user_id', $m->user_id)
            ->where('approved', true)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $m->project_id))
            ->orderBy('id')->get();
        if ($rows->isNotEmpty()) {
            $out .= "## APPROVED COMPANY KNOWLEDGE (approved by the user)\n";
            foreach ($rows as $k) {
                $out .= "- {$k->title}: " . $this->clip((string) $k->body, 3000) . "\n";
            }
            $out .= "\n";
        }

        $decisions = Decision::where('project_id', $m->project_id)->where('status', 'approved')->orderBy('id')->get();
        if ($decisions->isNotEmpty()) {
            $out .= "## APPROVED DECISIONS (this project, approved by the user)\n";
            foreach ($decisions as $d) {
                $out .= "- {$d->title}" . ($d->detail ? ': ' . $this->clip((string) $d->detail, 1500) : '') . "\n";
            }
            $out .= "\n";
        }

        return $out;
    }

    private function participants(Meeting $m, int $selfId): string
    {
        $out = "## PARTICIPANTS\n";
        foreach ($m->agents_snapshot ?: [] as $a) {
            $out .= "- agent_id={$a['agent_id']} handle={$a['handle']} role={$a['role_type']}"
                . ((int) $a['agent_id'] === $selfId ? ' (you)' : '') . "\n";
        }

        return $out . "\n";
    }

    private function userInstructions(Meeting $m): string
    {
        $rows = Message::where('meeting_id', $m->id)->where('author_type', 'user')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return '';
        }
        $out = "## USER INSTRUCTIONS\n";
        foreach ($rows as $msg) {
            $out .= "[#{$msg->id}] USER: " . $this->clip((string) $msg->body) . "\n";
        }

        return $out . "\n";
    }

    private function transcript(Meeting $m): string
    {
        $rows = Message::where('meeting_id', $m->id)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return '';
        }

        $codes = Issue::where('meeting_id', $m->id)->pluck('code', 'id');
        $out   = "## TRANSCRIPT (this meeting only)\n";
        foreach ($rows as $msg) {
            $author = match ($msg->author_type) {
                'user'   => 'USER',
                'system' => 'SYSTEM',
                default  => $m->member((int) $msg->agent_id)['handle'] ?? 'AGENT',
            };
            $to   = $msg->recipient_agent_id ? ($m->member((int) $msg->recipient_agent_id)['handle'] ?? 'AGENT') : 'room';
            $tags = [$msg->kind];
            if ($msg->cycle) {
                $tags[] = 'cycle ' . $msg->cycle;
            }
            if ($msg->issue_id && isset($codes[$msg->issue_id])) {
                $tags[] = 'issue ' . $codes[$msg->issue_id];
            }
            if ($msg->reply_to_message_id) {
                $tags[] = 'reply to #' . $msg->reply_to_message_id;
            }
            $out .= "[#{$msg->id}] {$author} -> {$to} (" . implode(', ', $tags) . ")\n" . $this->clip((string) $msg->body) . "\n\n";
        }

        return $out;
    }

    private function issues(Meeting $m): string
    {
        $rows = Issue::where('meeting_id', $m->id)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return "## ISSUES\n(none recorded)\n\n";
        }
        $out = "## ISSUES\n";
        foreach ($rows as $i) {
            $target = $i->assigned_agent_id ? ($m->member((int) $i->assigned_agent_id)['handle'] ?? '-') : '-';
            $out .= "- issue_id={$i->id} ({$i->code}) [{$i->severity}] status={$i->status} target={$target} — {$i->title}"
                . ($i->detail ? ': ' . $this->clip((string) $i->detail, 1200) : '') . "\n";
        }

        return $out . "\n";
    }

    private function limits(Meeting $m): string
    {
        $left = max(0, (int) $m->max_calls - (int) $m->calls_used - (int) $m->reserved_calls);

        return "## LIMITS\n- model calls still unallocated: {$left} of {$m->max_calls} (one is always kept for the final recommendation)\n"
            . "- review/revision cycle: {$m->cycle} of {$m->max_cycles}\n";
    }

    private function clip(string $text, ?int $max = null): string
    {
        $max  = $max ?? (int) config('boardroom.limits.message_chars', 12000);
        $text = trim($text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . "\n[... pinutol dahil sa haba ...]" : $text;
    }
}
