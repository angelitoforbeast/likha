<?php

namespace Tests\Feature\Boardroom;

use App\Boardroom\Support\Secrets;
use App\Jobs\Boardroom\RunTurnJob;
use App\Models\Boardroom\Credential;
use App\Models\Boardroom\Meeting;
use App\Models\Boardroom\Turn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MOCKED — seguridad ng credentials at access. Walang totoong provider call o totoong API key.
 */
class SecurityTest extends BoardroomTestCase
{
    /** Test 9: walang secret sa logs, sa API response, sa database (plaintext), sa prompt, o sa queue payload. */
    public function test_secrets_are_excluded_from_logs_and_api_responses(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $secrets = array_values($this->keys);

        // Kinukuha ang LAHAT ng isinusulat sa log
        $logged = new class {
            public array $records = [];

            public function __call($method, $args)
            {
                $this->records[] = [$method, $args];
            }
        };
        Log::swap($logged);

        // Ibinabalik ng "provider" ang mismong key sa error message (gaya ng ginagawa ng ilang API)
        $this->fakeProvider(function ($request, $n, $task, $handle) {
            if ($task === 'proposal' && $handle === 'COO') {
                return $this->openaiError(401, 'Incorrect API key provided: ' . $this->keys['COO'] . '. Authorization: Bearer ' . $this->keys['COO'], 'invalid_request_error', 'invalid_api_key');
            }

            return null;
        });

        $m = $this->start($this->meeting($user));
        $this->assertSame('failed', $m->status);

        $turn = Turn::where('meeting_id', $m->id)->where('status', 'failed')->firstOrFail();
        $this->assertSame('invalid_credentials', $turn->error_code);
        $this->assertStringContainsString('[REDACTED]', $turn->error_message);

        // ── API responses ──
        $cto = $this->agent('CTO');
        $responses = [
            $this->actingAs($user)->getJson('/boardroom/api/agents')->assertOk()->getContent(),
            $this->actingAs($user)->getJson('/boardroom/api/bootstrap')->assertOk()->getContent(),
            $this->actingAs($user)->getJson("/boardroom/api/meetings/{$m->id}")->assertOk()->getContent(),
            $this->actingAs($user)->putJson("/boardroom/api/agents/{$cto->id}", [
                'handle' => 'CTO', 'display_name' => 'CTO', 'role_type' => 'contributor', 'provider' => 'openai',
                'model' => 'gpt-6-astra', 'enabled' => true, 'settings' => ['effort' => 'high'], 'api_key' => $this->keys['CTO'],
            ])->assertOk()->getContent(),
            $this->actingAs($user)->postJson("/boardroom/api/agents/{$cto->id}/test")->getContent(),
            $this->actingAs($user)->get('/boardroom')->assertOk()->getContent(),
            $this->actingAs($user)->get('/boardroom/agents')->assertOk()->getContent(),
        ];
        $encrypted = Credential::pluck('secret_encrypted')->all();
        foreach ($responses as $i => $content) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $content, "May secret sa response #{$i}.");
            }
            foreach ($encrypted as $cipher) {
                $this->assertStringNotContainsString($cipher, $content, "May encrypted secret sa response #{$i}.");
            }
            $this->assertStringNotContainsString('secret_encrypted', $content);
        }
        foreach (json_decode($responses[0], true)['agents'] as $row) {
            $this->assertMatchesRegularExpression('/^•{8}[A-Za-z0-9]{4}$/u', $row['credential']['masked'], 'Naka-mask ang credential sa UI.');
            $this->assertSame(['provider', 'label', 'masked', 'updated_at', 'last_tested_at', 'last_test_ok', 'last_test_message'], array_keys($row['credential']));
        }

        // ── Logs ──
        $this->assertNotEmpty($logged->records, 'Dapat may log ng nabigong turn.');
        $logText = json_encode($logged->records, JSON_UNESCAPED_UNICODE);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $logText);
        }

        // ── Database: encrypted ang key; walang plaintext kahit saan ──
        foreach (Credential::all() as $c) {
            $this->assertNotContains($c->secret_encrypted, $secrets);
            $this->assertContains(Secrets::decrypt($c->secret_encrypted), $secrets);
            $this->assertArrayNotHasKey('secret_encrypted', $c->toArray());
        }
        foreach (['br_turns', 'br_messages', 'br_meetings', 'br_agents', 'br_issues', 'br_decisions'] as $table) {
            $dump = json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $dump, "May plaintext na key sa {$table}.");
            }
        }

        // ── Prompts at payload: ang key ay nasa header lang ──
        foreach ($this->calls as $call) {
            $payload = json_encode($call['body'], JSON_UNESCAPED_UNICODE);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $payload);
            }
        }

        // ── Queue payload: turn ID lang ──
        $serialized = serialize(new RunTurnJob($turn->id));
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_redaction_removes_common_secret_shapes(): void
    {
        $text = Secrets::redact('key sk-proj-AbC123_xyz-987654 and Bearer abc.def-ghi123456 and x-api-key: zzzzzzzz1234 plus custom-secret-value', ['custom-secret-value']);

        $this->assertStringNotContainsString('sk-proj-AbC123', $text);
        $this->assertStringNotContainsString('abc.def-ghi123456', $text);
        $this->assertStringNotContainsString('zzzzzzzz1234', $text);
        $this->assertStringNotContainsString('custom-secret-value', $text);
    }

    public function test_stored_keys_cannot_be_read_with_a_different_encryption_key(): void
    {
        $this->giveKeys(['CEO']);
        $cipher = Credential::first()->secret_encrypted;

        config(['boardroom.encryption_key' => 'base64:' . base64_encode(str_repeat('z', 32))]);

        $this->expectException(\Illuminate\Contracts\Encryption\DecryptException::class);
        Secrets::decrypt($cipher);
    }

    /** CEO lang ang may access sa pages at sa API (kasama ang settings at credentials). */
    public function test_only_the_ceo_can_access_the_boardroom(): void
    {
        $this->get('/boardroom')->assertRedirect();
        $this->getJson('/boardroom/api/agents')->assertStatus(401);

        $staff = $this->user('Encoder', 'staff@example.test');
        $cto   = $this->agent('CTO');

        $this->actingAs($staff)->get('/boardroom')->assertStatus(403);
        $this->actingAs($staff)->get('/boardroom/agents')->assertStatus(403);
        $this->actingAs($staff)->getJson('/boardroom/api/agents')->assertStatus(403);
        $this->actingAs($staff)->putJson("/boardroom/api/agents/{$cto->id}", ['api_key' => 'sk-test-attacker-key-000000000000'])->assertStatus(403);
        $this->actingAs($staff)->postJson("/boardroom/api/agents/{$cto->id}/test")->assertStatus(403);
        $this->assertSame(0, Credential::count());
    }

    /** Ang project at meeting ay sa may-ari lang — kahit CEO rin ang isa pang user. */
    public function test_meetings_are_scoped_to_their_owner(): void
    {
        $owner = $this->user('CEO', 'owner@example.test');
        $other = $this->user('CEO', 'other@example.test');
        $this->giveKeys();
        $this->fakeProvider();

        $m = $this->start($this->meeting($owner));

        $this->actingAs($other)->getJson("/boardroom/api/meetings/{$m->id}")->assertStatus(404);
        $this->actingAs($other)->postJson("/boardroom/api/meetings/{$m->id}/stop")->assertStatus(404);
        $this->actingAs($other)->postJson("/boardroom/api/meetings/{$m->id}/messages", ['body' => 'hello'])->assertStatus(404);
        $this->assertSame([], $this->actingAs($other)->getJson('/boardroom/api/bootstrap')->json('projects'));

        $this->actingAs($other)->postJson('/boardroom/api/meetings', [
            'project_id' => $m->project_id, 'title' => 'x', 'objective' => 'y',
            'agent_ids' => \App\Models\Boardroom\Agent::pluck('id')->all(),
        ])->assertStatus(422);
        $this->assertSame(1, Meeting::count());
    }

    /** Ang output ng model ay ipinapakita bilang TEXT — walang HTML na isinasagawa. */
    public function test_model_output_is_delivered_as_plain_text(): void
    {
        $user = $this->user();
        $this->giveKeys();
        $this->fakeProvider(null, ['brief' => '<script>alert(1)</script><img src=x onerror=alert(2)>']);

        $m = $this->start($this->meeting($user));

        $json = $this->actingAs($user)->getJson("/boardroom/api/meetings/{$m->id}")->assertOk();
        $this->assertSame('<script>alert(1)</script><img src=x onerror=alert(2)>', $json->json('messages.0.body'));
        $this->assertStringContainsString('application/json', $json->headers->get('content-type'));

        $page = $this->actingAs($user)->get('/boardroom')->assertOk()->getContent();
        $this->assertStringNotContainsString('alert(1)', $page, 'Ang page ay walang naka-embed na model output.');
        $this->assertStringNotContainsString('x-html', $page, 'Bawal ang x-html: text lang ang pag-render.');
        $this->assertStringNotContainsString('innerHTML', $page);
        $this->assertStringNotContainsString('localStorage', $page, 'Walang credential o data sa localStorage.');
    }
}
