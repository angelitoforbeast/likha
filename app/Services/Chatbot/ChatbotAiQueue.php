<?php

namespace App\Services\Chatbot;

use App\Jobs\ResumeChatbotAi;
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
    public const KEEP_DAYS = 2;
    public const REDISPATCH_SECONDS = 30; // ang answered/failed na hindi pa naituloy (nawalang job) ay ipinapadala ulit

    /** Hindi naghihintay ang AI step: naka-on ang switch, may table na, live na takbo ng chat o ng wait-skip (hindi Test o sequence). */
    public static function async(FlowRun $run, Outbox $outbox): bool
    {
        return (bool) config('services.chatbot.ai_async', true) && !$run->test && $run->startedBy === null
            && $outbox instanceof BotcakeOutbox && Schema::hasTable(self::TABLE);
    }

    public static function timeout(): int
    {
        return max(5, (int) config('services.openai.timeout', 60));
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
                'ai_calls' => $run->aiCalls, 'steps' => $run->steps], JSON_UNESCAPED_UNICODE),
            'status' => 'pending', 'deadline_at' => now()->addSeconds(self::timeout()), 'created_at' => now(), 'updated_at' => now(),
        ]);
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
        $status = Schema::hasTable(self::TABLE) ? DB::table(self::TABLE)->where('request_id', $id)->value('status') : null;
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
        $ids = DB::table(self::TABLE)->where('status', 'pending')->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))
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
        ]);
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

    /**
     * Ang walang sagot sa deadline → failed (sumusunod sa failed port); ang answered/failed na hindi pa naituloy pagkatapos
     * ng REDISPATCH_SECONDS → ipinapadala ulit ang resume (isang beses lang tumatakbo); ang luma → binubura.
     * @return array{failed: int, redispatched: int, pruned: int}
     */
    public static function sweep(): array
    {
        $out = ['failed' => 0, 'redispatched' => 0, 'pruned' => 0];
        if (!Schema::hasTable(self::TABLE)) return $out;

        $late = DB::table(self::TABLE)->whereIn('status', ['pending', 'sending'])->where('deadline_at', '<=', now())->orderBy('id')->limit(500)->get();
        foreach ($late as $row) {
            $ok = DB::table(self::TABLE)->where('id', $row->id)->whereIn('status', ['pending', 'sending'])->update([
                'status' => 'failed', 'error' => 'OpenAI did not answer in time.', 'answered_at' => now(), 'updated_at' => now(),
            ]);
            if ($ok === 1) {
                ResumeChatbotAi::dispatch(self::ticket($row));
                $out['failed']++;
            }
        }

        $stuck = DB::table(self::TABLE)->whereIn('status', ['answered', 'failed'])->where('updated_at', '<=', now()->subSeconds(self::REDISPATCH_SECONDS))
            ->orderBy('id')->limit(500)->get();
        foreach ($stuck as $row) {
            DB::table(self::TABLE)->where('id', $row->id)->update(['updated_at' => now()]);
            ResumeChatbotAi::dispatch(self::ticket($row));
            $out['redispatched']++;
        }

        $out['pruned'] = DB::table(self::TABLE)->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->whereIn('status', ['resumed', 'failed', 'answered'])->limit(5000)->delete();

        return $out;
    }
}
