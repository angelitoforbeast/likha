<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\BotcakeMessage;
use App\Services\BotcakeClient;
use App\Services\BotcakeMock;
use App\Services\ChatblastSender;
use App\Services\PageSettings;
use App\Support\BotcakeClock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Ang pool ng padala sa Botcake (botcake:send-pool): hanggang services.botcake.send_concurrency na ticket nang sabay, sa
 * iisang proseso (TicketPool). Bawat page (token) ay may sariling "lane": isang request lang kada 1 / rate_per_page na
 * segundo, eksakto dahil iisang proseso ang nagpapasya (kahati ang cache key ng BotcakeClient, kaya iginagalang din ang
 * naghihintay na padala sa ibang lugar). Sa 429 ay ang page lang na iyon ang humihinto. Ang ulit ay ang patakaran ng
 * BotcakeClient::call. Ang send_content ng chatbot ay isang request; ang chatblast ay ang stepper ng ChatblastSender
 * (sulat, basa, send_flow), kaya pareho ang mga hakbang at status. Ang test page (BotcakeMock) ay sumasagot sa timer.
 */
class BotcakeSendPool extends TicketPool
{
    public const BEAT = 'botcake-send-pool:beat';
    public const TIMEOUT = 15;          // gaya ng BotcakeClient::shared()
    public const MAX_RETRY_AFTER = 10;  // gaya ng BotcakeClient::shared()

    private BotcakeClock $clock;
    /** @var array<string, array{row: object, gen: ?\Generator, req: array, attempt: int, lane: string, token: string, notBefore: float}> */
    private array $jobs = [];
    /** @var array<string, array{next: float, last: float, paused: float, queue: array<int, string>}> lane (sha1 ng token) */
    private array $lanes = [];
    /** @var array<string, array{0: string, 1: float}> page => [token, nabasa noong] */
    private array $tokens = [];
    /** @var array<string, array<int, float>> lane => oras ng bawat request (para sa test at sa sukat) */
    public array $sentAt = [];

    public function __construct(mixed $handler = null, ?BotcakeClock $clock = null)
    {
        parent::__construct($handler);
        $this->clock = $clock ?? app(BotcakeClock::class);
    }

    public function max(): int
    {
        return max(1, (int) config('services.botcake.send_concurrency', 100));
    }

    protected function nowMs(): int
    {
        return (int) round($this->clock->now() * 1000);
    }

    protected function recover(): void
    {
        BotcakeSendQueue::recover($this->owner);
    }

    protected function sweep(): void
    {
        BotcakeSendQueue::sweep();
    }

    public function tick(bool $claim = true): int
    {
        $started = 0;
        if ($claim) {
            $started = count(BotcakeSendQueue::claim($this->owner, $this->max() - count($this->jobs), fn (object $c) => $this->room($c), fn (object $row) => $this->take($row), $this->fullPages()));
        }
        $this->lanes();
        $this->pump();
        $this->lanes();

        return $started;
    }

    /**
     * May puwang pa ba ang page ng ticket: hanggang perPage() na hawak kada lane, para hindi maubos ng isang page (hal.
     * isang chatblast campaign) ang lahat ng puwesto ng pool. Ang hawak na (ulit na kuha) ay hindi.
     */
    private function room(object $c): bool
    {
        if (isset($this->jobs[(string) $c->request_id])) return false;
        $token = $this->token((string) $c->page_name);
        $lane = $token === '' ? '' : sha1($token);
        $held = 0;
        foreach ($this->jobs as $job) if ($job['lane'] === $lane) $held++;

        return $held < $this->perPage();
    }

    /** @return array<int, string> ang mga page na puno na ang lane (hindi na kukunan ng bago) */
    private function fullPages(): array
    {
        $held = [];
        $pages = [];
        foreach ($this->jobs as $job) {
            $held[$job['lane']] = ($held[$job['lane']] ?? 0) + 1;
            $pages[$job['lane']][(string) $job['row']->page_name] = true;
        }
        $full = [];
        foreach ($held as $lane => $n) if ($n >= $this->perPage()) $full = array_merge($full, array_keys($pages[$lane]));

        return $full;
    }

    /** Ilang ticket kada page ang hawak: ang kayang ipadala sa mga 3 segundo (ang chatblast ay tatlong tawag bawat isa). */
    public function perPage(): int
    {
        return max(2, (int) ceil(3 / BotcakeClient::interval()));
    }

    /** Ang token ng page (page settings), nasa memory nang 10 s. */
    private function token(string $page): string
    {
        $t = $this->tokens[$page] ?? null;
        if ($t === null || $this->clock->now() - $t[1] > 10) {
            $t = $this->tokens[$page] = [trim((string) app(PageSettings::class)->get($page, 'botcake', 'token')), $this->clock->now()];
        }

        return $t[0];
    }

    private function take(object $row): void
    {
        $id = (string) $row->request_id;
        $this->inflight[$id] = true;
        try {
            if ($row->kind === 'chatbot') {
                if (($why = $this->mismatch($row)) !== null) {
                    $this->refuse($row, $why);
                    return;
                }
                $token = $this->token((string) $row->page_name);
                if ($token === '') {
                    $this->forget($id);
                    BotcakeSendQueue::finishChatbot($row, $this->owner, ['status' => 'failed', 'error' => BotcakeOutbox::NO_TOKEN, 'http' => null]);
                    return;
                }
                $messages = (array) (json_decode((string) $row->payload, true)['messages'] ?? []);
                $req = ['step' => 'send', 'method' => 'POST', 'path' => '/pages/' . rawurlencode((string) $row->page_id) . '/flows/send_content',
                    'data' => ['psid' => (string) $row->psid, 'data' => ['version' => 'v2', 'content' => ['messages' => $messages]]], 'onlyRateLimit' => true];
                $this->queue($id, ['row' => $row, 'gen' => null, 'req' => $req, 'attempt' => 0, 'lane' => sha1($token), 'token' => $token, 'notBefore' => 0.0]);
                return;
            }

            $sender = app(ChatblastSender::class);
            $m = BotcakeMessage::find($row->message_id);
            $cfg = $m ? $sender->config((string) $m->page_name) : null;
            if (!$m || $m->status !== 'sending' || $cfg === null) {
                if ($m && $m->status === 'sending') {
                    BotcakeMessage::whereKey($m->id)->where('status', 'sending')->update(['status' => 'failed', 'reason' => 'The page settings changed before sending; safe to send again']);
                }
                $this->forget($id);
                BotcakeSendQueue::finishChatblast($row, $this->owner);
                return;
            }
            // Ang token na naging invalid habang naghihintay (401 sa ibang mensahe): hindi na tinatawagan, gaya ng job.
            if (str_starts_with((string) app(PageSettings::class)->get((string) $m->page_name, 'botcake', 'token_status'), 'invalid')) {
                BotcakeMessage::whereKey($m->id)->where('status', 'sending')->update(['status' => 'failed', 'reason' => 'Token invalid: fix it on /page-settings (Test token) before sending']);
                $this->forget($id);
                BotcakeSendQueue::finishChatblast($row, $this->owner);
                return;
            }
            $gen = $sender->stepper($m, $cfg);
            $this->queue($id, ['row' => $row, 'gen' => $gen, 'req' => $gen->current(), 'attempt' => 0, 'lane' => sha1($cfg['token']), 'token' => $cfg['token'], 'notBefore' => 0.0]);
        } catch (Throwable $e) {
            $this->failed($row, $e);
        }
    }

    /**
     * Bago tumawag: ang page ID ng ticket ay ang page ID ng page sa settings, at ang chat (kapag mayroon) ay sa page at PSID
     * ng ticket (botcake_chats). Null kapag tugma.
     */
    private function mismatch(object $row): ?string
    {
        $pageId = trim((string) app(PageSettings::class)->get((string) $row->page_name, 'botcake', 'page_id'));
        if ($pageId !== (string) $row->page_id) return 'page';
        if ($row->chat_id !== null) {
            $chat = BotcakeChat::find($row->chat_id);
            if (!$chat || (string) $chat->page_id !== (string) $row->page_id || (string) $chat->psid !== (string) $row->psid) return 'chat';
        }

        return null;
    }

    /** Hindi tugma ang ticket: walang padala, hindi itinutuloy ang takbo, event send_mismatch at ERROR log (mga id lang). */
    private function refuse(object $row, string $why): void
    {
        $this->forget((string) $row->request_id);
        DB::table(BotcakeSendQueue::TABLE)->where('id', $row->id)->where('status', 'sending')->where('claimed_by', $this->owner)
            ->update(['status' => 'resumed', 'error' => 'Ticket mismatch (' . $why . '); nothing sent', 'done_at' => now(), 'resumed_at' => now(), 'updated_at' => now()]);
        $ids = ['request' => (string) $row->request_id, 'check' => $why, 'page' => (string) $row->page_id, 'psid' => (string) $row->psid, 'chat' => (string) ($row->chat_id ?? '')];
        Log::error('botcake send: ticket mismatch, nothing sent', $ids);
        ChatbotEvents::write((int) $row->page_id, (int) $row->psid, 'send_mismatch', $ids, $row->flow_id === null ? null : (int) $row->flow_id, $row->node_id);
    }

    private function queue(string $id, array $job): void
    {
        $job['since'] = $this->clock->now();
        $this->jobs[$id] = $job;
        $this->lanes[$job['lane']] ??= ['next' => 0.0, 'last' => 0.0, 'paused' => 0.0, 'queue' => []];
        $this->lanes[$job['lane']]['queue'][] = $id;
    }

    /** Bawat lane na puwede na: ang unang job na handa, isang request, saka ang susunod na puwede ay pagkalipas ng interval. */
    private function lanes(): void
    {
        $interval = BotcakeClient::interval();
        foreach ($this->lanes as $key => $lane) {
            // Ang oras ay binabasa sa bawat lane: ang naunang padala sa loop na ito ay may mga query (owns, called), kaya ang
            // lumang "ngayon" ay magpapalapit sa dalawang padala ng iisang page nang mas maikli sa interval.
            $now = $this->clock->now();
            if (!$lane['queue'] || $now < $lane['paused'] || $now < $lane['next']) continue;
            // Ang handa na may pinakamataas na priority (chatbot, saka Delivering, saka ang ibang chatblast), ang pinakauna sa
            // mga kapareho; ang naghintay nang lampas 10 s sa lane ay nauuna (hawak ng chatblast ang lock ng customer).
            $pick = null;
            $best = null;
            foreach ($lane['queue'] as $i => $id) {
                $job = $this->jobs[$id];
                if ($job['notBefore'] > $now) continue;
                $rank = $now - ($job['since'] ?? $now) > 10 ? -1 : (int) ($job['row']->priority ?? 2);
                if ($best === null || $rank < $best) { $best = $rank; $pick = $i; }
                if ($rank <= 0) break;
            }
            if ($pick === null) continue;
            // Iisang rate ng page kasama ang ibang proseso (sequence, Inbox, setup check): ang puwesto ay kinukuha nang atomic.
            [$ok, $free] = BotcakeClient::slot($key, $now, false);
            if (!$ok) {
                $this->lanes[$key]['next'] = $free;
                continue;
            }
            $id = $lane['queue'][$pick];
            array_splice($this->lanes[$key]['queue'], $pick, 1);
            // Ang susunod na puwede ay mula sa oras na talagang lumabas ang tawag (hindi sa oras ng pasya): eksaktong interval.
            $at = $this->issue($id);
            if ($at === null) continue;
            $this->lanes[$key]['next'] = $at + $interval;
            $this->lanes[$key]['last'] = $at;
            if ($at > $free + 0.000001) BotcakeClient::sentAt($key, $at);
            $this->sentAt[$key][] = $at;
            if (count($this->sentAt[$key]) > 100) array_shift($this->sentAt[$key]);
        }
    }

    /** @return float|null ang oras na lumabas ang tawag, o null kapag walang tawag (hindi na hawak, o nilaktawan) */
    private function issue(string $id): ?float
    {
        $job = $this->jobs[$id] ?? null;
        if ($job === null) return null;
        $row = $job['row'];
        $q = $job['req'];
        // Bago ang bawat tawag: hawak pa ba ng pool na ito ang ticket (hindi naibalik ng sweep o kinuha ng bagong pool)?
        if (!BotcakeSendQueue::owns($row, $this->owner)) {
            $this->forget($id);
            return null;
        }
        if ($row->kind === 'chatbot' && $job['attempt'] === 0) {
            // Naghintay sa lane: ang pause ng tao at ang emergency stop ay sinusuri ngayon, bago lumabas ang padala.
            if (($skip = $this->skipNow($row)) !== null) {
                $this->forget($id);
                BotcakeSendQueue::finishChatbot($row, $this->owner, ['status' => 'skipped', 'error' => $skip, 'http' => null]);
                return null;
            }
            // Itinatala bago tumawag: hindi na inuulit pagkatapos ng crash.
            if (!BotcakeSendQueue::called($row, $this->owner)) {
                $this->forget($id);
                return null;
            }
        }
        // Ang send_flow ng chatblast: ang "natawag na" ay ngayon, kapag talagang lumabas (hindi kapag naitala ang hakbang).
        if ($row->kind === 'chatblast' && ($q['step'] ?? '') === 'flow' && $job['attempt'] === 0) BotcakeSendQueue::called($row, $this->owner);
        $at = $this->clock->now();
        $mock = BotcakeClient::mockAnswer($q['method'], $q['path'], $q['data']);
        if ($mock !== null) {
            $ms = (int) (BotcakeMock::pages()[(string) $row->page_id]['latency'] ?? 0);
            $this->after($id, $ms, fn () => $this->respond($id, $mock[0], $mock[1], null));
            return $at;
        }
        $options = ['headers' => ['access-token' => $job['token'], 'Accept' => 'application/json'], 'timeout' => self::TIMEOUT, 'connect_timeout' => 10];
        $options[$q['method'] === 'GET' ? 'query' : 'json'] = $q['data'];
        $this->http->requestAsync($q['method'], BotcakeClient::BASE . $q['path'], $options)->then(
            function (ResponseInterface $res) use ($id) {
                $text = (string) $res->getBody();
                $this->respond($id, $res->getStatusCode(), json_decode($text, true) ?? $text, $res->getHeaderLine('Retry-After'));
            },
            fn ($e) => $this->respond($id, 0, null, null),
        );

        return $at;
    }

    /** Ang sagot ng isang request: ulit (ayon sa BotcakeClient::retryWait), ang susunod na hakbang, o tapos na. */
    private function respond(string $id, int $status, mixed $body, ?string $retryAfter): void
    {
        $job = $this->jobs[$id] ?? null;
        if ($job === null) return;
        $row = $job['row'];
        try {
            $wait = BotcakeClient::retryWait($job['attempt'], $status, $retryAfter, $job['req']['onlyRateLimit'], BotcakeClient::MAX_RETRIES, self::MAX_RETRY_AFTER);
            if ($wait !== null) {
                $this->jobs[$id]['attempt']++;
                $until = $this->clock->now() + $wait;
                // 429: ang page lang na ito ang humihinto; ang iba (5xx, walang sagot) ay ang request lang.
                if ($status === 429) $this->lanes[$job['lane']]['paused'] = max($this->lanes[$job['lane']]['paused'], $until);
                else $this->jobs[$id]['notBefore'] = $until;
                array_unshift($this->lanes[$job['lane']]['queue'], $id);
                return;
            }
            $r = ['http' => $status, 'body' => $body, 'attempts' => $job['attempt'] + 1];
            if ($row->kind === 'chatbot') {
                $this->forget($id);
                BotcakeSendQueue::finishChatbot($row, $this->owner, BotcakeOutbox::outcome($r));
                return;
            }
            $job['gen']->send($r);
            if ($job['gen']->valid()) {
                $this->jobs[$id]['req'] = $job['gen']->current();
                $this->jobs[$id]['attempt'] = 0;
                $this->jobs[$id]['notBefore'] = 0.0;
                $this->jobs[$id]['since'] = $this->clock->now();
                $this->lanes[$job['lane']]['queue'][] = $id;
                return;
            }
            $this->forget($id);
            BotcakeSendQueue::finishChatblast($row, $this->owner);
        } catch (Throwable $e) {
            $this->failed($row, $e);
        }
    }

    /** Ang dahilan kung bakit hindi na ipapadala ngayon (naka-off ang mga sagot, o tao na ang sumasagot), o null. */
    private function skipNow(object $row): ?string
    {
        if (!BotcakeOutbox::enabled()) return BotcakeOutbox::SWITCHED_OFF;
        $paused = (new CustomerFields((int) $row->page_id, (int) $row->psid))->get(CustomerFields::BOT_PAUSED);

        return $paused === '1' ? 'The bot was paused before the send went out' : null;
    }

    /** Pumalya ang pool sa ticket (hal. database): chatbot → failed port; chatblast → gaya ng error ng job (unknown o failed). */
    private function failed(object $row, Throwable $e): void
    {
        report($e);
        $this->forget((string) $row->request_id);
        try {
            if ($row->kind === 'chatbot') {
                BotcakeSendQueue::finishChatbot($row, $this->owner, ['status' => 'failed', 'error' => 'Error: ' . mb_substr($e->getMessage(), 0, 150), 'http' => null]);
            } else {
                $flowSent = DB::table(BotcakeSendQueue::TABLE)->where('id', $row->id)->whereNotNull('called_at')->exists();
                app(ChatblastSender::class)->poolError((int) $row->message_id, $e, $flowSent);
                BotcakeSendQueue::finishChatblast($row, $this->owner);
            }
        } catch (Throwable $again) {
            report($again); // ang row ay sending pa: ang pagsisimula ng susunod na pool ang magtatapos
        }
    }

    private function forget(string $id): void
    {
        unset($this->jobs[$id], $this->inflight[$id]);
    }
}
