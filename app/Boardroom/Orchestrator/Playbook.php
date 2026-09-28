<?php

namespace App\Boardroom\Orchestrator;

use App\Boardroom\Support\Secrets;
use App\Models\Boardroom\Lesson;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Message;
use Illuminate\Support\Collection;

/**
 * Playbook kada role: mga aral na itinuro ng user. KUSANG nase-save mula sa chat — walang approval step —
 * kaya bawat natutunan ay may nakikitang paalala sa chat at pwedeng i-undo.
 *
 * Hiwalay ito sa: instructions ng role, company knowledge, at history ng meeting.
 * Hindi nito binabago ang model; isinasama lang ang mga aral sa bawat call ng role na iyon.
 */
class Playbook
{
    // Pinakabagong ganito karaming aktibong aral lang ang isinasama sa prompt (para hindi lumobo ang bawat call).
    public const MAX_IN_CONTEXT = 30;

    /** Mga aral na isasama sa prompt ng role para sa meeting na ito. */
    public function forContext(int $agentId, int $userId, ?int $projectId): Collection
    {
        return Lesson::where('agent_id', $agentId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))
            ->orderByDesc('id')
            ->limit(self::MAX_IN_CONTEXT)
            ->get()
            ->sortBy('id')
            ->values();
    }

    /** Ang bahagi ng system prompt para sa playbook ('' kung wala pang aral). */
    public function block(Collection $lessons): string
    {
        if ($lessons->isEmpty()) {
            return '';
        }
        $out = "=== YOUR PLAYBOOK ===\n"
            . "Rules the user taught you. Follow them. They take priority over your general instructions above. "
            . "If two rules conflict, follow the one with the higher id.\n";
        foreach ($lessons as $l) {
            $out .= "- [L{$l->id}] " . $l->line() . "\n";
        }

        return $out;
    }

    /**
     * Kusang i-save ang aral na napansin ng role sa message ng user.
     *
     * @param  array{rule: string, applies_when: ?string, replaces_lesson_id: ?int}  $lesson
     * @return Lesson|null null kapag walang laman o kapareho na ng dati
     */
    public function learn(Meeting $m, array $member, array $lesson, ?int $questionMessageId): ?Lesson
    {
        $agentId = (int) $member['agent_id'];
        $rule    = trim(Secrets::redact((string) ($lesson['rule'] ?? '')));
        $when    = trim(Secrets::redact((string) ($lesson['applies_when'] ?? '')));
        if ($rule === '') {
            return null;
        }

        $mine = Lesson::where('agent_id', $agentId)->where('user_id', $m->user_id)->where('status', 'active');

        // Kapareho na ng isang aktibong aral → walang bagong ise-save.
        $same = (clone $mine)->get()->first(fn (Lesson $l) => $this->norm($l->rule) === $this->norm($rule));
        if ($same) {
            return null;
        }

        // Pinapalitan lang ang aral na SARILI ng role na ito at ng user na ito.
        $replaces = ! empty($lesson['replaces_lesson_id'])
            ? (clone $mine)->whereKey((int) $lesson['replaces_lesson_id'])->first()
            : null;

        $new = Lesson::create([
            'agent_id'     => $agentId,
            'user_id'      => $m->user_id,
            'project_id'   => null,   // default: sa lahat ng project; nababago sa Agents page
            'meeting_id'   => $m->id,
            'message_id'   => $questionMessageId,
            'applies_when' => $when !== '' ? mb_substr($when, 0, 300) : null,
            'rule'         => mb_substr($rule, 0, 1000),
            'status'       => 'active',
            'source'       => 'chat',
        ]);

        if ($replaces) {
            $replaces->forceFill(['status' => 'replaced', 'replaced_by_id' => $new->id])->save();
        }

        // Laging may nakikitang paalala — dahil walang approval step, dito nalalaman ng user kung ano ang natutunan.
        Message::create([
            'meeting_id'          => $m->id,
            'author_type'         => 'system',
            'agent_id'            => $agentId,
            'kind'                => 'lesson',
            'cycle'               => (int) $m->cycle,
            'reply_to_message_id' => $questionMessageId,
            'body'                => "Natutunan ni {$member['handle']}: " . $new->line()
                . ($replaces ? "\n(Pinalitan ang dating aral: " . $replaces->line() . ')' : ''),
            'meta'                => ['lesson_id' => $new->id, 'replaced_lesson_id' => $replaces?->id],
        ]);

        return $new;
    }

    private function norm(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? ''));
    }
}
