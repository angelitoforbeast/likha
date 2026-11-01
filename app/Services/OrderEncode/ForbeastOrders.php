<?php

namespace App\Services\OrderEncode;

use App\Jobs\SendOrderToForbeast;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ForbeastSite;
use App\Services\Chatbot\Gateways;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ang order na naisulat sa macro_output ay ipinapadala rin sa Forbeast (spec 211): PUT /v1/site/orders/{ref} sa gateway
 * ng page, sa sariling job (SendOrderToForbeast), kaya ang encode ay hindi naghihintay. Ang ref ay iisa kada macro_output
 * row (<site>-mo-<id>) at ang version ay ang oras ng sulat sa ms, kaya ang bawat update ay pumapalit sa iisang order sa
 * Forbeast at ang ulit na padala ay "duplicate". Ang estado ay nasa order_encodes row na sumulat (forbeast_*).
 * Walang ipinapadala kapag walang go-live (order_encode.forbeast.since), sa row na nagawa bago noon (walang backfill), at
 * sa page na walang naka-link na gateway. Ang sagot ng Forbeast ay untrusted: ang order_id, duplicate, stale, error at
 * ang maikling message lang ang binabasa, bilang text.
 */
class ForbeastOrders
{
    public const QUEUED = 'queued';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const PAUSED = 'paused';

    /** Ang status ng order kapag wala pang STATUS ang row: ang hilaw na upload, o ang encode. */
    public const UPLOADED = 'uploaded';
    public const ENCODED = 'encoded';

    /** Ang haba ng bawat field ayon sa Forbeast (SiteOrderInput); ang lampas ay 422 sa kanila, kaya pinuputol dito. */
    private const LENGTHS = ['name' => 200, 'phone' => 20, 'details' => 5000, 'item' => 200, 'shop' => 1000, 'cxd' => 5000, 'status' => 40];
    private const FIELD_LENGTH = 5000;
    private const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    private const RESUME_BATCH = 500;

    public function __construct(private PageSettings $settings)
    {
    }

    /** Ang go-live: ang mga row na nagawa mula noon lang ang ipinapadala. null = nakapatay. */
    public static function since(): ?Carbon
    {
        $raw = trim((string) config('order_encode.forbeast.since'));
        if ($raw === '') return null;
        try {
            return Carbon::parse($raw, 'Asia/Manila');
        } catch (Throwable) {
            return null;
        }
    }

    public static function pauseMinutes(): int
    {
        return max(1, (int) config('order_encode.forbeast.pause_minutes', 15));
    }

    /** Ang tanda na tinanggihan ng gateway ang site key (401/403): habang naroon, walang tawag sa gateway na iyon. */
    public static function pauseKey(string $gateway): string
    {
        return 'forbeast-orders:paused:' . $gateway;
    }

    /** Ang pangalan ng site sa ref: ang config, o ang site na ito ayon sa app.url (likha / incepxion). */
    public static function site(): string
    {
        $site = strtolower((string) preg_replace('/[^A-Za-z0-9_.-]/', '', (string) config('order_encode.forbeast.site')));

        return $site !== '' ? $site : ForbeastSite::self();
    }

    public static function ref(int $rowId): string
    {
        return self::site() . '-mo-' . $rowId;
    }

    /**
     * Ang gateway na tatanggap ng order ng page: ang chatbot.gateway ng page, o ang aktibong gateway kapag gateway ang
     * provider ng page; naka-link lang (URL, site key at secret). null = walang ipapadala.
     */
    public function gateway(string $page): ?string
    {
        $g = trim((string) $this->settings->get($page, ChatbotFields::PROVIDER, Gateways::KEY));
        if (!Gateways::valid($g)) $g = ChatbotProvider::name($page) === ChatbotProvider::GATEWAY ? Gateways::active($page) : '';

        return Gateways::valid($g) && ForbeastSite::linked($g) ? $g : null;
    }

    /**
     * Pagkatapos maisulat ang row ng order: ang padala ay naka-queue (pagkatapos ng commit). Hindi kailanman nagpapabagsak
     * ng encode o ng upload. @return bool true kapag naka-queue
     */
    public function queue(OrderEncode $o, MacroOutput $row, string $stage): bool
    {
        try {
            $since = self::since();
            if ($since === null || !$o->exists || $o->mode !== 'live' || !$row->created_at || $row->created_at->lt($since)) return false;
            $gateway = $this->gateway((string) $o->page);
            if ($gateway === null) return false;

            // Ang oras ng sulat sa ms; laging lampas sa huling padala ng row na ito, para hindi ito "duplicate" sa Forbeast.
            $version = max(now()->getTimestampMs(), (int) $o->forbeast_version + 1);
            $state = ['ref' => self::ref((int) $row->id), 'row' => (int) $row->id, 'gateway' => $gateway, 'stage' => $stage === self::ENCODED ? self::ENCODED : self::UPLOADED,
                'tries' => 0, 'reason' => null, 'queued_at' => now()->toIso8601String()];
            $this->store($o, ['forbeast_state' => self::QUEUED, 'forbeast_version' => $version, 'forbeast' => $state]);
            SendOrderToForbeast::dispatch((int) $o->id, $version)->afterCommit();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Ang padala ng job. Ang lumang version (may mas bagong sulat na sa row) ay walang ginagawa.
     * @return array{result: string, reason: ?string} result: sent | failed | paused | retry | skipped
     */
    public function send(int $id, int $version, bool $probe = false): array
    {
        $o = OrderEncode::find($id);
        if (!$o || (int) $o->forbeast_version !== $version) return ['result' => 'skipped', 'reason' => 'superseded'];
        if ($o->forbeast_state === self::SENT) return ['result' => 'skipped', 'reason' => 'already sent'];
        $state = (array) $o->forbeast;
        $gateway = (string) ($state['gateway'] ?? '');

        if (!Gateways::valid($gateway) || !ForbeastSite::linked($gateway)) return $this->pause($o, $version, 'The gateway is not linked as a site any more.');
        if (!$probe && Cache::has(self::pauseKey($gateway))) return $this->pause($o, $version, $state['reason'] ?? 'Paused: the gateway refused the site key.', false);

        $row = MacroOutput::find((int) ($state['row'] ?? 0));
        if (!$row) return $this->finish($o, $version, self::FAILED, 'The macro_output row is gone.');
        $body = $this->payload($o, $row, (string) ($state['stage'] ?? self::UPLOADED), $version);
        if ($body['page_id'] === '') return $this->finish($o, $version, self::FAILED, 'The page has no Facebook page id.');

        $this->merge($o, $version, ['tries' => (int) ($state['tries'] ?? 0) + 1, 'tried_at' => now()->toIso8601String()]);
        $c = Gateways::config($gateway);
        try {
            $res = Http::withToken($c['site_key'])->acceptJson()->asJson()->connectTimeout(5)->timeout(max(5, (int) $c['timeout']))
                ->put($c['url'] . '/v1/site/orders/' . rawurlencode((string) $state['ref']), $body);
        } catch (ConnectionException) {
            return $this->retry($o, $version, 'Forbeast did not answer.');
        } catch (Throwable $e) {
            report($e);

            return $this->retry($o, $version, 'Forbeast did not answer.');
        }

        $json = is_array($res->json()) ? $res->json() : [];
        $status = $res->status();
        if ($res->successful()) {
            $extra = ['order_id' => is_int($json['order_id'] ?? null) ? $json['order_id'] : null, 'duplicate' => ($json['duplicate'] ?? false) === true,
                'stale' => ($json['stale'] ?? false) === true, 'sent_at' => now()->toIso8601String()];
            $done = $this->finish($o, $version, self::SENT, null, $extra);
            Cache::forget(self::pauseKey($gateway));
            $this->resume($gateway);

            return $done;
        }
        $reason = self::reason($status, $json);
        if ($status === 401 || $status === 403) {
            Cache::put(self::pauseKey($gateway), true, now()->addMinutes(self::pauseMinutes()));

            return $this->pause($o, $version, $reason);
        }
        if ($status === 408 || $status === 429 || $status >= 500) return $this->retry($o, $version, $reason);

        return $this->finish($o, $version, self::FAILED, $reason);
    }

    /** Ang huling subok ng job ay lumampas na (hindi dumating sa loob ng oras ng retry): failed, makikita sa listahan. */
    public function gaveUp(int $id, int $version, string $why): void
    {
        $o = OrderEncode::find($id);
        if (!$o || (int) $o->forbeast_version !== $version || $o->forbeast_state !== self::QUEUED) return;
        $last = (string) (((array) $o->forbeast)['reason'] ?? '');
        $this->finish($o, $version, self::FAILED, mb_substr('Gave up: ' . ($last !== '' ? $last : self::clean($why)), 0, 300));
    }

    /** "Send again" ng may-ari: bagong version ng parehong row. @return bool false kapag hindi na maipapadala */
    public function again(OrderEncode $o): bool
    {
        $row = MacroOutput::find((int) (((array) $o->forbeast)['row'] ?? $o->macro_output_id));
        if (!$row) return false;

        return $this->queue($o, $row, (string) (((array) $o->forbeast)['stage'] ?? self::UPLOADED));
    }

    /**
     * Ang bantay ng pause (kada ilang minuto): sa bawat gateway na may naka-pause at lipas na ang pause, ang pinakaluma ay
     * ipinapadala ulit bilang probe; kapag tinanggap, ang lahat ng iba ay ipinapadala (resume). @return int ang mga probe
     */
    public function probe(): int
    {
        if (self::since() === null) return 0;
        $sent = 0;
        $paused = OrderEncode::where('forbeast_state', self::PAUSED)->orderBy('id')->limit(self::RESUME_BATCH)->get(['id', 'forbeast_state', 'forbeast_version', 'forbeast']);
        foreach ($paused->groupBy(fn (OrderEncode $o) => (string) (((array) $o->forbeast)['gateway'] ?? '')) as $gateway => $rows) {
            if (Cache::has(self::pauseKey((string) $gateway))) continue;
            $first = $rows->first();
            $this->requeue($first, true);
            $sent++;
        }

        return $sent;
    }

    /** Ang body ng PUT ayon sa kontrata: ang A..H ng order (input → details, shop → item at shop), at ang hilaw sa fields. */
    public function payload(OrderEncode $o, MacroOutput $row, string $stage, int $version): array
    {
        $f = (array) ($o->input['fields'] ?? []);
        $in = $f ? new Input($f) : Input::fromRow((array) ($o->input['row'] ?? []));
        $text = fn (string $field, string $key) => self::text($in->get($field), self::LENGTHS[$key]);
        $status = trim((string) $row->STATUS);

        $fields = [];
        foreach (Input::FIELDS as $i => $field) $fields[self::LETTERS[$i]] = self::cut($in->get($field), self::FIELD_LENGTH);

        return [
            'page_id' => $this->pageId($o),
            'version' => $version,
            'ordered_at' => self::orderedAt((string) $row->TIMESTAMP, $in->get('date')),
            'name' => $text('name', 'name'),
            'phone' => $text('phone', 'phone'),
            'details' => $text('input', 'details'),
            'item' => $text('shop', 'item'),
            'shop' => $text('shop', 'shop'),
            'cxd' => $text('cxd', 'cxd'),
            'price' => self::price($in->get('price')),
            'status' => self::text($status !== '' ? $status : $stage, self::LENGTHS['status']),
            'fields' => $fields,
        ];
    }

    /** Ang Facebook page id ng takbo, o ang botcake.page_id ng page; '' kapag wala o hindi numero. */
    private function pageId(OrderEncode $o): string
    {
        $id = trim((string) ($o->input['page_id'] ?? ''));
        if ($id === '' || $id === '0') $id = trim((string) $this->settings->get((string) $o->page, 'botcake', 'page_id'));

        return preg_match('/^\d{1,30}$/', $id) ? $id : '';
    }

    /** Ang oras ng order sa Manila (+08:00): ang TIMESTAMP ng row, o ang petsa ng order; hindi kailanman lampas sa ngayon. */
    private static function orderedAt(string $timestamp, string $date): string
    {
        $now = now('Asia/Manila');
        $at = null;
        foreach ([trim($timestamp), LiveOrder::timestamp($date)] as $t) {
            if (!preg_match('/^\d{2}:\d{2} \d{2}-\d{2}-\d{4}$/', $t)) continue;
            try {
                $at = Carbon::createFromFormat('!H:i d-m-Y', $t, 'Asia/Manila');
                break;
            } catch (Throwable) {
            }
        }
        if (!$at || $at->gt($now)) $at = $now;

        return $at->copy()->setTimezone('Asia/Manila')->format('Y-m-d\TH:i:sP');
    }

    /** Ang presyo bilang numero (hanggang 2 decimal); null kapag hindi presyo (ang hilaw ay nasa fields.H). */
    private static function price(string $raw): ?string
    {
        $p = str_replace([',', '₱', ' ', "\u{00A0}"], '', trim($raw));
        if (preg_match('/^(?:php|p)(\d.*)$/i', $p, $m)) $p = $m[1];

        return preg_match('/^\d{1,10}(\.\d{1,2})?$/', $p) ? $p : null;
    }

    /** Text na putol sa haba ng Forbeast; null kapag blangko (ang Forbeast ang naglalagay ng "No name" at iba pa). */
    private static function text(string $value, int $length): ?string
    {
        $value = self::cut($value, $length);

        return trim($value) === '' ? null : $value;
    }

    /** Valid na UTF-8 at hanggang $length na character, kung hindi ay walang binabago. */
    private static function cut(string $value, int $length): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) $value = (string) mb_scrub($value, 'UTF-8');

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    /** Ang dahilan mula sa sagot ng Forbeast (untrusted): ang error code, ang maikling message at ang mga field na mali. */
    private static function reason(int $status, array $json): string
    {
        $code = is_string($json['error'] ?? null) && preg_match('/^[a-z_]{1,40}$/', $json['error']) ? $json['error'] : '';
        $msg = is_string($json['message'] ?? null) ? self::clean($json['message']) : '';
        $fields = is_array($json['fields'] ?? null) ? array_values(array_filter(array_map(fn ($k) => is_string($k) ? self::clean($k, 40) : '', array_keys($json['fields'])))) : [];

        return mb_substr(($msg !== '' ? 'Forbeast said "' . $msg . '"' : 'Forbeast refused it') . ' (HTTP ' . $status . ($code !== '' ? ', ' . $code : '') . ')'
            . ($fields ? ' Fields: ' . implode(', ', array_slice($fields, 0, 10)) . '.' : ''), 0, 300);
    }

    private static function clean(string $text, int $length = 200): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_check_encoding($text, 'UTF-8') ? $text : (string) mb_scrub($text, 'UTF-8'));

        return mb_substr(trim($text), 0, $length);
    }

    /** @return array{result: string, reason: ?string} */
    private function retry(OrderEncode $o, int $version, string $reason): array
    {
        $this->merge($o, $version, ['reason' => $reason]);

        return ['result' => 'retry', 'reason' => $reason];
    }

    /** @return array{result: string, reason: ?string} */
    private function pause(OrderEncode $o, int $version, string $reason, bool $mark = true): array
    {
        $this->set($o, $version, self::PAUSED, ['reason' => $reason] + ($mark ? ['paused_at' => now()->toIso8601String()] : []));

        return ['result' => self::PAUSED, 'reason' => $reason];
    }

    /** @return array{result: string, reason: ?string} */
    private function finish(OrderEncode $o, int $version, string $state, ?string $reason, array $extra = []): array
    {
        $this->set($o, $version, $state, ['reason' => $reason] + $extra + ($state === self::FAILED ? ['failed_at' => now()->toIso8601String()] : []));

        return ['result' => $state, 'reason' => $reason];
    }

    /** Ang lahat ng naka-pause ng gateway ay ipinapadala ulit (tinanggap na ang key). */
    private function resume(string $gateway): void
    {
        OrderEncode::where('forbeast_state', self::PAUSED)->orderBy('id')->limit(self::RESUME_BATCH)->get(['id', 'forbeast_state', 'forbeast_version', 'forbeast'])
            ->filter(fn (OrderEncode $o) => (((array) $o->forbeast)['gateway'] ?? '') === $gateway)
            ->each(fn (OrderEncode $o) => $this->requeue($o, false));
    }

    /** Ang naka-pause na padala ay naka-queue ulit (parehong version: parehong laman ng order). */
    private function requeue(OrderEncode $o, bool $probe): void
    {
        $version = (int) $o->forbeast_version;
        if ($this->set($o, $version, self::QUEUED, [], self::PAUSED)) SendOrderToForbeast::dispatch((int) $o->id, $version, $probe)->afterCommit();
    }

    /** Ang estado at mga detalye, kapag ang version ay ang padalang ito pa (at ang estado ay $from, kung ibinigay). */
    private function set(OrderEncode $o, int $version, string $state, array $extra, ?string $from = null): bool
    {
        $o->refresh();
        if ((int) $o->forbeast_version !== $version || ($from !== null && $o->forbeast_state !== $from)) return false;

        return $this->write($o, $version, ['forbeast_state' => $state, 'forbeast' => array_merge((array) $o->forbeast, $extra)], $from);
    }

    private function merge(OrderEncode $o, int $version, array $extra): void
    {
        $o->refresh();
        if ((int) $o->forbeast_version === $version) $this->write($o, $version, ['forbeast' => array_merge((array) $o->forbeast, $extra)]);
    }

    /** Kondisyonal na UPDATE: walang sulat kapag may mas bagong padala na ang row (ibang version) o nagbago ang estado. */
    private function write(OrderEncode $o, int $version, array $values, ?string $from = null): bool
    {
        $db = $values;
        if (array_key_exists('forbeast', $db)) $db['forbeast'] = json_encode($db['forbeast'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $n = OrderEncode::query()->whereKey($o->id)->where('forbeast_version', $version)->when($from !== null, fn ($q) => $q->where('forbeast_state', $from))->update($db);
        if ($n === 1) {
            $o->forceFill($values)->syncOriginalAttributes(array_keys($values));
        }

        return $n === 1;
    }

    /** Ang bagong padala ng row, nang hindi sinusulat ang ibang nabago sa model. */
    private function store(OrderEncode $o, array $values): void
    {
        $db = $values;
        $db['forbeast'] = json_encode($db['forbeast'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        OrderEncode::query()->whereKey($o->id)->update($db);
        $o->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }
}
