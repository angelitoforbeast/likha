<?php

namespace Tests\Feature\Boardroom;

use App\Boardroom\Orchestrator\MeetingOrchestrator;
use App\Boardroom\Support\Secrets;
use App\Models\Boardroom\Agent;
use App\Models\Boardroom\Credential;
use App\Models\Boardroom\Issue;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Project;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base ng mga Boardroom test.
 *
 * MOCKED: lahat ng provider call dito ay dumadaan sa Http::fake() — WALANG totoong request na lumalabas
 * at walang totoong API key. Ang mga test na ito ay HINDI patunay na gumagana ang live provider.
 * Ang live check ay nasa BoardroomLiveSmokeTest (opt-in).
 */
abstract class BoardroomTestCase extends TestCase
{
    /** @var array<int, array{url: string, key: ?string, body: array}> lahat ng request na "ipinadala" sa provider */
    protected array $calls = [];

    /** @var array<string, string> handle → pekeng key */
    protected array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'boardroom.retry.backoff_ms' => [0, 0],
            'boardroom.encryption_key'   => 'base64:' . base64_encode(str_repeat('k', 32)),
        ]);

        // Minimal na mga table ng app na kailangan ng auth + layout (sqlite in-memory).
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->string('password_plain')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('employee_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('name')->nullable();
            $t->string('employee_code')->nullable();
            $t->string('role')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        Artisan::call('migrate', [
            '--path'     => 'database/migrations/2026_09_28_100000_create_boardroom_tables.php',
            '--force'    => true,
        ]);

        $this->calls = [];
    }

    protected function user(string $role = 'CEO', string $email = 'ceo@example.test'): User
    {
        $user = User::create(['name' => $role . ' User', 'email' => $email, 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => $role]);

        return $user->fresh();
    }

    protected function agent(string $handle): Agent
    {
        return Agent::where('handle', $handle)->firstOrFail();
    }

    /** Bigyan ng MAGKAKAIBANG pekeng key ang bawat role. */
    protected function giveKeys(array $handles = ['CEO', 'CTO', 'COO', 'REVIEWER']): void
    {
        foreach ($handles as $handle) {
            $agent = $this->agent($handle);
            $key   = 'sk-test-' . strtolower($handle) . '-' . str_repeat(substr(md5($handle), 0, 4), 6);
            $this->keys[$handle] = $key;
            Credential::updateOrCreate(
                ['agent_id' => $agent->id, 'provider' => $agent->provider],
                ['secret_encrypted' => Secrets::encrypt($key), 'last4' => Secrets::last4($key), 'label' => $handle]
            );
        }
    }

    protected function handleForKey(?string $key): ?string
    {
        return array_search($key, $this->keys, true) ?: null;
    }

    protected function project(User $user, string $name = 'Warehouse'): Project
    {
        return Project::create(['user_id' => $user->id, 'name' => $name]);
    }

    protected function meeting(User $user, array $overrides = [], ?Project $project = null): Meeting
    {
        $project ??= $this->project($user);

        return app(MeetingOrchestrator::class)->create($user->id, array_merge([
            'project_id' => $project->id,
            'title'      => 'Warehouse packing monitoring',
            'objective'  => 'Mag-propose ng warehouse packing monitoring workflow. Suriin ang duplicate scans, packing verification, exceptions, at audit trail.',
            'agent_ids'  => Agent::whereIn('handle', ['CEO', 'CTO', 'COO', 'REVIEWER'])->pluck('id')->all(),
        ], $overrides));
    }

    protected function start(Meeting $m): Meeting
    {
        app(MeetingOrchestrator::class)->start($m);

        return $m->fresh();
    }

    // ───────────────────────── Pekeng provider ─────────────────────────

    /**
     * I-fake ang OpenAI Responses API gamit ang scripted na mga sagot.
     *
     * @param  callable|null  $override  fn(Request, int $n, string $task, ?string $handle): ?\GuzzleHttp\Promise\PromiseInterface
     */
    protected function fakeProvider(?callable $override = null, array $script = []): void
    {
        Http::fake(function (Request $request) use ($override, $script) {
            $body   = $request->data();
            $key    = preg_replace('/^Bearer\s+/i', '', (string) ($request->header('Authorization')[0] ?? '')) ?: ($request->header('x-api-key')[0] ?? null);
            $handle = $this->handleForKey($key);
            $task   = $this->taskOf((string) ($body['input'] ?? ''));

            $this->calls[] = ['url' => $request->url(), 'key' => $key, 'handle' => $handle, 'task' => $task, 'body' => $body];

            if ($override) {
                $response = $override($request, count($this->calls), $task, $handle);
                if ($response !== null) {
                    return $response;
                }
            }

            return $this->openai($this->scripted($task, $handle, (string) ($body['input'] ?? ''), $script));
        });
    }

    protected function taskOf(string $input): string
    {
        return match (true) {
            str_contains($input, 'YOUR PREVIOUS OUTPUT WAS REJECTED')      => 'repair',
            str_contains($input, 'write the meeting BRIEF')                 => 'brief',
            str_contains($input, 'write your INDEPENDENT PROPOSAL')         => 'proposal',
            str_contains($input, 'YOUR TASK — REVIEW the proposals')        => 'review',
            str_contains($input, 'decide the NEXT STEP')                    => 'route',
            str_contains($input, "answer the moderator's question")         => 'answer',
            str_contains($input, 'write your REVISED PROPOSAL')             => 'revision',
            str_contains($input, 'write the FINAL RECOMMENDATION')          => 'final',
            str_contains($input, 'the user addressed you directly')         => 'direct',
            default                                                         => 'other',
        };
    }

    /** Default na script ng isang buong meeting: review → tanong kay CTO → revision → approve → final. */
    protected function scripted(string $task, ?string $handle, string $input, array $script = []): string
    {
        if (isset($script[$task])) {
            $value = $script[$task];

            return is_callable($value) ? (string) $value($handle, $input) : (string) $value;
        }

        $cto = $this->agent('CTO')->id;

        switch ($task) {
            case 'brief':
                return "BRIEF-MARKER: Gumawa ng packing monitoring workflow. [ASSUMPTION] May barcode scanner ang bawat station.";
            case 'proposal':
                return "PROPOSAL-BY-{$handle}: [PROPOSAL] Mungkahi ni {$handle} para sa packing verification at audit trail.";
            case 'review':
                $open = Issue::whereIn('status', Issue::UNRESOLVED)->pluck('id')->all();
                if ($open) {
                    return json_encode([
                        'public_message' => 'Naayos na ang mga isyu sa revision. Aprubado.', 'verdict' => 'approve',
                        'issues' => [], 'resolved_issue_ids' => array_values($open),
                    ]);
                }

                return json_encode([
                    'public_message' => 'May isang malaking butas: paano hinahawakan ang duplicate scan?', 'verdict' => 'revise',
                    'issues' => [['title' => 'Duplicate scan handling', 'detail' => 'Hindi malinaw kung paano nade-detect ang dobleng scan.', 'severity' => 'high', 'target_agent_id' => $cto]],
                    'resolved_issue_ids' => [],
                ]);
            case 'route':
                $asked = \App\Models\Boardroom\Turn::where('purpose', 'answer')->exists();
                $issue = Issue::orderBy('id')->value('id');
                if (! $asked) {
                    return json_encode([
                        'action' => 'ask_agent', 'recipient_agent_id' => $cto, 'reply_to_message_id' => null, 'issue_id' => $issue,
                        'public_message' => '@CTO, paano mo ide-detect ang duplicate scan?', 'discussion_status' => 'continuing',
                    ]);
                }

                return json_encode([
                    'action' => 'request_revision', 'recipient_agent_id' => null, 'reply_to_message_id' => null, 'issue_id' => null,
                    'public_message' => 'I-revise ang mga proposal ayon sa napag-usapan.', 'discussion_status' => 'ready_for_revision',
                ]);
            case 'answer':
                return "ANSWER-BY-{$handle}: Unique constraint sa (order_id, scan_type) at idempotency key kada scan.";
            case 'revision':
                return "REVISION-BY-{$handle}: Idinagdag ang duplicate scan detection.";
            case 'final':
                return json_encode([
                    'status' => 'recommended', 'approach' => 'Scan-based packing verification na may audit trail.',
                    'reasons' => ['[VERIFIED FACT] Sinuri ng reviewer ang duplicate scan handling.'],
                    'alternatives' => [['option' => 'Manual checklist', 'why_not' => 'Walang audit trail.']],
                    'unresolved' => [['item' => 'Hardware budget', 'detail' => 'Hindi pa alam ang presyo ng scanner.']],
                    'next_actions' => [['action' => 'Gumawa ng pilot sa isang station', 'owner' => 'COO']],
                    'approvals_needed' => [['title' => 'Aprubahan ang pilot', 'detail' => 'Isang station sa loob ng 2 linggo.']],
                    'issue_updates' => [],
                ]);
            case 'direct':
                return "DIRECT-BY-{$handle}: Sagot sa tanong ng user.";
            default:
                return 'OK';
        }
    }

    /** Hugis ng sagot ng OpenAI Responses API (may reasoning item na dapat HINDI makuha). */
    protected function openai(string $text, array $overrides = [])
    {
        return Http::response(array_merge([
            'id'     => 'resp_' . substr(md5($text . count($this->calls)), 0, 12),
            'object' => 'response',
            'status' => 'completed',
            'model'  => 'gpt-6-astra',
            'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'HIDDEN-REASONING-SHOULD-NOT-LEAK']]],
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]],
            ],
            'usage'  => ['input_tokens' => 1000, 'output_tokens' => 200, 'output_tokens_details' => ['reasoning_tokens' => 50]],
        ], $overrides), 200);
    }

    protected function openaiError(int $status, string $message, string $type = 'invalid_request_error', ?string $code = null)
    {
        return Http::response(['error' => ['message' => $message, 'type' => $type, 'code' => $code]], $status);
    }

    /** @return array<int, array> mga request na para sa isang uri ng task */
    protected function callsFor(string $task): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['task'] === $task));
    }
}
