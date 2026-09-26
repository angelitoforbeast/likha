<?php

namespace App\Http\Controllers;

use App\Models\AstraAttachment;
use App\Models\AstraConversation;
use App\Models\AstraMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /astra — in-site AI chat (OpenAI Responses API).
 *
 *  - Ang API key ay NASA SERVER LANG (config services.openai.key / .env). Ang browser
 *    ay tumatawag sa /astra/send; ang server ang tumatawag sa OpenAI at ini-stream
 *    pabalik ang SSE events (pass-through) — same pattern ng GPTAdGenerator streaming.
 *  - Multi-user: bawat conversation/message/attachment ay naka-scope sa Auth::id().
 *  - Continuity: previous_response_id chain (store:true). Kapag expired/invalid sa
 *    OpenAI → fallback: ire-rebuild ang context mula sa sariling DB history.
 *  - Images: naka-private disk; isinasama bilang input_image (base64 data URL).
 */
class AstraChatController extends Controller
{
    /** Same list ng GPT Ad Generator — kilalang gumagana sa account; 4o family = vision. */
    public const ALLOWED_MODELS = [
        'gpt-4o'      => 'GPT-4o — pinakamaganda',
        'gpt-4o-mini' => 'GPT-4o mini — mabilis, mura',
        'gpt-4-turbo' => 'GPT-4 Turbo',
        'gpt-4'       => 'GPT-4 (legacy)',
    ];
    public const EFFORTS      = ['low', 'medium', 'high'];
    public const SEARCH_MODES = ['auto', 'required', 'off'];
    public const BUDGETS      = [2048, 4096, 8192, 16384];

    private const MAX_ATTACH     = 4;
    private const MAX_ATTACH_KB  = 8192;
    private const HISTORY_LIMIT  = 30;   // messages na ire-replay kapag walang previous_response_id
    private const OPENAI_TIMEOUT = 600;  // segundo — mahaba ang mga sagot na may search/reasoning

    private const INSTRUCTIONS = 'You are a thoughtful, capable assistant. Match the user’s language, including natural Tagalog/English when appropriate. Answer directly and clearly. Use available web search when current or uncertain facts require verification; cite sources you actually consulted. Follow up on prior conversation context. Clearly state limitations. Do not claim to run code, access a computer, or use tools that are unavailable. Provide concise reasoning summaries when supported, never private chain-of-thought.';

    // ── Pages / JSON ─────────────────────────────────────────────────────────

    public function index()
    {
        return view('astra.index', [
            'models'       => self::ALLOWED_MODELS,
            'defaultModel' => $this->defaultModel(),
            'efforts'      => self::EFFORTS,
            'searchModes'  => self::SEARCH_MODES,
            'budgets'      => self::BUDGETS,
            'maxAttach'    => self::MAX_ATTACH,
            'maxAttachKb'  => self::MAX_ATTACH_KB,
        ]);
    }

    /** GET /astra/conversations — listahan ng sariling conversations. */
    public function conversations()
    {
        $rows = AstraConversation::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(300)
            ->get(['id', 'title', 'model', 'last_message_at', 'updated_at']);

        return response()->json(['ok' => true, 'conversations' => $rows]);
    }

    /** GET /astra/conversations/{id} — messages ng isang conversation (owner lang). */
    public function show(int $id)
    {
        $conv = $this->ownConversation($id);
        $msgs = $conv->messages()->get([
            'id', 'role', 'text', 'reasoning', 'sources', 'attachments', 'status', 'usage', 'duration_ms', 'created_at',
        ]);

        return response()->json(['ok' => true, 'conversation' => $conv, 'messages' => $msgs]);
    }

    /** PATCH /astra/conversations/{id} — rename. */
    public function rename(Request $request, int $id)
    {
        $conv = $this->ownConversation($id);
        $data = $request->validate(['title' => ['required', 'string', 'max:160']]);
        $conv->update(['title' => trim($data['title'])]);
        return response()->json(['ok' => true, 'title' => $conv->title]);
    }

    /** DELETE /astra/conversations/{id} — soft delete. */
    public function destroy(int $id)
    {
        $conv = $this->ownConversation($id);
        $conv->delete();
        return response()->json(['ok' => true]);
    }

    /** GET /astra/conversations/{id}/export — plain-text download (walang key, gaya ng file). */
    public function export(int $id)
    {
        $conv  = $this->ownConversation($id);
        $lines = [];
        foreach ($conv->messages as $m) {
            $block = strtoupper((string) $m->role) . "\n" . (string) $m->text;
            if ($m->reasoning) $block .= "\n\nReasoning summary:\n" . $m->reasoning;
            if (!empty($m->sources)) {
                $block .= "\n\nSources:\n" . implode("\n", array_map(fn ($s) => ($s['title'] ?? $s['url']) . ' — ' . ($s['url'] ?? ''), $m->sources));
            }
            $lines[] = $block;
        }
        $name = Str::slug(Str::limit((string) ($conv->title ?: 'astra-conversation'), 60, '')) ?: 'astra-conversation';

        return response(implode("\n\n--------------------\n\n", $lines), 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '.txt"',
        ]);
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    /** POST /astra/upload — isang image; naka-private disk; nililink sa message pag na-send. */
    public function upload(Request $request)
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:' . self::MAX_ATTACH_KB],
        ]);

        $file = $request->file('image');
        $ext  = strtolower($file->getClientOriginalExtension() ?: 'png');
        $dir  = 'astra/' . Auth::id() . '/' . now('Asia/Manila')->format('Y-m');
        $name = Str::uuid()->toString() . '.' . $ext;
        $path = $file->storeAs($dir, $name, 'local');

        $att = AstraAttachment::create([
            'user_id'       => Auth::id(),
            'path'          => $path,
            'mime'          => $file->getMimeType() ?: 'image/' . $ext,
            'size'          => (int) $file->getSize(),
            'original_name' => $file->getClientOriginalName(),
        ]);

        return response()->json(['ok' => true, 'attachment' => $this->attachmentPayload($att)]);
    }

    /** GET /astra/attachments/{id} — i-serve ang image (owner lang). */
    public function attachment(int $id)
    {
        $att = AstraAttachment::query()->where('user_id', Auth::id())->findOrFail($id);
        $abs = Storage::disk('local')->path($att->path);
        abort_unless(is_file($abs), 404);

        return response()->file($abs, [
            'Content-Type'  => $att->mime,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    // ── Send (SSE stream) ────────────────────────────────────────────────────

    /**
     * POST /astra/send — JSON body: conversation_id?, prompt, model, effort, search,
     * budget, attachments[] (ids). Response = text/event-stream: pass-through ng
     * OpenAI Responses events + sariling `likha.start` / `likha.saved` events.
     */
    public function send(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'prompt'          => ['nullable', 'string', 'max:60000'],
            'model'           => ['nullable', 'string', 'max:60'],
            'effort'          => ['nullable', 'string', 'in:' . implode(',', self::EFFORTS)],
            'search'          => ['nullable', 'string', 'in:' . implode(',', self::SEARCH_MODES)],
            'budget'          => ['nullable', 'integer'],
            'attachments'     => ['nullable', 'array', 'max:' . self::MAX_ATTACH],
            'attachments.*'   => ['integer'],
        ]);

        $userId = (int) Auth::id();
        $prompt = trim((string) ($data['prompt'] ?? ''));
        $attIds = array_values(array_unique(array_map('intval', $data['attachments'] ?? [])));
        if ($prompt === '' && empty($attIds)) {
            abort(422, 'Walang mensahe.');
        }

        $model  = isset(self::ALLOWED_MODELS[$data['model'] ?? '']) ? $data['model'] : $this->defaultModel();
        $effort = $data['effort'] ?? 'medium';
        $search = $data['search'] ?? 'auto';
        $budget = in_array((int) ($data['budget'] ?? 0), self::BUDGETS, true) ? (int) $data['budget'] : 8192;

        // 1) Conversation — sarili lang; gumawa kung wala pa (title = unang mensahe).
        $conv = !empty($data['conversation_id'])
            ? $this->ownConversation((int) $data['conversation_id'])
            : AstraConversation::create([
                'user_id' => $userId,
                'title'   => Str::limit($prompt !== '' ? $prompt : 'Larawan', 60, '…'),
                'model'   => $model,
            ]);
        $conv->fill(['model' => $model, 'effort' => $effort, 'search_mode' => $search, 'max_output_tokens' => $budget])->save();

        // 2) Attachments — sarili lang, hindi pa nakalink.
        $atts = empty($attIds) ? collect() : AstraAttachment::query()
            ->where('user_id', $userId)->whereNull('message_id')->whereIn('id', $attIds)->get();

        // 3) User message (naka-save agad — kahit mag-fail ang API, nasa history).
        $userMsg = AstraMessage::create([
            'conversation_id' => $conv->id,
            'user_id'         => $userId,
            'role'            => 'user',
            'text'            => $prompt,
            'attachments'     => $atts->map(fn ($a) => $this->attachmentPayload($a))->values()->all() ?: null,
            'status'          => 'completed',
        ]);
        if ($atts->isNotEmpty()) {
            AstraAttachment::whereIn('id', $atts->pluck('id'))->update(['conversation_id' => $conv->id, 'message_id' => $userMsg->id]);
        }
        $conv->update(['last_message_at' => now('Asia/Manila')]);

        // 4) Current turn content (text + images bilang base64 data URL — walang public URL).
        $turn = [];
        if ($prompt !== '') $turn[] = ['type' => 'input_text', 'text' => $prompt];
        foreach ($atts as $a) {
            try {
                $bin = Storage::disk('local')->get($a->path);
                $turn[] = ['type' => 'input_image', 'image_url' => 'data:' . $a->mime . ';base64,' . base64_encode($bin), 'detail' => 'auto'];
            } catch (\Throwable $e) {
                Log::warning('Astra: attachment unreadable', ['id' => $a->id, 'err' => $e->getMessage()]);
            }
        }
        $currentTurn = ['role' => 'user', 'content' => $turn];

        $basePayload = [
            'model'             => $model,
            'instructions'      => self::INSTRUCTIONS,
            'stream'            => true,
            'store'             => true,
            'max_output_tokens' => $budget,
        ];
        if ($this->supportsReasoning($model)) {
            $basePayload['reasoning'] = ['effort' => $effort, 'summary' => 'auto'];
        }
        if ($search !== 'off') {
            $basePayload['tools']       = [['type' => 'web_search']];
            $basePayload['tool_choice'] = $search === 'required' ? 'required' : 'auto';
            $basePayload['include']     = ['web_search_call.action.sources'];
        }

        $prevId = $conv->openai_last_response_id;

        return $this->streamToClient($conv, $userMsg, $basePayload, $currentTurn, $prevId);
    }

    /**
     * Ang mismong stream: tawag sa OpenAI (may fallback), pass-through ng events,
     * tapos i-save ang assistant message pagkatapos.
     */
    private function streamToClient(AstraConversation $conv, AstraMessage $userMsg, array $basePayload, array $currentTurn, ?string $prevId): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($conv, $userMsg, $basePayload, $currentTurn, $prevId) {
            @set_time_limit(0);
            @ob_implicit_flush(true);
            while (ob_get_level() > 0) @ob_end_flush();

            $emit = function (array $obj): void {
                echo 'data: ' . json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                @flush();
            };

            $started = microtime(true);
            $emit(['type' => 'likha.start', 'conversation_id' => $conv->id, 'title' => $conv->title, 'user_message_id' => $userMsg->id]);

            $text = ''; $reasoning = []; $terminal = null; $aborted = false; $errorMsg = null;

            try {
                // Unang subok: previous_response_id (mura, dala ang context). Fallback: DB history replay.
                $attempts = [];
                if ($prevId) {
                    $attempts[] = $basePayload + ['previous_response_id' => $prevId, 'input' => [$currentTurn]];
                }
                $attempts[] = $basePayload + ['input' => array_merge($this->historyInput($conv, $userMsg->id), [$currentTurn])];

                $upstream = null;
                foreach ($attempts as $i => $payload) {
                    $upstream = Http::withToken((string) config('services.openai.key'))
                        ->connectTimeout(20)
                        ->timeout(self::OPENAI_TIMEOUT)
                        ->withOptions(['stream' => true])
                        ->post('https://api.openai.com/v1/responses', $payload);

                    if ($upstream->successful()) break;

                    // Kapag invalid/expired ang previous_response_id → subukan ang history replay.
                    $err = (string) ($upstream->json('error.message') ?? $upstream->body());
                    $isPrevIssue = $i === 0 && count($attempts) > 1
                        && ($upstream->status() === 404 || stripos($err, 'previous_response') !== false || stripos($err, 'not found') !== false);
                    if ($isPrevIssue) {
                        $emit(['type' => 'likha.notice', 'message' => 'Na-expire ang API context — ini-rebuild mula sa history.']);
                        continue;
                    }
                    throw new \RuntimeException($this->readableError($upstream->status(), $err));
                }

                $body = $upstream->toPsrResponse()->getBody();
                $buffer = '';
                while (!$body->eof()) {
                    if (connection_aborted()) { $aborted = true; break; }
                    $chunk = $body->read(8192);
                    if ($chunk === '' || $chunk === false) continue;
                    $buffer .= $chunk;

                    while (($pos = strpos($buffer, "\n\n")) !== false) {
                        $event  = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 2);
                        foreach (explode("\n", $event) as $line) {
                            $line = rtrim($line, "\r");
                            if (!str_starts_with($line, 'data:')) continue;
                            $json = trim(substr($line, 5));
                            if ($json === '' || $json === '[DONE]') continue;
                            $e = json_decode($json, true);
                            if (!is_array($e)) continue;

                            $t = (string) ($e['type'] ?? '');
                            if ($t === 'response.output_text.delta' || $t === 'response.refusal.delta') {
                                $text .= (string) ($e['delta'] ?? '');
                            } elseif ($t === 'response.reasoning_summary_text.done') {
                                $reasoning[] = (string) ($e['text'] ?? '');
                            } elseif (in_array($t, ['response.completed', 'response.incomplete', 'response.failed'], true)) {
                                $terminal = $e['response'] ?? null;
                            }
                            // Pass-through sa client (same event shapes na inaasahan ng UI).
                            echo 'data: ' . $json . "\n\n";
                            @flush();
                        }
                    }
                }
            } catch (\Throwable $ex) {
                $errorMsg = $ex->getMessage();
                Log::error('Astra stream error: ' . $errorMsg, ['conversation' => $conv->id]);
                $emit(['type' => 'error', 'message' => $errorMsg]);
            }

            // ── Persist ang assistant turn (best-effort) ──────────────────────
            try {
                $status  = $aborted ? 'aborted' : ($errorMsg ? 'failed' : ((string) ($terminal['status'] ?? 'completed')));
                $output  = is_array($terminal['output'] ?? null) ? $terminal['output'] : [];
                $sources = $this->collectSources($output);
                $final   = $this->finalText($output) ?: $text;
                $reason  = $this->reasoningText($output) ?: implode("\n\n", array_filter($reasoning));

                $asst = AstraMessage::create([
                    'conversation_id'    => $conv->id,
                    'user_id'            => $conv->user_id,
                    'role'               => 'assistant',
                    'text'               => $final,
                    'reasoning'          => $reason ?: null,
                    'sources'            => $sources ?: null,
                    'openai_response_id' => $terminal['id'] ?? null,
                    'status'             => $status,
                    'usage'              => $terminal['usage'] ?? null,
                    'duration_ms'        => (int) round((microtime(true) - $started) * 1000),
                ]);

                if (!$aborted && !$errorMsg && !empty($terminal['id'])) {
                    $conv->update(['openai_last_response_id' => $terminal['id'], 'last_message_at' => now('Asia/Manila')]);
                }

                $emit(['type' => 'likha.saved', 'conversation_id' => $conv->id, 'message_id' => $asst->id, 'title' => $conv->title, 'status' => $status]);
            } catch (\Throwable $ex) {
                Log::error('Astra persist error: ' . $ex->getMessage());
            }

            echo "data: [DONE]\n\n";
            @flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no'); // nginx: huwag i-buffer
        return $response;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function ownConversation(int $id): AstraConversation
    {
        return AstraConversation::query()->where('user_id', Auth::id())->findOrFail($id);
    }

    private function defaultModel(): string
    {
        $m = (string) config('services.openai.model', 'gpt-4o-mini');
        return isset(self::ALLOWED_MODELS[$m]) ? $m : array_key_first(self::ALLOWED_MODELS);
    }

    /** reasoning.effort ay para lang sa reasoning models (o-series / gpt-5*); mag-e-error sa 4o. */
    private function supportsReasoning(string $model): bool
    {
        return (bool) preg_match('/^(o\d|gpt-5)/i', $model);
    }

    private function attachmentPayload(AstraAttachment $a): array
    {
        return [
            'id'   => $a->id,
            'name' => $a->original_name,
            'mime' => $a->mime,
            'url'  => route('astra.attachment', $a->id),
        ];
    }

    /**
     * DB history → Responses `input` items (text lang; ang images ay sa current turn
     * lang para hindi mag-resend ng base64 kada turn).
     * @return array<int,array<string,mixed>>
     */
    private function historyInput(AstraConversation $conv, int $excludeMsgId): array
    {
        $rows = AstraMessage::query()
            ->where('conversation_id', $conv->id)
            ->where('id', '<', $excludeMsgId)
            ->whereIn('role', ['user', 'assistant'])
            ->whereIn('status', ['completed', 'incomplete'])
            ->orderByDesc('id')->limit(self::HISTORY_LIMIT)
            ->get(['role', 'text'])
            ->reverse()->values();

        $out = [];
        foreach ($rows as $r) {
            $txt = trim((string) $r->text);
            if ($txt === '') continue;
            $out[] = $r->role === 'assistant'
                ? ['role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $txt]]]
                : ['role' => 'user',      'content' => [['type' => 'input_text',  'text' => $txt]]];
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $output */
    private function collectSources(array $output): array
    {
        $found = [];
        foreach ($output as $item) {
            foreach ((array) ($item['content'] ?? []) as $c) {
                foreach ((array) ($c['annotations'] ?? []) as $a) {
                    if (($a['type'] ?? '') === 'url_citation' && !empty($a['url'])) {
                        $found[$a['url']] = ['url' => $a['url'], 'title' => $a['title'] ?? $a['url']];
                    }
                }
            }
            foreach ((array) ($item['action']['sources'] ?? []) as $s) {
                if (!empty($s['url'])) $found[$s['url']] = ['url' => $s['url'], 'title' => $s['title'] ?? $s['url']];
            }
        }
        return array_values($found);
    }

    /** @param array<int,array<string,mixed>> $output */
    private function finalText(array $output): string
    {
        $parts = [];
        foreach ($output as $item) {
            if (($item['type'] ?? '') !== 'message') continue;
            foreach ((array) ($item['content'] ?? []) as $c) {
                if (($c['type'] ?? '') === 'output_text' && isset($c['text'])) $parts[] = (string) $c['text'];
                elseif (($c['type'] ?? '') === 'refusal')                      $parts[] = (string) ($c['refusal'] ?? '');
            }
        }
        return trim(implode("\n\n", $parts));
    }

    /** @param array<int,array<string,mixed>> $output */
    private function reasoningText(array $output): string
    {
        $parts = [];
        foreach ($output as $item) {
            if (($item['type'] ?? '') !== 'reasoning') continue;
            foreach ((array) ($item['summary'] ?? []) as $s) {
                if (!empty($s['text'])) $parts[] = (string) $s['text'];
            }
        }
        return trim(implode("\n\n", $parts));
    }

    private function readableError(int $status, string $msg): string
    {
        return match (true) {
            $status === 401 => 'Tinanggihan ng OpenAI ang API key ng server. Ipa-check sa admin.',
            $status === 429 => 'Naabot ang API quota/rate limit. Subukan ulit mamaya. ' . $msg,
            $status === 403 => 'Walang permiso ang API project para sa request na ito. ' . $msg,
            $status === 404 => 'Hindi available ang model o conversation. ' . $msg,
            default         => $msg !== '' ? $msg : "OpenAI HTTP {$status}",
        };
    }
}
