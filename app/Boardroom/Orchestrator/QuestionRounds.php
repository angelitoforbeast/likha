<?php

namespace App\Boardroom\Orchestrator;

use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Message;
use App\Models\Boardroom\Round;
use App\Models\Boardroom\Turn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mga tanong ng user ("question rounds").
 *
 * Ang limit ng meeting (bilang ng call, cycle, token, gastos) ay para LANG sa kusang takbo ng meeting.
 * Kapag ang user ang nagtanong, may SARILING limit ang bawat tanong at hindi ito ibinabawas sa meeting:
 *
 *   - "Sagot lang" (mode=answer): bawat role na na-mention ay sasagot nang isang beses.
 *   - "Pag-usapan" (mode=discuss, hanggang 3 cycle): sagot → review → (baguhin → review) → buod ng moderator.
 *
 * Ang hangganan ng isang round ay ang istruktura nito mismo: hindi hihigit sa max_cycles na ikot.
 */
class QuestionRounds
{
    /**
     * Buksan ang round para sa isang tanong. Tinatawag sa loob ng transaction ng nag-post.
     *
     * @param  array<int, array>  $mentions  mga member na na-mention
     * @param  int  $cycles  0 = sagot lang; 1..3 = pag-usapan, hanggang ganito karaming cycle
     * @return array<int, int> mga turn na kailangang i-dispatch
     */
    public function open(Meeting $m, Message $question, array $mentions, int $cycles): array
    {
        $discuss = $cycles >= 1;

        if ($discuss) {
            // Sa talakayan, ang mga contributor ang sumasagot; ang reviewer at moderator ay may sariling papel.
            $answerers = array_values(array_filter($mentions, fn ($a) => ($a['role_type'] ?? '') === 'contributor'));
            if (! $answerers) {
                $answerers = $m->membersOfType('contributor');
            }
        } else {
            $answerers = array_values($mentions);
        }
        if (! $answerers) {
            return [];
        }

        $round = Round::create([
            'meeting_id' => $m->id,
            'message_id' => $question->id,
            'mode'       => $discuss ? 'discuss' : 'answer',
            'max_cycles' => $discuss ? min(Round::MAX_CYCLES, max(1, $cycles)) : 1,
            'cycle'      => 1,
            'phase'      => 'answers',
            'status'     => 'running',
            'agent_ids'  => array_map(fn ($a) => (int) $a['agent_id'], $answerers),
        ]);

        return $this->answers($m, $round);
    }

    /**
     * Ituloy ang round pagkatapos ng isang turn. Idempotent.
     *
     * @return array<int, int> mga turn na kailangang i-dispatch
     */
    public function advance(int $roundId): array
    {
        return DB::transaction(function () use ($roundId) {
            $round = Round::whereKey($roundId)->lockForUpdate()->first();
            if (! $round || $round->status !== 'running') {
                return [];
            }

            $turns = Turn::where('round_id', $round->id)->get();
            if ($turns->contains(fn (Turn $t) => in_array($t->status, Turn::PENDING, true))) {
                return [];   // hintayin munang matapos ang lahat ng turn ng ikot na ito
            }
            if ($turns->contains(fn (Turn $t) => in_array($t->status, ['failed', 'stopped'], true))) {
                $round->status = 'failed';   // may paalala na sa chat mula sa nabigong turn
                $round->save();

                return [];
            }

            $m   = Meeting::find($round->meeting_id);
            $new = [];

            if ($round->phase === 'answers') {
                $reviewer = $m->membersOfType('reviewer')[0] ?? null;
                if ($round->mode === 'answer') {
                    $round->phase  = 'done';
                    $round->status = 'completed';
                } elseif ($reviewer) {
                    $round->phase = 'review';
                    $new = $this->add($m, $round, $reviewer, 'qreview', "q{$round->id}:c{$round->cycle}:review");
                } else {
                    $new = $this->summary($m, $round);
                }
            } elseif ($round->phase === 'review') {
                $review  = $turns->first(fn (Turn $t) => $t->dedupe_key === "q{$round->id}:c{$round->cycle}:review");
                $verdict = (string) ($review?->outcome['verdict'] ?? 'ok');

                if ($verdict === 'revise' && (int) $round->cycle < (int) $round->max_cycles) {
                    $round->cycle = (int) $round->cycle + 1;
                    $round->phase = 'answers';
                    $new = $this->answers($m, $round);
                } else {
                    $new = $this->summary($m, $round);
                }
            } elseif ($round->phase === 'summary') {
                $round->phase  = 'done';
                $round->status = 'completed';
            }

            $round->save();

            return $new;
        });
    }

    /** @return array<int, int> */
    private function answers(Meeting $m, Round $round): array
    {
        $new = [];
        foreach ((array) $round->agent_ids as $agentId) {
            $member = $m->member((int) $agentId);
            if ($member) {
                $new = array_merge($new, $this->add($m, $round, $member, 'direct', "q{$round->id}:c{$round->cycle}:a{$agentId}"));
            }
        }

        return $new;
    }

    /** @return array<int, int> */
    private function summary(Meeting $m, Round $round): array
    {
        $moderator = $m->moderator();
        if (! $moderator) {
            $round->phase  = 'done';
            $round->status = 'completed';

            return [];
        }
        $round->phase = 'summary';

        return $this->add($m, $round, $moderator, 'qsummary', "q{$round->id}:summary");
    }

    /** @return array<int, int> */
    private function add(Meeting $m, Round $round, array $member, string $purpose, string $key): array
    {
        if (Turn::where('meeting_id', $m->id)->where('dedupe_key', $key)->exists()) {
            return [];
        }

        $turn = Turn::create([
            'uuid'                => (string) Str::uuid(),
            'meeting_id'          => $m->id,
            'round_id'            => $round->id,
            'agent_id'            => (int) $member['agent_id'],
            'purpose'             => $purpose,
            'phase'               => 'question',
            'cycle'               => (int) $round->cycle,
            'seq'                 => 0,
            'dedupe_key'          => $key,
            'status'              => 'queued',
            'reserved'            => false,   // walang reserba sa budget ng meeting
            'provider'            => $member['provider'],
            'model'               => $member['model'],
            'reply_to_message_id' => $round->message_id,
        ]);

        return [$turn->id];
    }
}
