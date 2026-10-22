<?php

namespace App\Services\Chatbot;

use App\Jobs\RunChatbotSequenceStep;
use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Models\ChatbotSequenceStep;
use App\Models\ChatbotSequenceSubscription;
use App\Services\PageSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ang mga sequence ng chatbot (drip):
 * - subscribe / unsubscribe (mula sa flow, sa inbox, o exit on reply);
 * - ang tick: kinukuha ang mga due (isang beses lang, kahit dalawang tick ang sabay) at isang job kada customer;
 * - ang step: pinapatakbo ang published na flow mula sa Start, sa parehong engine at outbox, saka umuusad.
 * Lahat ng oras ay Manila (ang timezone ng app).
 */
class ChatbotSequences
{
    public const BATCH = 500;
    public const SECONDS = 50;
    public const REDISPATCH_MINUTES = 10; // ang job na namatay sa gitna: kinukuha ulit pagkalipas nito
    public const HOLD_MINUTES = 60;       // naka-pause ang bot para sa customer: subukan ulit pagkalipas nito
    public const LOCKED_RETRY_MINUTES = 1;
    public const WINDOW_HOURS = 24;       // Messenger: send_content sa loob ng 24 oras mula sa huling mensahe ng customer

    private static ?bool $tables = null; // kada proseso: true lang ang iniimbak (sa ibaba)

    public function __construct(private PageSettings $settings, private ChatbotPage $page, private FlowEngine $engine, private BotcakeOutbox $outbox)
    {
    }

    // ---- Oras ----

    /** Ang oras ng padala: $from + delay, anumang oras ng araw (Manila, sa minuto). */
    public static function dueAt(CarbonInterface $from, int $amount, string $unit): Carbon
    {
        $t = Carbon::instance($from)->timezone('Asia/Manila')->seconds(0);

        return match ($unit) { 'days' => $t->addDays($amount), 'hours' => $t->addHours($amount), default => $t->addMinutes($amount) };
    }

    // ---- Subscribe ----

    /** Ang unang active na step mula sa $position (kasama), o null. */
    private function stepFrom(ChatbotSequence $seq, int $position): ?ChatbotSequenceStep
    {
        return $seq->steps()->where('active', true)->where('position', '>=', $position)->first();
    }

    /** Isang event na hindi bahagi ng takbo (inbox, tick, exit on reply). */
    private function event(int $pageId, int $psid, array $detail, ?int $flowId = null): void
    {
        ChatbotEvents::write($pageId, $psid, 'sequence', $detail, $flowId);
    }

    /**
     * Isama ang customer sa sequence (simula sa step 1). Walang ginagawa habang active o naka-pause na siya; mula ulit sa
     * step 1 kapag tapos, unsubscribed o error. Hindi isinasama kapag naka-pause ang sequence o walang active na step.
     * Ang event ay sa $run kapag galing sa flow (kasama ng takbo), kung hindi direkta.
     * @return array{result: string, name: string, reason?: string} result: subscribed | already | refused
     */
    public function subscribe(int $sequenceId, int $pageId, int $psid, string $via, ?int $by = null, ?FlowRun $run = null, ?string $node = null): array
    {
        $seq = ChatbotSequence::find($sequenceId);
        $out = function (array $r) use ($pageId, $psid, $run, $node) {
            $detail = ['op' => $r['result'], 'name' => $r['name']] + (isset($r['reason']) ? ['reason' => $r['reason']] : []);
            $run ? $run->event('sequence', $node, $detail) : $this->event($pageId, $psid, $detail);
            return $r;
        };
        if (!$seq) return $out(['result' => 'refused', 'name' => '#' . $sequenceId, 'reason' => 'the sequence was deleted']);
        if (!$seq->active) return $out(['result' => 'refused', 'name' => $seq->name, 'reason' => 'the sequence is paused']);
        $first = $this->stepFrom($seq, 1);
        if (!$first) return $out(['result' => 'refused', 'name' => $seq->name, 'reason' => 'the sequence has no active steps']);

        $values = ['status' => 'active', 'current_step' => $first->position, 'next_send_at' => self::dueAt(now(), $first->delay_amount, $first->delay_unit),
            'dispatched_at' => null, 'last_sent_at' => null, 'enrolled_via' => $via, 'enrolled_by' => $by, 'exit_reason' => null];
        $existing = ChatbotSequenceSubscription::where('sequence_id', $seq->id)->where('page_id', $pageId)->where('psid', $psid)->first();
        if ($existing && in_array($existing->status, ['active', 'paused'], true)) return $out(['result' => 'already', 'name' => $seq->name]);
        if ($existing) {
            // Kondisyonal: ang sabay na subscribe ay isa lang ang nagre-restart.
            $restarted = ChatbotSequenceSubscription::whereKey($existing->id)->whereNotIn('status', ['active', 'paused'])->update($values + ['updated_at' => now()]);
            if (!$restarted) return $out(['result' => 'already', 'name' => $seq->name]);
        } else {
            try {
                ChatbotSequenceSubscription::create($values + ['sequence_id' => $seq->id, 'page_id' => $pageId, 'psid' => $psid]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                return $out(['result' => 'already', 'name' => $seq->name]);
            }
        }

        return $out(['result' => 'subscribed', 'name' => $seq->name]);
    }

    /**
     * Alisin ang customer sa isang sequence ($sequenceId) o sa lahat (null) ng page na ito, kapag active o naka-pause.
     * @return array<int, string> ang mga pangalan ng sequence na inalisan
     */
    public function unsubscribe(?int $sequenceId, int $pageId, int $psid, string $reason, ?FlowRun $run = null, ?string $node = null, bool $onlyExitOnReply = false): array
    {
        $rows = ChatbotSequenceSubscription::with('sequence:id,name,exit_on_reply')->where('page_id', $pageId)->where('psid', $psid)
            ->whereIn('status', ['active', 'paused'])->when($sequenceId, fn ($q) => $q->where('sequence_id', $sequenceId))->get();
        $names = [];
        foreach ($rows as $row) {
            if ($onlyExitOnReply && !$row->sequence?->exit_on_reply) continue;
            $done = ChatbotSequenceSubscription::whereKey($row->id)->whereIn('status', ['active', 'paused'])
                ->update(['status' => 'unsubscribed', 'exit_reason' => $reason, 'next_send_at' => null, 'dispatched_at' => null, 'updated_at' => now()]);
            if (!$done) continue;
            $name = $row->sequence?->name ?? '#' . $row->sequence_id;
            $names[] = $name;
            $detail = ['op' => 'unsubscribed', 'name' => $name, 'reason' => $reason];
            $run ? $run->event('sequence', $node, $detail) : $this->event($pageId, $psid, $detail);
        }

        return $names;
    }

    /**
     * Sumagot ang customer (bagong chat sa page): alisin siya sa mga sequence ng page na may "exit when the customer
     * replies". Hindi kailanman pumipigil sa pagtanggap ng chat.
     */
    public function exitOnReply(int $pageId, int $psid): void
    {
        try {
            // Hindi pa na-migrate (mano-mano ang migrate pagkatapos ng deploy): walang gagawin, walang error.
            if (!self::$tables) {
                if (!\Illuminate\Support\Facades\Schema::hasTable('chatbot_sequence_subscriptions')) return;
                self::$tables = true;
            }
            $this->unsubscribe(null, $pageId, $psid, 'reply', onlyExitOnReply: true);
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ---- Tick ----

    /** Ang page na On (walang setting = On). */
    private function pageOn(string $page): bool
    {
        return $this->settings->get($page, 'chatbot', 'active') !== '0';
    }

    /**
     * Kunin ang mga row: isa-isang kondisyonal na update (dispatched_at), kaya ang row na nakuha na ng ibang tick (o ng
     * job na hindi pa luma) ay hindi nakukuha ulit, kahit walang row lock ang database.
     * @param array<int, int> $ids
     * @return array<int, int> ang mga nakuha
     */
    public function claim(array $ids, ?string $stamp = null): array
    {
        $stamp ??= now()->format('Y-m-d H:i:s');
        $claimed = [];
        foreach ($ids as $id) {
            // Naghihintay pa sa queue ang job nito (hawak ang lock): huwag galawin ang stamp, kundi ang job na iyon ay
            // magiging luma at walang gagawin, at ang bagong job ay hindi rin papasok (isa lang kada subscription).
            if ($this->stepQueued((int) $id)) continue;
            $n = DB::table('chatbot_sequence_subscriptions')->where('id', $id)->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(self::REDISPATCH_MINUTES)))
                ->update(['dispatched_at' => $stamp]);
            if ($n === 1) $claimed[] = (int) $id;
        }

        return $claimed;
    }

    /** May RunChatbotSequenceStep pa sa queue para sa subscription: hawak ang unique lock nito (binibitawan bago tumakbo). */
    private function stepQueued(int $id): bool
    {
        $lock = Cache::lock(UniqueLock::getKey(new RunChatbotSequenceStep($id)), 10);
        if (!$lock->get()) return true;
        $lock->release();

        return false;
    }

    /** Ang mga naka-pause dahil Off ang page, na On na ulit: active ulit (ang oras ng padala ay hindi binabago). */
    public function resumePaused(): int
    {
        $pages = DB::table('chatbot_sequence_subscriptions')->where('status', 'paused')->where('exit_reason', 'page off')
            ->distinct()->limit(self::BATCH)->pluck('page_id');
        $names = BotcakeChat::pageMap();
        $resumed = 0;
        foreach ($pages as $pageId) {
            $page = $names[(string) $pageId] ?? null;
            if ($page === null || !$this->pageOn($page)) continue;
            $resumed += DB::table('chatbot_sequence_subscriptions')->where('page_id', $pageId)->where('status', 'paused')->where('exit_reason', 'page off')
                ->update(['status' => 'active', 'exit_reason' => null, 'dispatched_at' => null, 'updated_at' => now()]);
        }

        return $resumed;
    }

    /** Isang tick: ibalik ang mga page na On na, saka isang job kada due na customer (500 kada batch, hanggang 50 s). */
    public function tick(): int
    {
        $this->resumePaused();
        $until = microtime(true) + self::SECONDS;
        $queued = 0;
        $after = null; // ang huling row ng nakaraang batch: ang nilaktawan (naghihintay pa ang job) ay hindi binabasa ulit
        do {
            // Walang row lock: ang kondisyonal na claim (isa-isa) ang nagsisiguro na isang beses lang.
            $rows = DB::table('chatbot_sequence_subscriptions as s')
                ->join('chatbot_sequences as q', 'q.id', '=', 's.sequence_id')
                ->where('s.status', 'active')->where('q.active', true)->where('s.next_send_at', '<=', now())
                ->where(fn ($w) => $w->whereNull('s.dispatched_at')->orWhere('s.dispatched_at', '<', now()->subMinutes(self::REDISPATCH_MINUTES)))
                ->when($after, fn ($q) => $q->where(fn ($w) => $w->where('s.next_send_at', '>', $after->next_send_at)
                    ->orWhere(fn ($x) => $x->where('s.next_send_at', $after->next_send_at)->where('s.id', '>', $after->id))))
                ->orderBy('s.next_send_at')->orderBy('s.id')->limit(self::BATCH)->get(['s.id', 's.next_send_at']);
            if ($rows->isEmpty()) break;
            $after = $rows->last();
            $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
            $stamp = now()->format('Y-m-d H:i:s');
            foreach ($this->claim($ids, $stamp) as $id) {
                RunChatbotSequenceStep::dispatch($id, $stamp);
                $queued++;
            }
        } while (count($ids) === self::BATCH && microtime(true) < $until);

        return $queued;
    }

    // ---- Step ----

    /**
     * Patakbuhin ang step ng isang subscription. Umuusad lang pagkatapos ng takbo o ng skip; ang pumalya ay error event
     * at umuusad pa rin (walang paulit-ulit).
     * @return string 'ran' | 'skipped' | 'failed' | 'held' | 'paused' | 'completed' | 'locked' | 'gone'
     */
    public function runStep(int $subscriptionId, ?string $claim = null): string
    {
        $sub = ChatbotSequenceSubscription::find($subscriptionId);
        if (!$sub || $sub->status !== 'active') return 'gone';
        // Habang active lang: ang sabay na unsubscribe (sagot, inbox) ay hindi binabalik.
        $release = fn (array $extra = []) => ChatbotSequenceSubscription::whereKey($sub->id)->where('status', 'active')->update($extra + ['dispatched_at' => null, 'updated_at' => now()]);

        $lock = Cache::lock('chatbot-run:' . $sub->page_id . ':' . $sub->psid, ChatbotRunner::LOCK_SECONDS);
        if (!$lock->get()) {
            $release(['next_send_at' => now()->addMinutes(self::LOCKED_RETRY_MINUTES)]);
            return 'locked';
        }

        try {
            $sub->refresh();
            if ($sub->status !== 'active') return 'gone';
            // Hinihintay pa ang sagot ng AI ng customer (chatbot:ai-pool): parang hawak ang lock, mamaya na ang step.
            if (ChatbotAiQueue::pending(new CustomerFields($sub->page_id, $sub->psid))) {
                $release(['next_send_at' => now()->addMinutes(self::LOCKED_RETRY_MINUTES)]);
                return 'locked';
            }
            // Ang job na naunahan ng mas bagong claim (naipon ang queue nang lampas 10 minuto) ay walang ginagawa.
            if ($claim !== null && $sub->dispatched_at?->format('Y-m-d H:i:s') !== $claim) return 'gone';
            if (!$sub->next_send_at || $sub->next_send_at->gt(now())) {
                if ($claim !== null) $release();
                return 'gone';
            }
            $seq = $sub->sequence;
            if (!$seq) {
                $release(['status' => 'error', 'exit_reason' => 'sequence deleted', 'next_send_at' => null]);
                $this->event($sub->page_id, $sub->psid, ['op' => 'failed', 'error' => 'the sequence was deleted', 'name' => '#' . $sub->sequence_id, 'step' => $sub->current_step]);
                return 'gone';
            }
            if (!$seq->active) {
                $release(); // hindi kinukuha ng tick hangga't naka-pause ang sequence
                return 'gone';
            }
            $step = $this->stepFrom($seq, $sub->current_step);
            if (!$step) return $this->complete($sub, $seq);

            $detail = ['name' => $seq->name, 'step' => $step->position];
            $page = BotcakeChat::pageMap()[(string) $sub->page_id] ?? null;
            if ($page === null) {
                $release(['status' => 'error', 'exit_reason' => 'page not set up', 'next_send_at' => null]);
                $this->event($sub->page_id, $sub->psid, ['op' => 'failed', 'error' => 'the page has no Botcake ID'] + $detail);
                return 'failed';
            }
            if (!$this->pageOn($page)) {
                $release(['status' => 'paused', 'exit_reason' => 'page off']);
                $this->event($sub->page_id, $sub->psid, ['op' => 'paused', 'reason' => 'page off', 'name' => $seq->name]);
                return 'paused';
            }
            $fields = new CustomerFields($sub->page_id, $sub->psid);
            if ($fields->get(CustomerFields::BOT_PAUSED) === '1') {
                $release(['next_send_at' => now()->addMinutes(self::HOLD_MINUTES)]);
                $this->event($sub->page_id, $sub->psid, ['op' => 'held', 'reason' => 'bot paused'] + $detail);
                return 'held';
            }
            $last = BotcakeChat::where('page_id', (string) $sub->page_id)->where('psid', (string) $sub->psid)->orderByDesc('id')->first();
            // Lampas 24 oras mula sa huling mensahe ng customer: walang ipapadala at tapos na ang subscription.
            if (!$last || !$last->received_at || $last->received_at->lte(now()->subHours(self::WINDOW_HOURS))) {
                $release(['status' => 'unsubscribed', 'exit_reason' => 'outside 24h', 'next_send_at' => null]);
                $this->event($sub->page_id, $sub->psid, ['op' => 'skipped', 'reason' => 'outside 24h'] + $detail);
                return 'skipped';
            }
            $flow = $step->flow_id ? ChatbotFlow::find($step->flow_id) : null;
            $why = !$flow ? 'the flow was deleted' : ($flow->published_at === null || !is_array($flow->graph) ? 'the flow is not published' : null);
            if ($why !== null) {
                $this->event($sub->page_id, $sub->psid, ['op' => 'failed', 'error' => $why] + $detail, $flow?->id);
                return $this->advance($sub, $seq, $step, false);
            }

            $run = new FlowRun($flow->id, $flow->graph, $page, (string) $sub->page_id, $this->page->botfields($page), $fields, null,
                '', (string) $last->name, now()->format('Y-m-d H:i'));
            $run->startedBy = 'sequence ' . $seq->name . ' step ' . $step->position;
            [$run->sequenceId, $run->sequenceStep] = [(int) $seq->id, (int) $step->position];
            try {
                $this->engine->run($run, $this->outbox);
                $run->event('sequence', null, ['op' => 'ran'] + $detail);
            } catch (Throwable $e) {
                report($e);
                $run->event('sequence', null, ['op' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 150)] + $detail);
            }
            ChatbotEvents::store($run, $sub->page_id, $sub->psid);

            return $this->advance($sub, $seq, $step, true);
        } finally {
            $lock->release();
        }
    }

    /** Ang susunod na active na step pagkatapos ng $step (ang delay ay mula ngayon), o tapos na. */
    private function advance(ChatbotSequenceSubscription $sub, ChatbotSequence $seq, ChatbotSequenceStep $step, bool $sent): string
    {
        $next = $this->stepFrom($seq, $step->position + 1);
        $values = ['dispatched_at' => null, 'updated_at' => now()] + ($sent ? ['last_sent_at' => now()] : []);
        if (!$next) {
            ChatbotSequenceSubscription::whereKey($sub->id)->where('status', 'active')->update($values);
            return $this->complete($sub->fresh(), $seq);
        }
        // Habang active lang: ang customer na sumagot (o inalis) habang tumatakbo ang step ay hindi ibinabalik.
        ChatbotSequenceSubscription::whereKey($sub->id)->where('status', 'active')->update($values + ['current_step' => $next->position,
            'next_send_at' => self::dueAt(now(), $next->delay_amount, $next->delay_unit)]);

        return $sent ? 'ran' : 'skipped';
    }

    private function complete(ChatbotSequenceSubscription $sub, ChatbotSequence $seq): string
    {
        $done = ChatbotSequenceSubscription::whereKey($sub->id)->where('status', 'active')
            ->update(['status' => 'completed', 'next_send_at' => null, 'dispatched_at' => null, 'updated_at' => now()]);
        if ($done) $this->event($sub->page_id, $sub->psid, ['op' => 'completed', 'name' => $seq->name]);

        return 'completed';
    }
}
