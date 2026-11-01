<?php

namespace App\Services\Chatbot;

use App\Models\PageSetting;
use App\Services\ChatblastSetupCheck;
use App\Services\PageSettings;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang pool ng AI key ng kumpanya (spec 210): mga row sa page_settings ng nakalaang page na "_all", provider ai_keys, may
 * history (sino at kailan) gaya ng ibang setting. Bawat key:
 * - k<id>.secret: ang key, naka-encrypt (is_secret). Sa delete, ang value ng lahat ng row nito ay null (wala na, hindi lang tago).
 * - k<id>.info: JSON {provider, label, last4, hash, enabled, deleted}; ang hash (HMAC ng app key) ang panghambing ng doble.
 * - k<id>.health: JSON {state ok|down, reason, until, rest, at, test, tested_at}: ang marka ng palya, naitatala (hindi nawawala
 *   sa cache clear o restart), gaya ng lipat ng ForbeastWatch.
 * Ang event (lipat mula sa isang key) at ang alert (lahat ng key pumalya) ay mga row din; ang history nila ang feed ng screen.
 * Walang method dito na nagbabalik ng key sa browser: ang secret ay para lang sa tawag sa provider.
 */
class ChatbotAiKeys
{
    public const PAGE = ChatblastSetupCheck::ALL_PAGE;
    public const PROVIDER = 'ai_keys';
    public const MAX_KEY = 300;
    public const MAX_LABEL = 60;
    public const LEGACY_LABEL = 'Server key';
    public const ALERT_SECONDS = 3600;
    public const ALERT_SHOWN_HOURS = 24;
    /** Ang dahilan ng marka sa screen. */
    public const REASONS = ['no_credit' => 'no credit', 'invalid' => 'invalid key', 'rate_limited' => 'rate limited', 'failing' => 'failing'];

    public function __construct(private PageSettings $settings)
    {
    }

    /** @return array<int, string> ang mga provider na may pool (pooled sa config): openai, anthropic, deepseek */
    public static function providers(): array
    {
        return array_values(array_filter(ChatbotAiProviders::names(), fn ($p) => (bool) ChatbotAiProviders::option($p, 'pooled', false)));
    }

    public static function pooled(string $provider): bool
    {
        return in_array($provider, self::providers(), true);
    }

    /** "OpenAI, Claude or DeepSeek" */
    public static function providerList(): string
    {
        $labels = array_map(fn ($p) => ChatbotAiProviders::label($p), self::providers());
        $last = array_pop($labels);

        return $labels ? implode(', ', $labels) . ' or ' . $last : (string) $last;
    }

    private static function hash(string $key): string
    {
        return hash_hmac('sha256', $key, (string) config('app.key'));
    }

    /** May pahinga pa ang key (down at hindi pa tapos ang oras, o walang katapusan hanggang Test)? */
    public static function resting(array $health): bool
    {
        if (($health['state'] ?? 'ok') !== 'down') return false;

        return ($health['until'] ?? null) === null || (int) $health['until'] > now()->getTimestamp();
    }

    /**
     * Ang mga key na hindi binura, ayon sa pagkakadagdag. Ang secret ay '' kapag hindi mabasa (napalitan ang APP_KEY).
     * @return array<string, array{id: string, provider: string, label: string, last4: string, hash: string, enabled: bool, secret: string, health: array, added_at: ?string}>
     */
    public function all(): array
    {
        $raw = $this->settings->all(self::PAGE, self::PROVIDER);
        $out = [];
        foreach ($raw as $k => $v) {
            if (!preg_match('/^k([a-f0-9]{8})\.info$/', $k, $m)) continue;
            $info = json_decode($v, true);
            if (!is_array($info) || !empty($info['deleted'])) continue;
            $id = $m[1];
            $health = json_decode((string) ($raw['k' . $id . '.health'] ?? ''), true);
            $out[$id] = ['id' => $id, 'provider' => (string) ($info['provider'] ?? ''), 'label' => (string) ($info['label'] ?? ''), 'last4' => (string) ($info['last4'] ?? ''),
                'hash' => (string) ($info['hash'] ?? ''), 'enabled' => (bool) ($info['enabled'] ?? true), 'secret' => (string) ($raw['k' . $id . '.secret'] ?? ''),
                'health' => is_array($health) ? $health : [], 'added_at' => $info['added_at'] ?? null];
        }
        uasort($out, fn ($a, $b) => strcmp((string) $a['added_at'], (string) $b['added_at']));

        return $out;
    }

    /** May key ng provider sa pool (kahit down o disabled)? Wala = ang OpenAI ay ang services.openai.key gaya ng dati. */
    public function inPool(string $provider): bool
    {
        foreach ($this->all() as $k) if ($k['provider'] === $provider) return true;

        return false;
    }

    /** Ang ref ng key para sa tawag (walang ibang lumalabas sa labas ng server kundi ang label at huling 4). */
    private static function ref(array $k): array
    {
        return ['id' => $k['id'], 'provider' => $k['provider'], 'label' => $k['label'], 'last4' => $k['last4'], 'secret' => $k['secret'], 'legacy' => false];
    }

    /**
     * Ang mga magagamit na key ng provider ngayon (naka-enable, nababasa, walang pahinga), iniikot (round-robin) para
     * nahahati ang mga tawag. Ang OpenAI na walang key sa pool: ang services.openai.key ("Server key"), gaya ng dati.
     * @return array<int, array{id: ?string, provider: string, label: string, last4: string, secret: string, legacy: bool}>
     */
    public function usable(string $provider): array
    {
        $all = array_values(array_filter($this->all(), fn ($k) => $k['provider'] === $provider));
        if (!$all) {
            $legacy = $provider === ChatbotAiProviders::OPENAI ? ChatbotAiProviders::key($provider) : '';

            return $legacy === '' ? [] : [['id' => null, 'provider' => $provider, 'label' => self::LEGACY_LABEL, 'last4' => substr($legacy, -4), 'secret' => $legacy, 'legacy' => true]];
        }
        $ok = array_values(array_filter($all, fn ($k) => $k['enabled'] && $k['secret'] !== '' && !self::resting($k['health'])));
        $n = count($ok);
        if ($n > 1) {
            $start = ((int) Cache::increment('chatbot-ai-keys:rr:' . $provider) - 1) % $n;
            $ok = [...array_slice($ok, $start), ...array_slice($ok, 0, $start)];
        }

        return array_map(fn ($k) => self::ref($k), $ok);
    }

    /** Isang bakanteng slot ng key kapag may key_concurrency ang provider; false = walang hangganan; null = puno. */
    public static function slot(array $ref, int $ttl): Lock|false|null
    {
        $cap = ChatbotAiProviders::option($ref['provider'], 'key_concurrency');
        if ($cap === null || $cap === '' || ($ref['id'] ?? null) === null) return false;
        for ($i = 0; $i < max(1, (int) $cap); $i++) {
            $lock = Cache::lock('chatbot-ai-key:' . $ref['id'] . ':slot:' . $i, max(1, $ttl));
            if ($lock->get()) return $lock;
        }

        return null;
    }

    // ---- Ang mga kilos ng owner (screen) ----

    /** @return array{error: ?string, id: ?string} */
    public function add(mixed $provider, mixed $label, mixed $key, ?int $userId): array
    {
        if (!is_string($provider) || !self::pooled($provider)) return ['error' => 'Pick ' . self::providerList() . '.', 'id' => null];
        if (($e = self::keyError($key)) !== null) return ['error' => $e, 'id' => null];
        if (($e = self::labelError($label)) !== null) return ['error' => $e, 'id' => null];
        $key = trim((string) $key);
        $label = trim((string) $label);
        $all = $this->all();
        if (count($all) >= (int) config('services.chatbot_ai.keys.max', 50)) return ['error' => 'The pool is full (' . (int) config('services.chatbot_ai.keys.max', 50) . ' keys). Delete one first.', 'id' => null];
        $hash = self::hash($key);
        foreach ($all as $k) if (hash_equals($k['hash'], $hash)) return ['error' => 'This key is already in the pool (' . $k['label'] . ').', 'id' => null];

        $id = bin2hex(random_bytes(4));
        DB::transaction(function () use ($id, $provider, $label, $key, $hash, $userId) {
            $this->settings->set(self::PAGE, self::PROVIDER, 'k' . $id . '.secret', $key, true, $userId, true);
            $this->write($id, 'info', ['provider' => $provider, 'label' => $label, 'last4' => substr($key, -4), 'hash' => $hash, 'enabled' => true, 'added_at' => now()->toIso8601String()], $userId);
        });

        return ['error' => null, 'id' => $id];
    }

    public static function keyError(mixed $key): ?string
    {
        if (!is_string($key) || trim($key) === '') return 'Paste the key.';
        $key = trim($key);
        if (preg_match('/\s/', $key)) return 'Paste one key on one line.';
        if (strlen($key) > self::MAX_KEY) return 'The key is too long (' . self::MAX_KEY . ' characters at most).';
        if (preg_match('/[^\x21-\x7E]/', $key)) return 'The key has characters that a key cannot have.';
        if (strlen($key) < 8) return 'This is too short to be a key.';

        return null;
    }

    public static function labelError(mixed $label): ?string
    {
        if (!is_string($label) || trim($label) === '') return 'Give the key a label.';
        if (preg_match('/[\x00-\x1F\x7F]/u', $label) || !mb_check_encoding($label, 'UTF-8')) return 'Label: one line, no control characters.';
        if (mb_strlen(trim($label)) > self::MAX_LABEL) return 'Label: ' . self::MAX_LABEL . ' characters at most.';

        return null;
    }

    /** Ang key (hindi binura), o null. */
    public function find(string $id): ?array
    {
        return preg_match('/^[a-f0-9]{8}$/', $id) ? ($this->all()[$id] ?? null) : null;
    }

    private function info(string $id): array
    {
        return (array) json_decode((string) $this->settings->get(self::PAGE, self::PROVIDER, 'k' . $id . '.info'), true);
    }

    /** Isang bagong row (history) ng bahagi ng key. */
    private function write(string $id, string $part, array $value, ?int $userId): void
    {
        $this->settings->set(self::PAGE, self::PROVIDER, 'k' . $id . '.' . $part, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false, $userId, true);
    }

    public function rename(string $id, mixed $label, ?int $userId): ?string
    {
        if ($this->find($id) === null) return 'This key is not in the pool.';
        if (($e = self::labelError($label)) !== null) return $e;
        $this->write($id, 'info', array_replace($this->info($id), ['label' => trim((string) $label)]), $userId);

        return null;
    }

    public function setEnabled(string $id, bool $enabled, ?int $userId): ?string
    {
        if ($this->find($id) === null) return 'This key is not in the pool.';
        $this->write($id, 'info', array_replace($this->info($id), ['enabled' => $enabled]), $userId);

        return null;
    }

    /** Burahin: ang info ay "deleted" (history), at ang value ng bawat secret row nito (pati history) ay null. */
    public function delete(string $id, ?int $userId): ?string
    {
        if ($this->find($id) === null) return 'This key is not in the pool.';
        DB::transaction(function () use ($id, $userId) {
            $this->write($id, 'info', array_replace($this->info($id), ['deleted' => true, 'deleted_at' => now()->toIso8601String()]), $userId);
            $secret = fn () => $this->settings->pageQuery(self::PAGE)->where('provider', self::PROVIDER)->where('key', 'k' . $id . '.secret');
            $secret()->where('active', true)->update(['active' => false, 'replaced_at' => now()]
                + ($userId && Schema::hasColumn('page_settings', 'replaced_by') ? ['replaced_by' => $userId] : []));
            $secret()->update(['value' => null, 'updated_at' => now()]);
            foreach ($this->settings->pageQuery(self::PAGE)->where('provider', self::PROVIDER)->where('key', 'k' . $id . '.health')->where('active', true)->get() as $row) {
                $this->settings->deactivate($row, $userId);
            }
        });
        PageSettings::forget(self::PAGE);
        Cache::forget('chatbot-ai-key:' . $id . ':fails');

        return null;
    }

    /**
     * Ang Test ng owner: isang murang tawag (ChatbotAiWire::test). Pumasa = malinis ang marka; ang palya na kasalanan ng key
     * = down hanggang pumasa ang susunod na Test (hindi ginagamit). @return array{error: ?string, result: ?string}
     */
    public function test(string $id, ?int $userId): array
    {
        $k = $this->find($id);
        if ($k === null) return ['error' => 'This key is not in the pool.', 'result' => null];
        if ($k['secret'] === '') return ['error' => 'This key cannot be decrypted (the app key changed). Delete it and add it again.', 'result' => null];
        $result = ChatbotAiWire::test($k['provider'], $k['secret']);
        $health = $this->freshHealth($id);
        $tested = ['test' => $result, 'tested_at' => now()->toIso8601String()];
        if ($result === 'ok') $health = ['state' => 'ok', 'reason' => null, 'until' => null, 'rest' => 0, 'at' => now()->toIso8601String()] + $tested;
        elseif (in_array($result, ChatbotAiWire::KEY_KINDS, true)) $health = ['state' => 'down', 'reason' => $result, 'until' => null, 'rest' => 0, 'at' => now()->toIso8601String()] + $tested;
        else $health = array_replace($health ?: ['state' => 'ok'], $tested); // model o request: hindi ang key ang mali
        $this->write($id, 'health', $health, $userId);
        Cache::forget('chatbot-ai-key:' . $id . ':fails');

        return ['error' => null, 'result' => $result];
    }

    // ---- Ang mga tawag (chain at pool) ----

    /** Ang health mula sa table mismo (hindi ang cache), para sa pagsulat ng marka. */
    private function freshHealth(string $id): array
    {
        $v = $this->settings->pageQuery(self::PAGE)->where('provider', self::PROVIDER)->where('key', 'k' . $id . '.health')->where('active', true)->orderByDesc('id')->value('value');

        return is_string($v) && is_array($h = json_decode($v, true)) ? $h : [];
    }

    /**
     * Isang palya ng key. Ang no_credit, invalid at rate_limited ay marka agad; ang failing ay pagkatapos ng failing_after sa
     * loob ng failing_window (o agad kapag bumabawi pa ang key). Ang bumabawing key na pumalya ulit ay doble ang pahinga
     * (hanggang max_rest). Iisang marka lang kahit maraming sabay na tawag ang tumama (lock at basa ulit sa table).
     * @return bool true = ang tawag na ito ang nagmarka (para sa event)
     */
    public function failed(array $ref, string $kind): bool
    {
        $id = $ref['id'] ?? null;
        if ($id === null || !in_array($kind, ChatbotAiWire::KEY_KINDS, true)) return false;
        $cached = $this->all()[$id]['health'] ?? [];
        if (self::resting($cached)) return false;
        $recovering = ($cached['state'] ?? 'ok') === 'down';
        if ($kind === 'failing' && !$recovering) {
            $c = 'chatbot-ai-key:' . $id . ':fails';
            Cache::add($c, 0, max(1, (int) config('services.chatbot_ai.keys.failing_window', 300)));
            if ((int) Cache::increment($c) < max(1, (int) config('services.chatbot_ai.keys.failing_after', 3))) return false;
            Cache::forget($c);
        }
        $lock = Cache::lock('chatbot-ai-key:' . $id . ':mark', 10);
        if (!$lock->get()) return false;
        try {
            $health = $this->freshHealth($id);
            if (self::resting($health)) return false;
            $base = max(1, (int) config('services.chatbot_ai.keys.rest.' . $kind, 60));
            $prev = ($health['state'] ?? 'ok') === 'down' ? (int) ($health['rest'] ?? 0) : 0;
            $rest = $kind === 'invalid' ? 0 : min(max(1, (int) config('services.chatbot_ai.keys.max_rest', 21600)), max($base, $prev * 2));
            $this->write($id, 'health', array_replace($health, ['state' => 'down', 'reason' => $kind, 'until' => $kind === 'invalid' ? null : now()->getTimestamp() + $rest,
                'rest' => $rest, 'at' => now()->toIso8601String()]), null);

            return true;
        } finally {
            $lock->release();
        }
    }

    /** Isang sagot ng key: ang marka (kung mayroon) ay nalilinis; ang bilang ng failing ay nagsisimula ulit. */
    public function succeeded(array $ref): void
    {
        $id = $ref['id'] ?? null;
        if ($id === null) return;
        Cache::forget('chatbot-ai-key:' . $id . ':fails');
        if ((($this->all()[$id]['health'] ?? [])['state'] ?? 'ok') !== 'down') return;
        $health = $this->freshHealth($id);
        if (($health['state'] ?? 'ok') !== 'down') return;
        $this->write($id, 'health', array_replace($health, ['state' => 'ok', 'reason' => null, 'until' => null, 'rest' => 0, 'at' => now()->toIso8601String()]), null);
    }

    /** Isang lipat mula sa isang key (page, provider, mula, papunta, dahilan, oras; walang key). */
    public function event(string $page, string $provider, string $from, ?string $to, string $reason): void
    {
        $this->settings->set(self::PAGE, self::PROVIDER, 'event', json_encode(['page' => mb_substr($page, 0, 255), 'provider' => $provider, 'from' => $from, 'to' => $to,
            'reason' => $reason, 'at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), false, null, true);
    }

    /** Lahat ng key ng chain ay pumalya: isang alert lang kada oras (hindi isa kada mensahe). */
    public function alert(string $page, string $error): void
    {
        if (!Cache::add('chatbot-ai-keys:alert', 1, self::ALERT_SECONDS)) return;
        $last = $this->latest('alert');
        if ($last && Carbon::parse($last['at'])->getTimestamp() > now()->getTimestamp() - self::ALERT_SECONDS) return;
        $this->settings->set(self::PAGE, self::PROVIDER, 'alert', json_encode(['page' => mb_substr($page, 0, 255), 'error' => $error, 'at' => now()->toIso8601String()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), false, null, true);
    }

    /** Ang JSON ng isang row (event, alert, usage.<gateway>.paused), o null. */
    public function latest(string $key): ?array
    {
        $v = $this->settings->get(self::PAGE, self::PROVIDER, $key);
        $a = $v !== null ? json_decode($v, true) : null;

        return is_array($a) ? $a : null;
    }

    /** Ang mga event (lipat), pinakabago muna, kada pahina. @return array{events: array, page: int, pages: int, total: int} */
    public function events(int $page, int $perPage = 25): array
    {
        $q = $this->settings->pageQuery(self::PAGE)->where('provider', self::PROVIDER)->where('key', 'event');
        $total = (clone $q)->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $rows = $q->orderByDesc('id')->skip(($page - 1) * $perPage)->take($perPage)->get(['value']);

        return ['events' => $rows->map(fn ($r) => json_decode((string) $r->value, true))->filter()->values()->all(), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }
}
