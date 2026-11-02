<?php

namespace App\Services\Chatbot;

use App\Jobs\HandleComment;
use App\Models\ChatbotCommentEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Ang sagot sa comment ng page na nasa Forbeast, gaya ng comment feature ng Botcake: ang Comment PM (isang private
 * message, una) at isa sa mga may lamang Comment Reply 1-3 (public, random, laban sa spam check ng Facebook). Ang
 * blangkong column ay patay ang bahaging iyon. Para lang sa page na bot On at provider Forbeast (ang Botcake page ay
 * may sariling comment feature), sa top-level na comment na hindi galing sa page o sa amin, isang beses kada comment,
 * hanggang 20 comment kada minuto kada page (ang iba ay naghihintay sa susunod na minuto, gaya ng Botcake). Isang sagot
 * lang kada tao (from_id) kada page kada araw sa Manila: ang mga sumunod niyang comment sa araw na iyon ay skipped
 * (daily_limit), para hindi siya makatanggap ng limang private message at limang public reply sa limang comment.
 */
class CommentReplies
{
    public const PER_MINUTE = 20;
    public const VARIANTS = ['comment_reply_1', 'comment_reply_2', 'comment_reply_3'];
    public const PM = 'comment_pm';
    public const DAILY_LIMIT = 'daily_limit';
    /** Ang bahaging binibilang na sagot sa araw: naipadala, naitala na ng Meta, o maaaring naipost na (504). */
    private const ANSWERED = ['sent', GatewayComments::ALREADY_REPLIED, GatewayComments::UNKNOWN];
    /** Ang comment ay naghihintay sa queue nang hanggang 6 na oras lang (HandleComment), kaya ang mas luma ay hindi masasagot ngayon. */
    private const QUEUE_HOURS = 7;
    /** Segundo bago subukan ulit kapag may ibang worker na nagpapasya para sa parehong tao at page. */
    private const LOCK_WAIT = 5;
    /** Ang mga dahilan ng skipped, sa screen (Replies). */
    public const REASONS = [
        'botcake_page' => 'the page replies via Botcake', 'bot_off' => 'the bot is Off', 'reply' => 'a reply to a reply',
        'page_author' => 'written by the page', 'ours' => 'our own reply', 'not_set' => 'not set (no Comment PM or Comment Reply)',
        'unknown_page' => 'unknown page', GatewayComments::ALREADY_REPLIED => 'already replied',
        self::DAILY_LIMIT => 'already answered today',
    ];

    /** @var callable|null ang pumipili ng variant (test seam); null = random_int */
    private static $pick = null;

    public function __construct(private ChatbotPage $page, private GatewayComments $gateway)
    {
    }

    /** Para sa test: ang pipili ng public reply mula sa mga variant (null = random). */
    public static function pickUsing(?callable $pick): void
    {
        self::$pick = $pick;
    }

    /**
     * Itinatabi ang comment (isang row kada comment_id) at ipinapasa sa queue. Ang text ng comment ay hindi itinatabi.
     * @return string ang bilang sa gateway-events: comment | duplicate | no_id
     */
    public function receive(array $e, string $pageName): string
    {
        if ((string) $e['comment_id'] === '' || !Schema::hasTable('chatbot_comment_events')) return 'no_id';
        try {
            $event = ChatbotCommentEvent::create([
                'page_id' => (int) $e['page_id'], 'comment_id' => (string) $e['comment_id'], 'post_id' => $e['post_id'], 'from_id' => $e['from_id'],
                'status' => 'queued', 'received_at' => now(),
            ]);
        } catch (QueryException $ex) {
            // Ang unique na comment_id: ang ulit na event (retry ng gateway) ay hindi sinasagot ulit.
            if (ChatbotCommentEvent::where('comment_id', (string) $e['comment_id'])->exists()) return 'duplicate';
            throw $ex;
        }
        $reason = $this->skipReason($e, $pageName);
        if ($reason !== null) {
            $event->update(['status' => 'skipped', 'reason' => $reason, 'handled_at' => now()]);
            return 'comment';
        }
        HandleComment::dispatch($event->id);

        return 'comment';
    }

    /**
     * Ang takbo ng job. @return int 0 = tapos (o wala nang gagawin); higit sa 0 = segundo bago subukan ulit (puno ang minuto)
     */
    public function handle(int $id): int
    {
        $event = ChatbotCommentEvent::find($id);
        if ($event === null || $event->status !== 'queued') return 0;
        $pageName = \App\Models\BotcakeChat::pageMap()[(string) $event->page_id] ?? null;
        if ($pageName === null) return $this->skip($event, 'unknown_page');
        // Muling sinusuri: maaaring pinatay ang bot o inilipat sa Botcake habang naghihintay.
        if (($reason = $this->pageReason($pageName)) !== null) return $this->skip($event, $reason);

        // Ang nag-comment na kilalang customer (may chat sa page): ang ad group ng pinakabagong ad niya, gaya ng takbo.
        $group = ctype_digit((string) $event->from_id) ? app(AdGroups::class)->forCustomer((string) $event->page_id, (string) $event->from_id, $pageName) : null;
        $fields = $this->page->botfields($pageName, $group);
        // Gaya ng FlowRun: ang botfield na may variable (hal. "Only {{PRICE}} pesos") ay pinupunan muna nang isang antas.
        $run = ['page_id' => (string) $event->page_id, 'page_name' => $pageName, 'current_date' => now('Asia/Manila')->toDateString()];
        $base = ChatbotVars::merge($fields, $run);
        $vars = ChatbotVars::merge(array_map(fn ($v) => ChatbotVars::interpolate((string) $v, $base), $fields), $run);
        $value = fn (string $key) => trim(ChatbotVars::interpolate(trim((string) ($fields[$key] ?? '')), $vars));
        $pm = $value(self::PM);
        $variants = array_values(array_filter(array_map($value, self::VARIANTS), fn ($v) => $v !== ''));
        if ($pm === '' && !$variants) return $this->skip($event, 'not_set');

        // Isang sagot kada tao kada page kada araw, sinusuri tuwing tatakbo (pati pagkatapos maghintay ng minuto). Ang
        // pagsuri at ang pagkuha ng row (sending) ay nasa iisang lock, kaya sa dalawang comment niyang sabay dumating ay isa
        // lang ang masasagot; ang row na sending ay binibilang nang sagot, kaya hindi hinahawakan ang lock habang nagpapadala.
        // Ang row na may naipadala nang bahagi (bago ang 429) ay tinatapos: iyon ang sagot ng araw.
        $limited = (string) $event->from_id !== '' && $event->pm_status === null && $event->reply_status === null;
        $lock = $limited ? Cache::lock('comment-replies:once:' . $event->page_id . ':' . $event->from_id, 10) : null;
        if ($lock !== null && !$lock->get()) return self::LOCK_WAIT;
        try {
            if ($limited && $this->answeredToday($event)) {
                ChatbotCommentEvent::whereKey($event->id)->where('status', 'queued')->update(['status' => 'skipped', 'reason' => self::DAILY_LIMIT, 'handled_at' => now()]);
                return 0;
            }
            if (($wait = $this->slot((string) $event->page_id)) > 0) return $wait;
            // Isang worker lang ang magpapadala (ang job na naulit ay walang gagawin).
            if (ChatbotCommentEvent::whereKey($event->id)->where('status', 'queued')->update(['status' => 'sending']) === 0) return 0;
        } finally {
            $lock?->release();
        }

        // Ang bahaging nasubukan na (may status) ay hindi na ipinapadala ulit: ang 429 ay bumabalik dito para sa natitira lang.
        $done = [];
        $codes = [];
        if ($pm !== '' && $event->pm_status === null) {
            $r = $this->gateway->privateReply($pageName, (string) $event->page_id, $event->comment_id, $pm);
            if ($r['status'] === GatewayComments::THROTTLED) return $this->throttled($event, $done + (($code = array_values(array_filter($codes))[0] ?? null) !== null ? ['reason' => $code] : []), (string) $event->page_id);
            $done += ['pm_status' => $r['status'], 'pm_text' => $pm, 'pm_error' => $r['error']];
            $codes[] = $r['status'] === 'failed' ? $r['code'] : null;
        }
        if ($variants && $event->reply_status === null) {
            $text = self::$pick ? (string) (self::$pick)($variants) : $variants[random_int(0, count($variants) - 1)];
            $r = $this->gateway->reply($pageName, (string) $event->page_id, $event->comment_id, $text);
            if ($r['status'] === GatewayComments::THROTTLED) return $this->throttled($event, $done + (($code = array_values(array_filter($codes))[0] ?? null) !== null ? ['reason' => $code] : []), (string) $event->page_id);
            $done += ['reply_status' => $r['status'], 'reply_text' => $text, 'reply_error' => $r['error'], 'reply_comment_id' => $r['comment_id']];
            $codes[] = $r['status'] === 'failed' ? $r['code'] : null;
        }
        $parts = array_filter([$done['pm_status'] ?? $event->pm_status, $done['reply_status'] ?? $event->reply_status]);
        $status = match (true) {
            in_array('failed', $parts, true) => 'failed',
            in_array(GatewayComments::UNKNOWN, $parts, true) => GatewayComments::UNKNOWN,
            in_array('sent', $parts, true) => 'sent',
            default => 'skipped',
        };
        $reason = $status === 'failed' ? (array_values(array_filter($codes))[0] ?? $event->reason) : ($status === 'skipped' ? GatewayComments::ALREADY_REPLIED : null);
        $event->update($done + ['status' => $status, 'reason' => $reason, 'handled_at' => now()]);

        return 0;
    }

    /**
     * Ang 429 ng Forbeast: ang naipadala na ay itinatabi, ang row ay naghihintay ulit, at puno na ang minuto ng page para
     * sa lahat ng comment nito. @return int segundo hanggang sa susunod na minuto
     */
    private function throttled(ChatbotCommentEvent $event, array $done, string $pageId): int
    {
        // Sa query (hindi sa model): ang model ay "queued" pa sa memorya, kaya hindi nito isusulat ang pagbalik mula sa sending.
        ChatbotCommentEvent::whereKey($event->id)->update($done + ['status' => 'queued']);
        $now = now();
        Cache::put('comment-replies:' . $pageId . ':' . $now->format('YmdHi'), self::PER_MINUTE, 120);

        return max(1, 60 - (int) $now->format('s'));
    }

    /**
     * Nasagot na ba ngayong araw (Manila, ang araw ng pagpapadala) ang taong ito sa page na ito ng ibang comment: may
     * bahaging naipadala, naitala na o maaaring naipost na, o nagpapadala pa. Ang skipped at ang pumalya bago may naipadala
     * ay hindi binibilang. Ang handled_at na null ay ginagawa pa ngayon (sending, o naghihintay na may naipadala na).
     */
    private function answeredToday(ChatbotCommentEvent $event): bool
    {
        $today = now('Asia/Manila')->startOfDay()->timezone(config('app.timezone'));

        // Ang received_at ay hangganan para sa index (page_id, received_at); ang from_id ay sinasala sa mga row na iyon.
        return ChatbotCommentEvent::where('page_id', $event->page_id)->where('received_at', '>=', $today->copy()->subHours(self::QUEUE_HOURS))
            ->where('from_id', (string) $event->from_id)->whereKeyNot($event->id)
            ->where(fn ($w) => $w->where('status', 'sending')->orWhereIn('pm_status', self::ANSWERED)->orWhereIn('reply_status', self::ANSWERED))
            ->where(fn ($w) => $w->whereNull('handled_at')->orWhere('handled_at', '>=', $today))
            ->exists();
    }

    /** Ang dahilan ng hindi pagsagot na alam na pagdating ng event, o null. */
    private function skipReason(array $e, string $pageName): ?string
    {
        if ($e['is_reply']) return 'reply';
        if ($e['is_page_author'] || (string) $e['from_id'] === (string) $e['page_id']) return 'page_author';
        if (ChatbotCommentEvent::where('reply_comment_id', (string) $e['comment_id'])->exists()) return 'ours';

        return $this->pageReason($pageName);
    }

    private function pageReason(string $pageName): ?string
    {
        if (ChatbotProvider::name($pageName) !== ChatbotProvider::GATEWAY) return 'botcake_page';
        if (app(\App\Services\BotcakeChatGuard::class)->botOff($pageName)) return 'bot_off';

        return null;
    }

    private function skip(ChatbotCommentEvent $event, string $reason): int
    {
        $event->update(['status' => 'skipped', 'reason' => $reason, 'handled_at' => now()]);

        return 0;
    }

    /** Ang upuan sa minutong ito ng page (hanggang PER_MINUTE): 0 = may upuan, kung hindi ay segundo hanggang sa susunod na minuto. */
    private function slot(string $pageId): int
    {
        $now = now();
        $key = 'comment-replies:' . $pageId . ':' . $now->format('YmdHi');
        Cache::add($key, 0, 120);
        if (Cache::increment($key) <= self::PER_MINUTE) return 0;

        return max(1, 60 - (int) $now->format('s'));
    }
}
