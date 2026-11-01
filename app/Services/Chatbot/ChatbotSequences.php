<?php

namespace App\Services\Chatbot;

use App\Jobs\ResumeChatbotSequenceSend;
use App\Jobs\RunChatbotSequenceStep;
use App\Models\BotcakeChat;
use App\Models\ChatbotSequence;
use App\Models\ChatbotSequenceStep;
use App\Models\ChatbotSequenceSubscription;
use App\Services\PageSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang mga sequence ng chatbot (drip):
 * - subscribe / unsubscribe (mula sa flow, sa inbox, o exit on reply);
 * - ang tick: kinukuha ang mga due (isang beses lang, kahit dalawang tick ang sabay) at isang job kada customer;
 * - ang step: pinapatakbo ang published na flow mula sa Start, sa parehong engine at outbox, saka umuusad. Kapag handa ang
 *   chatbot:sequence-pool, ang padala ay ipinapasa (hindi naghihintay ang worker) at ang step ay umuusad kapag alam na ang
 *   resulta (resumeSend); habang nasa himpapawid (send_request_id), hindi ito kinukuha ulit ng tick.
 * Lahat ng oras ay Manila (ang timezone ng app).
 */
class ChatbotSequences
{
    public const BATCH = 500;
    public const SECONDS = 50;
    public const REDISPATCH_MINUTES = 10; // ang job na namatay sa gitna (padalang naghihintay): kinukuha ulit pagkalipas nito
    public const STALE_MINUTES = 2;       // gaya nito, kapag ipinapasa ang padala (ang job ay hanggang HANDOFF_TIMEOUT lang)
    public const HANDOFF_TIMEOUT = 100;   // segundo: mas maikli sa STALE_MINUTES
    public const INLINE_TIMEOUT = 280;    // mas maikli sa lock ng customer (ChatbotRunner::LOCK_SECONDS)
    public const BACKSTOP_MINUTES = 5;    // lampas sa deadline ng ticket: ang naiwang tali ay tinatapos, anuman ang status
    public const HOLD_MINUTES = 60;       // naka-pause ang bot para sa customer: subukan ulit pagkalipas nito
    public const LOCKED_RETRY_MINUTES = 1;
    public const WINDOW_HOURS = 24;       // Messenger: send_content sa loob ng 24 oras mula sa huling mensahe ng customer
    public const RAN_WINDOW_SECONDS = 590; // ang ran ng step ay hindi lalayo rito sa unang padala (ResponseSpeed::STEP_PUSH_SECONDS)
    public const RUNNING = 'chatbot-seq-running:';
    public const NOT_RESENT = 'stopped after a send; not sent again';

    private static ?bool $tables = null; // kada proseso: true lang ang iniimbak (sa ibaba)
    /** Ang stamp ng naunang claim ng susunod na runStep (earlier()), isang beses lang. */
    private ?string $earlier = null;
    /** Ang timeout ng job na tatakbo (para sa tanda ng tumatakbo), isang beses lang. */
    private ?int $timeout = null;

    public function __construct(private PageSettings $settings, private ChatbotPage $page, private FlowEngine $engine, private BotcakeOutbox $outbox, private AdGroups $groups)
    {
    }

    // ---- Ang column ng padalang nasa himpapawid ----

    /**
     * May send_request_id na ba (mano-mano ang migrate pagkatapos ng deploy)? Naka-memo sa app: ang true habang buhay ang
     * proseso, ang false nang 60 s. Wala pa: lahat ay gaya ng dati (walang filter, walang sulat, walang hand-off).
     */
    public static function hasInFlight(): bool
    {
        $key = 'chatbot-sequences.in-flight';
        $app = app();
        if ($app->bound($key)) {
            [$has, $at] = $app->make($key);
            if ($has || microtime(true) - $at < 60) return $has;
        }
        try {
            $has = Schema::hasTable('chatbot_sequence_subscriptions') && Schema::hasColumn('chatbot_sequence_subscriptions', 'send_request_id');
        } catch (Throwable) {
            $has = false;
        }
        $app->instance($key, [$has, microtime(true)]);

        return $has;
    }

    /** Basahin ulit ang schema (pagkatapos ng migrate sa parehong proseso, at sa tests). */
    public static function forgetSchema(): void
    {
        app()->forgetInstance('chatbot-sequences.in-flight');
    }

    /** Ang pagbura ng tali sa ticket (kapag may column): bawat sumusulat ng status o step ay kasama ito. */
    public static function unlink(): array
    {
        return self::hasInFlight() ? ['send_request_id' => null] : [];
    }

    /** Ilang minuto bago kunin ulit ang claim na walang galaw: 2 kapag ipinapasa ang padala, 10 kapag naghihintay ang job. */
    public static function staleMinutes(): int
    {
        return BotcakeSendQueue::sequenceReady() ? self::STALE_MINUTES : self::REDISPATCH_MINUTES;
    }

    // ---- Oras ----

    /** Ang oras ng padala: $from + delay, anumang oras ng araw (Manila, sa minuto). */
    public static function dueAt(CarbonInterface $from, int $amount, string $unit): Carbon
    {
        $t = Carbon::instance($from)->timezone('Asia/Manila')->seconds(0);

        return match ($unit) { 'days' => $t->addDays($amount), 'hours' => $t->addHours($amount), default => $t->addMinutes($amount) };
    }

    // ---- Subscribe ----

    /** Ang unang active na step mula sa $position (kasama), o null. Mula sa mga step na kasama ng sequence (BotConfigCache). */
    private function stepFrom(ChatbotSequence $seq, int $position): ?ChatbotSequenceStep
    {
        return $seq->steps->first(fn (ChatbotSequenceStep $s) => $s->active && $s->position >= $position);
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
        $seq = BotConfigCache::sequence($sequenceId);
        $out = function (array $r) use ($pageId, $psid, $run, $node) {
            $detail = ['op' => $r['result'], 'name' => $r['name']] + (isset($r['reason']) ? ['reason' => $r['reason']] : []);
            $run ? $run->event('sequence', $node, $detail) : $this->event($pageId, $psid, $detail);
            return $r;
        };
        if (!$seq) return $out(['result' => 'refused', 'name' => '#' . $sequenceId, 'reason' => 'the sequence was deleted']);
        if (!$seq->active) return $out(['result' => 'refused', 'name' => $seq->name, 'reason' => 'the sequence is paused']);
        $first = $this->stepFrom($seq, 1);
        if (!$first) return $out(['result' => 'refused', 'name' => $seq->name, 'reason' => 'the sequence has no active steps']);

        // Ang tali sa lumang ticket ay binubura: ang resulta nito (kapag dumating) ay hindi na umuusad sa bagong subscription.
        $values = ['status' => 'active', 'current_step' => $first->position, 'next_send_at' => self::dueAt(now(), $first->delay_amount, $first->delay_unit),
            'dispatched_at' => null, 'last_sent_at' => null, 'enrolled_via' => $via, 'enrolled_by' => $by, 'exit_reason' => null] + self::unlink();
        // Ang page na inililipat (hold sa site na ito): held agad, at ang catch-up ng paglipat ang magdadala nito sa bagong site.
        if (($hold = $this->holdOf($pageId)) !== null) $values = ['status' => PageTransfers::HELD, 'exit_reason' => PageTransfers::HOLD_REASON . $hold] + $values;
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
        // Ang ad group ng customer sa pagpasok (ng takbong nagpasok, o ang pinakabagong ad niya): hindi lumilipat sa gitna.
        if (($page = BotcakeChat::pageMap()[(string) $pageId] ?? null) !== null) {
            $group = $run ? $run->adGroup : $this->groups->forCustomer($pageId, $psid, $page);
            // Ang pumalyang lookup sa Meta ay hindi itinatala: ang unang step ang kukuha ulit ng group.
            if (!($run ? $run->adGroupUnresolved : $this->groups->unresolved)) $this->groups->pin($seq->id, $run?->fields ?? new CustomerFields($pageId, $psid), $page, $group);
        }

        return $out(['result' => 'subscribed', 'name' => $seq->name]);
    }

    /** Ang id ng paglipat na humahawak sa page (PageTransfers::hold), o null. */
    private function holdOf(int $pageId): ?string
    {
        $page = BotcakeChat::pageMap()[(string) $pageId] ?? null;

        return $page === null ? null : $this->settings->get($page, PageTransfers::HOLD_PROVIDER, PageTransfers::HOLD_KEY);
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
                ->update(['status' => 'unsubscribed', 'exit_reason' => $reason, 'next_send_at' => null, 'dispatched_at' => null, 'updated_at' => now()] + self::unlink());
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
                if (!Schema::hasTable('chatbot_sequence_subscriptions')) return;
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
     * job na hindi pa luma) ay hindi nakukuha ulit, kahit walang row lock ang database. Ang row na nasa himpapawid ang padala
     * (send_request_id), may job pa sa queue, o may job na tumatakbo (RUNNING) ay hindi kailanman kinukuha.
     * @param array<int, int> $ids
     * @return array<int, int> ang mga nakuha
     */
    public function claim(array $ids, ?string $stamp = null): array
    {
        $stamp ??= now()->format('Y-m-d H:i:s');
        $stale = now()->subMinutes(self::staleMinutes());
        $inFlight = self::hasInFlight();
        $claimed = [];
        foreach ($ids as $id) {
            // Naghihintay pa sa queue ang job nito (hawak ang lock): huwag galawin ang stamp, kundi ang job na iyon ay
            // magiging luma at walang gagawin, at ang bagong job ay hindi rin papasok (isa lang kada subscription).
            if ($this->stepQueued((int) $id) || Cache::has(self::RUNNING . $id)) continue;
            $n = DB::table('chatbot_sequence_subscriptions')->where('id', $id)->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', $stale))
                ->when($inFlight, fn ($q) => $q->whereNull('send_request_id'))
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
            // Ang page na inililipat (hold): hindi muna, kundi may padala ang source habang kinokopya.
            if ($page === null || !$this->pageOn($page) || $this->holdOf((int) $pageId) !== null) continue;
            $resumed += DB::table('chatbot_sequence_subscriptions')->where('page_id', $pageId)->where('status', 'paused')->where('exit_reason', 'page off')
                ->update(['status' => 'active', 'exit_reason' => null, 'dispatched_at' => null, 'updated_at' => now()] + self::unlink());
        }

        return $resumed;
    }

    /**
     * Isang tick: ibalik ang mga page na On na, tapusin ang mga naiwang padala (settleInFlight), saka isang job kada due na
     * customer (500 kada batch, hanggang 50 s).
     */
    public function tick(): int
    {
        $this->resumePaused();
        $inFlight = self::hasInFlight();
        if ($inFlight) {
            try {
                $this->settleInFlight();
            } catch (Throwable $e) {
                report($e); // hindi kailanman humaharang sa mga due na step
            }
        }
        $stale = now()->subMinutes(self::staleMinutes());
        $until = microtime(true) + self::SECONDS;
        $queued = 0;
        $after = null; // ang huling row ng nakaraang batch: ang nilaktawan (naghihintay pa ang job) ay hindi binabasa ulit
        do {
            // Walang row lock: ang kondisyonal na claim (isa-isa) ang nagsisiguro na isang beses lang.
            $rows = DB::table('chatbot_sequence_subscriptions as s')
                ->join('chatbot_sequences as q', 'q.id', '=', 's.sequence_id')
                ->where('s.status', 'active')->where('q.active', true)->where('s.next_send_at', '<=', now())
                ->where(fn ($w) => $w->whereNull('s.dispatched_at')->orWhere('s.dispatched_at', '<', $stale))
                ->when($inFlight, fn ($q) => $q->whereNull('s.send_request_id'))
                ->when($after, fn ($q) => $q->where(fn ($w) => $w->where('s.next_send_at', '>', $after->next_send_at)
                    ->orWhere(fn ($x) => $x->where('s.next_send_at', $after->next_send_at)->where('s.id', '>', $after->id))))
                ->orderBy('s.next_send_at')->orderBy('s.id')->limit(self::BATCH)->get(['s.id', 's.next_send_at', 's.dispatched_at']);
            if ($rows->isEmpty()) break;
            $after = $rows->last();
            $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
            $earlier = $rows->mapWithKeys(fn ($r) => [(int) $r->id => $r->dispatched_at === null ? null : (string) $r->dispatched_at])->all();
            $stamp = now()->format('Y-m-d H:i:s');
            foreach ($this->claim($ids, $stamp) as $id) {
                // Ang claim na kinuha ulit (namatay ang job): ang stamp noon, para malaman ng step kung may naipadala na.
                RunChatbotSequenceStep::dispatch($id, $stamp, $earlier[$id] ?? null);
                $queued++;
            }
        } while (count($ids) === self::BATCH && microtime(true) < $until);

        return $queued;
    }

    // ---- Step ----

    /** Ang stamp ng naunang claim (namatay ang job) para sa susunod na runStep. */
    public function earlier(?string $at, ?int $timeout = null): static
    {
        $this->earlier = $at;
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Patakbuhin ang step ng isang subscription. Umuusad lang pagkatapos ng takbo o ng skip; ang pumalya ay error event
     * at umuusad pa rin (walang paulit-ulit). Kapag ipinasa ang padala: 'sending', at ang resume ang uusad.
     * Ang stamp ng naunang claim ng step na ito (earlier(), kinuha ulit ng tick): kapag may naipadala na mula roon, hindi na
     * pinapatakbo ulit.
     * @return string 'ran' | 'sending' | 'skipped' | 'failed' | 'held' | 'paused' | 'completed' | 'locked' | 'gone'
     */
    public function runStep(int $subscriptionId, ?string $claim = null): string
    {
        [$earlier, $this->earlier, $timeout, $this->timeout] = [$this->earlier, null, $this->timeout, null];
        $sub = ChatbotSequenceSubscription::find($subscriptionId);
        if (!$sub || $sub->status !== 'active') return 'gone';
        $inFlight = self::hasInFlight();
        // Habang active lang at walang padalang nasa himpapawid: ang sabay na unsubscribe (sagot, inbox) ay hindi binabalik.
        $release = fn (array $extra = []) => ChatbotSequenceSubscription::whereKey($sub->id)->where('status', 'active')->when($inFlight, fn ($q) => $q->whereNull('send_request_id'))
            ->update($extra + ['dispatched_at' => null, 'updated_at' => now()]);
        // Tumatakbo: hindi ito kinukuha ulit ng tick habang buhay ang job (hanggang sa timeout nito).
        Cache::put(self::RUNNING . $sub->id, 1, ($timeout ?? (BotcakeSendQueue::sequenceReady() ? self::HANDOFF_TIMEOUT : self::INLINE_TIMEOUT)) + 10);

        $lock = Cache::lock('chatbot-run:' . $sub->page_id . ':' . $sub->psid, ChatbotRunner::LOCK_SECONDS);
        if (!$lock->get()) {
            $release(['next_send_at' => now()->addMinutes(self::LOCKED_RETRY_MINUTES)]);
            Cache::forget(self::RUNNING . $sub->id);
            return 'locked';
        }

        try {
            $sub->refresh();
            if ($sub->status !== 'active') return 'gone';
            // Nasa himpapawid ang padala ng step: ang ticket ang magtatapos.
            if ($inFlight && $sub->send_request_id !== null) return 'gone';
            // Hinihintay pa ang sagot ng AI ng customer (chatbot:ai-pool): parang hawak ang lock, mamaya na ang step.
            if (ChatbotAiQueue::pending(new CustomerFields($sub->page_id, $sub->psid))) {
                $release(['next_send_at' => now()->addMinutes(self::LOCKED_RETRY_MINUTES)]);
                return 'locked';
            }
            // Ang claim ay itinatala ulit sa simula: ang lipas ay mula ngayon. Ang job na naunahan ng mas bagong claim (o ng
            // ibang job) ay walang ginagawa.
            $stamp = null;
            if ($claim !== null) {
                $stamp = now()->format('Y-m-d H:i:s');
                // Sa parehong segundo ng claim: walang babaguhin (ang MySQL ay nagbibilang ng nabagong row, hindi ng tumugma).
                if ($stamp === $claim) {
                    if (!DB::table('chatbot_sequence_subscriptions')->where('id', $sub->id)->where('status', 'active')->where('dispatched_at', $claim)->exists()) return 'gone';
                } elseif (!DB::table('chatbot_sequence_subscriptions')->where('id', $sub->id)->where('status', 'active')->where('dispatched_at', $claim)->update(['dispatched_at' => $stamp])) {
                    return 'gone';
                }
            }
            // Isang padala ng sequence lang kada customer: ang may ticket pa sa ibang sequence ay mamaya na.
            if ($inFlight && BotcakeSendQueue::hasTable() && DB::table(BotcakeSendQueue::TABLE)->where('customer', BotcakeSendQueue::sequenceCustomer($sub->page_id, $sub->psid))
                ->whereIn('status', ['pending', 'sending', 'done', 'failed'])->exists()) {
                $release(['next_send_at' => now()->addMinutes(self::LOCKED_RETRY_MINUTES)]);
                return 'locked';
            }
            if (!$sub->next_send_at || $sub->next_send_at->gt(now())) {
                if ($claim !== null) $release();
                return 'gone';
            }
            $seq = BotConfigCache::sequence((int) $sub->sequence_id);
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

            // Ang due ay kung kailan dapat tumakbo ang step: ang /chatbot/speed ay sumusukat ng delay mula rito hanggang sa padala.
            $detail = ['name' => $seq->name, 'step' => $step->position, 'sequence_id' => (int) $seq->id, 'due' => $sub->next_send_at?->format('Y-m-d H:i:s')];
            // Kinuha ulit pagkatapos mamatay ang job: may naipadala na ang naunang takbo ng step na ito, kaya hindi na inuulit.
            if ($earlier !== null && $this->sentSince($sub, $seq, $step, $earlier)) {
                $this->event($sub->page_id, $sub->psid, ['op' => 'failed', 'error' => self::NOT_RESENT] + $detail);
                return $this->advance($sub, $seq, $step, true);
            }
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
            $last = BotcakeChat::active()->where('page_id', (string) $sub->page_id)->where('psid', (string) $sub->psid)->orderByDesc('id')->first();
            $at = $this->lastMessageAt($last, $fields);
            // Lampas 24 oras mula sa huling mensahe ng customer: walang ipapadala at tapos na ang subscription.
            if (!$at || $at->lte(now()->subHours(self::WINDOW_HOURS))) {
                $release(['status' => 'unsubscribed', 'exit_reason' => 'outside 24h', 'next_send_at' => null]);
                $this->event($sub->page_id, $sub->psid, ['op' => 'skipped', 'reason' => 'outside 24h'] + $detail);
                return 'skipped';
            }
            $flow = $step->flow_id ? BotConfigCache::flow((int) $step->flow_id) : null;
            $why = !$flow ? 'the flow was deleted' : ($flow->published_at === null || !is_array($flow->graph) ? 'the flow is not published' : null);
            if ($why !== null) {
                $this->event($sub->page_id, $sub->psid, ['op' => 'failed', 'error' => $why] + $detail, $flow?->id);
                return $this->advance($sub, $seq, $step, false);
            }

            $group = $this->groups->forSequence((int) $seq->id, $fields, $page);
            $run = new FlowRun($flow->id, $flow->graph, $page, (string) $sub->page_id, $this->page->botfields($page, $group), $fields, null,
                '', (string) $last?->name, now()->format('Y-m-d H:i'));
            $run->adGroup = $group;
            $run->adGroupUnresolved = $this->groups->unresolved;
            if ($this->groups->lookupNote !== null) $run->event('ad_lookup', null, ['note' => $this->groups->lookupNote]);
            $run->startedBy = 'sequence ' . $seq->name . ' step ' . $step->position;
            [$run->sequenceId, $run->sequenceStep] = [(int) $seq->id, (int) $step->position];
            if ($inFlight) {
                $run->sequenceTicket = ['subscription_id' => (int) $sub->id, 'current_step' => (int) $sub->current_step, 'step' => (int) $step->position,
                    'due' => $detail['due'], 'name' => (string) $seq->name, 'sequence_id' => (int) $seq->id, 'stamp' => $stamp, 'first_sent_at' => null, 'side_effects' => false];
            }
            try {
                $this->engine->run($run, $this->outbox);
                if (!$run->sendPending) $run->event('sequence', null, ['op' => 'ran'] + $detail);
            } catch (Throwable $e) {
                report($e);
                if (!$run->sendPending) $run->event('sequence', null, ['op' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 150)] + $detail);
            }
            ChatbotEvents::store($run, $sub->page_id, $sub->psid);
            // Ipinasa ang padala: ang resume (o ang tick) ang uusad kapag alam na ang resulta.
            if ($run->sendPending) return 'sending';

            return $this->advance($sub, $seq, $step, true);
        } finally {
            $lock->release();
            Cache::forget(self::RUNNING . $sub->id);
        }
    }

    /** Ang huling mensahe: ang chat dito, o ang dala ng paglipat ng page mula sa kabilang site, alin ang mas bago. */
    private function lastMessageAt(?BotcakeChat $last, CustomerFields $fields): ?Carbon
    {
        $at = $last?->received_at;
        $moved = $fields->get(CustomerFields::LAST_MESSAGE_AT);
        $moved = $moved !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $moved) ? Carbon::parse($moved) : null;
        if ($moved && (!$at || $moved->gt($at))) $at = $moved;

        return $at ? Carbon::instance($at) : null;
    }

    /** May padala na ba ang step na ito (sequence + step) sa customer mula sa naunang claim? */
    private function sentSince(ChatbotSequenceSubscription $sub, ChatbotSequence $seq, ChatbotSequenceStep $step, string $earlier): bool
    {
        if (!Schema::hasColumn('chatbot_replies', 'sequence_id')) return false;

        return DB::table('chatbot_replies')->where('page_id', $sub->page_id)->where('psid', $sub->psid)->where('kind', 'send')
            ->where('sequence_id', $seq->id)->where('sequence_step', $step->position)->where('sent_at', '>=', $earlier)->exists();
    }

    /**
     * Ang susunod na active na step pagkatapos ng $step (ang delay ay mula ngayon), o tapos na. $ticket: ang padalang
     * nasa himpapawid na tinatapos (ang row ay dapat hawak pa ito); null = ang job mismo (walang padalang nasa himpapawid).
     * Kasama ang current_step na nabasa: ang ulit o huling resume ay walang nababago.
     */
    private function advance(ChatbotSequenceSubscription $sub, ChatbotSequence $seq, ChatbotSequenceStep $step, bool $sent, ?string $ticket = null): string
    {
        $next = $this->stepFrom($seq, $step->position + 1);
        $values = ['dispatched_at' => null, 'updated_at' => now()] + ($sent ? ['last_sent_at' => now()] : []) + self::unlink();
        $guard = $this->guard((int) $sub->current_step, $ticket);
        // Na-hold ang page (paglipat) habang ipinapadala ang step: itinatala pa rin ang usad, held pa rin, kaya ang kopya at ang
        // hold:false ay hindi na ito uulitin. Ang huling step: lampas sa dulo, kaya sa alinmang site ay tapos agad, walang padala.
        $held = fn (array $at) => $guard(ChatbotSequenceSubscription::whereKey($sub->id)->where('status', PageTransfers::HELD)
            ->where('exit_reason', 'like', PageTransfers::HOLD_REASON . '%'))->update($values + $at);
        if (!$next) {
            if ($guard(ChatbotSequenceSubscription::whereKey($sub->id)->where('status', 'active'))->update($values)) return $this->complete($sub->fresh(), $seq);
            if ($held(['current_step' => $step->position + 1, 'next_send_at' => now()])) return $sent ? 'ran' : 'skipped';
            return 'completed';
        }
        // Habang active lang: ang customer na sumagot (o inalis) habang tumatakbo ang step ay hindi ibinabalik.
        $at = ['current_step' => $next->position, 'next_send_at' => self::dueAt(now(), $next->delay_amount, $next->delay_unit)];
        if (!$guard(ChatbotSequenceSubscription::whereKey($sub->id)->where('status', 'active'))->update($values + $at)) $held($at);

        return $sent ? 'ran' : 'skipped';
    }

    /** Ang kondisyon ng may hawak ng step: ang parehong current_step at ang padalang ito (o walang padalang nasa himpapawid). */
    private function guard(int $currentStep, ?string $ticket): \Closure
    {
        $inFlight = self::hasInFlight();

        return fn ($q) => $q->where('current_step', $currentStep)
            ->when($inFlight, fn ($w) => $ticket === null ? $w->whereNull('send_request_id') : $w->where('send_request_id', $ticket));
    }

    private function complete(ChatbotSequenceSubscription $sub, ChatbotSequence $seq): string
    {
        $done = ChatbotSequenceSubscription::whereKey($sub->id)->where('status', 'active')
            ->update(['status' => 'completed', 'next_send_at' => null, 'dispatched_at' => null, 'updated_at' => now()] + self::unlink());
        if ($done) $this->event($sub->page_id, $sub->psid, ['op' => 'completed', 'name' => $seq->name]);

        return 'completed';
    }

    // ---- Ang padalang ipinasa (chatbot:sequence-pool) ----

    /**
     * Ang pagsusuri bago ang tawag (sa pool, sa oras ng padala): hawak pa ng subscription ang ticket (active, parehong step;
     * ang sumagot na customer ay inalis na), active ang sequence, On ang page, naka-on ang mga sagot, hindi naka-pause ang bot,
     * at bukas pa ang 24 oras. null = ipadala.
     * @return array{code: string, reason: string}|null
     */
    public function sendCheck(object $row): ?array
    {
        $s = (array) (json_decode((string) $row->context, true)['sequence'] ?? []);
        $sub = $s ? DB::table('chatbot_sequence_subscriptions')->where('id', (int) $s['subscription_id'])->first() : null;
        $no = fn (string $code, string $reason) => ['code' => $code, 'reason' => $reason];
        if (!$sub || $sub->send_request_id !== $row->request_id || (int) $sub->current_step !== (int) $s['current_step']) return $no('not_holder', 'The customer left the sequence before the send went out');
        if ($sub->status === PageTransfers::HELD) return $no('held', 'The page is moving to another site; the step goes with it');
        if ($sub->status !== 'active') return $no('not_holder', 'The customer left the sequence before the send went out');
        $seq = BotConfigCache::sequence((int) $sub->sequence_id);
        if (!$seq) return $no('seq_deleted', 'The sequence was deleted before the send went out');
        if (!$seq->active) return $no('seq_paused', 'The sequence was paused before the send went out');
        if (!$this->pageOn((string) $row->page_name)) return $no('page_off', 'The page was turned off before the send went out');
        if (!BotcakeOutbox::enabled()) return $no('switched_off', BotcakeOutbox::SWITCHED_OFF);
        $fields = new CustomerFields((int) $row->page_id, (int) $row->psid);
        if ($fields->get(CustomerFields::BOT_PAUSED) === '1') return $no('bot_paused', 'The bot was paused before the send went out');
        $last = BotcakeChat::active()->where('page_id', (string) $row->page_id)->where('psid', (string) $row->psid)->orderByDesc('id')->first();
        $at = $this->lastMessageAt($last, $fields);
        if (!$at || $at->lte(now()->subHours(self::WINDOW_HOURS))) return $no('outside_24h', 'The 24-hour window closed before the send went out');

        return null;
    }

    /**
     * Ang resulta ng padala ng step (ResumeChatbotSequenceSend), sa lock ng customer, isang beses lang (done/failed →
     * resumed). Itinutuloy ang step mula sa send node (ang mga naunang node ay hindi inuulit); umuusad kapag tapos, o ipinapasa
     * ang susunod na padala. Ang hindi na hawak ng subscription (sumagot, inalis, lumipat ang page) ay hindi umuusad.
     * @return string 'ran' | 'sending' | 'skipped' | 'dropped' | 'locked' | 'gone' | ang resulta ng undo
     */
    public function resumeSend(array $ticket): string
    {
        if (!Schema::hasTable(BotcakeSendQueue::TABLE)) return 'gone';
        $row = DB::table(BotcakeSendQueue::TABLE)->where('request_id', (string) ($ticket['request_id'] ?? ''))->first();
        if (!$row || $row->kind !== BotcakeSendQueue::SEQUENCE || !in_array($row->status, ['done', 'failed'], true)) return 'gone';

        $lock = Cache::lock('chatbot-run:' . $row->page_id . ':' . $row->psid, ChatbotRunner::LOCK_SECONDS);
        if (!$lock->get()) return 'locked';

        try {
            if (DB::table(BotcakeSendQueue::TABLE)->where('id', $row->id)->whereIn('status', ['done', 'failed'])
                ->update(['status' => 'resumed', 'resumed_at' => now(), 'updated_at' => now()]) !== 1) return 'gone';

            return $this->continueStep($row);
        } finally {
            $lock->release();
        }
    }

    private function continueStep(object $row): string
    {
        $ctx = (array) json_decode((string) $row->context, true);
        $s = (array) ($ctx['sequence'] ?? []);
        $r = (array) json_decode((string) $row->result, true);
        $attempt = isset($r['status']) && !isset($r['check']); // isang totoong subok (may row), hindi ang pagsusuri bago ang tawag
        $first = $s['first_sent_at'] ?? ($attempt ? (string) ($r['at'] ?? $row->done_at) : null);
        $detail = ['name' => (string) ($s['name'] ?? ''), 'step' => (int) ($s['step'] ?? 0), 'sequence_id' => (int) ($s['sequence_id'] ?? 0), 'due' => $s['due'] ?? null];
        $pageId = (int) $row->page_id;
        $psid = (int) $row->psid;
        // Ang oras ng ran: hindi mas maaga sa huling padala, at hindi lalampas sa 590 s mula sa unang padala ng step, para ang
        // /chatbot/speed ay laging may padala ng step sa loob ng 600 s bago ang ran.
        $ranAt = function () use ($first, $row): Carbon {
            // + 1 s: ang row ng padala ay isinusulat sa parehong sandali, minsan sa kasunod na segundo.
            $last = Carbon::parse((string) $row->done_at)->addSecond();
            if ($first === null) return $last;
            $from = Carbon::parse($first);

            return $from->copy()->addSeconds(self::RAN_WINDOW_SECONDS)->min($last)->max($from);
        };

        $sub = $s ? ChatbotSequenceSubscription::find((int) $s['subscription_id']) : null;
        $holder = $sub && $sub->status === 'active' && $sub->send_request_id === $row->request_id && (int) $sub->current_step === (int) $s['current_step'];
        if (!$holder) {
            // Tapos na ang step sa ibang paraan (sumagot, inalis, lumipat ang page): ang naipadala ay naitala na, walang usad.
            if ($first !== null) {
                $this->event($pageId, $psid, ['op' => 'ran'] + $detail);
                $id = DB::table('chatbot_events')->where('page_id', $pageId)->where('psid', $psid)->where('type', 'sequence')->max('id');
                DB::table('chatbot_events')->where('id', $id)->update(['created_at' => $ranAt()]);
            }
            return 'dropped';
        }
        $seq = BotConfigCache::sequence((int) $sub->sequence_id);
        if (!$seq) {
            ChatbotSequenceSubscription::whereKey($sub->id)->where('send_request_id', $row->request_id)
                ->update(['status' => 'error', 'exit_reason' => 'sequence deleted', 'next_send_at' => null, 'dispatched_at' => null, 'send_request_id' => null, 'updated_at' => now()]);
            $this->event($pageId, $psid, ['op' => 'failed', 'error' => 'the sequence was deleted'] + $detail);
            return 'gone';
        }
        $step = $seq->steps->firstWhere('position', (int) $s['step']) ?? new ChatbotSequenceStep(['position' => (int) $s['step']]);
        // Tapos na walang resulta ng pool na ito (hal. kinuha ng lumang pool bilang chatblast): hindi alam kung naipadala, kaya
        // tinatapos ang step, walang ulit.
        if (!isset($r['status'])) {
            $this->event($pageId, $psid, ['op' => 'failed', 'error' => self::NOT_RESENT] + $detail);
            return $this->advance($sub, $seq, $step, true, (string) $row->request_id);
        }

        if (($r['status'] ?? '') === 'skipped' && isset($r['check'])) {
            // Walang naipadala pa sa step at walang epekto ang takbo: gaya ng pagsusuri sa simula ng step (uulitin mamaya).
            if ($first === null && empty($ctx['side_effects'])) return $this->undo($sub, $seq, (string) $r['check'], (string) $row->request_id, $detail);
            $this->event($pageId, $psid, ['op' => 'skipped', 'reason' => mb_substr((string) $r['error'], 0, 150)] + $detail);
            return $this->advance($sub, $seq, $step, $first !== null, (string) $row->request_id);
        }

        $flow = BotConfigCache::flow((int) $row->flow_id);
        $node = collect(is_array($flow?->graph) ? $flow->graph['nodes'] ?? [] : [])->firstWhere('id', $row->node_id);
        if (!$flow || $flow->published_at === null || !in_array($node['type'] ?? '', ['send', 'ai'], true)) {
            $this->event($pageId, $psid, ['op' => 'failed', 'error' => 'the flow changed'] + $detail, $flow?->id);
            return $this->advance($sub, $seq, $step, $first !== null, (string) $row->request_id);
        }
        $page = (string) $row->page_name;
        $group = is_int($ctx['ad_group'] ?? null) ? $ctx['ad_group'] : null;
        $payload = (array) json_decode((string) $row->payload, true);
        $run = new FlowRun($flow->id, $flow->graph, $page, (string) $row->page_id, $this->page->botfields($page, $group), new CustomerFields($pageId, $psid), null,
            (string) ($ctx['input'] ?? ''), (string) ($ctx['full_name'] ?? ''), (string) ($ctx['received_at'] ?? ''));
        $run->adGroup = $group;
        $run->runId = $row->run_id ?: null;
        $run->aiCalls = (int) ($ctx['ai_calls'] ?? 0);
        $run->steps = (int) ($ctx['steps'] ?? 0);
        $run->startedBy = 'sequence ' . $seq->name . ' step ' . $s['step'];
        [$run->sequenceId, $run->sequenceStep] = [(int) $seq->id, (int) $s['step']];
        $run->sequenceTicket = ['request_id' => (string) $row->request_id, 'stamp' => null, 'first_sent_at' => $first, 'side_effects' => !empty($ctx['side_effects'])] + $s;
        $run->sendResult = ['node' => (string) $row->node_id, 'status' => (string) ($r['status'] ?? ($row->status === 'done' ? 'sent' : 'failed')),
            'error' => $r['error'] ?? $row->error ?? null, 'messages' => (array) ($payload['messages'] ?? []), 'http' => isset($r['http']) ? (int) $r['http'] : null,
            'tags' => is_array($payload['tags'] ?? null) ? $payload['tags'] : null, 'retried' => true];
        $run->ticket = ['request_id' => (string) $row->request_id, 'page_id' => $pageId, 'psid' => $psid, 'chat_id' => null, 'flow_id' => (int) $row->flow_id, 'node_id' => (string) $row->node_id];
        try {
            $this->engine->run($run, $this->outbox);
            if (!$run->sendPending) {
                $run->event('sequence', null, ['op' => 'ran'] + $detail);
                $run->events[array_key_last($run->events)]['at'] = $ranAt();
            }
        } catch (Throwable $e) {
            report($e);
            if (!$run->sendPending) $run->event('sequence', null, ['op' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 150)] + $detail);
        }
        ChatbotEvents::store($run, $pageId, $psid);
        if ($run->sendPending) return 'sending';

        return $this->advance($sub, $seq, $step, true, (string) $row->request_id);
    }

    /** Ang hindi naipadalang unang padala ng step: ang subscription ay gaya ng iniiwan ng runStep sa parehong dahilan. */
    private function undo(ChatbotSequenceSubscription $sub, ChatbotSequence $seq, string $code, string $ticket, array $detail): string
    {
        $q = fn () => ChatbotSequenceSubscription::whereKey($sub->id)->where('send_request_id', $ticket);
        $base = ['dispatched_at' => null, 'send_request_id' => null, 'updated_at' => now()];
        switch ($code) {
            case 'page_off':
                $q()->where('status', 'active')->update(['status' => 'paused', 'exit_reason' => 'page off'] + $base);
                $this->event($sub->page_id, $sub->psid, ['op' => 'paused', 'reason' => 'page off', 'name' => $seq->name]);
                return 'paused';
            case 'bot_paused':
                $q()->update(['next_send_at' => now()->addMinutes(self::HOLD_MINUTES)] + $base);
                $this->event($sub->page_id, $sub->psid, ['op' => 'held', 'reason' => 'bot paused'] + $detail);
                return 'held';
            case 'outside_24h':
                $q()->where('status', 'active')->update(['status' => 'unsubscribed', 'exit_reason' => 'outside 24h', 'next_send_at' => null] + $base);
                $this->event($sub->page_id, $sub->psid, ['op' => 'skipped', 'reason' => 'outside 24h'] + $detail);
                return 'skipped';
            default: // naka-pause ang sequence, nagbago ang page ID, o lumilipat ang page: hindi umuusad, mamaya na
                $q()->update($base);
                return 'gone';
        }
    }

    /**
     * Ang mga naiwang padala (bawat tick):
     * - ang paglilinis ng ticket (deadline, nawalang resume, luma);
     * - patay ang chatbot:sequence-pool: ang hindi pa natawag ay kinakansela at ang step ay pinapatakbo ulit sa job (wala
     *   nang pool, kaya naghihintay ang padala), o tinatapos kapag may epekto na; ang natawag na ay failed, walang ulit;
     * - ang tali na lampas sa deadline ng ticket + BACKSTOP_MINUTES, anuman ang status: tinatapos, walang ulit kapag natawag.
     */
    public function settleInFlight(): void
    {
        if (!Schema::hasTable(BotcakeSendQueue::TABLE)) return;
        BotcakeSendQueue::sweepSequence();
        // Patay lang kapag walang beat nang DEAD_POOL_SECONDS (ang malinis na paghinto ay nag-iiwan ng beat na "stopped", kaya ang
        // ilang segundo bago ang bagong pool ay hindi patay). Ang ticket na kagagalaw lang ay hindi ginagalaw.
        if (!SequenceSendPool::alive(BotcakeSendQueue::DEAD_POOL_SECONDS)) {
            $rows = DB::table(BotcakeSendQueue::TABLE)->where('kind', BotcakeSendQueue::SEQUENCE)->whereIn('status', ['pending', 'sending'])->whereNull('called_at')
                ->where('updated_at', '<=', now()->subSeconds(BotcakeSendQueue::DEAD_POOL_SECONDS))->orderBy('id')->limit(self::BATCH)->get();
            foreach ($rows as $t) $this->cancel($t, 'The sequence send pool was not running; nothing sent');
            BotcakeSendQueue::recoverSequence(null);
        }
        $old = now()->subSeconds(BotcakeSendQueue::SEQUENCE_SECONDS + self::BACKSTOP_MINUTES * 60);
        $subs = DB::table('chatbot_sequence_subscriptions')->whereNotNull('send_request_id')->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', $old))
            ->orderBy('id')->limit(self::BATCH)->get();
        foreach ($subs as $sub) {
            $t = DB::table(BotcakeSendQueue::TABLE)->where('request_id', $sub->send_request_id)->first();
            if ($t && in_array($t->status, ['pending', 'sending'], true) && $t->called_at === null) {
                $this->cancel($t, 'Not sent in time; nothing sent');
            } elseif ($t && $t->status === 'sending') {
                BotcakeSendQueue::finishSequence($t, $t->claimed_by, ['status' => 'failed', 'error' => BotcakeSendQueue::noAnswer(json_decode((string) $t->result, true)['via'] ?? null), 'http' => null]);
            } elseif ($t && in_array($t->status, ['done', 'failed'], true)) {
                // Ang resume ay sa job (unique), hindi sa tick: ang tick ay hindi tumatagal kahit marami.
                ResumeChatbotSequenceSend::dispatch(BotcakeSendQueue::sequenceTicket($t));
            } else {
                $this->abandon($sub, $t);
            }
        }
    }

    /** Kanselahin ang ticket na hindi pa natawag (kondisyonal: ang tawag na nauna ay hindi naaapektuhan), saka ang step nito. */
    private function cancel(object $t, string $why): void
    {
        if (DB::table(BotcakeSendQueue::TABLE)->where('id', $t->id)->whereIn('status', ['pending', 'sending'])->whereNull('called_at')
            ->update(['status' => 'resumed', 'error' => $why, 'done_at' => now(), 'resumed_at' => now(), 'updated_at' => now()]) !== 1) return;
        $ctx = (array) json_decode((string) $t->context, true);
        $sub = DB::table('chatbot_sequence_subscriptions')->where('send_request_id', $t->request_id)->first();
        if (!$sub) return;
        // Walang naipadala pa at walang epekto: ang step ay kukunin ulit ng tick (mula sa Start). Kung hindi: tinatapos, walang ulit.
        if (empty($ctx['side_effects']) && ($ctx['sequence']['first_sent_at'] ?? null) === null) {
            DB::table('chatbot_sequence_subscriptions')->where('id', $sub->id)->where('send_request_id', $t->request_id)
                ->update(['send_request_id' => null, 'dispatched_at' => null, 'updated_at' => now()]);
            return;
        }
        $this->abandon($sub, $t);
    }

    /** Ang step na naputol pagkatapos ng padala o ng epekto: failed, umuusad, walang ulit. */
    private function abandon(object $row, ?object $t): void
    {
        $lock = Cache::lock('chatbot-run:' . $row->page_id . ':' . $row->psid, ChatbotRunner::LOCK_SECONDS);
        if (!$lock->get()) return; // may tumatakbo pa (hal. ang resume): sa susunod na tick
        try {
            $sub = ChatbotSequenceSubscription::find($row->id);
            if (!$sub || $sub->send_request_id !== $row->send_request_id) return;
            $seq = BotConfigCache::sequence((int) $sub->sequence_id);
            if (!$seq) {
                ChatbotSequenceSubscription::whereKey($sub->id)->where('send_request_id', $row->send_request_id)->update(['send_request_id' => null, 'dispatched_at' => null, 'updated_at' => now()]);
                return;
            }
            $s = (array) (json_decode((string) ($t->context ?? ''), true)['sequence'] ?? []);
            // Ang step ng ticket mismo (hindi ang susunod na active mula rito), para walang nalalaktawang step.
            $step = (isset($s['step']) ? $seq->steps->firstWhere('position', (int) $s['step']) : $this->stepFrom($seq, (int) $sub->current_step))
                ?? new ChatbotSequenceStep(['position' => (int) ($s['step'] ?? $sub->current_step)]);
            $this->event($sub->page_id, $sub->psid, ['op' => 'failed', 'error' => self::NOT_RESENT, 'name' => $seq->name, 'step' => $step->position, 'sequence_id' => (int) $seq->id,
                'due' => $s['due'] ?? $sub->next_send_at?->format('Y-m-d H:i:s')]);
            if ($t) DB::table(BotcakeSendQueue::TABLE)->where('id', $t->id)->whereIn('status', ['done', 'failed'])->update(['status' => 'resumed', 'resumed_at' => now(), 'updated_at' => now()]);
            if ($sub->status === 'active') $this->advance($sub, $seq, $step, true, (string) $row->send_request_id);
            else ChatbotSequenceSubscription::whereKey($sub->id)->where('send_request_id', $row->send_request_id)->update(['send_request_id' => null, 'updated_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Bago lumipat ang page sa ibang site (PageTransfers hold at release, sa parehong transaksyon): ang padalang hindi pa
     * natawag ay kinakansela (ang step ay ipapadala ng bagong site, isang beses); ang natawag na ay umuusad na ngayon, kaya ang
     * kopya ay ang susunod na step at hindi na ito inuulit doon.
     */
    public static function settleForMove(int $pageId): void
    {
        if (!self::hasInFlight() || !Schema::hasTable(BotcakeSendQueue::TABLE)) return;
        $uncalled = DB::table(BotcakeSendQueue::TABLE)->where('page_id', $pageId)->where('kind', BotcakeSendQueue::SEQUENCE)->whereIn('status', ['pending', 'sending'])
            ->whereNull('called_at')->get(['id', 'request_id']);
        foreach ($uncalled as $t) {
            if (DB::table(BotcakeSendQueue::TABLE)->where('id', $t->id)->whereIn('status', ['pending', 'sending'])->whereNull('called_at')
                ->update(['status' => 'resumed', 'error' => 'page transferred; nothing sent', 'done_at' => now(), 'resumed_at' => now(), 'updated_at' => now()]) !== 1) continue;
            DB::table('chatbot_sequence_subscriptions')->where('send_request_id', $t->request_id)->update(['send_request_id' => null, 'dispatched_at' => null]);
        }
        foreach (DB::table('chatbot_sequence_subscriptions')->where('page_id', $pageId)->whereNotNull('send_request_id')->get() as $sub) {
            $seq = BotConfigCache::sequence((int) $sub->sequence_id);
            $step = $seq?->steps->first(fn ($s) => $s->active && $s->position >= (int) $sub->current_step);
            $next = $step ? $seq->steps->first(fn ($s) => $s->active && $s->position >= $step->position + 1) : null;
            $values = ['send_request_id' => null, 'dispatched_at' => null, 'last_sent_at' => now()]
                + ($next ? ['current_step' => $next->position, 'next_send_at' => self::dueAt(now(), $next->delay_amount, $next->delay_unit)]
                    : ['current_step' => (int) $sub->current_step + 1, 'next_send_at' => now()]);
            DB::table('chatbot_sequence_subscriptions')->where('id', $sub->id)->where('send_request_id', $sub->send_request_id)->update($values);
        }
    }
}
