<?php

namespace App\Boardroom\Orchestrator;

use App\Boardroom\Capabilities\CapabilityRegistry;
use App\Boardroom\Providers\AdapterFactory;
use App\Boardroom\Providers\ProviderRequest;
use App\Boardroom\Providers\ProviderResult;
use App\Boardroom\Support\Secrets;
use App\Models\Boardroom\Agent;
use App\Models\Boardroom\Credential;
use App\Models\Boardroom\Decision;
use App\Models\Boardroom\Issue;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Message;
use App\Models\Boardroom\Round;
use App\Models\Boardroom\Turn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nagpapatakbo ng ISANG turn = isang hiwalay na model call para sa isang role.
 *
 * - Atomic ang pag-claim (queued → generating), kaya ang dobleng job ay walang epekto.
 * - Ang credential ay kinukuha sa oras ng request, para sa role at provider na ito LANG.
 * - Ang mga parameter ay sinasala muna ng capability registry; ang hindi supported ay hindi ipinapadala.
 * - Walang fallback sa ibang model, provider, o key. Kapag nabigo, nabigo — at ipinapakita sa user.
 * - Ang pagkakakilanlan ng nagsalita ay galing sa server (turn.agent_id), hindi sa sinabi ng model.
 */
class TurnRunner
{
    public function __construct(
        private MeetingOrchestrator $orchestrator,
        private ContextBuilder $context,
        private CapabilityRegistry $registry,
        private AdapterFactory $adapters,
        private OutputInterpreter $interpreter,
        private Budget $budget,
        private QuestionRounds $rounds,
        private Playbook $playbook,
        private Registry $resources,
    ) {
    }

    public function run(int $turnId): void
    {
        $claimed = Turn::whereKey($turnId)->where('status', 'queued')->update([
            'status' => 'generating', 'started_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
        ]);
        if (! $claimed) {
            return;   // na-claim na ng ibang job, o hindi na queued (paused / stopped / tapos na)
        }

        $turn    = Turn::find($turnId);
        $attempt = (int) $turn->attempts;
        $secret  = null;

        try {
            $secret = $this->execute($turn, $attempt);
        } catch (\Throwable $e) {
            Log::error('boardroom.turn_crashed', [
                'turn' => $turn->uuid, 'meeting_id' => $turn->meeting_id, 'purpose' => $turn->purpose,
                'error' => Secrets::safeMessage($e->getMessage(), [$secret]),
            ]);
            $this->orchestrator->failTurn($turn, 'internal_error', 'May internal error habang pinoproseso ang turn.', true, $attempt);
        }
    }

    /** @return string|null ang ginamit na secret (para lang ma-redact sakaling may exception) */
    private function execute(Turn $turn, int $attempt): ?string
    {
        $meeting = Meeting::find($turn->meeting_id);

        if ($this->parkIfHalted($meeting, $turn, $attempt)) {
            return null;
        }

        // ── Role + credential (para sa role at provider na ito lang) ──
        $member = $meeting->member((int) $turn->agent_id);
        if (! $member) {
            $this->orchestrator->failTurn($turn, 'not_a_member', 'Hindi kasali sa meeting na ito ang role.', false, $attempt);

            return null;
        }
        $agent = Agent::find($turn->agent_id);
        if (! $agent || $agent->archived_at || ! $agent->enabled) {
            $this->orchestrator->failTurn($turn, 'agent_disabled', "Naka-disable o naka-archive ang role na {$member['handle']}.", false, $attempt);

            return null;
        }
        $credential = Credential::where('agent_id', $agent->id)->where('provider', $member['provider'])->first();
        if (! $credential) {
            $this->orchestrator->failTurn($turn, 'no_credential',
                "Walang API key si {$member['handle']} para sa {$member['provider']}. Ilagay sa Agents / Roles.", false, $attempt);

            return null;
        }
        try {
            $secret = Secrets::decrypt($credential->secret_encrypted);
        } catch (\Throwable $e) {
            $this->orchestrator->failTurn($turn, 'credential_unreadable',
                "Hindi mabasa ang naka-save na API key ni {$member['handle']} (nagbago ang encryption key?). I-enter ulit ang key.", false, $attempt);

            return null;
        }

        // ── Context + mga parameter na supported ──
        $built    = $this->context->build($meeting, $turn);
        $resolved = $this->registry->resolve($member['provider'], $member['model'], (array) ($member['settings'] ?? []));
        $adapter  = $this->adapters->make($member['provider']);
        $request  = new ProviderRequest(
            $member['provider'], $member['model'], $built['system'], $built['input'],
            $resolved['applied'], $built['schema'], $resolved['structured']
        );

        $turn->forceFill([
            'context_hash' => $built['hash'],
            'provider'     => $member['provider'],
            'model'        => $member['model'],
            'request_meta' => [
                'sent'       => $this->sentSummary($adapter->buildPayload($request)),
                'dropped'    => $resolved['dropped'],
                'structured' => $built['schema'] ? $resolved['structured'] : null,
                'endpoint'   => $adapter->endpointName(),
            ],
        ])->save();

        // ── Request, na may bounded retries para LANG sa retryable na failure ──
        $maxRetries = (int) config('boardroom.retry.max_retries', 2);
        $backoff    = (array) config('boardroom.retry.backoff_ms', [2000, 6000]);
        $result     = null;

        for ($try = 0; $try <= $maxRetries; $try++) {
            if ($try > 0) {
                $wait = (int) ($backoff[$try - 1] ?? end($backoff) ?: 0);
                if ($wait > 0) {
                    usleep($wait * 1000);
                }
                if ($this->parkIfHalted(Meeting::find($meeting->id), $turn, $attempt)) {
                    return $secret;
                }
            }

            $deny = $this->consumeCall($turn, $member);
            if ($deny) {
                $this->orchestrator->limitReached($turn, $attempt, $deny);

                return $secret;
            }

            $result = $adapter->send($request, $secret);
            if ($result->ok) {
                $this->recordUsage($turn, $member, $result);
            }
            if ($result->ok || ! $result->retryable) {
                break;
            }
        }

        if (! $result->ok) {
            Log::warning('boardroom.turn_failed', [
                'turn' => $turn->uuid, 'meeting_id' => $meeting->id, 'agent' => $member['handle'],
                'provider' => $member['provider'], 'model' => $member['model'],
                'code' => $result->errorCode, 'http' => $result->httpStatus,
                'error' => Secrets::safeMessage($result->errorMessage, [$secret]),
            ]);
            $this->orchestrator->failTurn($turn, (string) $result->errorCode, Secrets::safeMessage($result->errorMessage, [$secret]), $result->retryable, $attempt);

            return $secret;
        }

        $this->registry->markLiveVerified($member['provider'], $member['model']);
        $this->finish($meeting, $turn->fresh(), $attempt, $built['purpose'], $member, $result);

        return $secret;
    }

    /** Huwag tumawag ng model kapag naka-pause o naka-stop ang meeting. @return bool true = hindi itinuloy */
    private function parkIfHalted(?Meeting $meeting, Turn $turn, int $attempt): bool
    {
        $status   = $meeting?->status ?? 'draft';
        $question = $turn->isQuestion();   // tanong ng user: tumatakbo kahit tapos, nahinto, o naka-stop na ang meeting

        $park = match (true) {
            $status === 'draft'                                                        => 'stopped',
            $status === 'paused'                                                       => 'paused',
            ! $question && in_array($status, ['stopped', 'completed', 'blocked'], true) => 'stopped',
            ! $question && $status !== 'running'                                        => 'paused',   // failed / needs_input: hintayin ang user
            default                                                                    => null,
        };
        if ($park === null) {
            return false;
        }

        DB::transaction(function () use ($turn, $attempt, $park) {
            $updated = Turn::whereKey($turn->id)->where('status', 'generating')->where('attempts', $attempt)
                ->update(['status' => $park, 'updated_at' => now()] + ($park === 'stopped' ? ['finished_at' => now()] : []));
            if ($updated && $park === 'stopped') {
                $m = Meeting::whereKey($turn->meeting_id)->lockForUpdate()->first();
                $this->orchestrator->release($m, $turn->fresh());
                $m->save();
            }
        });

        return true;
    }

    /** Bilangin ang model call BAGO ito ipadala. @return string|null dahilan kung hindi pinayagan */
    private function consumeCall(Turn $turn, array $member): ?string
    {
        return DB::transaction(function () use ($turn, $member) {
            $m = Meeting::whereKey($turn->meeting_id)->lockForUpdate()->first();
            $t = Turn::find($turn->id);

            // Tanong ng user: hiwalay na bilang, walang ibinabawas sa limit ng meeting.
            if ($t->isQuestion() && ! $t->reserved) {
                $m->question_calls = (int) $m->question_calls + 1;
                $t->requests       = (int) $t->requests + 1;
                $t->save();
                $m->save();
                if ($t->round_id) {
                    Round::whereKey($t->round_id)->increment('calls_used');
                }

                return null;
            }

            if ($t->reserved) {
                $t->reserved       = false;
                $m->reserved_calls = max(0, (int) $m->reserved_calls - 1);
                if ((int) $m->calls_used >= (int) $m->max_calls) {
                    $t->save();
                    $m->save();

                    return 'call_limit';
                }
            } else {
                $isFinal = $t->purpose === 'final' || ! $this->budget->reservesFinal($m);
                $deny    = $this->budget->deny($m, [$member], ! $isFinal);
                if ($deny) {
                    return $deny;
                }
            }

            $m->calls_used = (int) $m->calls_used + 1;
            $t->requests   = (int) $t->requests + 1;
            $t->save();
            $m->save();

            return null;
        });
    }

    /** Usage ng provider at tantiyang gastos — magkahiwalay. Walang hinuhulaang gastos kapag hindi alam ang presyo. */
    private function recordUsage(Turn $turn, array $member, ProviderResult $r): void
    {
        $cost = $this->registry->estimateCost($member['provider'], $member['model'], $r->tokensIn, $r->tokensOut);

        DB::transaction(function () use ($turn, $r, $cost) {
            $m = Meeting::whereKey($turn->meeting_id)->lockForUpdate()->first();
            // Hiwalay ang usage ng mga tanong ng user, para hindi nito kainin ang mga limit ng meeting.
            $p = $turn->isQuestion() ? 'question_' : '';
            $m->{$p . 'tokens_in'}  = (int) $m->{$p . 'tokens_in'} + $r->tokensIn;
            $m->{$p . 'tokens_out'} = (int) $m->{$p . 'tokens_out'} + $r->tokensOut;
            if ($cost === null) {
                $m->{$p . 'unpriced_calls'} = (int) $m->{$p . 'unpriced_calls'} + 1;
            } else {
                $costKey     = $p === '' ? 'est_cost_usd' : 'question_cost_usd';
                $m->$costKey = round((float) $m->$costKey + $cost, 6);
            }
            $m->save();

            $t = Turn::find($turn->id);
            $t->tokens_in        = (int) $t->tokens_in + $r->tokensIn;
            $t->tokens_out       = (int) $t->tokens_out + $r->tokensOut;
            $t->tokens_reasoning = (int) $t->tokens_reasoning + $r->tokensReasoning;
            if ($cost !== null) {
                $t->est_cost_usd = round((float) $t->est_cost_usd + $cost, 6);
            }
            $t->provider_response_id = $r->responseId ? mb_substr($r->responseId, 0, 120) : $t->provider_response_id;
            $t->finish               = $r->finish;
            $t->save();
        });
    }

    private function finish(Meeting $meeting, Turn $turn, int $attempt, string $purpose, array $member, ProviderResult $r): void
    {
        $text = trim($r->text);

        if (in_array($r->finish, [ProviderResult::REFUSED, ProviderResult::FILTERED], true)) {
            $why = $r->finish === ProviderResult::REFUSED ? 'Tumanggi ang model na sumagot.' : 'Na-block ng content filter ng provider ang sagot.';
            $this->orchestrator->failTurn($turn, $r->finish, $why . ' Walang awtomatikong paglipat sa ibang model.', false, $attempt);

            return;
        }
        if ($text === '') {
            $truncated = $r->finish === ProviderResult::TRUNCATED;
            $this->orchestrator->failTurn(
                $turn,
                $truncated ? 'truncated_empty' : 'empty_output',
                $truncated
                    ? "Naubos ang max output tokens bago makasagot si {$member['handle']} (napunta sa reasoning). Taasan ang Max output tokens o babaan ang effort."
                    : 'Walang laman ang sagot ng model.',
                false,
                $attempt
            );

            return;
        }

        $data = null;
        if (ContextBuilder::learns($turn)) {
            // Sagot sa tanong ng user + posibleng aral. Mapagpatawad: kung hindi JSON, buong text ang sagot, walang aral.
            $data = $this->interpreter->answer($text);
            $text = $data['answer'];
        } elseif (in_array($purpose, Prompts::STRUCTURED, true)) {
            $fresh  = Meeting::find($meeting->id);
            $parsed = match ($purpose) {
                'route'   => $this->interpreter->route($fresh, (int) $turn->agent_id, $text),
                'review'  => $this->interpreter->review($fresh, $text),
                'final'   => $this->interpreter->final($fresh, $text),
                'qreview' => $this->interpreter->qreview($text),
            };
            if (! $parsed['ok']) {
                $error = $parsed['error'] . ($r->finish === ProviderResult::TRUNCATED ? ' (The output was cut off at the token limit — be more concise.)' : '');
                $this->orchestrator->dispatch($this->orchestrator->rejectStructured($turn, $attempt, $error, $text));

                return;
            }
            $data = $parsed['data'];
        }

        $saved = DB::transaction(function () use ($turn, $attempt, $purpose, $member, $r, $text, $data) {
            $updated = Turn::whereKey($turn->id)->where('status', 'generating')->where('attempts', $attempt)
                ->update(['status' => 'completed', 'finished_at' => now(), 'updated_at' => now(), 'error_code' => null, 'error_message' => null]);
            if (! $updated) {
                return false;   // may ibang humawak na sa turn na ito (hal. na-mark na stale) — huwag magdoble
            }

            $m       = Meeting::whereKey($turn->meeting_id)->lockForUpdate()->first();
            $logical = $turn->purpose === 'repair' ? Turn::find($turn->parent_turn_id) : $turn;

            $message = $this->apply($m, $turn, $logical, $purpose, $member, $r, $text, $data);

            if ($logical && $logical->id !== $turn->id) {
                // Naayos ng repair: ang orihinal na turn ay itinuturing nang tapos.
                $logical->forceFill([
                    'status' => 'completed', 'finish' => 'repaired', 'outcome' => $this->outcome($purpose, $data, $message),
                    'finished_at' => now(),
                ])->save();
            }
            Turn::whereKey($turn->id)->update(['outcome' => json_encode($this->outcome($purpose, $data, $message))]);
            $m->save();

            return true;
        });

        if (! $saved) {
            return;
        }
        if ($turn->round_id) {
            $this->orchestrator->dispatch($this->rounds->advance((int) $turn->round_id));
        }
        $this->orchestrator->advance((int) $turn->meeting_id);
    }

    /** I-save ang message (isa kada turn) at ilapat ang validated na resulta. */
    private function apply(Meeting $m, Turn $turn, ?Turn $logical, string $purpose, array $member, ProviderResult $r, string $text, ?array $data): Message
    {
        $meta = array_filter([
            'finish'         => $r->finish,
            'truncated'      => $r->finish === ProviderResult::TRUNCATED ?: null,
            'model_reported' => $r->modelReported,
            'repaired'       => $turn->purpose === 'repair' ?: null,
        ], fn ($v) => $v !== null);

        $base = [
            'meeting_id'          => $m->id,
            'turn_id'             => $turn->id,
            'author_type'         => 'agent',
            'agent_id'            => (int) $turn->agent_id,   // galing sa server, hindi sa sinabi ng model
            'cycle'               => (int) ($logical?->cycle ?? $turn->cycle),
            'provider'            => $member['provider'],
            'model'               => $member['model'],
            'reply_to_message_id' => $turn->reply_to_message_id,
            'issue_id'            => $turn->issue_id,
        ];

        switch ($purpose) {
            case 'route':
                $kind = match ($data['action']) {
                    'ask_agent'        => 'question',
                    'needs_user_input' => 'question',
                    default            => 'routing',
                };
                $message = Message::create($base + [
                    'kind'               => $kind,
                    'recipient_agent_id' => $data['recipient_agent_id'],
                    'body'               => $data['public_message'],
                    'meta'               => $meta + ['routing' => array_diff_key($data, ['public_message' => 1])],
                ]);
                $message->forceFill(['reply_to_message_id' => $data['reply_to_message_id'], 'issue_id' => $data['issue_id']])->save();
                if (! empty($data['overridden_from'])) {
                    $this->orchestrator->notice($m, "Pinili ng moderator ang \"{$data['overridden_from']}\" pero hindi na ito pwede dahil sa limit. Didiretso na sa final recommendation.");
                    $m->stop_reason = $m->stop_reason ?: 'call_limit';
                }
                if ($data['action'] === 'needs_user_input' && $data['issue_id']) {
                    Issue::where('meeting_id', $m->id)->whereKey($data['issue_id'])->update(['status' => 'needs_user_input', 'updated_at' => now()]);
                }

                return $message;

            case 'review':
                $message = Message::create($base + ['kind' => 'review', 'body' => $data['public_message'], 'meta' => $meta + ['verdict' => $data['verdict']]]);
                if ($data['resolved_issue_ids']) {
                    Issue::where('meeting_id', $m->id)->whereIn('id', $data['resolved_issue_ids'])
                        ->update(['status' => 'resolved', 'resolution' => 'Minarkahang resolved ng reviewer.', 'updated_at' => now()]);
                }
                $n     = Issue::where('meeting_id', $m->id)->count();
                $codes = [];
                foreach ($data['issues'] as $row) {
                    $issue = Issue::create([
                        'meeting_id'         => $m->id,
                        'code'               => 'I' . (++$n),
                        'title'              => $row['title'],
                        'detail'             => $row['detail'],
                        'severity'           => $row['severity'],
                        'status'             => 'open',
                        'raised_by_agent_id' => (int) $turn->agent_id,
                        'assigned_agent_id'  => $row['target_agent_id'],
                        'raised_message_id'  => $message->id,
                        'cycle'              => (int) $m->cycle,
                    ]);
                    $codes[] = $issue->code;
                }
                $message->forceFill(['meta' => $message->meta + ['issue_codes' => $codes]])->save();

                return $message;

            case 'final':
                foreach ($data['issue_updates'] as $u) {
                    Issue::where('meeting_id', $m->id)->whereKey($u['issue_id'])
                        ->update(['status' => $u['status'], 'resolution' => $u['note'] ?: null, 'updated_at' => now()]);
                }
                // Ang hindi tahasang na-resolve ay nananatiling HINDI resolved — walang pinipilit na consensus.
                Issue::where('meeting_id', $m->id)->whereIn('status', ['open', 'answered'])->update(['status' => 'unresolved', 'updated_at' => now()]);

                foreach ($data['approvals_needed'] as $row) {
                    Decision::create([
                        'project_id' => $m->project_id, 'meeting_id' => $m->id,
                        'title' => mb_substr($row['title'], 0, 300), 'detail' => $row['detail'] ?: null, 'status' => 'proposed',
                    ]);
                }
                $m->final = $data;

                return Message::create($base + ['kind' => 'final', 'body' => $this->interpreter->renderFinal($data), 'meta' => $meta + ['status' => $data['status']]]);

            case 'answer':
                if ($turn->issue_id) {
                    Issue::where('meeting_id', $m->id)->whereKey($turn->issue_id)->where('status', 'open')
                        ->update(['status' => 'answered', 'updated_at' => now()]);
                }

                return Message::create($base + ['kind' => 'answer', 'body' => $text, 'meta' => $meta]);

            case 'direct':
                $answer = Message::create($base + ['kind' => 'answer', 'body' => $text, 'meta' => $meta + ['to_user' => true]]);
                // Kusang pagkatuto: kung may napansing aral ang role sa message ng user, i-save agad sa playbook nito.
                if (! empty($data['lesson'])) {
                    $this->playbook->learn($m, $member, $data['lesson'], $turn->reply_to_message_id);
                }

                // Datos para sa resources registry: ang UNANG na-mention lang ang tagatala. Ang backend ang nagva-validate.
                $round = $turn->round_id ? Round::find($turn->round_id) : null;
                if (! empty($data['changes']) && ContextBuilder::records($turn, $round)) {
                    $question = Message::where('meeting_id', $m->id)->find($turn->reply_to_message_id);
                    $result   = $this->resources->apply($m, $member, $data['changes'], $question);
                    $this->resources->notice($m, $member, $result, $question);
                    if ($result['numbers']) {
                        // Huwag iwan ang buong account number sa sagot ng role.
                        $answer->forceFill(['body' => $this->resources->maskNumbers((string) $answer->body, $result['numbers'])])->save();
                    }
                }

                return $answer;

            case 'qreview':
                return Message::create($base + ['kind' => 'review', 'body' => $data['public_message'], 'meta' => $meta + ['to_user' => true, 'verdict' => $data['verdict']]]);

            case 'qsummary':
                return Message::create($base + ['kind' => 'summary', 'body' => $text, 'meta' => $meta + ['to_user' => true]]);

            default:   // brief | proposal | revision
                return Message::create($base + ['kind' => $purpose, 'body' => $text, 'meta' => $meta]);
        }
    }

    private function outcome(string $purpose, ?array $data, Message $message): array
    {
        $out = ['message_id' => $message->id];

        return match ($purpose) {
            'route'  => $out + array_diff_key((array) $data, ['public_message' => 1]),
            'review' => $out + ['verdict' => $data['verdict'], 'issues' => count($data['issues'])],
            'final'  => $out + ['status' => $data['status']],
            'qreview' => $out + ['verdict' => $data['verdict']],
            default  => $out,
        };
    }

    /** Ang mga parameter na ipapadala, WALANG prompt text at walang secret. */
    private function sentSummary(array $payload): array
    {
        unset($payload['input'], $payload['instructions'], $payload['messages'], $payload['system']);
        if (isset($payload['text']['format']['schema'])) {
            $payload['text']['format']['schema'] = '(schema)';
        }
        if (isset($payload['output_config']['format']['schema'])) {
            $payload['output_config']['format']['schema'] = '(schema)';
        }

        return $payload;
    }
}
