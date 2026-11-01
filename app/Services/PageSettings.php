<?php

namespace App\Services;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Basa at sulat ng page_settings. Isang ACTIVE row kada page + provider + key: ang bagong value ay pumapalit
 * (ang luma ay active = false + replaced_at, naiiwan bilang history). Ang page ay inihahambing trim at
 * case-insensitive, gaya ng macro_output.PAGE.
 */
class PageSettings
{
    /**
     * Mga key na system lang ang sumusulat (hindi tinatanggap mula sa page). Kasama rin ang mga tugma sa
     * DETAIL_PATTERN: ang pangalan ng bawat flow (botcake.flow.<x>.name) at ang body ng shipping template.
     */
    public const SYSTEM_KEYS = ['botcake.token_status', 'botcake.template.shipping.text', 'botcake.test_ok',
        'botcake.flow.shipping.options', 'botcake.field.message.options', 'botcake.chat.key', 'chatbot.active', 'chatbot.flow_id',
        'botcake.setup.check', 'botcake.setup.delivery', 'botcake.mock', 'botcake.mock_latency_ms', 'botcake.mock_error_rate',
        'chatbot.mock_ai', 'chatbot.mock_ai_latency_ms', 'forbeast.token_status', 'forbeast.webhook_secret', 'forbeast.permissions', 'chatbot.auto_switch',
        'incepxionbot.token_status', 'incepxionbot.webhook_secret', 'incepxionbot.permissions', 'chatbot.gateway', 'botcake.chat_block',
        'chatbot.ai_provider', 'chatbot.ai_fallback', 'chatbot.ai_local_model', 'chatbot.ai_local_timeout', 'chatbot.ai_local_concurrency',
        'chatbot.ai_breaker_failures', 'chatbot.ai_breaker_seconds', 'chatbot.ai_local_reasoning_effort', 'facebook.name', 'facebook.name_checked',
        'chatbot.ai_anthropic_model', 'chatbot.ai_deepseek_model'];

    /**
     * Ang chatbot key na mukhang key (api_key, ai_key, …): hindi tinatanggap mula sa page, dahil ang mga key ng AI ay nasa
     * config lang. Ang mga setting ng AI ng page (chatbot.ai_provider, …) ay nasa SYSTEM_KEYS: ang AI control lang ang sumusulat.
     */
    public static function keyLike(string $provider, string $key): bool
    {
        return $provider === 'chatbot' && (bool) preg_match('/(^|_)(api_?)?key$/', $key);
    }

    /**
     * Mga system key na ipinapakita sa loob ng cell ng ibang key (flow ID, template, message field), hindi bilang sariling
     * column: ang pangalan ng flow, ang body ng template, at ang mga kandidato para sa dropdown (JSON na [{id, name}]).
     */
    public const DETAIL_PATTERN = '/^botcake\.flow\.[a-z0-9_]+\.name$|^botcake\.template\.shipping\.text$|^botcake\.(flow\.shipping|field\.message)\.options$/';

    /** Mga key na laging secret. */
    public const SECRET_KEYS = ['botcake.token', 'botcake.chat.key', 'forbeast.token', 'forbeast.webhook_secret', 'incepxionbot.token', 'incepxionbot.webhook_secret'];

    /**
     * Ang mga key na nagkakabit ng page (ID, token, secret, chat key, flow): ang pagbura ng isa ay tahimik na pumuputol sa
     * bot o sa sync, kaya nabubura lang ito nang may kumpirmasyon. Ang sagot ay kung ano ang titigil (para sa tanong), o
     * null para sa ibang key. Ang iisang listahan para sa page settings at sa Connections.
     */
    public static function clearWarning(string $full, bool $secret = false): ?string
    {
        $provider = (string) strstr($full, '.', true);
        $named = [
            'botcake.page_id'  => 'Botcake chats of this page stop reaching the bot here, and Chatblast and the Botcake sync stop for it.',
            'botcake.token'    => 'Botcake sends, tests, Chatblast and the sync stop for this page.',
            'botcake.chat.key' => 'Botcake chats of this page are refused here until the chat block is set up again.',
            'chatbot.flow_id'  => 'The bot of this page has no flow and stops replying.',
        ];
        if (isset($named[$full])) return $named[$full];
        if (str_ends_with($full, '.page_id')) return 'The ' . $provider . ' connection of this page stops.';
        if (str_ends_with($full, '.token') || $secret || in_array($full, self::SECRET_KEYS, true)) return $provider . ' stops working for this page.';

        return null;
    }

    public static function isSystemKey(string $full): bool
    {
        return in_array($full, self::SYSTEM_KEYS, true) || self::isDetailKey($full);
    }

    public static function isDetailKey(string $full): bool
    {
        return (bool) preg_match(self::DETAIL_PATTERN, $full);
    }

    public function normPage(?string $page): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $page));
    }

    public function pageKey(string $page): string
    {
        return mb_strtolower($this->normPage($page), 'UTF-8');
    }

    public function pageQuery(string $page)
    {
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        return PageSetting::whereRaw("LOWER($trim(page_name)) = ?", [$this->pageKey($page)]);
    }

    /**
     * Ang mali sa input, o null kapag tama. Ang mga kilalang key ay may sariling rule; ang iba ay malayang text.
     * @return array<string, string>|null field => mensahe
     */
    public function validate(string $page, string $provider, string $key, ?string $value, bool $system = false): ?array
    {
        $provider = strtolower(trim($provider));
        $key      = strtolower(trim($key));
        $value    = trim((string) $value);
        $full     = $provider . '.' . $key;

        if ($this->normPage($page) === '') return ['page_name' => 'The page is required.'];
        // Ang "_all" ay ang mga pangalan ng Setup box (ChatblastSetupCheck), hindi page: isinusulat lang ng Setup box.
        if (!$system && $this->pageKey($page) === ChatblastSetupCheck::ALL_PAGE) return ['page_name' => '"' . ChatblastSetupCheck::ALL_PAGE . '" is reserved for the Setup box.'];
        if (mb_strlen($this->normPage($page)) > 255) return ['page_name' => 'Page: 255 characters at most.'];
        if (!preg_match('/^[a-z0-9_]{1,50}$/', $provider)) return ['provider' => 'Provider: lowercase letters, digits and _ only.'];
        // Ang key ng analyst (rts_pct:<item>) ay may pangalan ng item sa dulo, kaya system lang at hindi sa karaniwang key rule.
        $analyst = $system && AnalystRts::isKey($provider, $key);
        if (strlen($key) > 100 || (!$analyst && !preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $key))) {
            return ['key' => 'Key: lowercase letters, digits, _ and . only (e.g. flow.on_delivery).'];
        }

        // Ang mga ad group ay sa Ad groups ng page sa /chatbot/pages lang (Chatbot\AdGroups: pangalan, ad na iisa ang group).
        if (!$system && $provider === Chatbot\AdGroups::PROVIDER) return ['provider' => 'Ad groups are set in the page\'s Ad groups on /chatbot/pages.'];
        if (!$system && self::keyLike($provider, $key)) return ['key' => 'Keys are set on the server, not here.'];
        if (!$system && self::isSystemKey($full)) {
            return ['value' => $full . ' is written by the system (Test token / sync), not here.'];
        }

        // Order encode (spec 130): tatlong key lang, at tama ang value.
        if ($provider === OrderEncode\Settings::PROVIDER) {
            if (!in_array($key, OrderEncode\Settings::KEYS, true)) return ['key' => 'order_encode takes only: ' . implode(', ', OrderEncode\Settings::KEYS) . '.'];
            if ($key === 'mode' && !in_array(strtolower($value), OrderEncode\Settings::MODES, true)) return ['value' => 'order_encode.mode is off, shadow or live.'];
            if ($key !== 'mode' && Chatbot\FlowGraph::spreadsheetId($value) === null) return ['value' => 'order_encode.' . $key . ' takes a Google Sheets link or ID.'];
        }

        $digits = $full === 'botcake.page_id'
            || preg_match('/^botcake\.(flow|field)\.[a-z0-9_]+$/', $full);

        if ($digits && !preg_match('/^\d+$/', $value)) {
            return ['value' => $full . ' takes digits only (no spaces, slashes or letters).'];
        }
        if ($value === '') return ['value' => 'The value is required.'];

        return null;
    }

    /**
     * Isulat ang value. Parehong value ng active → walang bago. $source: ang sumulat mula sa labas ng Likha (walang user
     * id), hal. ang X-Forbeast-User ng page values API.
     * @return array{status:string, setting:PageSetting} status = created|replaced|unchanged
     */
    public function set(string $page, string $provider, string $key, string $value, bool $secret = false, ?int $userId = null, bool $system = false, ?string $source = null): array
    {
        $errors = $this->validate($page, $provider, $key, $value, $system);
        if ($errors) throw new \InvalidArgumentException(reset($errors));

        $provider = strtolower(trim($provider));
        $key      = strtolower(trim($key));
        $value    = trim($value);
        $secret   = $secret || in_array($provider . '.' . $key, self::SECRET_KEYS, true);

        $res = DB::transaction(function () use ($page, $provider, $key, $value, $secret, $userId, $source) {
            $current = $this->pageQuery($page)->where('provider', $provider)->where('key', $key)
                ->where('active', true)->lockForUpdate()->orderByDesc('id')->get();

            $head = $current->first();
            if ($head && $current->count() === 1 && $head->is_secret === $secret && $head->plainValue() === $value) {
                return ['status' => 'unchanged', 'setting' => $head];
            }

            foreach ($current as $old) {
                $old->update(['active' => false, 'replaced_at' => now()]);
            }

            // Ang unang naitalang baybay ng page ang ginagamit, para iisa ang pangalan sa listahan.
            $name = $this->pageQuery($page)->orderBy('id')->value('page_name') ?? $this->normPage($page);

            $setting = PageSetting::create([
                'page_name'  => $name,
                'provider'   => $provider,
                'key'        => $key,
                'value'      => $secret ? Crypt::encryptString($value) : $value,
                'is_secret'  => $secret,
                'active'     => true,
                'created_by' => $userId,
            ] + ($source !== null && \Illuminate\Support\Facades\Schema::hasColumn('page_settings', 'source') ? ['source' => $source] : []));

            // Walang value sa log: sino, kailan at aling key lang.
            $status = $current->isEmpty() ? 'created' : 'replaced';
            Log::info('PageSettings: ' . $status, [
                'page' => $name, 'key' => $provider . '.' . $key, 'setting_id' => $setting->id, 'by' => $userId,
            ]);

            // Ang naka-cache ng /api/botcake/chat (page_id => page, mga hash ng chat.key): binubura ngayon at pagkatapos ng commit.
            if ($provider === 'botcake' && in_array($key, ['page_id', 'chat.key'], true)) $this->forgetChatPageMap();
            // Ang listahan ng mock page (BotcakeMock): binubura kapag nagbago ang flag, ang page_id o ang bagal at error.
            if (($provider === 'botcake' && in_array($key, BotcakeMock::KEYS, true)) || ($provider === 'chatbot' && in_array($key, BotcakeMock::AI_KEYS, true))) {
                BotcakeMock::forget();
                DB::afterCommit(fn () => BotcakeMock::forget());
            }

            return ['status' => $status, 'setting' => $setting];
        });
        // Pagkatapos ng commit: ang Ready ng page (ChatblastReady) ay kinukuwenta ulit sa susunod na tanong.
        if ($res['status'] !== 'unchanged' && ChatblastReady::watches($provider, $key)) ChatblastReady::forget($this->pageKey($page));

        return $res;
    }

    private function forgetChatPageMap(): void
    {
        BotcakeChat::forgetPageMap();
        DB::afterCommit(fn () => BotcakeChat::forgetPageMap());
    }

    public function deactivate(PageSetting $setting, ?int $userId = null, ?string $source = null): void
    {
        if (!$setting->active) return;

        // Ang nag-clear ay itinatala (replaced_by, o replaced_source mula sa labas ng Likha) para sa Changes; ang walang user ay null.
        $setting->update(['active' => false, 'replaced_at' => now()] + ($userId && \Illuminate\Support\Facades\Schema::hasColumn('page_settings', 'replaced_by') ? ['replaced_by' => $userId] : [])
            + ($source !== null && \Illuminate\Support\Facades\Schema::hasColumn('page_settings', 'replaced_source') ? ['replaced_source' => $source] : []));
        if (ChatblastReady::watches($setting->provider, $setting->key)) ChatblastReady::forget($this->pageKey($setting->page_name));
        if ($setting->provider === 'botcake' && in_array($setting->key, ['page_id', 'chat.key'], true)) $this->forgetChatPageMap();
        if ($setting->provider === 'botcake' && in_array($setting->key, BotcakeMock::KEYS, true)) BotcakeMock::forget();
        Log::info('PageSettings: deactivated', [
            'page' => $setting->page_name, 'key' => $setting->fullKey(), 'setting_id' => $setting->id, 'by' => $userId,
        ]);
    }

    public function get(string $page, string $provider, string $key): ?string
    {
        $row = $this->map($page)[strtolower($provider) . '.' . strtolower($key)] ?? null;

        return $row === null ? null : self::plain($row);
    }

    /**
     * Lahat ng active na setting ng page para sa isang provider, ayon sa id (ang pinakabago ang masusunod sa parehong
     * key), mula sa parehong cache ng get(). @return array<string, string> key => plain value
     */
    public function all(string $page, string $provider): array
    {
        return array_map(fn (array $r) => $r['value'], $this->allWithIds($page, $provider));
    }

    /**
     * Gaya ng all(), kasama ang id ng page_settings row (ang version ng botfield sa Results).
     * @return array<string, array{value: string, id: int}> key => plain value at row id
     */
    public function allWithIds(string $page, string $provider): array
    {
        $out = [];
        $prefix = strtolower($provider) . '.';
        foreach ($this->map($page) as $full => $row) {
            if (str_starts_with($full, $prefix)) $out[substr($full, strlen($prefix))] = ['value' => (string) self::plain($row), 'id' => (int) $row[2]];
        }

        return $out;
    }

    /** Ilang segundo naka-cache ang settings ng isang page, at ilang segundo ang memo sa loob ng isang proseso. */
    public const CACHE_TTL = 600;
    public const MEMO_TTL = 2;

    /** @var array<string, array{0: float, 1: array<string, array{0: ?string, 1: bool, 2: int}>}> page key => [nabasa noong, map] */
    private array $memo = [];

    public static function cacheKey(string $page): string
    {
        // v2: may row id na ang bawat setting; ang lumang cache (walang id) ay hindi na binabasa.
        return 'page-settings:v2:' . sha1((new self)->pageKey($page));
    }

    /**
     * Ang mga active na setting ng page: provider.key => [value gaya ng nasa table (ang secret ay naka-encrypt pa rin, at
     * sa memory lang binubuksan), is_secret, row id], ayon sa id. Mula sa memo (hanggang MEMO_TTL), saka sa cache, saka sa
     * isang query.
     * @return array<string, array{0: ?string, 1: bool, 2: int}>
     */
    private function map(string $page): array
    {
        $pk = $this->pageKey($page);
        $hit = $this->memo[$pk] ?? null;
        if ($hit !== null && microtime(true) - $hit[0] < self::MEMO_TTL) return $hit[1];

        $map = Cache::remember(self::cacheKey($page), self::CACHE_TTL, function () use ($page) {
            $out = [];
            foreach ($this->pageQuery($page)->where('active', true)->orderBy('id')->get(['id', 'provider', 'key', 'value', 'is_secret']) as $s) {
                $out[strtolower($s->provider) . '.' . strtolower($s->key)] = [$s->getRawOriginal('value'), (bool) $s->is_secret, (int) $s->id];
            }

            return $out;
        });
        $this->memo[$pk] = [microtime(true), $map];

        return $map;
    }

    /**
     * Ang settings ng maraming page nang sabay, para sa listahan na nagbabasa ng get() kada page (hal. /chatbot/data):
     * isang basa sa cache para sa lahat, at isang query para sa mga wala pa roon, sa parehong laman ng map(). Ang page na
     * walang nakitang row sa iisang query ay iniiwan sa map() (ang sariling query nito), para walang page na mukhang walang
     * settings dahil lang sa ibang pagkakasulat ng pangalan. Ang nabasa sa query ay sa memo lang (ang request na ito), hindi
     * sa cache: ang set() na pumasok habang binabasa ang lahat ng page ay hindi dapat matakpan ng lumang map nang 10 minuto.
     * @param array<int, string> $pages
     */
    public function preload(array $pages): void
    {
        $byKey = [];
        foreach ($pages as $p) $byKey[self::cacheKey($p)] = $this->pageKey($p);
        if (!$byKey) return;
        $now = microtime(true);
        $missing = [];
        foreach (Cache::many(array_keys($byKey)) as $ck => $map) {
            if (is_array($map)) $this->memo[$byKey[$ck]] = [$now, $map];
            else $missing[$byKey[$ck]] = $ck;
        }
        if (!$missing) return;

        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $maps = [];
        foreach (array_chunk(array_keys($missing), 500) as $chunk) {
            $rows = PageSetting::selectRaw("LOWER($trim(page_name)) AS pk, id, provider, " . DB::getQueryGrammar()->wrap('key') . ', value, is_secret')
                ->whereRaw("LOWER($trim(page_name)) IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk)
                ->where('active', true)->orderBy('id')->get();
            foreach ($rows as $s) {
                $pk = mb_strtolower((string) $s->pk, 'UTF-8');
                if (isset($missing[$pk])) $maps[$pk][strtolower($s->provider) . '.' . strtolower($s->key)] = [$s->getRawOriginal('value'), (bool) $s->is_secret, (int) $s->id];
            }
        }
        foreach ($maps as $pk => $map) $this->memo[$pk] = [$now, $map];
    }

    /** @param array{0: ?string, 1: bool, 2: int} $row */
    private static function plain(array $row): ?string
    {
        $s = new PageSetting();
        $s->value = $row[0];
        $s->is_secret = $row[1];

        return $s->plainValue();
    }

    /** Burahin ang naka-cache na settings ng page (ngayon at pagkatapos ng commit, para hindi maibalik ang luma). */
    public static function forget(string $page): void
    {
        $key = self::cacheKey($page);
        Cache::forget($key);
        DB::afterCommit(fn () => Cache::forget($key));
        if (app()->resolved(self::class)) unset(app(self::class)->memo[(new self)->pageKey($page)]);
    }

    /**
     * Mga page na may active na lahat ng key na ito (pangalan gaya ng naitala).
     * @return array<int, string>
     */
    public function pagesWith(string $provider, array $keys): array
    {
        $keys = array_values(array_unique($keys));
        $rows = PageSetting::where('provider', $provider)->whereIn('key', $keys)->where('active', true)->where('page_name', '!=', ChatblastSetupCheck::ALL_PAGE)
            ->orderBy('id')->get(['page_name', 'key']);

        return $rows->groupBy(fn ($r) => $this->pageKey($r->page_name))
            ->filter(fn ($g) => $g->pluck('key')->unique()->count() === count($keys))
            ->map(fn ($g) => $g->first()->page_name)
            ->sort()->values()->all();
    }
}
