<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotReply;
use Illuminate\Support\Facades\DB;

/**
 * Ang totoong oras ng padala ng Forbeast (mula sa send_status, SendStatus): ang pending (tinanggap ng Forbeast pero hindi
 * pa kumpirmadong naipadala, sa huling 24 oras), ang unconfirmed (lampas 24 oras na walang kumpirmasyon) at ang lag mula
 * sa pagtanggap hanggang sa padala. Ang Botcake ay sabay, kaya walang pending. Hangga't walang send_status sa huling
 * 24 oras (hindi pa naka-deploy sa Forbeast), "not available": walang pending na ipinapakita at walang alarm.
 */
class RealSendTime
{
    public const WINDOW_SECONDS = 86400;
    public const PENDING_RED = 120;

    /** May mga column na (client_ref, confirmed_*, via)? */
    public static function ready(): bool
    {
        return ChatbotReply::hasConfirm() && ChatbotReply::hasVia();
    }

    /** May dumating na send_status sa huling 24 oras, sa anumang page? */
    public function available(): bool
    {
        if (!self::ready()) return false;
        $since = now()->subSeconds(self::WINDOW_SECONDS);

        // Ang sent_at ay para sa index (via, confirmed_status, sent_at): ang padala ay laging bago ang kumpirmasyon nito.
        return DB::table('chatbot_replies')->where('via', ChatbotProvider::FORBEAST)->whereIn('confirmed_status', SendStatus::STATUSES)
            ->where('sent_at', '>=', $since->copy()->subSeconds(self::WINDOW_SECONDS))->where('confirmed_at', '>=', $since)->exists();
    }

    /**
     * Ang sent_at ng pinakaunang kumpirmadong padala ng Forbeast (kailanman); null = wala pa. Ang mas luma rito ay hindi
     * kailanman makakakuha ng send_status (bago ito naka-deploy sa Forbeast), kaya hindi pending o unconfirmed. Isang seek
     * sa index kada status.
     */
    private function firstConfirmed(): ?string
    {
        $mins = array_filter(array_map(fn ($s) => DB::table('chatbot_replies')->where('via', ChatbotProvider::FORBEAST)->where('confirmed_status', $s)->min('sent_at'),
            SendStatus::STATUSES));

        return $mins ? min($mins) : null;
    }

    /**
     * Ang hindi pa kumpirmado ng Forbeast (tinanggap, status sent), mula sa unang kumpirmasyon. Hindi kasama ang may mas
     * bagong kumpirmadong padala sa parehong page + PSID: sunod-sunod ang padala ng Forbeast kada PSID, kaya tapos na ito
     * (nawalang send_status), at hindi nito mahahawakan ang alarm nang 24 oras.
     */
    private function unconfirmedQuery(?array $pages)
    {
        return DB::table('chatbot_replies as r')->where('r.via', ChatbotProvider::FORBEAST)->whereNull('r.confirmed_status')->where('r.status', 'sent')
            ->whereIn('r.kind', ['send', 'manual'])->where('r.sent_at', '>=', $this->firstConfirmed() ?? '9999-12-31 00:00:00')
            ->when($pages !== null, fn ($q) => $q->whereIn('r.page_id', $pages))
            ->whereNotExists(fn ($q) => $q->from('chatbot_replies as l')->whereColumn('l.page_id', 'r.page_id')->whereColumn('l.psid', 'r.psid')
                ->whereColumn('l.sent_at', '>', 'r.sent_at')->whereNotNull('l.confirmed_status')->where('l.via', ChatbotProvider::FORBEAST));
    }

    /** Ang pending ngayon kada page (page ID => [count, oldest na edad sa segundo]). @return array<string, array{count: int, oldest: int}> */
    public function pending(?array $pages): array
    {
        $now = now()->getTimestamp();
        $out = [];
        $rows = $this->unconfirmedQuery($pages)->where('sent_at', '>=', now()->subSeconds(self::WINDOW_SECONDS)->format('Y-m-d H:i:s'))
            ->groupBy('page_id')->selectRaw('page_id, COUNT(*) as n, MIN(sent_at) as oldest')->get();
        foreach ($rows as $r) $out[(string) $r->page_id] = ['count' => (int) $r->n, 'oldest' => max(0, $now - strtotime($r->oldest))];

        return $out;
    }

    /** Ang lampas 24 oras na walang kumpirmasyon, ipinadala mula $startAt hanggang $endAt, kada page. @return array<string, int> */
    public function unconfirmed(string $startAt, string $endAt, ?array $pages): array
    {
        $before = now()->subSeconds(self::WINDOW_SECONDS)->format('Y-m-d H:i:s');

        return $this->unconfirmedQuery($pages)->where('sent_at', '>=', $startAt)->where('sent_at', '<', min($endAt, $before))
            ->groupBy('page_id')->selectRaw('page_id, COUNT(*) as n')->pluck('n', 'page_id')->mapWithKeys(fn ($n, $p) => [(string) $p => (int) $n])->all();
    }

    /** Ang segundo mula pagtanggap hanggang padala ng mga naipadala (sent o partial) ng Forbeast. @return int[] */
    public function lags(string $startAt, string $endAt, ?array $pages): array
    {
        return DB::table('chatbot_replies')->where('via', ChatbotProvider::FORBEAST)->whereIn('confirmed_status', SendStatus::DELIVERED)
            ->where('sent_at', '>=', $startAt)->where('sent_at', '<', $endAt)->when($pages !== null, fn ($q) => $q->whereIn('page_id', $pages))
            ->get(['sent_at', 'confirmed_at'])->map(fn ($r) => max(0, strtotime($r->confirmed_at) - strtotime($r->sent_at)))->all();
    }

    /** Ang buod ng pending: bilang, pinakamatandang edad, at ilaw (red kapag lampas PENDING_RED). */
    public static function summary(array $pending): array
    {
        $oldest = $pending ? max(array_column($pending, 'oldest')) : 0;

        return ['count' => array_sum(array_column($pending, 'count')), 'oldest' => $oldest, 'light' => $oldest > self::PENDING_RED ? 'red' : 'green'];
    }
}
