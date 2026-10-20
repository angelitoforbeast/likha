<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang talaan para sa /chatbot/results, kinukuha sa sandali ng padala:
 * - tags: kada mensahe ng padala, ang mga botfield ng template (at ang id ng active na page_settings row = ang version)
 *   at ang customer fields; kada padala, ang flow version, ang sequence at step, at ang ad_id ng customer.
 * - replied: ang unang chat ng customer sa page pagkatapos ng padala, sa loob ng 24 oras.
 * - order: ang node na "Counts as an order", isa kada customer kada 24 oras.
 * Walang dagdag na basa kada mensahe: ang mga id ng botfield ay mula sa basa ng ChatbotPage::botfields() ng takbo, at ang
 * flow version at ad_id ay naka-cache. Ang wala pang table (hindi pa na-migrate) ay hindi pumipigil sa padala.
 */
class ChatbotTags
{
    public const REPLY_HOURS = 24;
    public const ORDER_HOURS = 24;
    public const ORDER_SENDS = 5; // ilang padala bago ang order ang itinatala
    public const BACKFILL_HOURS = 48;

    /** Ang mga variable ng takbo (FlowRun::vars): hindi botfield, hindi customer field. */
    private const RUN_VARS = ['last input', 'full name', 'first name', 'psid', 'page id', 'page name', 'current date', 'last interaction'];

    /** @var array<int, int> kada app instance (ang worker ay iisa; bawat test ay bago): 0 = handa, iba = kailan susuriin ulit */
    private static array $ready = [];

    public static function ready(): bool
    {
        // Ang migrate ay mano-mano pagkatapos ng deploy: habang wala pa, sinusuri ulit kada minuto lang (hindi kada padala).
        $app = spl_object_id(app());
        if (isset(self::$ready[$app]) && (self::$ready[$app] === 0 || self::$ready[$app] > time())) return self::$ready[$app] === 0;
        $ok = Schema::hasColumn('chatbot_replies', 'tags') && Schema::hasTable('chatbot_reply_tags');
        self::$ready[$app] = $ok ? 0 : time() + 60;

        return $ok;
    }

    /** Ang hash ng published na graph (unang 12 hex ng md5): pareho ang graph, pareho ang hash. */
    public static function graphHash(array $graph): string
    {
        return substr(md5((string) json_encode($graph)), 0, 12);
    }

    /**
     * Ang id ng huling Publish ng flow (chatbot_flow_versions): ang Publish ay nagsusulat ng live na graph at ng version
     * nito sa iisang transaction, kaya ang pinakabagong row ang live. Naka-cache ayon sa hash ng graph; binubura ng
     * Publish ang key ng graph nito (forgetFlowVersion), kaya ang ibinalik na lumang graph ay may bagong id rin. Null
     * kapag walang row (flow na na-publish bago nagkaroon ng versions).
     */
    public static function flowVersion(int $flowId, string $hash): ?int
    {
        try {
            $id = Cache::remember(self::versionKey($flowId, $hash), 86400,
                fn () => (int) DB::table('chatbot_flow_versions')->where('flow_id', $flowId)->max('id'));
        } catch (Throwable) {
            return null;
        }

        return $id ?: null;
    }

    private static function versionKey(int $flowId, string $hash): string
    {
        return 'chatbot-flow-version:' . $flowId . ':' . $hash;
    }

    public static function forgetFlowVersion(int $flowId, array $graph): void
    {
        Cache::forget(self::versionKey($flowId, self::graphHash($graph)));
    }

    /** Ang ad ng customer: botcake_psids ng page, kung wala ay ang ad_id ng unang chat niya. Naka-cache nang isang araw. */
    public static function adId(int $pageId, int $psid, string $pageName): ?string
    {
        try {
            $ad = Cache::remember('chatbot-ad:' . $pageId . ':' . $psid, 86400, function () use ($pageId, $psid, $pageName) {
                $settings = app(PageSettings::class);
                $fromPsid = BotcakePsid::where('psid', (string) $psid)->where('ad_id', '<>', '')->whereNotNull('ad_id')->get(['page', 'ad_id'])
                    ->first(fn ($r) => $settings->pageKey((string) $r->page) === $settings->pageKey($pageName))?->ad_id;
                if ($fromPsid) return (string) $fromPsid;
                $first = BotcakeChat::where('page_id', (string) $pageId)->where('psid', (string) $psid)->orderBy('id')->first(['payload']);
                $ad = trim((string) ($first?->payload['ad_id'] ?? ''));

                return preg_match('/^\d{1,64}$/', $ad) ? $ad : '';
            });
        } catch (Throwable) {
            return null;
        }

        return $ad !== '' ? $ad : null;
    }

    /**
     * Ang tags ng isang padala ng takbo. $templates: ang template ng bawat mensahe (sa ayos ng mga mensahe; null = walang
     * template, hal. ang sagot ng AI). Ang botfield na may variable (hal. "Only {{PRICE}} pesos") ay kasama ang PRICE.
     */
    public static function forSend(FlowRun $run, array $templates): array
    {
        $ids = ChatbotPage::versionIds($run->pageName);
        $hash = self::graphHash($run->graph);
        $messages = [];
        foreach ($templates as $template) {
            $botfields = [];
            $fields = [];
            $names = self::names((string) $template);
            foreach ($names as $name) {
                if (isset($ids[$name])) {
                    $botfields[$ids[$name]['key']] = $ids[$name]['id'];
                    // Isang antas lang, gaya ng FlowRun::vars().
                    foreach (self::names((string) ($run->botfields[$ids[$name]['key']] ?? '')) as $inner) {
                        if (isset($ids[$inner])) $botfields[$ids[$inner]['key']] ??= $ids[$inner]['id'];
                    }
                } elseif (!in_array($name, self::RUN_VARS, true)) {
                    $fields[] = $name;
                }
            }
            $messages[] = ['botfields' => $botfields, 'fields' => array_values(array_unique($fields))];
        }

        return [
            'flow_version_id' => $run->test ? null : self::flowVersion($run->flowId, $hash),
            'sequence_id' => $run->sequenceId, 'sequence_step' => $run->sequenceStep,
            'ad_id' => $run->test ? null : self::adId((int) $run->pageId, $run->fields->psid, $run->pageName),
            'tags' => ['v' => 1, 'flow_hash' => $hash, 'messages' => $messages],
        ];
    }

    /** Ang tags ng manual na sagot (Inbox): walang template at walang flow. */
    public static function manual(int $pageId, int $psid, string $pageName, int $messages): array
    {
        return ['ad_id' => self::adId($pageId, $psid, $pageName),
            'tags' => ['v' => 1, 'messages' => array_fill(0, $messages, ['botfields' => [], 'fields' => []])]];
    }

    /** @return array<int, string> ang mga {{pangalan}} ng template, naka-norm */
    private static function names(string $template): array
    {
        if (!str_contains($template, '{{')) return [];
        preg_match_all(ChatbotVars::PATTERN, $template, $m);

        return array_values(array_unique(array_map(fn ($n) => ChatbotVars::norm($n), $m[1])));
    }

    /**
     * Ang mga column ng reply mula sa tags (para sa ChatbotReply::create). Walang tags o wala pang column = wala.
     */
    public static function columns(?array $tags): array
    {
        if (!$tags || !self::ready()) return [];

        return array_intersect_key($tags, array_flip(['flow_version_id', 'sequence_id', 'sequence_step', 'ad_id', 'tags']));
    }

    /** Isang row kada mensahe at botfield (isang insert kada padala), para sa "By message". Ang naipadala lang. */
    public static function storeRows(object $reply, ?array $tags): void
    {
        if (!$tags || $reply->status !== 'sent' || !self::ready()) return;
        $rows = [];
        foreach (array_values((array) ($tags['tags']['messages'] ?? [])) as $i => $m) {
            $base = ['reply_id' => $reply->id, 'page_id' => $reply->page_id, 'flow_id' => $reply->flow_id, 'flow_version_id' => $tags['flow_version_id'] ?? null,
                'node_id' => $reply->node_id, 'message' => min($i, 255), 'sent_at' => $reply->sent_at];
            $botfields = (array) ($m['botfields'] ?? []);
            if (!$botfields) $rows[] = $base + ['botfield' => null, 'setting_id' => null];
            foreach ($botfields as $key => $id) $rows[] = $base + ['botfield' => mb_substr((string) $key, 0, 100), 'setting_id' => (int) $id];
        }
        try {
            if ($rows) DB::table('chatbot_reply_tags')->insert($rows);
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ---- Replied ----

    /**
     * Dumating ang chat ng customer: lahat ng padala sa kanya sa page na ito na wala pang sagot, naipadala sa loob ng 24
     * oras bago ang chat, ay sinagot ng chat na ito (ito ang unang chat pagkatapos ng mga iyon).
     */
    public static function markReplied(int $pageId, int $psid, Carbon $at): int
    {
        if (!self::ready()) return 0;
        $rows = DB::table('chatbot_replies')->where('page_id', $pageId)->where('psid', $psid)
            ->where('sent_at', '>=', $at->copy()->subHours(self::REPLY_HOURS))->where('sent_at', '<', $at)
            ->whereIn('kind', ['send', 'manual'])->where('status', 'sent')->whereNull('replied_at')->get(['id', 'sent_at']);
        $n = 0;
        foreach ($rows as $r) {
            $n += DB::table('chatbot_replies')->where('id', $r->id)->whereNull('replied_at')
                ->update(['replied_at' => $at, 'reply_seconds' => max(0, $at->getTimestamp() - Carbon::parse($r->sent_at)->getTimestamp())]);
        }

        return $n;
    }

    /** Ang hourly na backfill: ang mga padala ng huling 48 oras na wala pang sagot, kada customer. @return int ilang napunan */
    public static function backfill(): int
    {
        if (!self::ready()) return 0;
        $n = 0;
        DB::table('chatbot_replies')->where('sent_at', '>=', now()->subHours(self::BACKFILL_HOURS))->whereNull('replied_at')
            ->whereIn('kind', ['send', 'manual'])->where('status', 'sent')->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$n) {
                foreach ($rows as $r) {
                    $sent = Carbon::parse($r->sent_at);
                    $chat = BotcakeChat::where('page_id', (string) $r->page_id)->where('psid', (string) $r->psid)
                        ->where('received_at', '>', $sent)->where('received_at', '<=', $sent->copy()->addHours(self::REPLY_HOURS))
                        ->orderBy('received_at')->value('received_at');
                    if ($chat === null) continue;
                    $at = Carbon::parse($chat);
                    $n += DB::table('chatbot_replies')->where('id', $r->id)->whereNull('replied_at')
                        ->update(['replied_at' => $at, 'reply_seconds' => max(0, $at->getTimestamp() - $sent->getTimestamp())]);
                }
            }, 'id');

        return $n;
    }

    // ---- Order ----

    /**
     * Dumaan ang live na takbo sa node na "Counts as an order": isang chatbot_events na order, maliban kung may order na ang
     * customer sa loob ng 24 oras. Kasama ang takbo, ang flow version, ang huling 5 padala sa kanya (id, pinakabago muna)
     * at ang sequence step na huling ipinadala sa kanya.
     */
    public static function order(FlowRun $run, string $nodeId): bool
    {
        if ($run->test || !self::ready()) return false;
        $pageId = (int) $run->pageId;
        $psid = $run->fields->psid;
        if (DB::table('chatbot_events')->where('page_id', $pageId)->where('psid', $psid)->where('type', 'order')
            ->where('created_at', '>=', now()->subHours(self::ORDER_HOURS))->exists()) return false;

        $sends = DB::table('chatbot_replies')->where('page_id', $pageId)->where('psid', $psid)->whereIn('kind', ['send', 'manual'])->where('status', 'sent')
            ->orderByDesc('id')->limit(self::ORDER_SENDS)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $seq = DB::table('chatbot_replies')->where('page_id', $pageId)->where('psid', $psid)->whereNotNull('sequence_id')
            ->where('sent_at', '>=', now()->subDays(ChatbotResults::MAX_DAYS))->orderByDesc('id')->first(['sequence_id', 'sequence_step']);
        $hash = self::graphHash($run->graph);
        $run->runId ??= bin2hex(random_bytes(4));
        DB::table('chatbot_events')->insert(['page_id' => $pageId, 'psid' => $psid, 'chat_id' => $run->chatId, 'flow_id' => $run->flowId, 'node_id' => $nodeId,
            'type' => 'order', 'detail' => json_encode([
                'run' => $run->runId, 'flow_version' => self::flowVersion($run->flowId, $hash), 'flow_hash' => $hash, 'sends' => $sends,
                'sequence_id' => $seq ? (int) $seq->sequence_id : null, 'sequence_step' => $seq ? (int) $seq->sequence_step : null,
                'ad_id' => self::adId($pageId, $psid, $run->pageName),
            ]), 'created_at' => now()]);

        return true;
    }
}
