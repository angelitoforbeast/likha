<?php

namespace App\Boardroom\Orchestrator;

use App\Boardroom\Support\Secrets;
use App\Jobs\Boardroom\RunTurnJob;
use App\Models\Boardroom\Agent;
use App\Models\Boardroom\Credential;
use App\Models\Boardroom\Issue;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Message;
use App\Models\Boardroom\Project;
use App\Models\Boardroom\Round;
use App\Models\Boardroom\Turn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ang orchestrator ng meeting — nasa backend, hindi sa model.
 *
 * Ito ang nagpapasya kung sino ang susunod, nagrereserba ng budget, at nagpapatupad ng mga limit.
 * Bawat turn ay may unique na dedupe key kada meeting, kaya ang refresh, retry, o dobleng job ay
 * hindi nakakagawa ng dobleng turn. Ang advance() ay idempotent at naka-lock kada meeting.
 *
 * Daloy: brief → proposals (parallel, iisang brief snapshot) → review → discussion → revision → final.
 */
class MeetingOrchestrator
{
    public function __construct(
        private Budget $budget,
        private ContextBuilder $context,
        private QuestionRounds $rounds,
    ) {
    }

    // ═════════════════════════ Mga aksyon ng user ═════════════════════════

    public function create(int $userId, array $data): Meeting
    {
        $project = Project::where('user_id', $userId)->whereNull('archived_at')->find($data['project_id'] ?? 0);
        if (! $project) {
            throw ValidationException::withMessages(['project_id' => 'Hindi makita ang project.']);
        }

        $agents = Agent::active()->whereIn('id', (array) ($data['agent_ids'] ?? []))->orderBy('sort_order')->orderBy('id')->get();
        $this->assertComposition($agents);

        $contributors = $agents->where('role_type', 'contributor')->count();
        $hasReviewer  = $agents->where('role_type', 'reviewer')->isNotEmpty();
        $minCalls     = 3 + $contributors + ($hasReviewer ? 1 : 0);   // brief + proposals + review + 1 routing + final

        $maxCalls = (int) ($data['max_calls'] ?? config('boardroom.limits.max_calls', 16));
        if ($maxCalls < $minCalls) {
            throw ValidationException::withMessages(['max_calls' => "Kailangan ng hindi bababa sa {$minCalls} model call para sa mga napiling role."]);
        }

        $spend = isset($data['spend_limit_usd']) && $data['spend_limit_usd'] !== '' ? (float) $data['spend_limit_usd'] : null;
        if ($spend !== null) {
            foreach ($agents as $a) {
                if ($this->budget->worstCost($a->snapshot()) === null) {
                    throw ValidationException::withMessages(['spend_limit_usd' =>
                        "Hindi maipatupad ang spending limit: walang alam na presyo ang model na {$a->model} ({$a->handle}). "
                        . 'Ilagay ang presyo sa model registry o alisin ang spending limit.']);
                }
            }
        }

        return DB::transaction(function () use ($userId, $data, $project, $agents, $maxCalls, $spend) {
            $m = Meeting::create([
                'uuid'                    => (string) Str::uuid(),
                'project_id'              => $project->id,
                'user_id'                 => $userId,
                'group_id'                => $data['group_id'] ?? null,
                'title'                   => trim((string) $data['title']),
                'objective'               => trim((string) $data['objective']),
                'constraints'             => trim((string) ($data['constraints'] ?? '')) ?: null,
                'status'                  => 'draft',
                'phase'                   => 'brief',
                'max_cycles'              => max(1, min(3, (int) ($data['max_cycles'] ?? config('boardroom.limits.max_cycles', 3)))),
                'max_calls'               => $maxCalls,
                'max_total_output_tokens' => ! empty($data['max_total_output_tokens']) ? (int) $data['max_total_output_tokens'] : null,
                'spend_limit_usd'         => $spend,
            ]);

            foreach ($agents->values() as $i => $a) {
                DB::table('br_meeting_agents')->insert([
                    'meeting_id' => $m->id, 'agent_id' => $a->id, 'role_type' => $a->role_type, 'sort_order' => $i + 1,
                ]);
            }

            return $m;
        });
    }

    /**
     * Simulan ang meeting. Idempotent: ang pag-refresh o pag-ulit ng request ay HINDI nagre-restart.
     *
     * @return array{ok: bool, errors: array<int, string>}
     */
    public function start(Meeting $m): array
    {
        if ($m->status !== 'draft') {
            return ['ok' => true, 'errors' => []];
        }

        $agents = $this->memberAgents($m);
        $errors = $this->readiness($agents);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        $snapshot = $agents->map(fn (Agent $a) => $a->snapshot())->values()->all();

        $started = Meeting::whereKey($m->id)->where('status', 'draft')->update([
            'status'          => 'running',
            'phase'           => 'brief',
            'agents_snapshot' => json_encode($snapshot),
            'started_at'      => now(),
            'updated_at'      => now(),
        ]);
        if ($started) {
            $this->advance($m->id);
        }

        return ['ok' => true, 'errors' => []];
    }

    public function pause(Meeting $m): void
    {
        Meeting::whereKey($m->id)->where('status', 'running')->update(['status' => 'paused', 'updated_at' => now()]);
        // Ang mga turn na naka-queue ay hindi na tatawag ng model: ipa-park sila ng RunTurnJob bilang "paused".
    }

    public function resume(Meeting $m): void
    {
        $ids = DB::transaction(function () use ($m) {
            $locked = Meeting::whereKey($m->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'paused') {
                return [];
            }
            $locked->status = 'running';
            $locked->save();

            return $this->requeue($locked->id, ['paused']);
        });

        $this->dispatch($ids);
        $this->advance($m->id);
    }

    public function stop(Meeting $m): void
    {
        DB::transaction(function () use ($m) {
            $locked = Meeting::whereKey($m->id)->lockForUpdate()->first();
            if (! $locked || in_array($locked->status, ['stopped', 'completed', 'blocked'], true)) {
                return;
            }
            $locked->status      = 'stopped';
            $locked->stop_reason = 'user';
            $locked->finished_at = now();

            Turn::where('meeting_id', $locked->id)->whereIn('status', ['queued', 'paused'])
                ->update(['status' => 'stopped', 'reserved' => false, 'finished_at' => now(), 'updated_at' => now()]);
            Round::where('meeting_id', $locked->id)->where('status', 'running')->update(['status' => 'stopped', 'updated_at' => now()]);
            // Ang natitirang reserba ay para na lang sa mga turn na kasalukuyang tumatakbo.
            $locked->reserved_calls = Turn::where('meeting_id', $locked->id)->where('status', 'generating')->where('reserved', true)->count();
            $locked->save();

            $this->notice($locked, 'Itinigil ng user ang meeting. ' . $this->unresolvedText($locked));
        });
    }

    /** Ulitin ang mga nabigong turn (parehong turn row — walang bagong turn na nagagawa). */
    public function retry(Meeting $m): void
    {
        $ids = DB::transaction(function () use ($m) {
            $locked = Meeting::whereKey($m->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'failed') {
                return [];
            }

            $failed = Turn::where('meeting_id', $locked->id)->where('status', 'failed')
                ->where('purpose', '!=', 'direct')->whereNull('round_id')->whereNull('parent_turn_id')->get();

            // I-refresh ang config ng mga role na nabigo (hal. pinalitan ang model pagkatapos ng error).
            $this->refreshSnapshot($locked, $failed->pluck('agent_id')->unique()->all());

            foreach ($failed as $t) {
                // Ang lumang repair ng turn na ito ay hindi na gagamitin; gagawa ulit kung kailangan.
                Turn::where('parent_turn_id', $t->id)->whereIn('status', ['failed', 'queued', 'paused'])
                    ->update(['status' => 'stopped', 'updated_at' => now()]);
                $member = $locked->member((int) $t->agent_id);
                $t->forceFill([
                    'status' => 'queued', 'error_code' => null, 'error_message' => null, 'retryable' => false,
                    'finished_at' => null, 'provider' => $member['provider'] ?? $t->provider, 'model' => $member['model'] ?? $t->model,
                ])->save();
            }

            $locked->status     = 'running';
            $locked->last_error = null;
            $locked->save();

            return $this->requeue($locked->id, ['paused', 'queued']);
        });

        $this->dispatch($ids);
        $this->advance($m->id);
    }

    /**
     * Message ng user. Ang tanong ng user ay may SARILING limit at hindi ibinabawas sa limit ng meeting,
     * kaya makakasagot ang mga role kahit ubos na ang model calls ng meeting o tapos na ito.
     *
     * @param  int  $cycles  0 = "Sagot lang": sasagot nang tig-isang beses ang bawat na-mention.
     *                       1..3 = "Pag-usapan": sagot → review → baguhin, hanggang ganito karaming cycle, tapos buod.
     * @return array{message: Message, notes: array<int, string>}
     */
    public function postUserMessage(Meeting $m, int $userId, string $body, int $cycles = 0): array
    {
        $notes  = [];
        $new    = [];
        $cycles = max(0, min(Round::MAX_CYCLES, $cycles));

        $message = DB::transaction(function () use ($m, $userId, $body, $cycles, &$notes, &$new) {
            $locked   = Meeting::whereKey($m->id)->lockForUpdate()->first();
            $mentions = $this->mentions($locked, $body);

            $msg = Message::create([
                'meeting_id'         => $locked->id,
                'author_type'        => 'user',
                'user_id'            => $userId,
                'recipient_agent_id' => $mentions[0]['agent_id'] ?? null,
                'kind'               => 'instruction',
                'cycle'              => (int) $locked->cycle,
                'body'               => $body,
                'meta'               => $cycles > 0 ? ['discuss_cycles' => $cycles] : null,
            ]);

            if ($mentions || $cycles > 0) {
                if ($locked->status === 'draft') {
                    $notes[] = 'Hindi pa nagsisimula ang meeting — isasama ang message na ito sa brief.';
                } else {
                    $new = $this->rounds->open($locked, $msg, $mentions, $cycles);
                    if ($new && $locked->status === 'paused') {
                        $notes[] = 'Naka-pause ang meeting — sasagot sila pagka-Resume.';
                    }
                }
            }

            // Naghihintay ang meeting sa sagot ng user → ituloy.
            if ($locked->status === 'needs_input') {
                $locked->status = 'running';
            }
            $locked->save();

            foreach ($notes as $note) {
                $this->notice($locked, $note);
            }

            return $msg;
        });

        $this->dispatch($new);
        $this->advance($m->id);

        return ['message' => $message, 'notes' => $notes];
    }

    /** Turn na "generating" pa rin kahit lampas na sa inaasahang tagal = namatay ang worker. */
    public function recoverStale(Meeting $m): void
    {
        $retries = (int) config('boardroom.retry.max_retries', 2);
        $backoff = array_sum(array_slice((array) config('boardroom.retry.backoff_ms', []), 0, $retries)) / 1000;
        $grace   = (int) config('boardroom.stale_grace_s', 90);

        $turns = Turn::where('meeting_id', $m->id)->where('status', 'generating')->get();
        foreach ($turns as $t) {
            $member  = $m->member((int) $t->agent_id);
            $timeout = (int) ($member['settings']['timeout_s'] ?? config('boardroom.limits.timeout_s', 240));
            $limit   = ($timeout + 15) * ($retries + 1) + $backoff + $grace;
            if ($t->started_at && $t->started_at->diffInSeconds(now()) > $limit) {
                $this->failTurn($t, 'stale_worker', 'Hindi natapos ang turn (naputol ang worker). Pwedeng i-retry.', true, (int) $t->attempts);
            }
        }
    }

    // ═════════════════════════ Engine ═════════════════════════

    /** Tingnan ang estado ng meeting at i-schedule ang susunod na turn kung pwede. Idempotent. */
    public function advance(int $meetingId): void
    {
        $ids = DB::transaction(function () use ($meetingId) {
            $m = Meeting::whereKey($meetingId)->lockForUpdate()->first();
            if (! $m || $m->status !== 'running') {
                return [];
            }

            $new = [];
            for ($i = 0; $i < 12; $i++) {
                if (! $this->step($m, $new)) {
                    break;
                }
            }
            $m->save();

            return $new;
        });

        $this->dispatch($ids);
    }

    /** @param array<int, int> $turnIds */
    public function dispatch(array $turnIds): void
    {
        foreach ($turnIds as $id) {
            RunTurnJob::dispatch((int) $id)->onQueue((string) config('boardroom.queue', 'default'));
        }
    }

    /** Nabigong turn. Para sa phase turn, humihinto ang meeting hanggang i-retry o i-stop ng user. */
    public function failTurn(Turn $turn, string $code, string $message, bool $retryable, ?int $attempt = null): void
    {
        DB::transaction(function () use ($turn, $code, $message, $retryable, $attempt) {
            $q = Turn::whereKey($turn->id)->where('status', 'generating');
            if ($attempt !== null) {
                $q->where('attempts', $attempt);
            }
            $safe = Secrets::safeMessage($message);
            if (! $q->update([
                'status' => 'failed', 'error_code' => $code, 'error_message' => $safe,
                'retryable' => $retryable, 'finished_at' => now(), 'updated_at' => now(),
            ])) {
                return;
            }

            $m      = Meeting::whereKey($turn->meeting_id)->lockForUpdate()->first();
            $handle = $m->member((int) $turn->agent_id)['handle'] ?? 'AGENT';

            if ($turn->isQuestion()) {
                // Tanong ng user: hindi nito binabago ang status ng meeting. Tapos na lang ang round.
                $this->release($m, $turn->fresh());
                if ($turn->round_id) {
                    Round::whereKey($turn->round_id)->where('status', 'running')->update(['status' => 'failed', 'updated_at' => now()]);
                }
                $this->notice($m, "Hindi nakasagot si {$handle} ({$code}): {$safe}");
            } elseif ($m->status === 'running') {
                $m->status     = 'failed';
                $m->last_error = mb_substr("{$handle} ({$turn->purpose}) — {$code}: {$safe}", 0, 500);
            }
            $m->save();
        });
    }

    /**
     * Hindi valid ang structured output. Isang repair call lang ang pinapayagan (binibilang sa limit).
     *
     * @return array<int, int> mga turn na kailangang i-dispatch
     */
    public function rejectStructured(Turn $turn, int $attempt, string $error, string $raw): array
    {
        return DB::transaction(function () use ($turn, $attempt, $error, $raw) {
            $meta = (array) $turn->request_meta;
            $meta['raw_output'] = mb_substr(Secrets::redact($raw), 0, 20000);

            $updated = Turn::whereKey($turn->id)->where('status', 'generating')->where('attempts', $attempt)->update([
                'status' => 'failed', 'error_code' => 'invalid_structured_output', 'error_message' => Secrets::safeMessage($error),
                'retryable' => false, 'request_meta' => json_encode($meta), 'finished_at' => now(), 'updated_at' => now(),
            ]);
            if (! $updated) {
                return [];
            }

            $m      = Meeting::whereKey($turn->meeting_id)->lockForUpdate()->first();
            $member = $m->member((int) $turn->agent_id);
            $handle = $member['handle'] ?? 'AGENT';
            $new    = [];

            // Tanong ng user: isang repair din lang, pero hindi ito dumadaan sa budget ng meeting.
            if ($turn->round_id) {
                if ($turn->purpose !== 'repair') {
                    $key    = "repair:{$turn->id}";
                    $repair = Turn::where('meeting_id', $m->id)->where('dedupe_key', $key)->first();
                    if (! $repair) {
                        $repair = Turn::create([
                            'uuid' => (string) Str::uuid(), 'meeting_id' => $m->id, 'round_id' => $turn->round_id,
                            'agent_id' => (int) $turn->agent_id, 'purpose' => 'repair', 'phase' => $turn->phase,
                            'cycle' => $turn->cycle, 'seq' => 0, 'dedupe_key' => $key, 'status' => 'queued', 'reserved' => false,
                            'parent_turn_id' => $turn->id, 'reply_to_message_id' => $turn->reply_to_message_id,
                            'provider' => $turn->provider, 'model' => $turn->model,
                        ]);

                        return [$repair->id];
                    }
                }
                Round::whereKey($turn->round_id)->where('status', 'running')->update(['status' => 'failed', 'updated_at' => now()]);
                $this->notice($m, "Hindi natapos ang sagot ni {$handle}: hindi valid ang structured output kahit matapos ang isang repair.");

                return [];
            }

            if ($turn->purpose !== 'repair' && $m->status === 'running') {
                $isFinal = $turn->purpose === 'final';
                $deny    = $this->budget->deny($m, [$member], ! $isFinal && $this->budget->reservesFinal($m));
                if ($deny === null) {
                    $key    = "repair:{$turn->id}";
                    $repair = Turn::where('meeting_id', $m->id)->where('dedupe_key', $key)->first();
                    if ($repair) {
                        $repair->forceFill([
                            'status' => 'queued', 'reserved' => true, 'error_code' => null, 'error_message' => null, 'finished_at' => null,
                        ])->save();
                        $m->reserved_calls++;
                    } else {
                        $repair = $this->createTurn($m, $member, 'repair', $key, [
                            'parent_turn_id' => $turn->id, 'phase' => $turn->phase, 'cycle' => $turn->cycle, 'seq' => $turn->seq,
                            'issue_id' => $turn->issue_id, 'reply_to_message_id' => $turn->reply_to_message_id,
                        ]);
                    }
                    $new[] = $repair->id;
                    $m->save();

                    return $new;
                }
                $error .= ' (Walang natitirang budget para sa repair: ' . Budget::reasonText($deny) . '.)';
            }

            if ($m->status === 'running') {
                $m->status     = 'failed';
                $m->last_error = mb_substr("{$handle} ({$turn->purpose}) — hindi valid ang structured output: " . Secrets::safeMessage($error), 0, 500);
            }
            $m->save();

            return [];
        });
    }

    /** Tinanggihan ng budget ang isang request sa loob ng turn (hal. retry na wala nang natitirang call). */
    public function limitReached(Turn $turn, int $attempt, string $reason): void
    {
        DB::transaction(function () use ($turn, $attempt, $reason) {
            $updated = Turn::whereKey($turn->id)->where('status', 'generating')->where('attempts', $attempt)->update([
                'status' => 'stopped', 'error_code' => 'limit', 'error_message' => Budget::reasonText($reason),
                'finished_at' => now(), 'updated_at' => now(),
            ]);
            if (! $updated) {
                return;
            }
            $m = Meeting::whereKey($turn->meeting_id)->lockForUpdate()->first();
            $this->release($m, $turn->fresh());

            if ($turn->purpose === 'direct') {
                $this->notice($m, 'Hindi na naituloy ang direktang tanong: ' . Budget::reasonText($reason) . '.');
            } elseif ($m->status === 'running') {
                $this->limitHit($m, $reason, $turn->purpose === 'final' || $turn->purpose === 'repair' && $m->phase === 'final');
            }
            $m->save();
        });

        $this->advance((int) $turn->meeting_id);
    }

    /** Bawiin ang nakareserbang call ng isang turn na hindi na tatawag ng model. */
    public function release(Meeting $m, Turn $turn): void
    {
        if ($turn->reserved) {
            $turn->forceFill(['reserved' => false])->save();
            $m->reserved_calls = max(0, (int) $m->reserved_calls - 1);
        }
    }

    // ───────────────────────── Mga hakbang kada phase ─────────────────────────

    /** @return bool true = nagbago ang phase at kailangang suriin ulit */
    private function step(Meeting $m, array &$new): bool
    {
        if ($m->status !== 'running') {
            return false;
        }

        // Hintayin munang matapos ang lahat ng phase turn (kasama ang parallel na proposals at repair).
        // Hindi kasama ang mga turn ng tanong ng user: hindi nila hinaharang ang takbo ng meeting.
        $pending = Turn::where('meeting_id', $m->id)->whereNull('round_id')->where('purpose', '!=', 'direct')
            ->whereIn('status', Turn::PENDING)->exists();
        if ($pending) {
            return false;
        }

        return match ($m->phase) {
            'brief'      => $this->stepBrief($m, $new),
            'proposals'  => $this->stepProposals($m, $new),
            'review'     => $this->stepReview($m, $new),
            'discussion' => $this->stepDiscussion($m, $new),
            'revision'   => $this->stepRevision($m, $new),
            'final'      => $this->stepFinal($m, $new),
            default      => false,
        };
    }

    private function stepBrief(Meeting $m, array &$new): bool
    {
        $turn = $this->turn($m, 'brief');
        if (! $turn) {
            $this->plan($m, [['member' => $m->moderator(), 'purpose' => 'brief', 'key' => 'brief']], $new);

            // Kapag tinanggihan ng limit ang brief mismo, lumipat na ang phase — suriin ulit para hindi maiwang "running".
            return $m->phase !== 'brief';
        }
        if (! $this->done($m, $turn)) {
            return false;
        }

        $brief = $this->messageOf($turn);
        $m->brief_snapshot = $this->context->briefSnapshot($m, $brief);   // iisang snapshot para sa lahat ng proposal
        $m->phase = 'proposals';

        return true;
    }

    private function stepProposals(Meeting $m, array &$new): bool
    {
        $items = [];
        foreach ($m->membersOfType('contributor') as $member) {
            $key  = "proposal:{$member['agent_id']}";
            $turn = $this->turn($m, $key);
            if (! $turn) {
                $items[] = ['member' => $member, 'purpose' => 'proposal', 'key' => $key];
            } elseif (! $this->done($m, $turn)) {
                return false;
            }
        }
        if ($items) {
            $this->plan($m, $items, $new);   // sabay-sabay: nirereserba muna ang budget para sa lahat

            return $m->phase !== 'proposals';
        }

        $m->cycle = 1;
        $m->phase = $m->membersOfType('reviewer') ? 'review' : 'discussion';

        return true;
    }

    private function stepReview(Meeting $m, array &$new): bool
    {
        $reviewer = $m->membersOfType('reviewer')[0] ?? null;
        if (! $reviewer) {
            $m->phase = 'discussion';

            return true;
        }

        $key  = "review:c{$m->cycle}";
        $turn = $this->turn($m, $key);
        if (! $turn) {
            $this->plan($m, [['member' => $reviewer, 'purpose' => 'review', 'key' => $key]], $new);

            return $m->phase !== 'review';
        }
        if (! $this->done($m, $turn)) {
            return false;
        }

        $open = Issue::where('meeting_id', $m->id)->whereIn('status', Issue::UNRESOLVED)->exists();
        if (($turn->outcome['verdict'] ?? '') === 'approve' && ! $open) {
            $m->phase = 'final';
        } else {
            $m->phase = 'discussion';
        }

        return true;
    }

    private function stepDiscussion(Meeting $m, array &$new): bool
    {
        $moderator = $m->moderator();
        $last = Turn::where('meeting_id', $m->id)->where('purpose', 'route')->where('cycle', $m->cycle)->orderByDesc('seq')->first();

        if (! $last) {
            $this->plan($m, [['member' => $moderator, 'purpose' => 'route', 'key' => "route:c{$m->cycle}:n1", 'attrs' => ['seq' => 1]]], $new);

            return $m->phase !== 'discussion';
        }
        if (! $this->done($m, $last)) {
            return false;
        }

        $decision = (array) $last->outcome;
        $nextRoute = fn () => [[
            'member' => $moderator, 'purpose' => 'route',
            'key' => "route:c{$m->cycle}:n" . ($last->seq + 1), 'attrs' => ['seq' => $last->seq + 1],
        ]];

        switch ($decision['action'] ?? '') {
            case 'ask_agent':
                $key    = "answer:c{$m->cycle}:n{$last->seq}";
                $answer = $this->turn($m, $key);
                if (! $answer) {
                    $question = $this->messageOf($last);
                    $this->plan($m, [[
                        'member'  => $m->member((int) $decision['recipient_agent_id']),
                        'purpose' => 'answer',
                        'key'     => $key,
                        'attrs'   => ['seq' => $last->seq, 'issue_id' => $decision['issue_id'] ?? null, 'reply_to_message_id' => $question?->id],
                    ]], $new);

                    return $m->phase !== 'discussion';
                }
                if (! $this->done($m, $answer)) {
                    return false;
                }
                $this->plan($m, $nextRoute(), $new);

                return $m->phase !== 'discussion';

            case 'request_revision':
                $m->phase = 'revision';

                return true;

            case 'needs_user_input':
                $question = $this->messageOf($last);
                $answered = $question && Message::where('meeting_id', $m->id)->where('author_type', 'user')->where('id', '>', $question->id)->exists();
                if (! $answered) {
                    $m->status = 'needs_input';

                    return false;
                }
                $this->plan($m, $nextRoute(), $new);

                return $m->phase !== 'discussion';

            case 'blocked':
                $m->stop_reason = 'blocked';
                $m->phase = 'final';

                return true;

            default:   // finalize
                $m->phase = 'final';

                return true;
        }
    }

    private function stepRevision(Meeting $m, array &$new): bool
    {
        $route = Turn::where('meeting_id', $m->id)->where('purpose', 'route')->where('cycle', $m->cycle)
            ->where('status', 'completed')->orderByDesc('seq')->first();
        $decision = (array) ($route?->outcome);
        $target  = (int) ($decision['recipient_agent_id'] ?? 0);
        $targets = $target && $m->member($target) ? [$m->member($target)] : $m->membersOfType('contributor');

        $items = [];
        foreach ($targets as $member) {
            $key  = "revision:c{$m->cycle}:{$member['agent_id']}";
            $turn = $this->turn($m, $key);
            if (! $turn) {
                $items[] = ['member' => $member, 'purpose' => 'revision', 'key' => $key, 'attrs' => ['reply_to_message_id' => $this->messageOf($route)?->id]];
            } elseif (! $this->done($m, $turn)) {
                return false;
            }
        }
        if ($items) {
            $this->plan($m, $items, $new);

            return $m->phase !== 'revision';
        }

        $reviewer = $m->membersOfType('reviewer')[0] ?? null;
        if (! $reviewer) {
            $m->phase = 'final';

            return true;
        }
        if ((int) $m->cycle >= (int) $m->max_cycles) {
            $m->stop_reason = 'cycle_limit';
            $this->notice($m, 'Naabot na ang pinakamaraming review/revision cycle. Didiretso na sa final recommendation.');
            $m->phase = 'final';

            return true;
        }
        $deny = $this->budget->deny($m, [$reviewer], true);
        if ($deny) {
            $this->limitHit($m, $deny, false);

            return true;
        }

        $m->cycle = (int) $m->cycle + 1;
        $m->phase = 'review';

        return true;
    }

    private function stepFinal(Meeting $m, array &$new): bool
    {
        $turn = $this->turn($m, 'final');
        if (! $turn) {
            $this->plan($m, [['member' => $m->moderator(), 'purpose' => 'final', 'key' => 'final']], $new, true);

            return false;
        }
        if (! $this->done($m, $turn)) {
            return false;
        }

        $m->status      = ($turn->outcome['status'] ?? '') === 'blocked' ? 'blocked' : 'completed';
        $m->phase       = 'done';
        $m->finished_at = now();

        return false;
    }

    // ───────────────────────── Mga katulong ─────────────────────────

    /**
     * I-schedule ang mga turn — nirereserba muna ang budget para sa LAHAT bago gumawa ng kahit isa.
     *
     * @param array<int, array{member: array, purpose: string, key: string, attrs?: array}> $items
     */
    private function plan(Meeting $m, array $items, array &$new, bool $isFinal = false): bool
    {
        $items = array_values(array_filter($items, fn ($i) => $i['member'] && ! $this->turn($m, $i['key'])));
        if (! $items) {
            return true;
        }

        $deny = $this->budget->deny($m, array_column($items, 'member'), ! $isFinal && $this->budget->reservesFinal($m));
        if ($deny) {
            $this->limitHit($m, $deny, $isFinal);

            return false;
        }

        foreach ($items as $item) {
            $turn = $this->createTurn($m, $item['member'], $item['purpose'], $item['key'], $item['attrs'] ?? []);
            if ($turn) {
                $new[] = $turn->id;
            }
        }

        return true;
    }

    /** Gumawa ng turn na may nakareserbang call. Ang unique na dedupe key ang pumipigil sa doble. */
    private function createTurn(Meeting $m, array $member, string $purpose, string $key, array $attrs = []): ?Turn
    {
        if ($this->turn($m, $key)) {
            return null;
        }

        $turn = Turn::create(array_merge([
            'uuid'       => (string) Str::uuid(),
            'meeting_id' => $m->id,
            'agent_id'   => (int) $member['agent_id'],
            'purpose'    => $purpose,
            'phase'      => $m->phase,
            'cycle'      => (int) $m->cycle,
            'seq'        => 0,
            'dedupe_key' => $key,
            'status'     => 'queued',
            'reserved'   => true,
            'provider'   => $member['provider'],
            'model'      => $member['model'],
        ], $attrs));

        $m->reserved_calls = (int) $m->reserved_calls + 1;

        return $turn;
    }

    /** Naabot ang limit: diretso sa final kung kaya pa; kung hindi, ihinto at ipakita ang hindi pa resolved. */
    private function limitHit(Meeting $m, string $reason, bool $isFinal): void
    {
        $text = Budget::reasonText($reason);

        if ($isFinal || $m->phase === 'final') {
            $m->status      = 'stopped';
            $m->stop_reason = "limit:{$reason}";
            $m->finished_at = now();
            $this->notice($m, "Itinigil ang meeting: {$text}. Walang natirang kapasidad para sa final recommendation. " . $this->unresolvedText($m));

            return;
        }

        $m->stop_reason = $reason;
        $m->phase       = 'final';
        $this->notice($m, "Hindi na itinuloy ang susunod na hakbang: {$text}. Didiretso na sa final recommendation.");
    }

    private function unresolvedText(Meeting $m): string
    {
        $open = Issue::where('meeting_id', $m->id)->whereIn('status', Issue::UNRESOLVED)->orderBy('id')->get();
        if ($open->isEmpty()) {
            return 'Walang nakatalang bukas na isyu.';
        }

        return 'Hindi pa nareresolba: ' . $open->map(fn ($i) => "{$i->code} {$i->title}")->implode('; ') . '.';
    }

    public function notice(Meeting $m, string $text): Message
    {
        return Message::create([
            'meeting_id'  => $m->id,
            'author_type' => 'system',
            'kind'        => 'notice',
            'cycle'       => (int) $m->cycle,
            'body'        => $text,
        ]);
    }

    private function turn(Meeting $m, string $key): ?Turn
    {
        return Turn::where('meeting_id', $m->id)->where('dedupe_key', $key)->first();
    }

    /** Tapos na ba ang turn? Ang nabigong turn habang tumatakbo ang meeting ay nagpapahinto rito. */
    private function done(Meeting $m, Turn $turn): bool
    {
        if ($turn->status === 'completed') {
            return true;
        }
        if (in_array($turn->status, ['failed', 'stopped'], true) && $m->status === 'running') {
            $handle = $m->member((int) $turn->agent_id)['handle'] ?? 'AGENT';
            $m->status     = 'failed';
            $m->last_error = mb_substr("{$handle} ({$turn->purpose}) — " . ($turn->error_code ?: 'failed') . ': ' . ($turn->error_message ?: ''), 0, 500);
        }

        return false;
    }

    /** Ang message ng isang turn — o ng repair nito kung doon nanggaling ang valid na output. */
    public function messageOf(?Turn $turn): ?Message
    {
        if (! $turn) {
            return null;
        }
        $ids = Turn::where('parent_turn_id', $turn->id)->pluck('id')->push($turn->id);

        return Message::whereIn('turn_id', $ids)->orderByDesc('id')->first();
    }

    /** @return array<int, int> */
    private function requeue(int $meetingId, array $statuses): array
    {
        $ids = Turn::where('meeting_id', $meetingId)->whereIn('status', $statuses)->pluck('id')->all();
        if ($ids) {
            Turn::whereIn('id', $ids)->update(['status' => 'queued', 'updated_at' => now()]);
        }

        return $ids;
    }

    private function refreshSnapshot(Meeting $m, array $agentIds): void
    {
        $snapshot = $m->agents_snapshot ?: [];
        foreach ($snapshot as $i => $old) {
            if (! in_array((int) $old['agent_id'], array_map('intval', $agentIds), true)) {
                continue;
            }
            $agent = Agent::find($old['agent_id']);
            if (! $agent) {
                continue;
            }
            $fresh = $agent->snapshot();
            $fresh['role_type'] = $old['role_type'];   // hindi nagbabago ang papel sa loob ng isang run
            if ($fresh != $old) {
                $snapshot[$i] = $fresh;
                $this->notice($m, "Na-update ang config ni {$fresh['handle']} para sa retry: {$old['provider']}/{$old['model']} → {$fresh['provider']}/{$fresh['model']}.");
            }
        }
        $m->agents_snapshot = $snapshot;
    }

    /** @return array<int, array> mga member na na-mention gamit ang @HANDLE */
    private function mentions(Meeting $m, string $body): array
    {
        $out = [];
        if (preg_match_all('/@([A-Za-z][A-Za-z0-9_\-]{0,39})/', $body, $found)) {
            foreach ($found[1] as $handle) {
                $member = $m->memberByHandle($handle);
                if ($member) {
                    $out[(int) $member['agent_id']] = $member;
                }
            }
        }

        return array_values($out);
    }

    private function memberAgents(Meeting $m)
    {
        $ids = DB::table('br_meeting_agents')->where('meeting_id', $m->id)->orderBy('sort_order')->pluck('agent_id')->all();

        return Agent::whereIn('id', $ids)->get()->sortBy(fn ($a) => array_search($a->id, $ids))->values();
    }

    private function assertComposition($agents): void
    {
        $errors = [];
        if ($agents->where('role_type', 'moderator')->count() !== 1) {
            $errors[] = 'Kailangan ng eksaktong isang moderator (CEO) sa meeting.';
        }
        $contributors = $agents->where('role_type', 'contributor')->count();
        if ($contributors < 1 || $contributors > 4) {
            $errors[] = 'Kailangan ng 1 hanggang 4 na contributor (hal. CTO, COO).';
        }
        if ($agents->where('role_type', 'reviewer')->count() > 1) {
            $errors[] = 'Isang reviewer lang ang pwede kada meeting.';
        }
        foreach ($agents as $a) {
            if (! $a->enabled) {
                $errors[] = "Naka-disable ang role na {$a->handle}.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['agent_ids' => $errors]);
        }
    }

    /** @return array<int, string> mga dahilan kung bakit hindi pa pwedeng magsimula */
    private function readiness($agents): array
    {
        $errors = [];
        foreach ($agents as $a) {
            if ($a->archived_at || ! $a->enabled) {
                $errors[] = "{$a->handle}: naka-disable o naka-archive.";
                continue;
            }
            if (trim((string) $a->model) === '') {
                $errors[] = "{$a->handle}: walang napiling model.";
            }
            if (! Credential::where('agent_id', $a->id)->where('provider', $a->provider)->exists()) {
                $errors[] = "{$a->handle}: walang API key para sa {$a->provider}. Ilagay sa Agents / Roles.";
            }
        }

        return $errors;
    }
}
