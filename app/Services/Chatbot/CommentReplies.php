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
 * hanggang 20 comment kada minuto kada page (ang iba ay naghihintay sa susunod na minuto, gaya ng Botcake).
 */
class CommentReplies
{
    public const PER_MINUTE = 20;
    public const VARIANTS = ['comment_reply_1', 'comment_reply_2', 'comment_reply_3'];
    public const PM = 'comment_pm';
    /** Ang mga dahilan ng skipped, sa screen (Replies). */
    public const REASONS = [
        'botcake_page' => 'the page replies via Botcake', 'bot_off' => 'the bot is Off', 'reply' => 'a reply to a reply',
        'page_author' => 'written by the page', 'ours' => 'our own reply', 'not_set' => 'not set (no Comment PM or Comment Reply)',
        'unknown_page' => 'unknown page', GatewayComments::ALREADY_REPLIED => 'already replied',
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

        if (($wait = $this->slot((string) $event->page_id)) > 0) return $wait;
        // Isang worker lang ang magpapadala (ang job na naulit ay walang gagawin).
        if (ChatbotCommentEvent::whereKey($event->id)->where('status', 'queued')->update(['status' => 'sending']) === 0) return 0;

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
