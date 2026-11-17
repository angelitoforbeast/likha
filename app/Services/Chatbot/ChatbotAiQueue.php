<?php

namespace App\Services\Chatbot;

use App\Jobs\ResumeChatbotAi;
use App\Services\OrderEncode\FlowEncode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ang AI step na hindi naghihintay: ang ticket (chatbot_ai_requests) na ipinapasa ng takbo, kinukuha ng chatbot:ai-pool,
 * at ibinabalik bilang ResumeChatbotAi. Ang mga paglipat ng status ay kondisyonal (isang UPDATE na may WHERE sa dating
 * status at sa may hawak), kaya ang huling sagot, ang ulit na job o ang dalawang pool ay hindi nagdodoble:
 * pending → sending → answered | failed → resumed.
 */
class ChatbotAiQueue
{
    public const TABLE = 'chatbot_ai_requests';
    public const KEEP_DAYS = 2; // ang text ng customer (messages, answer, context)
    public const RECORD_DAYS = 90; // ang record ng request (provider, bilis, tokens), kapag may mga column na nito
    public const REDISPATCH_SECONDS = 30; // ang answered/failed na hindi pa naituloy (nawalang job) ay ipinapadala ulit
    // Ang mga index na binabasa ng claim at ng sweep (mula sa create migration). Ang mga status na hinahanap nila (pending,
    // sending, encode, answered, failed) ay ang mga nasa himpapawid lang, kaya kaunti ang binabasa kahit milyon ang table.
    public const STATUS_INDEX = 'chatbot_ai_requests_status_next_at_index';
    public const CREATED_INDEX = 'chatbot_ai_requests_created_at_index';
    public const TEXTS_FROM = 'chatbot-ai-sweep:texts-from'; // hanggang saan na nasuri ang pagbura ng text (created_at)

    /**
     * Hindi naghihintay ang AI step: naka-on ang switch, live na takbo ng chat o ng wait-skip (hindi Test o sequence), may
     * table na, at may buhay na chatbot:ai-pool (heartbeat). Kapag wala pa ang pool (hal. bago mailagay sa supervisor, o
     * patay), naghihintay ang step gaya ng dati, kaya walang customer na napupunta sa failed port dahil lang walang pool.
     */
    public static function async(FlowRun $run, Outbox $outbox): bool
    {
        return (bool) config('services.chatbot.ai_async', true) && !$run->test && $run->startedBy === null
            && $outbox instanceof BotcakeOutbox && ChatbotAiPool::alive() && Schema::hasTable(self::TABLE);
    }

    public static function timeout(): int
    {
        return max(5, (int) config('services.openai.timeout', 60));
    }

    /** Tumakbo na ang migration ng record? Bago iyon, ang AI step ay gaya ng dati (walang record, bura pagkatapos ng 2 araw). */
    public static function recorded(): bool
    {
        return Schema::hasColumn(self::TABLE, 'provider');
    }

    /** Ang mga column ng record mula sa resulta ng chain (ChatbotAiChain::outcome): walang text ng customer o ng sagot. */
    private static function recordOf(array $res): array
    {
        return ['provider' => $res['provider'], 'model_used' => $res['model_used'], 'fell_back' => (bool) $res['fell_back'],
            'fallback_reason' => $res['fallback_reason'], 'ai_attempts' => json_encode($res['attempts'], JSON_UNESCAPED_UNICODE),
            'latency_ms' => $res['latency_ms'], 'prompt_tokens' => $res['prompt_tokens'], 'completion_tokens' => $res['completion_tokens']]
            + (ChatbotAiUsage::ready() ? ['key_id' => $res['key_id'] ?? null, 'key_label' => $res['key_label'] ?? null, 'key_last4' => $res['key_last4'] ?? null] : []);
    }

    /**
     * Ang gastos at ang padala ng usage (spec 210): ang sagot lang ng provider na may pool (OpenAI, Claude, DeepSeek) ang
     * sinisingil; ang palya, ang Local at ang mock ay wala. Ang gateway ay ang sa page ngayon; walang naka-set up = no_gateway.
     */
    private static function billOf(array $res, string $page): array
    {
        if (!ChatbotAiUsage::ready() || !isset($res['attempts'])) return [];
        if (!$res['ok'] || !ChatbotAiKeys::pooled((string) $res['provider'])) return ['cost_micros' => null, 'cost_flag' => null, 'usage_status' => null, 'usage_gateway' => null];
        [$cost, $flag] = ChatbotAiCost::of((string) $res['model_used'], $res['prompt_tokens'], $res['completion_tokens']);
        $gateway = ChatbotAiUsage::gatewayOf($page);

        return ['cost_micros' => $cost, 'cost_flag' => $flag, 'usage_status' => $gateway !== null ? ChatbotAiUsage::PENDING : ChatbotAiUsage::NO_GATEWAY, 'usage_gateway' => $gateway];
    }

    /**
     * Ang record ng naghihintay na tawag (Test, sequence, walang pool): isang row na status sync (hindi kinukuha, sinu-sweep o
     * itinutuloy), walang text ng customer o ng sagot.
     */
    public static function record(FlowRun $run, string $nodeId, string $model, int $maxTokens, array $res): void
    {
        if (!isset($res['attempts']) || !Schema::hasTable(self::TABLE) || !self::recorded()) return;
        DB::table(self::TABLE)->insert([
            'request_id' => (string) Str::uuid(), 'page_id' => (int) $run->pageId, 'psid' => (int) $run->fields->psid, 'chat_id' => $run->chatId,
            'flow_id' => (int) $run->flowId, 'node_id' => mb_substr($nodeId, 0, 20), 'run_id' => $run->runId, 'page_name' => $run->pageName, 'model' => mb_substr($model, 0, 100),
            'messages' => null, 'max_tokens' => $maxTokens, 'context' => null, 'status' => 'sync', 'deadline_at' => now(), 'answered_at' => now(),
            'error' => $res['ok'] ? null : mb_substr((string) $res['error'], 0, 255), 'path' => 'sync', 'created_at' => now(), 'updated_at' => now(),
        ] + self::recordOf($res) + self::billOf($res, $run->pageName));
    }

    /** Ang ticket: isang row, at ang __ai ng customer (ang marka na may hinihintay pang sagot). @return string request_id */
    public static function submit(FlowRun $run, string $nodeId, string $model, array $messages, int $maxTokens): string
    {
        $id = (string) Str::uuid();
        $run->runId ??= bin2hex(random_bytes(4));
        DB::table(self::TABLE)->insert([
            'request_id' => $id, 'page_id' => (int) $run->pageId, 'psid' => $run->fields->psid, 'chat_id' => $run->chatId,
            'flow_id' => $run->flowId, 'node_id' => $nodeId, 'run_id' => $run->runId, 'page_name' => $run->pageName, 'model' => $model,
            'messages' => json_encode($messages, JSON_UNESCAPED_UNICODE), 'max_tokens' => $maxTokens,
            'context' => json_encode(['input' => $run->input, 'full_name' => $run->fullName, 'received_at' => $run->receivedAt,
                'ai_calls' => $run->aiCalls, 'steps' => $run->steps, 'ad_group' => $run->adGroup], JSON_UNESCAPED_UNICODE),
            'status' => 'pending', 'deadline_at' => now()->addSeconds(self::timeout()), 'created_at' => now(), 'updated_at' => now(),
        ] + (self::recorded() ? ['path' => 'async'] : []));
        $run->fields->set(CustomerFields::AI, $id);

        return $id;
    }

    /**
     * May hinihintay pang sagot ng AI ang customer (__ai)? Ang __ai na wala nang row, o naituloy na, ay binubura (hindi
     * naiipit ang customer).
     */
    public static function pending(CustomerFields $fields): bool
    {
        $id = (string) $fields->get(CustomerFields::AI);
        if ($id === '') return false;
        // Ang marka ay maaaring padala sa Botcake (send:<id>, botcake:send-pool) o AI step.
        $table = str_starts_with($id, BotcakeSendQueue::MARK) ? BotcakeSendQueue::TABLE : self::TABLE;
        if ($table === BotcakeSendQueue::TABLE) $id = substr($id, strlen(BotcakeSendQueue::MARK));
        $status = Schema::hasTable($table) ? DB::table($table)->where('request_id', $id)->value('status') : null;
        if ($status === null || $status === 'resumed') {
            $fields->clear(CustomerFields::AI);
            return false;
        }

        return true;
    }

    /** Ang ticket na kasama ng resume job (mula sa row, gaya ng nabasa ng pool). */
    public static function ticket(object $row): array
    {
        return ['request_id' => (string) $row->request_id, 'page_id' => (int) $row->page_id, 'psid' => (int) $row->psid,
            'chat_id' => $row->chat_id === null ? null : (int) $row->chat_id, 'flow_id' => (int) $row->flow_id, 'node_id' => (string) $row->node_id];
    }

    /**
     * Kunin ang hanggang $n na pending (lampas na sa next_at), isa-isa at kondisyonal, para sa pool na $owner.
     * @return array<int, object>
     */
    public static function claim(string $owner, int $n): array
    {
        if ($n <= 0) return [];
        // Sa index (status, next_at): ang pending lang ang binabasa at inaayos; sa id (PRIMARY), maaaring basahin ng database ang buong table.
        $ids = DB::table(self::TABLE)->useIndex(self::STATUS_INDEX)->where('status', 'pending')->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))
            ->where('deadline_at', '>', now())->orderBy('id')->limit($n)->pluck('id');
        $out = [];
        foreach ($ids as $id) {
            $ok = DB::table(self::TABLE)->where('id', $id)->where('status', 'pending')
                ->update(['status' => 'sending', 'claimed_by' => $owner, 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            if ($ok === 1) $out[] = DB::table(self::TABLE)->where('id', $id)->first();
        }

        return $out;
    }

    /**
     * Ang sagot (o ang pagpalya) ng request na hawak pa ng $owner, saka ang resume job. false = hindi na hawak (huli na,
     * nag-timeout, o kinuha ng ibang pool): walang ginagawa.
     */
    public static function finish(object $row, string $owner, array $res, ?int $http): bool
    {
        $ok = DB::table(self::TABLE)->where('id', $row->id)->where('status', 'sending')->where('claimed_by', $owner)->update([
            'status' => $res['ok'] ? 'answered' : 'failed', 'answer' => $res['ok'] ? $res['text'] : null,
            'error' => $res['ok'] ? null : mb_substr((string) $res['error'], 0, 255), 'http' => $http, 'answered_at' => now(), 'updated_at' => now(),
        ] + (isset($res['attempts']) && self::recorded() ? self::recordOf($res) + self::billOf($res, (string) $row->page_name) : []));
        if ($ok !== 1) return false;
        ResumeChatbotAi::dispatch(self::ticket($row));

        return true;
    }

    /** 429: ibalik sa pending, hindi muna bago ang $at (walang nawawala; ang deadline ay ganoon pa rin). */
    public static function later(object $row, string $owner, \DateTimeInterface $at): void
    {
        DB::table(self::TABLE)->where('id', $row->id)->where('status', 'sending')->where('claimed_by', $owner)
            ->update(['status' => 'pending', 'claimed_by' => null, 'next_at' => $at, 'updated_at' => now()]);
    }

    /** Ang naiwang "sending" ng pool na namatay (hindi $owner): balik sa pending; ang lampas na sa deadline ay sa sweep. */
    public static function recover(string $owner): int
    {
        if (!Schema::hasTable(self::TABLE)) return 0;

        return DB::table(self::TABLE)->where('status', 'sending')->where(fn ($q) => $q->whereNull('claimed_by')->orWhere('claimed_by', '!=', $owner))
            ->update(['status' => 'pending', 'claimed_by' => null, 'updated_at' => now()]);
    }

    /** Ang text ng walang sagot sa deadline: ang huling provider ng chain ng page ("OpenAI did not answer in time." kapag OpenAI lang). */
    private static function late(object $row): string
    {
        $chain = ChatbotAiChain::forRow($row);

        return ChatbotAiProviders::late($chain ? $chain[array_key_last($chain)]['provider'] : ChatbotAiProviders::OPENAI);
    }

    /**
     * Ang walang sagot sa deadline → failed (sumusunod sa failed port); ang answered/failed na hindi pa naituloy pagkatapos
     * ng REDISPATCH_SECONDS → ipinapadala ulit ang resume (isang beses lang tumatakbo); ang luma → binubura.
     * @return array{failed: int, redispatched: int, pruned: int}
     */
    /** Ang row na binubura: walang usage na naghihintay na maipadala sa Forbeast (ang pending ay hindi kailanman binubura). */
    private static function sentOrNone($q)
    {
        return ChatbotAiUsage::ready() ? $q->whereNull('usage_status')->orWhere('usage_status', '!=', ChatbotAiUsage::PENDING) : $q;
    }

    public static function sweep(): array
    {
        $out = ['failed' => 0, 'redispatched' => 0, 'pruned' => 0];
        if (!Schema::hasTable(self::TABLE)) return $out;
        // Bawat SELECT ay may hangganan ng oras (SweepLimit): ang mabagal na query ay humihinto at isang warning lang, kaya
        // hindi naharang ng sweep ang pool (at ang pagkuha ng bagong request) nang matagal.
        (new SweepLimit('chatbot AI sweep'))->run(function (SweepLimit $limit) use (&$out) {
            self::sweepWithin($limit, $out);
        });

        return $out;
    }

    /** Ang mga hakbang ng sweep, ang bawat SELECT sa loob ng $limit. Ang paglampas sa deadline ang nauuna (ang naiipit na customer). */
    private static function sweepWithin(SweepLimit $limit, array &$out): void
    {
        // Sa index (status, next_at): ang mga nasa himpapawid lang ang binabasa at inaayos sa id, hindi ang buong table sa PRIMARY.
        $late = $limit->cap(DB::table(self::TABLE)->useIndex(self::STATUS_INDEX)->whereIn('status', ['pending', 'sending'])->where('deadline_at', '<=', now())->orderBy('id')->limit(500))->get();
        foreach ($late as $row) {
            $ok = DB::table(self::TABLE)->where('id', $row->id)->whereIn('status', ['pending', 'sending'])->update([
                'status' => 'failed', 'error' => self::late($row), 'answered_at' => now(), 'updated_at' => now(),
            ]);
            if ($ok === 1) {
                ResumeChatbotAi::dispatch(self::ticket($row));
                $out['failed']++;
            }
        }

        // Ang Encode order na lampas na sa "Wait up to": ang resulta ng encode kapag naitabi na (si Astra ang hindi natapos), kung wala ay failed.
        $late = $limit->cap(DB::table(self::TABLE)->useIndex(self::STATUS_INDEX)->where('status', FlowEncode::STATUS)->where('deadline_at', '<=', now())->orderBy('id')->limit(500))->get();
        foreach ($late as $row) {
            $encoded = $row->answer !== null;
            $ok = DB::table(self::TABLE)->where('id', $row->id)->where('status', FlowEncode::STATUS)->update([
                'status' => $encoded ? 'answered' : 'failed', 'error' => $encoded ? null : 'The encode did not finish in time.', 'answered_at' => now(), 'updated_at' => now(),
            ]);
            if ($ok === 1) {
                ResumeChatbotAi::dispatch(self::ticket($row));
                $out['failed']++;
            }
        }

        $stuck = $limit->cap(DB::table(self::TABLE)->useIndex(self::STATUS_INDEX)->whereIn('status', ['answered', 'failed'])->where('updated_at', '<=', now()->subSeconds(self::REDISPATCH_SECONDS))
            ->orderBy('id')->limit(500))->get();
        foreach ($stuck as $row) {
            DB::table(self::TABLE)->where('id', $row->id)->update(['updated_at' => now()]);
            ResumeChatbotAi::dispatch(self::ticket($row));
            $out['redispatched']++;
        }

        // Ang pagbura ay laging "hanapin ang mga id sa index (may hangganan ng oras), saka burahin ayon sa id": ang DELETE na
        // naka-ayos sa id ay maaaring basahin ang buong table at walang MAX_EXECUTION_TIME ang DELETE.
        $old = now()->subDays(self::KEEP_DAYS);
        if (!self::recorded()) {
            $out['pruned'] = self::prune($limit, self::CREATED_INDEX, 'created_at', fn ($q) => $q->where('created_at', '<', $old)->whereIn('status', ['resumed', 'failed', 'answered']));
            return;
        }
        // May record: ang naiwang answered/failed (hindi naituloy) ay binubura pagkatapos ng 2 araw gaya ng dati; sa tapos na
        // (resumed, sync) ang text ng customer lang ang binubura, at ang row (record) pagkatapos ng 90. Ang answered/failed ay
        // hinahanap sa index (status, next_at): kaunti sila, samantalang ang mas luma sa 2 araw ay halos buong table.
        $out['pruned'] = self::prune($limit, self::STATUS_INDEX, 'id', fn ($q) => $q->whereIn('status', ['failed', 'answered'])->where('created_at', '<', $old)->where(fn ($w) => self::sentOrNone($w)));
        self::clearTexts($limit);
        $record = now()->subDays(self::RECORD_DAYS);
        $out['pruned'] += self::prune($limit, self::CREATED_INDEX, 'created_at', fn ($q) => $q->where('created_at', '<', $record)->whereIn('status', ['resumed', 'sync'])->where(fn ($w) => self::sentOrNone($w)));
    }

    /**
     * Hanggang 5000 na row ng $rule: ang mga id ay hinahanap sa $index (naka-ayos sa $order, kasunod ng id), saka binubura
     * ayon sa id at sa parehong $rule (ang row na nagbago sa pagitan, hal. naituloy, ay hindi nabubura). @return int ilang nabura
     */
    private static function prune(SweepLimit $limit, string $index, string $order, \Closure $rule): int
    {
        $q = DB::table(self::TABLE)->useIndex($index)->where($rule)->orderBy($order);
        if ($order !== 'id') $q->orderBy('id');
        $ids = $limit->cap($q->limit(5000), ['id'])->pluck('id');

        return $ids->isEmpty() ? 0 : DB::table(self::TABLE)->whereIn('id', $ids->all())->where($rule)->delete();
    }

    /**
     * Ang text ng customer (messages, answer, context) ng tapos na (resumed, sync) na mas luma sa KEEP_DAYS, hanggang
     * KEEP_DAYS + 5 pabalik, 5000 kada sweep, gaya ng dati. Naka-ayos sa created_at (may index), at mula lang sa huling
     * nasuri (TEXTS_FROM): kapag nabura na ang mga text, ang 5 araw ay wala nang mahahanap at binabasa ang lahat ng row
     * nito bawat sweep. Kaya sa karaniwan, ang ilang segundo lang na lumampas sa 2 araw mula sa huling sweep ang binabasa.
     * Walang TEXTS_FROM (unang takbo, nabura ang cache): mula 7 araw pabalik, tig-isang oras, at naitatabi ang bawat
     * natapos na oras, kaya ang naubusan ng oras ng sweep ay itinutuloy ng susunod, hindi inuulit mula sa simula.
     */
    private static function clearTexts(SweepLimit $limit): void
    {
        $to = now()->subDays(self::KEEP_DAYS);
        $floor = now()->subDays(self::KEEP_DAYS + 5);
        $seen = Cache::get(self::TEXTS_FROM);
        $from = $seen === null ? $floor : Carbon::parse($seen)->max($floor);
        $left = 5000;
        while ($left > 0 && $from->lessThan($to)) {
            $end = $from->copy()->addHour()->min($to);
            $rows = $limit->cap(DB::table(self::TABLE)->useIndex(self::CREATED_INDEX)->whereBetween('created_at', [$from, $end])->whereIn('status', ['resumed', 'sync'])
                ->where(fn ($q) => $q->whereNotNull('messages')->orWhereNotNull('answer')->orWhereNotNull('context'))->orderBy('created_at')->orderBy('id')->limit($left), ['id', 'created_at'])->get();
            if ($rows->isNotEmpty()) DB::table(self::TABLE)->whereIn('id', $rows->pluck('id')->all())->update(['messages' => null, 'answer' => null, 'context' => null]);
            $left -= $rows->count();
            // Naubos ang 5000: may natitira pa sa oras na iyon, kaya doon magsisimula ang susunod (ang nabura na ay hindi na tugma).
            $from = $left === 0 ? Carbon::parse($rows->last()->created_at) : $end;
            Cache::forever(self::TEXTS_FROM, $from->format('Y-m-d H:i:s'));
        }
    }
}
