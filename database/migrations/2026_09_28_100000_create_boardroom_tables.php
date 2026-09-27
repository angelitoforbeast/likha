<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI Boardroom (/boardroom) — lahat ng table ay BAGO at may prefix na br_.
 * Walang ginagalaw na existing table. May kasamang seed: 4 na role, "Core Boardroom" group,
 * at model-capability registry (walang API key — sa settings screen iyon inilalagay).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Roles / agents ────────────────────────────────────────────────
        Schema::create('br_agents', function (Blueprint $t) {
            $t->id();
            $t->string('handle', 40)->unique();            // para sa @mention, hal. CTO
            $t->string('display_name', 120);
            $t->string('role_type', 20)->default('contributor');   // moderator | contributor | reviewer
            $t->text('description')->nullable();
            $t->text('instructions')->nullable();          // system instructions ng role
            $t->string('provider', 32)->default('openai');
            $t->string('model', 120)->default('');
            $t->json('settings')->nullable();              // effort, thinking, max_output_tokens, timeout_s, options
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });

        // ── Credentials: isa kada (role, provider). Encrypted ang secret; last4 lang ang naipapakita. ──
        Schema::create('br_credentials', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agent_id');
            $t->string('provider', 32);
            $t->string('label', 120)->nullable();
            $t->text('secret_encrypted');
            $t->string('last4', 8)->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamp('last_tested_at')->nullable();
            $t->boolean('last_test_ok')->nullable();
            $t->string('last_test_message', 500)->nullable();
            $t->timestamps();
            $t->unique(['agent_id', 'provider']);
        });

        // ── Model-capability registry (editable) ──────────────────────────
        Schema::create('br_model_capabilities', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 32);
            $t->string('model', 120);
            $t->string('label', 160)->nullable();
            $t->string('endpoint', 60)->nullable();        // hal. responses | messages | chat.completions
            $t->json('efforts')->nullable();               // pinapayagang reasoning effort values
            $t->string('default_effort', 20)->nullable();
            $t->json('thinking_modes')->nullable();        // pinapayagang thinking modes
            $t->string('default_thinking', 20)->nullable();
            $t->json('optional_params')->nullable();       // iba pang model-specific options + rules
            $t->string('structured_output', 20)->default('none');   // json_schema | json_object | none
            $t->unsignedInteger('max_output_tokens')->nullable();
            $t->unsignedInteger('context_window')->nullable();
            $t->decimal('price_in', 12, 4)->nullable();    // USD kada 1M input tokens (null = hindi alam)
            $t->decimal('price_out', 12, 4)->nullable();   // USD kada 1M output tokens
            $t->string('doc_url', 500)->nullable();
            $t->boolean('verified')->default(false);       // napatunayan sa opisyal na docs
            $t->date('verified_at')->nullable();
            $t->timestamp('live_verified_at')->nullable(); // napatunayan sa TOTOONG request (Test Connection / meeting)
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'model']);
        });

        // ── Groups (default na set ng mga role) ───────────────────────────
        Schema::create('br_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->text('description')->nullable();
            $t->boolean('is_default')->default(false);
            $t->timestamps();
        });
        Schema::create('br_group_agents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id');
            $t->unsignedBigInteger('agent_id');
            $t->unsignedInteger('sort_order')->default(0);
            $t->unique(['group_id', 'agent_id']);
        });

        // ── Projects + meetings (rooms) ───────────────────────────────────
        Schema::create('br_projects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('name', 160);
            $t->text('description')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });

        Schema::create('br_meetings', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('project_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('group_id')->nullable();
            $t->string('title', 200);
            $t->text('objective');
            $t->text('constraints')->nullable();
            $t->string('status', 20)->default('draft');    // draft|running|paused|stopped|completed|failed|needs_input|blocked
            $t->string('phase', 20)->default('brief');     // brief|proposals|review|discussion|revision|final|done
            $t->unsignedInteger('cycle')->default(0);
            $t->unsignedInteger('max_cycles')->default(3);
            $t->unsignedInteger('max_calls')->default(16);
            $t->unsignedInteger('calls_used')->default(0);
            $t->unsignedInteger('reserved_calls')->default(0);
            $t->unsignedBigInteger('max_total_output_tokens')->nullable();   // null = walang token limit
            $t->decimal('spend_limit_usd', 12, 4)->nullable();               // null = walang spending limit
            $t->unsignedBigInteger('tokens_in')->default(0);
            $t->unsignedBigInteger('tokens_out')->default(0);
            $t->decimal('est_cost_usd', 14, 6)->default(0);                  // kabuuan ng mga turn na ALAM ang presyo
            $t->unsignedInteger('unpriced_calls')->default(0);               // mga call na walang alam na presyo
            $t->json('agents_snapshot')->nullable();       // non-secret config ng bawat role sa run na ito
            $t->json('brief_snapshot')->nullable();        // {message_id, hash} — iisang brief para sa lahat ng proposal
            $t->json('final')->nullable();
            $t->string('stop_reason', 60)->nullable();
            $t->string('last_error', 500)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        Schema::create('br_meeting_agents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('meeting_id');
            $t->unsignedBigInteger('agent_id');
            $t->string('role_type', 20);
            $t->unsignedInteger('sort_order')->default(0);
            $t->unique(['meeting_id', 'agent_id']);
        });

        // ── Turns (isang turn = isang model call; may unique na dedupe key) ──
        Schema::create('br_turns', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('meeting_id');
            $t->unsignedBigInteger('agent_id');
            $t->string('purpose', 20);                     // brief|proposal|review|route|answer|revision|final|repair|direct
            $t->string('phase', 20);
            $t->unsignedInteger('cycle')->default(0);
            $t->unsignedInteger('seq')->default(0);
            $t->string('dedupe_key', 120);
            $t->string('status', 20)->default('queued');   // queued|generating|completed|failed|paused|stopped
            $t->unsignedInteger('attempts')->default(0);   // ilang beses na-claim ng worker
            $t->unsignedInteger('requests')->default(0);   // ilang model request ang naipadala
            $t->boolean('reserved')->default(false);       // may hawak pang nakareserbang call sa budget ng meeting
            $t->unsignedBigInteger('parent_turn_id')->nullable();
            $t->unsignedBigInteger('issue_id')->nullable();
            $t->unsignedBigInteger('reply_to_message_id')->nullable();
            $t->string('context_hash', 64)->nullable();
            $t->string('provider', 32)->nullable();
            $t->string('model', 120)->nullable();
            $t->json('request_meta')->nullable();          // mga parameter na TOTOONG naipadala (walang secret)
            $t->json('outcome')->nullable();               // validated na resulta ng structured turn (routing, review, final)
            $t->string('provider_response_id', 120)->nullable();
            $t->string('finish', 20)->nullable();          // completed|truncated|refused|filtered|error
            $t->unsignedBigInteger('tokens_in')->default(0);
            $t->unsignedBigInteger('tokens_out')->default(0);
            $t->unsignedBigInteger('tokens_reasoning')->default(0);
            $t->decimal('est_cost_usd', 14, 6)->nullable();   // null = hindi alam ang presyo (hindi gumagawa ng hula)
            $t->string('error_code', 60)->nullable();
            $t->string('error_message', 500)->nullable();  // sanitized
            $t->boolean('retryable')->default(false);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['meeting_id', 'dedupe_key']);
            $t->index(['meeting_id', 'status']);
        });

        Schema::create('br_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('meeting_id')->index();
            $t->unsignedBigInteger('turn_id')->nullable()->unique();   // isang message kada turn
            $t->string('author_type', 12);                 // agent | user | system
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('recipient_agent_id')->nullable();
            $t->unsignedBigInteger('reply_to_message_id')->nullable();
            $t->unsignedBigInteger('issue_id')->nullable();
            $t->string('kind', 20);                        // brief|proposal|review|question|answer|revision|final|instruction|notice
            $t->unsignedInteger('cycle')->default(0);
            $t->longText('body');
            $t->json('meta')->nullable();
            $t->string('provider', 32)->nullable();
            $t->string('model', 120)->nullable();
            $t->timestamps();
        });

        Schema::create('br_issues', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('meeting_id')->index();
            $t->string('code', 12);                        // I1, I2, …
            $t->string('title', 300);
            $t->text('detail')->nullable();
            $t->string('severity', 12)->default('medium'); // low | medium | high
            $t->string('status', 24)->default('open');     // open|answered|resolved|unresolved|needs_user_input|blocked
            $t->unsignedBigInteger('raised_by_agent_id')->nullable();
            $t->unsignedBigInteger('assigned_agent_id')->nullable();
            $t->unsignedBigInteger('raised_message_id')->nullable();
            $t->unsignedInteger('cycle')->default(0);
            $t->text('resolution')->nullable();
            $t->timestamps();
            $t->unique(['meeting_id', 'code']);
        });

        Schema::create('br_decisions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id')->index();
            $t->unsignedBigInteger('meeting_id')->index();
            $t->string('title', 300);
            $t->text('detail')->nullable();
            $t->string('status', 16)->default('proposed'); // proposed | approved | rejected
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });

        // Company knowledge na APPROVED ng user — hiwalay sa meeting history at sa role instructions.
        Schema::create('br_knowledge', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('project_id')->nullable()->index();   // null = buong kumpanya
            $t->string('title', 200);
            $t->text('body');
            $t->boolean('approved')->default(false);
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        $this->seed();
    }

    public function down(): void
    {
        foreach ([
            'br_knowledge', 'br_decisions', 'br_issues', 'br_messages', 'br_turns', 'br_meeting_agents',
            'br_meetings', 'br_projects', 'br_group_agents', 'br_groups', 'br_model_capabilities',
            'br_credentials', 'br_agents',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function seed(): void
    {
        $now = now();

        $common = "Sumagot sa wikang ginamit sa objective (Taglish ay ok). Maging tiyak at maikli. "
            . "Lagyan ng label ang bawat mahalagang pahayag: [VERIFIED FACT] kung galing sa ibinigay na impormasyon, "
            . "[ASSUMPTION] kung hula, [PROPOSAL] kung mungkahi, [APPROVED DECISION] kung aprubado na ng user. "
            . "Huwag mag-imbento ng datos, presyo, o resulta.";

        $roles = [
            ['CEO', 'CEO / Moderator', 'moderator',
                'Nagbibigay ng brief, pumipili kung sino ang sasagot sa bawat isyu, at sumusulat ng final recommendation.',
                "Ikaw ang CEO at Moderator ng boardroom. Trabaho mo: linawin ang objective, magbigay ng malinaw na brief, "
                . "piliin kung sinong role ang dapat sumagot sa bawat isyu, at buuin ang final recommendation. "
                . "Huwag pilitin ang consensus — ilista ang mga hindi napagkasunduan. {$common}"],
            ['CTO', 'CTO', 'contributor',
                'Teknikal na disenyo: system, data, integration, seguridad, at kung ano ang kayang gawin.',
                "Ikaw ang CTO. Magmungkahi mula sa teknikal na pananaw: architecture, data model, integration, "
                . "seguridad, reliability, at tantiyang effort. Sabihin ang teknikal na panganib at kung ano ang kailangang patunayan. {$common}"],
            ['COO', 'COO', 'contributor',
                'Operasyon: proseso, tao, SOP, gastos, at kung paano ito tatakbo araw-araw.',
                "Ikaw ang COO. Magmungkahi mula sa pananaw ng operasyon: daloy ng trabaho, mga tao at responsibilidad, "
                . "SOP, training, exception handling, at sukatan ng tagumpay. Sabihin kung ano ang mahirap ipatupad sa aktwal. {$common}"],
            ['REVIEWER', 'Reviewer / Quality Lead', 'reviewer',
                'Sumusuri ng mga proposal: butas, kontradiksyon, panganib, at kulang na ebidensya.',
                "Ikaw ang Reviewer at Quality Lead. Suriin ang mga proposal: hanapin ang butas, kontradiksyon, "
                . "hindi napatunayang palagay, at panganib. Maging patas at tiyak — bawat isyu ay dapat may malinaw na tanong na masasagot. "
                . "Huwag mag-apruba kung may malaking isyu pang bukas. {$common}"],
        ];

        $agentIds = [];
        foreach ($roles as $i => [$handle, $name, $type, $desc, $instr]) {
            $agentIds[$handle] = DB::table('br_agents')->insertGetId([
                'handle'       => $handle,
                'display_name' => $name,
                'role_type'    => $type,
                'description'  => $desc,
                'instructions' => $instr,
                'provider'     => 'openai',
                'model'        => 'gpt-6-astra',
                'settings'     => json_encode(['effort' => 'high', 'max_output_tokens' => 16000, 'timeout_s' => 240]),
                'enabled'      => true,
                'sort_order'   => $i + 1,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        $groupId = DB::table('br_groups')->insertGetId([
            'name'        => 'Core Boardroom',
            'description' => 'CEO / Moderator, CTO, COO, at Reviewer / Quality Lead.',
            'is_default'  => true,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $n = 0;
        foreach ($agentIds as $id) {
            DB::table('br_group_agents')->insert(['group_id' => $groupId, 'agent_id' => $id, 'sort_order' => ++$n]);
        }

        // ── Capability registry. verified = ayon sa OPISYAL NA DOCS noong 2026-09-28.
        //    live_verified_at = napatunayan sa totoong request (null = hindi pa).
        $docs = '2026-09-28';
        $allEfforts = ['low', 'medium', 'high', 'xhigh', 'max'];
        $caps = [
            // OpenAI — Responses API
            ['openai', 'gpt-6-astra', 'GPT-6 Astra', 'responses', $allEfforts, 'high', [], null, [],
                'json_schema', 128000, 1050000, 10, 50, 'https://platform.openai.com/docs/models/gpt-6-astra',
                true, 'Hindi tinatanggap ang effort=none (400). Kasama ang reasoning tokens sa max_output_tokens.'],
            ['openai', 'gpt-5.2', 'GPT-5.2', 'responses', ['low'], 'low', [], null, [],
                'json_schema', null, null, 1.75, 14, 'https://platform.openai.com/docs/models',
                false, 'Ginagamit na ng checker_1 sa effort=low. Ibang effort value at output limit: hindi pa na-verify — i-edit dito kapag napatunayan.'],

            // Anthropic — native Messages API
            ['anthropic', 'claude-opus-5', 'Claude Opus 5', 'messages', $allEfforts, 'high', ['adaptive', 'disabled'], 'adaptive',
                ['thinking_disabled_max_effort' => 'high'],
                'json_schema', 128000, 1000000, 5, 25, 'https://platform.claude.com/docs/en/about-claude/models/overview',
                true, 'thinking=disabled ay tinatanggap lang sa effort na high pababa. Walang budget_tokens at sampling params.'],
            ['anthropic', 'claude-sonnet-5', 'Claude Sonnet 5', 'messages', $allEfforts, 'high', ['adaptive', 'disabled'], 'adaptive', [],
                'json_schema', 128000, 1000000, 2, 10, 'https://platform.claude.com/docs/en/about-claude/models/overview',
                true, 'Walang budget_tokens at sampling params.'],
            ['anthropic', 'claude-fable-5-1', 'Claude Fable 5.1', 'messages', $allEfforts, 'high', ['adaptive'], 'adaptive', [],
                'json_schema', 128000, 1000000, 10, 50, 'https://platform.claude.com/docs/en/about-claude/models/overview',
                true, 'Hindi tinatanggap ang thinking=disabled. Maaaring mag-refuse (stop_reason=refusal) — walang awtomatikong fallback sa ibang model.'],
            ['anthropic', 'claude-opus-4-8', 'Claude Opus 4.8', 'messages', $allEfforts, 'high', ['adaptive', 'disabled'], 'adaptive', [],
                'json_schema', 128000, 1000000, 5, 25, 'https://platform.claude.com/docs/en/about-claude/models/overview',
                true, 'Walang budget_tokens.'],
            ['anthropic', 'claude-haiku-4-5', 'Claude Haiku 4.5', 'messages', [], null, ['disabled', 'enabled'], 'disabled',
                ['thinking_budget_tokens' => ['min' => 1024, 'default' => 4096]],
                'json_schema', 64000, 200000, 1, 5, 'https://platform.claude.com/docs/en/about-claude/models/overview',
                true, 'Walang effort parameter. thinking=enabled ay nangangailangan ng budget_tokens (min 1024, mas mababa sa max_tokens).'],

            // DeepSeek — chat completions
            ['deepseek', 'deepseek-v4-pro', 'DeepSeek V4 Pro', 'chat.completions', ['low', 'high', 'max'], 'high', ['enabled', 'disabled'], 'enabled',
                ['effort_requires_thinking' => true],
                'json_object', 384000, null, 1.32, 3.96, 'https://api-docs.deepseek.com/',
                true, 'Peak price ang nakalagay (kalahati sa off-peak). Magkaiba ang dalawang doc page sa pwesto ng reasoning_effort — top-level ang ipinapadala; patunayan sa Test Connection.'],
            ['deepseek', 'deepseek-flash', 'DeepSeek Flash', 'chat.completions', ['low', 'high', 'max'], 'high', ['enabled', 'disabled'], 'disabled',
                ['effort_requires_thinking' => true],
                'json_object', 384000, null, 0.3, 1.2, 'https://api-docs.deepseek.com/',
                true, 'Peak price ang nakalagay (kalahati sa off-peak). Magkaiba ang dalawang doc page sa pwesto ng reasoning_effort — top-level ang ipinapadala; patunayan sa Test Connection.'],
        ];

        foreach ($caps as [$provider, $model, $label, $endpoint, $efforts, $defEffort, $thinking, $defThinking, $options,
                 $structured, $maxOut, $context, $priceIn, $priceOut, $doc, $verified, $notes]) {
            DB::table('br_model_capabilities')->insert([
                'provider'          => $provider,
                'model'             => $model,
                'label'             => $label,
                'endpoint'          => $endpoint,
                'efforts'           => json_encode($efforts),
                'default_effort'    => $defEffort,
                'thinking_modes'    => json_encode($thinking),
                'default_thinking'  => $defThinking,
                'optional_params'   => json_encode($options),
                'structured_output' => $structured,
                'max_output_tokens' => $maxOut,
                'context_window'    => $context,
                'price_in'          => $priceIn,
                'price_out'         => $priceOut,
                'doc_url'           => $doc,
                'verified'          => $verified,
                'verified_at'       => $verified ? $docs : null,
                'live_verified_at'  => null,
                'notes'             => $notes,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }
    }
};
