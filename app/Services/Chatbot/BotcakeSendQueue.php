<?php

namespace App\Services\Chatbot;

use App\Jobs\ResumeChatbotSend;
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
    public const CHATBLAST_SECONDS = 240; // mas maikli sa lock ng customer ng chatblast (300 s)
    public const KEEP_DAYS = 2;
    public const REDISPATCH_SECONDS = 30;

    /** Naka-on, may buhay na botcake:send-pool, at may table na. */
    public static function enabled(): bool
    {
        return (bool) config('services.botcake.async_sends', true) && BotcakeSendPool::alive() && Schema::hasTable(self::TABLE);
    }

    /** Ipapasa ba ang padala ng takbong ito: live (hindi Test o sequence), sa Botcake, at naka-on ang mga sagot. */
    public static function async(FlowRun $run, Outbox $outbox): bool
    {
        return !$run->test && $run->startedBy === null && $outbox instanceof BotcakeOutbox && BotcakeOutbox::enabled() && self::enabled();
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
            'payload' => json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE),
            'context' => json_encode(['input' => $run->input, 'full_name' => $run->fullName, 'received_at' => $run->receivedAt,
                'ai_calls' => $run->aiCalls, 'steps' => $run->steps], JSON_UNESCAPED_UNICODE),
            'status' => 'pending', 'deadline_at' => now()->addSeconds(self::CHATBOT_SECONDS), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $run->fields->set(CustomerFields::AI, self::MARK . $id);

        return $id;
    }

    /** Ang tatlong hakbang ng isang chatblast na mensahe bilang ticket, kasama ang lock ng customer na hawak ng job. */
    public static function submitChatblast(BotcakeMessage $m, array $cfg, string $lockKey, string $lockOwner): bool
    {
        if (!self::enabled() || $lockOwner === '') return false;
        DB::table(self::TABLE)->insert([
            // Ang Delivering ay nauuna sa ibang chatblast (gaya ng chatblast-high sa Horizon).
            'request_id' => (string) Str::uuid(), 'kind' => 'chatblast', 'priority' => $m->kind === ChatblastSender::HIGH_KIND ? 1 : 2, 'customer' => $lockKey,
            'page_id' => (int) $cfg['page_id'], 'page_name' => $m->page_name, 'psid' => (string) $m->psid, 'message_id' => $m->id, 'lock_owner' => $lockOwner,
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
     * row; ang tinanggihan ay naiiwang pending.
     * @return array<int, object>
     */
    public static function claim(string $owner, int $n, ?callable $accept = null, ?callable $then = null, array $fullPages = []): array
    {
        if ($n <= 0) return [];
        $t = self::TABLE;
        $rows = DB::table("$t as t")->where('t.status', 'pending')->where(fn ($q) => $q->whereNull('t.next_at')->orWhere('t.next_at', '<=', now()))
            ->where('t.deadline_at', '>', now())
            // Ang page na puno na ang lane ay hindi na isinasama sa SQL: hindi nito mapupuno ang listahan ng kandidato (ang ibang page ay nakikita pa rin).
            ->when($fullPages, fn ($q) => $q->whereNotIn('t.page_name', $fullPages))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from("$t as o")->whereColumn('o.customer', 't.customer')->whereColumn('o.id', '<', 't.id')
                ->whereIn('o.status', ['pending', 'sending']))
            ->orderBy('t.priority')->orderBy('t.id')->limit($n * 5)->get(['t.id', 't.request_id', 't.page_name', 't.kind']);
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
        $messages = (array) (json_decode((string) $row->payload, true)['messages'] ?? []);
        BotcakeOutbox::record((int) $row->page_id, (int) $row->psid, $row->chat_id === null ? null : (int) $row->chat_id, $row->flow_id === null ? null : (int) $row->flow_id,
            (string) $row->node_id, 'send', $messages, $o['status'], $o['error'], $o['http'], $o['raw'] ?? null);
        ResumeChatbotSend::dispatch(self::ticket($row));

        return true;
    }

    /** Tapos na ang chatblast na mensahe (ang status ay naisulat na ng stepper): bitawan ang lock at itakda ang gap. */
    public static function finishChatblast(object $row, ?string $owner): bool
    {
        $q = DB::table(self::TABLE)->where('id', $row->id);
        if ($owner !== null) $q->where('status', 'sending')->where('claimed_by', $owner);
        else $q->where('status', 'pending');
        if ($q->update(['status' => 'done', 'done_at' => now(), 'updated_at' => now()]) !== 1) return false;
        app(ChatblastSender::class)->afterPool((string) $row->customer, (string) $row->lock_owner);

        return true;
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
        $rows = DB::table(self::TABLE)->where('status', 'sending')->when($owner, fn ($q) => $q->where(fn ($w) => $w->whereNull('claimed_by')->orWhere('claimed_by', '!=', $owner)))->get();
        foreach ($rows as $row) {
            $back = fn () => DB::table(self::TABLE)->where('id', $row->id)->where('status', 'sending')->where('claimed_by', $row->claimed_by)
                ->update(['status' => 'pending', 'claimed_by' => null, 'called_at' => null, 'updated_at' => now()]);
            if ($row->kind === 'chatblast') {
                if (app(ChatblastSender::class)->lost((int) $row->message_id, $row->called_at !== null)) $back();
                else self::finishChatblast($row, $row->claimed_by);
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

        foreach (DB::table(self::TABLE)->where('status', 'pending')->where('deadline_at', '<=', now())->orderBy('id')->limit(500)->get() as $row) {
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

        $out['pruned'] = DB::table(self::TABLE)->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->whereIn('status', ['resumed', 'failed', 'done'])->orderBy('id')->limit(5000)->delete();

        return $out;
    }
}
