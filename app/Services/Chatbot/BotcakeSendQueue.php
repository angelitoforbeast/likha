<?php

namespace App\Services\Chatbot;

use App\Jobs\ResumeChatbotSend;
use App\Jobs\ResumeChatbotSequenceSend;
use App\Jobs\SendChatblastMessage;
use App\Models\BotcakeMessage;
use App\Services\ChatblastSender;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ang padala sa Botcake na hindi naghihintay: ang ticket (botcake_send_requests) na ipinapasa ng takbo ng chatbot (isang
 * send_content) o ng chatblast job (ang tatlong hakbang), kinukuha ng botcake:send-pool. Gaya ng ChatbotAiQueue, ang bawat
 * paglipat ng status ay kondisyonal (WHERE sa dating status at sa may hawak), kaya walang nadodoble:
 * chatbot: pending → sending → done | failed → resumed; chatblast: pending → sending → done.
 * Hindi na inuulit ang padalang natawag na (called_at, o ang send_flow sa steps ng chatblast) pagkatapos ng crash.
 */
class BotcakeSendQueue
{
    public const TABLE = 'botcake_send_requests';
    public const MARK = 'send:'; // ang __ai ng customer habang hinihintay ang resulta ng padala
    public const CHATBOT_SECONDS = 600; // gaya ng naghihintay na worker sa matagal na pila; ang chat na lampas 30 minuto ay hindi na sinasagot
    public const DEAD_POOL_SECONDS = 60; // ang "sending" ay ibinabalik lang kapag ganito na katagal walang heartbeat (hindi ang mabagal na pool)
    // Ang paghihintay sa pila ay walang bayad (ang lock ng customer ay kinukuha ng pool kapag sinimulan na ang mensahe), kaya
    // ang deadline ay para lang sa talagang naipit (hal. patay ang pool), hindi sa mahabang pila ng malaking chatblast.
    public const CHATBLAST_SECONDS = 3 * 3600;
    public const KEEP_DAYS = 2;
    public const REDISPATCH_SECONDS = 30;

    /** Naka-on, may buhay na botcake:send-pool, at may table na. */
    public static function enabled(): bool
    {
        return (bool) config('services.botcake.async_sends', true) && BotcakeSendPool::alive() && Schema::hasTable(self::TABLE);
    }

    /**
     * Ipapasa ba ang padala ng takbong ito: live (hindi Test o sequence), sa Botcake, at naka-on ang mga sagot. Ang page na
     * nasa Forbeast Gateway ay diretso (ang gateway ang may pila at rate kada page).
     */
    public static function async(FlowRun $run, Outbox $outbox): bool
    {
        return !$run->test && $run->startedBy === null && $outbox instanceof BotcakeOutbox && BotcakeOutbox::enabled() && self::enabled()
            && ChatbotProvider::name($run->pageName) === ChatbotProvider::BOTCAKE;
    }

    public static function chatbotCustomer(int|string $pageId, int|string $psid): string
    {
        return 'chatbot:' . $pageId . ':' . $psid;
    }

    /**
     * Ang send_content ng isang node bilang ticket, at ang marka ng customer. Null kapag walang token ang page (ang
     * naghihintay na padala ang magtatala ng failed, gaya ng dati).
     */
    public static function submitChatbot(FlowRun $run, string $nodeId, array $messages): ?string
    {
        if (trim((string) app(PageSettings::class)->get($run->pageName, 'botcake', 'token')) === '') return null;
        $id = (string) Str::uuid();
        $run->runId ??= bin2hex(random_bytes(4));
        DB::table(self::TABLE)->insert([
            'request_id' => $id, 'kind' => 'chatbot', 'priority' => 0, 'customer' => self::chatbotCustomer($run->pageId, $run->fields->psid),
            'page_id' => (int) $run->pageId, 'page_name' => $run->pageName, 'psid' => (string) $run->fields->psid, 'chat_id' => $run->chatId,
            'flow_id' => $run->flowId, 'node_id' => $nodeId, 'run_id' => $run->runId,
            'payload' => json_encode(['messages' => $messages] + ($run->tags ? ['tags' => $run->tags] : []), JSON_UNESCAPED_UNICODE),
            'context' => json_encode(['input' => $run->input, 'full_name' => $run->fullName, 'received_at' => $run->receivedAt,
                'ai_calls' => $run->aiCalls, 'steps' => $run->steps, 'ad_group' => $run->adGroup], JSON_UNESCAPED_UNICODE),
            'status' => 'pending', 'deadline_at' => now()->addSeconds(self::CHATBOT_SECONDS), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $run->fields->set(CustomerFields::AI, self::MARK . $id);

        return $id;
    }

    /**
     * Ang tatlong hakbang ng isang chatblast na mensahe bilang ticket. Walang lock habang naghihintay sa pila: ang pool ang
     * kukuha ng lock ng customer kapag sinimulan na ang mensahe (started), at ang ayos kada customer ay sa claim.
     */
    public static function submitChatblast(BotcakeMessage $m, array $cfg, string $lockKey): bool
    {
        if (!self::enabled()) return false;
        DB::table(self::TABLE)->insert([
            // Ang Delivering ay nauuna sa ibang chatblast (gaya ng chatblast-high sa Horizon).
            'request_id' => (string) Str::uuid(), 'kind' => 'chatblast', 'priority' => $m->kind === ChatblastSender::HIGH_KIND ? 1 : 2, 'customer' => $lockKey,
            'page_id' => (int) $cfg['page_id'], 'page_name' => $m->page_name, 'psid' => (string) $m->psid, 'message_id' => $m->id,
            'payload' => json_encode(['lock_key' => $lockKey]), // ang token at mga ID ay binabasa ng pool sa page settings
            'status' => 'pending', 'deadline_at' => now()->addSeconds(self::CHATBLAST_SECONDS), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return true;
    }

    /** Ang ticket na kasama ng resume job (mula sa row, gaya ng nabasa ng pool). */
    public static function ticket(object $row): array
    {
        return ['request_id' => (string) $row->request_id, 'page_id' => (int) $row->page_id, 'psid' => (int) $row->psid,
            'chat_id' => $row->chat_id === null ? null : (int) $row->chat_id, 'flow_id' => (int) $row->flow_id, 'node_id' => (string) $row->node_id];
    }

    /** May hawak pa bang ticket ang chatblast na mensahe (pending o sending)? */
    public static function holds(int $messageId): bool
    {
        return Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->where('message_id', $messageId)->whereIn('status', ['pending', 'sending'])->exists();
    }

    /**
     * Kunin ang hanggang $n na pending, kondisyonal, para sa pool na $owner. Laging ang pinakaunang hindi pa tapos ng bawat
     * customer lang: ang kasunod ay naghihintay hanggang matapos ito (ayos ng padala kada customer). Ayon sa priority: ang
     * chatbot, saka ang Delivering, saka ang ibang chatblast. $accept (ng pool) ang nagpapasya kung may puwang pa ang page ng
     * row; ang tinanggihan ay naiiwang pending. $fullPages: puno na ang page para sa chatblast; $fullForReplies: puno na ang
     * page para sa sagot ng bot (priority 0), na may sariling puwesto kahit puno ng chatblast ang page. $repliesOnly: puno na
     * ng chatblast ang pool, kaya ang mga sagot lang ang hinahanap (hindi binabasa ang daan-daang chatblast kada ikot).
     * @return array<int, object>
     */
    public static function claim(string $owner, int $n, ?callable $accept = null, ?callable $then = null, array $fullPages = [], array $fullForReplies = [], bool $repliesOnly = false, bool $sequence = false): array
    {
        if ($n <= 0) return [];
        $t = self::TABLE;
        $rows = DB::table("$t as t")->where('t.status', 'pending')->where(fn ($q) => $q->whereNull('t.next_at')->orWhere('t.next_at', '<=', now()))
            // Ang sequence ticket ay walang deadline na nagpapabigo: naghihintay hanggang sa pool (huli, hindi nawawala).
            ->where(fn ($q) => $sequence ? $q : $q->where('t.deadline_at', '>', now()))
            // Ang page na puno na ang lane ay hindi na isinasama sa SQL: hindi nito mapupuno ang listahan ng kandidato (ang ibang page ay nakikita pa rin).
            ->when($fullPages, fn ($q) => $q->where(fn ($w) => $w->where('t.priority', 0)->orWhereNotIn('t.page_name', $fullPages)))
            ->when($fullForReplies, fn ($q) => $q->where(fn ($w) => $w->where('t.priority', '>', 0)->orWhereNotIn('t.page_name', $fullForReplies)))
            ->when($repliesOnly, fn ($q) => $q->where('t.priority', 0))
            // Ang sequence ticket ay sa chatbot:sequence-pool lang; ang botcake:send-pool ay hindi kailanman kumukuha nito.
            ->where('t.priority', $sequence ? '=' : '<', self::SEQUENCE_PRIORITY)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from("$t as o")->whereColumn('o.customer', 't.customer')->whereColumn('o.id', '<', 't.id')
                ->whereIn('o.status', ['pending', 'sending']))
            ->orderBy('t.priority')->orderBy('t.id')->limit($n * 5)->get(['t.id', 't.request_id', 't.page_name', 't.kind', 't.priority']);
        $out = [];
        foreach ($rows as $candidate) {
            if (count($out) >= $n) break;
            if ($accept && !$accept($candidate)) continue;
            $id = $candidate->id;
            $ok = DB::table($t)->where('id', $id)->where('status', 'pending')
                ->update(['status' => 'sending', 'claimed_by' => $owner, 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            if ($ok !== 1) continue;
            $row = DB::table($t)->where('id', $id)->first();
            if ($then) $then($row); // agad: ang susunod na $accept ay nakikita na ito
            $out[] = $row;
        }

        return $out;
    }

    /** May hawak pa ba ang $owner sa ticket (sending)? Sinusuri bago ang bawat tawag sa Botcake. */
    public static function owns(object $row, string $owner): bool
    {
        return DB::table(self::TABLE)->where('id', $row->id)->where('status', 'sending')->where('claimed_by', $owner)->exists();
    }

    /**
     * Itatala bago tumawag sa Botcake (ang send_content ng chatbot, o ang send_flow ng chatblast): pagkatapos nito, hindi na
     * inuulit kahit mag-crash. false = hindi na hawak ng $owner (hal. kinuha ng bagong pool, o natawag na): walang tawag.
     */
    public static function called(object $row, string $owner): bool
    {
        return DB::table(self::TABLE)->where('id', $row->id)->where('status', 'sending')->where('claimed_by', $owner)->whereNull('called_at')
            ->update(['called_at' => now(), 'updated_at' => now()]) === 1;
    }

    /**
     * Ang resulta ng send_content (sent o failed) ng ticket na hawak pa ng $owner: ang talaan (chatbot_replies), saka ang
     * resume. false = hindi na hawak (huli na o kinuha ng iba): walang ginagawa.
     * @param array{status: string, error: ?string, http: ?int} $o
     */
    public static function finishChatbot(object $row, ?string $owner, array $o): bool
    {
        // Walang may hawak (sweep): ang pending lang, para hindi maagaw ang kakukuha pa lang ng pool.
        $q = DB::table(self::TABLE)->where('id', $row->id);
        if ($owner !== null) $q->where('status', 'sending')->where('claimed_by', $owner);
        else $q->where('status', 'pending');
        $ok = $q->update(['status' => $o['status'] === 'failed' ? 'failed' : 'done', 'result' => json_encode($o, JSON_UNESCAPED_UNICODE),
            'error' => $o['error'] === null ? null : mb_substr($o['error'], 0, 255), 'http' => $o['http'], 'done_at' => now(), 'updated_at' => now()]);
        if ($ok !== 1) return false;
        $payload = (array) json_decode((string) $row->payload, true);
        BotcakeOutbox::record((int) $row->page_id, (int) $row->psid, $row->chat_id === null ? null : (int) $row->chat_id, $row->flow_id === null ? null : (int) $row->flow_id,
            (string) $row->node_id, 'send', (array) ($payload['messages'] ?? []), $o['status'], $o['error'], $o['http'], $o['raw'] ?? null,
            is_array($payload['tags'] ?? null) ? $payload['tags'] : null, ChatbotProvider::BOTCAKE); // ang pool ay laging Botcake (send_content)
        ResumeChatbotSend::dispatch(self::ticket($row));

        return true;
    }

    /**
     * Tapos na ang chatblast na mensahe (ang status ay naisulat na ng stepper): bitawan ang lock at itakda ang gap. Kapag
     * hindi pa nakuha ang lock (natapos bago magsimula, hal. nagbago ang page settings), walang gap: walang naipadala.
     */
    public static function finishChatblast(object $row, ?string $owner): bool
    {
        $q = DB::table(self::TABLE)->where('id', $row->id);
        if ($owner !== null) $q->where('status', 'sending')->where('claimed_by', $owner);
        else $q->where('status', 'pending');
        if ($q->update(['status' => 'done', 'done_at' => now(), 'updated_at' => now()]) !== 1) return false;
        if ((string) $row->lock_owner !== '') app(ChatblastSender::class)->afterPool((string) $row->customer, (string) $row->lock_owner);

        return true;
    }

    /**
     * Sinimulan ng pool ang chatblast na hawak nito: ang lock ng customer ($lockOwner) ay itinatala sa ticket, para mabitawan
     * ng finishChatblast, recover o sweep. false = hindi na hawak ng $owner.
     */
    public static function started(object $row, string $owner, string $lockOwner): bool
    {
        if (DB::table(self::TABLE)->where('id', $row->id)->where('status', 'sending')->where('claimed_by', $owner)
            ->update(['lock_owner' => $lockOwner, 'updated_at' => now()]) !== 1) return false;
        $row->lock_owner = $lockOwner;

        return true;
    }

    /**
     * Hindi pa masisimulan ang chatblast (may hawak ng customer o nasa gap pa): balik sa pending, kukunin ulit pagkalipas ng
     * $seconds. Walang nabibigo at walang job na ibinabalik; ang kasunod ng customer ay naghihintay pa rin sa likod nito.
     */
    public static function later(object $row, string $owner, int $seconds): bool
    {
        return DB::table(self::TABLE)->where('id', $row->id)->where('status', 'sending')->where('claimed_by', $owner)
            ->update(['status' => 'pending', 'claimed_by' => null, 'next_at' => now()->addSeconds($seconds), 'updated_at' => now()]) === 1;
    }

    /**
     * Ang naiwang "sending" ng pool na namatay (hindi $owner):
     * - hindi pa natawag → pending (ipapadala nang isang beses ng bagong pool);
     * - chatbot, natawag na → failed na "Botcake did not answer" (gaya ng nawalang tawag), saka ang resume; hindi inuulit;
     * - chatblast → unknown kapag natawag na ang send_flow, kung hindi ay uulitin ang tatlong hakbang.
     */
    public static function recover(?string $owner): int
    {
        if (!Schema::hasTable(self::TABLE)) return 0;
        $n = 0;
        $rows = DB::table(self::TABLE)->where('status', 'sending')->where('kind', '!=', self::SEQUENCE)->when($owner, fn ($q) => $q->where(fn ($w) => $w->whereNull('claimed_by')->orWhere('claimed_by', '!=', $owner)))->get();
        foreach ($rows as $row) {
            $back = fn () => DB::table(self::TABLE)->where('id', $row->id)->where('status', 'sending')->where('claimed_by', $row->claimed_by)
                ->update(['status' => 'pending', 'claimed_by' => null, 'called_at' => null, 'updated_at' => now()]);
            if ($row->kind === 'chatblast') {
                if (app(ChatblastSender::class)->lost((int) $row->message_id, $row->called_at !== null)) {
                    // Naghihintay ulit sa pila: hindi hawak ang lock ng customer (kukunin ulit ng pool kapag sinimulan).
                    if ($back() === 1 && (string) $row->lock_owner !== '') {
                        Cache::restoreLock((string) $row->customer, (string) $row->lock_owner)->release();
                        DB::table(self::TABLE)->where('id', $row->id)->where('status', 'pending')->update(['lock_owner' => null]);
                    }
                } else {
                    self::finishChatblast($row, $row->claimed_by);
                }
            } elseif ($row->called_at === null) {
                $back();
            } else {
                self::finishChatbot($row, $row->claimed_by, ['status' => 'failed', 'error' => 'Botcake did not answer (HTTP 0)', 'http' => null]);
            }
            $n++;
        }

        return $n;
    }

    /**
     * Ang pending na lampas na sa deadline (hindi naipadala ng pool sa oras): chatbot → failed (failed port); chatblast →
     * balik sa queue (ang job ang magpapadala ulit). Ang "sending" ng patay na pool kapag walang buhay na pool. Ang done o
     * failed na hindi pa naituloy → resume ulit. Ang luma → binubura.
     * @return array{failed: int, requeued: int, redispatched: int, pruned: int}
     */
    public static function sweep(): array
    {
        $out = ['failed' => 0, 'requeued' => 0, 'redispatched' => 0, 'pruned' => 0];
        if (!Schema::hasTable(self::TABLE)) return $out;
        if (!BotcakeSendPool::alive(self::DEAD_POOL_SECONDS)) self::recover(null);

        foreach (DB::table(self::TABLE)->where('status', 'pending')->where('kind', '!=', self::SEQUENCE)->where('deadline_at', '<=', now())->orderBy('id')->limit(500)->get() as $row) {
            if ($row->kind === 'chatblast') {
                if (DB::table(self::TABLE)->where('id', $row->id)->where('status', 'pending')->update(['status' => 'failed', 'error' => 'Not sent in time; queued again', 'done_at' => now(), 'updated_at' => now()]) !== 1) continue;
                BotcakeMessage::whereKey($row->message_id)->where('status', 'sending')->update(['status' => 'queued']);
                // Walang naipadala: ang lock lang ang binibitawan (walang gap ng customer).
                Cache::restoreLock((string) $row->customer, (string) $row->lock_owner)->release();
                SendChatblastMessage::dispatch((int) $row->message_id);
                $out['requeued']++;
            } elseif (self::finishChatbot($row, null, ['status' => 'failed', 'error' => 'The send pool did not send it in time', 'http' => null])) {
                $out['failed']++;
            }
        }

        foreach (DB::table(self::TABLE)->where('kind', 'chatbot')->whereIn('status', ['done', 'failed'])->where('updated_at', '<=', now()->subSeconds(self::REDISPATCH_SECONDS))->orderBy('id')->limit(500)->get() as $row) {
            DB::table(self::TABLE)->where('id', $row->id)->update(['updated_at' => now()]);
            ResumeChatbotSend::dispatch(self::ticket($row));
            $out['redispatched']++;
        }

        $out['pruned'] = DB::table(self::TABLE)->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->where('kind', '!=', self::SEQUENCE)->whereIn('status', ['resumed', 'failed', 'done'])->orderBy('id')->limit(5000)->delete();

        return $out;
    }

    // ---- Ang sequence step (chatbot:sequence-pool) ----

    public const SEQUENCE = 'sequence';
    public const SEQUENCE_PRIORITY = 3;
    public const SEQUENCE_SECONDS = 25 * 3600; // talaan lang: ang sequence ticket ay hindi nabibigo dahil huli (ang 24 oras ay sinusuri bago ang tawag)
    /** Ang mga node na walang epekto kapag inulit ang step mula sa Start (allowlist; ang Set ay set o clear lang). */
    public const REPEATABLE = ['start', 'condition', 'send', 'end'];

    /** May table na (naka-memo sa app kapag mayroon na: tinatawag ito sa bawat step at padala). */
    public static function hasTable(): bool
    {
        $key = 'botcake-send-queue.table';
        if (app()->bound($key)) return true;
        if (!Schema::hasTable(self::TABLE)) return false;
        app()->instance($key, true);

        return true;
    }

    public static function sequenceCustomer(int|string $pageId, int|string $psid): string
    {
        return 'sequence:' . $pageId . ':' . $psid;
    }

    /**
     * Handa ba ang daan ng sequence: naka-on (services.chatbot.sequence_handoff), may column at table, buhay ang
     * chatbot:sequence-pool (may tanda nito sa beat), at ang botcake:send-pool ay patay o bago (iniiwan ang sequence; ang
     * luma ay kukunin ang ticket na parang chatblast).
     */
    public static function sequenceReady(): bool
    {
        return (bool) config('services.chatbot.sequence_handoff', true) && ChatbotSequences::hasInFlight() && self::hasTable()
            && SequenceSendPool::capable() && BotcakeSendPool::leavesSequences();
    }

    /** Ipapasa ba ang padala ng takbong ito: ang step ng sequence (live), naka-on ang mga sagot, at handa ang daan. Botcake at gateway, pareho. */
    public static function sequenceAsync(FlowRun $run, Outbox $outbox): bool
    {
        return !$run->test && $run->sequenceTicket !== null && $outbox instanceof BotcakeOutbox && BotcakeOutbox::enabled() && self::sequenceReady();
    }

    /** Walang epekto ang node kapag inulit: ang REPEATABLE, at ang Set na set o clear lang. */
    public static function repeatable(array $node): bool
    {
        $type = (string) ($node['type'] ?? '');
        if (in_array($type, self::REPEATABLE, true)) return true;

        return $type === 'set' && collect((array) ($node['config']['ops'] ?? []))->every(fn ($o) => in_array($o['op'] ?? 'set', ['set', 'clear'], true));
    }

    /** May epekto na ba ang takbo na hindi dapat ulitin (anumang node maliban sa REPEATABLE at sa Set na set/clear lang)? */
    public static function sideEffects(FlowRun $run): bool
    {
        if (!empty($run->sequenceTicket['side_effects'])) return true;
        $nodes = [];
        foreach ($run->graph['nodes'] ?? [] as $n) $nodes[$n['id']] = $n;
        foreach ($run->path as $p) {
            if (!self::repeatable($nodes[$p['node']] ?? ['type' => $p['type'] ?? ''])) return true;
        }

        return false;
    }

    /**
     * Ang padala ng step bilang ticket, at ang tali ng subscription dito, sa iisang transaksyon: ang tali ay kondisyonal
     * (active, parehong step, ang claim ng job o ang naunang ticket ng step), kaya hindi nakikita ng pool ang ticket na
     * walang tali. null = hindi na hawak ng takbo ang step (hal. sumagot ang customer): walang ticket, walang padala.
     */
    public static function submitSequence(FlowRun $run, string $nodeId, array $messages): ?string
    {
        $s = (array) $run->sequenceTicket;
        $id = (string) Str::uuid();
        $run->runId ??= bin2hex(random_bytes(4));
        $side = self::sideEffects($run);

        try {
            return self::link($run, $nodeId, $messages, $s, $id, $side);
        } catch (\Illuminate\Database\QueryException $e) {
            // Hal. deadlock sa paglipat ng page, kahit naulit: walang ticket; ang step ay ibinabalik sa tick (handOffFailed).
            report($e);
            $run->sequenceTicket = ['released' => true] + $s;

            return null;
        }
    }

    /** Ang ticket at ang tali, sa iisang transaksyon (hanggang 3 subok kapag deadlock). */
    private static function link(FlowRun $run, string $nodeId, array $messages, array $s, string $id, bool $side): ?string
    {
        return DB::transaction(function () use ($run, $nodeId, $messages, $s, $id, $side) {
            $stamp = now()->format('Y-m-d H:i:s');
            $prev = $s['request_id'] ?? null;
            $linked = DB::table('chatbot_sequence_subscriptions')->where('id', (int) $s['subscription_id'])->where('status', 'active')->where('current_step', (int) $s['current_step'])
                ->when(($s['stamp'] ?? null) !== null, fn ($q) => $q->where('dispatched_at', $s['stamp']))
                ->where(fn ($q) => $prev === null ? $q->whereNull('send_request_id') : $q->where('send_request_id', $prev))
                ->update(['send_request_id' => $id, 'dispatched_at' => $stamp, 'updated_at' => now()]);
            if ($linked !== 1) {
                $run->sequenceTicket = ['moved' => true] + $s;
                return null;
            }
            $sequence = array_intersect_key($s, array_flip(['subscription_id', 'current_step', 'step', 'due', 'name', 'sequence_id', 'first_sent_at']));
            DB::table(self::TABLE)->insert([
                'request_id' => $id, 'kind' => self::SEQUENCE, 'priority' => self::SEQUENCE_PRIORITY, 'customer' => self::sequenceCustomer($run->pageId, $run->fields->psid),
                'page_id' => (int) $run->pageId, 'page_name' => $run->pageName, 'psid' => (string) $run->fields->psid, 'chat_id' => null,
                'flow_id' => $run->flowId, 'node_id' => $nodeId, 'run_id' => $run->runId,
                'payload' => json_encode(['messages' => $messages] + ($run->tags ? ['tags' => $run->tags] : []), JSON_UNESCAPED_UNICODE),
                'context' => json_encode(['input' => $run->input, 'full_name' => $run->fullName, 'received_at' => $run->receivedAt, 'ai_calls' => $run->aiCalls,
                    'steps' => $run->steps, 'ad_group' => $run->adGroup, 'sequence' => $sequence, 'side_effects' => $side], JSON_UNESCAPED_UNICODE),
                'status' => 'pending', 'deadline_at' => now()->addSeconds(self::SEQUENCE_SECONDS), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $run->sequenceTicket = ['request_id' => $id, 'stamp' => $stamp, 'side_effects' => $side] + $s;

            return $id;
        }, 3);
    }

    /** May sequence ticket bang naghihintay na makuha ngayon (para sa galaw ng pool)? */
    public static function sequencePending(): bool
    {
        return DB::table(self::TABLE)->where('status', 'pending')->where('priority', self::SEQUENCE_PRIORITY)
            ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))->exists();
    }

    /** May sagot ba ng bot sa customer na hindi pa tapos (pending o sending) sa botcake:send-pool? Ang sequence ay naghihintay dito. */
    public static function replyOpen(int|string $pageId, int|string $psid): bool
    {
        return DB::table(self::TABLE)->where('customer', self::chatbotCustomer($pageId, $psid))->whereIn('status', ['pending', 'sending'])->exists();
    }

    /** Ang called() ng sequence: itinatala rin ang daan at ang client_ref, para sa talaan kahit mamatay ang pool pagkatapos ng tawag. */
    public static function calledSequence(object $row, string $owner, string $via, string $ref): bool
    {
        // Kasama ang may hawak (active, ang ticket na ito): ang sagot ng customer na naisulat sa pagitan ay humihinto sa padala.
        return DB::table(self::TABLE)->where('id', $row->id)->where('status', 'sending')->where('claimed_by', $owner)->whereNull('called_at')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('chatbot_sequence_subscriptions')->where('send_request_id', $row->request_id)->where('status', 'active'))
            ->update(['called_at' => now(), 'result' => json_encode(['via' => $via, 'client_ref' => $ref]), 'updated_at' => now()]) === 1;
    }

    /** Ang ticket na kasama ng resume job ng sequence. */
    public static function sequenceTicket(object $row): array
    {
        return ['request_id' => (string) $row->request_id];
    }

    /**
     * Ang resulta ng padala ng sequence ($o: status sent | failed | skipped, error, http, raw, via, ref, check) ng ticket na
     * hawak pa ng $owner (null = ang pending, para sa sweep): ang talaan (chatbot_replies, gaya ng padalang naghintay; ang
     * skipped ng pagsusuri bago ang tawag, "check", ay walang row), saka ang resume. false = hindi na hawak.
     */
    public static function finishSequence(object $row, ?string $owner, array $o): bool
    {
        $q = DB::table(self::TABLE)->where('id', $row->id);
        if ($owner !== null) $q->where('status', 'sending')->where('claimed_by', $owner);
        else $q->where('status', 'pending');
        $called = isset($o['via'], $o['ref']) ? [] : (array) json_decode((string) DB::table(self::TABLE)->where('id', $row->id)->value('result'), true);
        $o += ['via' => $called['via'] ?? ChatbotProvider::BOTCAKE, 'ref' => $called['client_ref'] ?? null, 'at' => now()->format('Y-m-d H:i:s')];
        $ok = $q->update(['status' => $o['status'] === 'failed' ? 'failed' : 'done', 'result' => json_encode($o, JSON_UNESCAPED_UNICODE),
            'error' => $o['error'] === null ? null : mb_substr($o['error'], 0, 255), 'http' => $o['http'], 'done_at' => now(), 'updated_at' => now()]);
        if ($ok !== 1) return false;
        if (!isset($o['check'])) {
            $payload = (array) json_decode((string) $row->payload, true);
            BotcakeOutbox::record((int) $row->page_id, (int) $row->psid, null, $row->flow_id === null ? null : (int) $row->flow_id, (string) $row->node_id, 'send',
                (array) ($payload['messages'] ?? []), $o['status'], $o['error'], $o['http'], $o['raw'] ?? null, is_array($payload['tags'] ?? null) ? $payload['tags'] : null,
                $o['via'], $o['ref']);
        }
        ResumeChatbotSequenceSend::dispatch(self::sequenceTicket($row));

        return true;
    }

    /** Ang error ng tawag na walang sagot, ayon sa daan. */
    public static function noAnswer(?string $via): string
    {
        return (ChatbotProvider::isGateway($via) ? 'Gateway' : 'Botcake') . ' did not answer (HTTP 0)';
    }

    /**
     * Ang naiwang "sending" ng sequence pool na namatay (hindi $owner): hindi pa natawag → pending (isang beses ng bagong
     * pool); natawag na → failed na walang sagot, saka ang resume. Hindi kailanman inuulit.
     */
    public static function recoverSequence(?string $owner): int
    {
        if (!Schema::hasTable(self::TABLE)) return 0;
        $n = 0;
        $rows = DB::table(self::TABLE)->where('status', 'sending')->where('kind', self::SEQUENCE)
            ->when($owner, fn ($q) => $q->where(fn ($w) => $w->whereNull('claimed_by')->orWhere('claimed_by', '!=', $owner)))->get();
        foreach ($rows as $row) {
            if ($row->called_at === null) {
                DB::table(self::TABLE)->where('id', $row->id)->where('status', 'sending')->where('claimed_by', $row->claimed_by)->whereNull('called_at')
                    ->update(['status' => 'pending', 'claimed_by' => null, 'updated_at' => now()]);
            } else {
                $via = json_decode((string) $row->result, true)['via'] ?? null;
                self::finishSequence($row, $row->claimed_by, ['status' => 'failed', 'error' => self::noAnswer($via), 'http' => null]);
            }
            $n++;
        }

        return $n;
    }

    /**
     * Ang paglilinis ng mga sequence ticket (ang pool kada 5 s, ang chatbot:sequence-tick kada minuto). Ang pending ay hindi
     * kailanman binibigo dahil huli (huli ay puwede, nawala ay hindi); ang done o failed na hindi pa naituloy → resume ulit (unique ang job,
     * kaya hindi nagpapatong); ang luma → binubura nang paunti-unti.
     * @return array{failed: int, redispatched: int, pruned: int}
     */
    public static function sweepSequence(): array
    {
        $out = ['failed' => 0, 'redispatched' => 0, 'pruned' => 0];
        if (!Schema::hasTable(self::TABLE)) return $out;
        // Kada status, sa index (status, priority, id): hindi binabasa ang buong table.
        foreach (['done', 'failed'] as $status) {
            foreach (DB::table(self::TABLE)->where('status', $status)->where('priority', self::SEQUENCE_PRIORITY)->where('updated_at', '<=', now()->subSeconds(self::REDISPATCH_SECONDS))
                ->orderBy('id')->limit(500)->get() as $row) {
                DB::table(self::TABLE)->where('id', $row->id)->update(['updated_at' => now()]);
                ResumeChatbotSequenceSend::dispatch(self::sequenceTicket($row));
                $out['redispatched']++;
            }
        }
        // Ang luma (tapos, pumalya, skipped o kinansela = resumed) ay binubura nang paunti-unti, isang beses kada minuto: hanggang sa
        // pinakabagong id na lampas KEEP_DAYS (isang basa sa index ng created_at), saka kada status sa index (status, priority, id).
        if (Cache::add('sequence-prune:' . ChatbotSequences::site(), 1, 60)) {
            $cutoff = DB::table(self::TABLE)->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->orderByDesc('created_at')->orderByDesc('id')->value('id');
            foreach ($cutoff === null ? [] : ['resumed', 'failed', 'done'] as $status) {
                $ids = DB::table(self::TABLE)->where('status', $status)->where('priority', self::SEQUENCE_PRIORITY)->where('id', '<=', $cutoff)
                    ->orderBy('id')->limit(5000)->pluck('id');
                if ($ids->isNotEmpty()) $out['pruned'] += DB::table(self::TABLE)->whereIn('id', $ids->all())->delete();
            }
        }

        return $out;
    }
}
