<?php

namespace App\Services\MetaAds;

use App\Models\UploadLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang mga Facebook na naka-connect sa bawat Likha user (Meta Ads). Isang app_settings row kada Facebook account ng tao,
 * key = meta_ads.connection.<user id>.<fb_user_id>, kasi iisang Likha login ang gamit ng staff sa ilang Facebook account (ibang
 * browser profile bawat isa). Ang lumang key na meta_ads.connection.<user id> (bago ang 287, o kapag walang fb_user_id) ay
 * binabasa pa rin bilang connection ng user na iyon, para walang kailangang mag-Connect ulit. Ang bahagi ng key pagkatapos ng
 * PREFIX ang "ref" ng connection; doon dumadaan ang update at disconnect para isang row lang ang tinatamaan.
 * Value = JSON: token (naka-encrypt), fb_user_id, fb_user_name, expires_at (unix),
 * ad_accounts [{id, name, status}], status (ok | expired), connected_at, last_sync_at, last_error.
 * Hindi page_settings: ang /page-settings grid ay naglilista ng bawat provider, kaya doon lalabas ang token row.
 * Ang Disconnect ay binubura ang row, kaya wala nang kopya ng token.
 */
class Connections
{
    public const PREFIX = 'meta_ads.connection.';
    /** Ilang araw bago mag-expire ay pula na ang Reconnect. */
    public const WARN_DAYS = 7;

    public function __construct(private Graph $graph)
    {
    }

    /** Ang Meta app: ang naka-save sa /ads_manager/report (AppConfig), o ang .env kapag walang row. */
    public function appId(): ?string
    {
        return AppConfig::appId();
    }

    /** Facebook Login for Business: ang configuration (permissions) sa Meta app. Kapag wala, scope=ads_read pa rin. */
    public function configId(): ?string
    {
        return AppConfig::configId();
    }

    /** Ang host ng APP_URL: ang App Domain na ilalagay sa Meta app. */
    public function appDomain(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/') . '/ads_manager/meta/callback';
    }

    public function dialogUrl(string $state): string
    {
        $login = $this->configId() !== null ? ['config_id' => $this->configId()] : ['scope' => 'ads_read'];

        return 'https://www.facebook.com/' . $this->graph->version() . '/dialog/oauth?' . http_build_query([
            'client_id'     => $this->appId(),
            'redirect_uri'  => $this->redirectUri(),
            'state'         => $state,
        ] + $login + [
            'response_type' => 'code',
        ]);
    }

    /**
     * Code → short token → long-lived token (~60 araw), ang expiry (debug_token), ang pangalan sa Facebook at ang mga
     * ad account na nababasa nito; saka isinusulat. Sa Login for Business (may config_id), kapag tumanggi ang
     * fb_exchange_token, ang token mula sa code ang itinatago (baka long-lived na ito); token_from ang nagsasabi kung alin.
     * @throws GraphException
     */
    public function connect(int $userId, string $code): array
    {
        $app = ['client_id' => $this->appId(), 'client_secret' => (string) AppConfig::appSecret()];

        $short = $this->graph->get('oauth/access_token', $app + ['redirect_uri' => $this->redirectUri(), 'code' => $code]);
        $from  = 'fb_exchange_token';
        try {
            $long = $this->graph->get('oauth/access_token', $app + ['grant_type' => 'fb_exchange_token', 'fb_exchange_token' => (string) ($short['access_token'] ?? '')]);
        } catch (GraphException $e) {
            if ($this->configId() === null) throw $e;
            [$long, $from] = [$short, 'code'];
        }
        $token = (string) ($long['access_token'] ?? '');
        if ($token === '') throw new GraphException('Facebook did not return a token.');

        // Ang app token (id|secret) ay nasa Authorization header, hindi sa URL. Ang dalawang exchange sa itaas ay GET na may
        // client_secret sa query, gaya ng dokumento ng Meta; ang mensahe ng anumang mali ay nililinis ng Graph::clean.
        $debug   = $this->graph->get('debug_token', ['input_token' => $token], $app['client_id'] . '|' . $app['client_secret'], false);
        $expires = (int) ($debug['data']['expires_at'] ?? 0);
        if ($expires <= 0 && isset($long['expires_in'])) $expires = time() + (int) $long['expires_in'];

        $me       = $this->graph->get('me', ['fields' => 'id,name'], $token);
        $accounts = $this->graph->all('me/adaccounts', ['fields' => 'account_id,name,account_status', 'limit' => 200], $token);

        return $this->save($userId, $token, [
            'fb_user_id'   => (string) ($me['id'] ?? ($debug['data']['user_id'] ?? '')),
            'fb_user_name' => (string) ($me['name'] ?? ''),
            'expires_at'   => $expires ?: null,
            'token_from'   => $from,
            'ad_accounts'  => array_map(fn ($a) => [
                'id'     => (string) ($a['account_id'] ?? preg_replace('/^act_/', '', (string) ($a['id'] ?? ''))),
                'name'   => (string) ($a['name'] ?? ''),
                'status' => (int) ($a['account_status'] ?? 0),
            ], $accounts),
        ]);
    }

    public function save(int $userId, string $token, array $data): array
    {
        $data = array_merge(['status' => 'ok', 'connected_at' => now()->toIso8601String(), 'last_sync_at' => null, 'last_error' => null], $data);
        $data['token'] = Crypt::encryptString($token);
        $key    = self::key($userId, $data['fb_user_id'] ?? null);
        $legacy = self::PREFIX . $userId;

        DB::transaction(function () use ($key, $legacy, $userId, $data) {
            DB::table('app_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($data), 'updated_at' => now(), 'created_at' => now()]);
            if ($key === $legacy) return;
            // Ang lumang row ng parehong account (o walang fb_user_id, kaya hindi matukoy at dati ay pinapalitan ng anumang
            // Connect) ay napalitan na ng bagong row: binubura para walang kambal at walang lumang kopya ng token.
            $old = $this->raw((string) $userId);
            if ($old !== null && in_array((string) ($old['fb_user_id'] ?? ''), ['', (string) $data['fb_user_id']], true)) {
                DB::table('app_settings')->where('key', $legacy)->delete();
            }
        });

        return $this->strip($data + ['user_id' => $userId, 'ref' => substr($key, strlen(self::PREFIX))]);
    }

    /**
     * Ang key: kada Facebook account ng user, kaya ang parehong account ulit ay pinapalitan ang sariling row (walang doble).
     * Ang walang fb_user_id ay sa lumang key (isa kada user), gaya ng dati.
     */
    public static function key(int $userId, ?string $fbUserId): string
    {
        $fb = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $fbUserId);

        return self::PREFIX . $userId . ($fb !== '' ? '.' . $fb : '');
    }

    /** $ref = ang ref ng connection (ang 'ref' sa all()); ang user id lang ay ang lumang key. */
    public function update(int|string $ref, array $patch): void
    {
        $raw = $this->raw((string) $ref);
        if ($raw === null) return;
        DB::table('app_settings')->where('key', self::PREFIX . $ref)->update(['value' => json_encode(array_merge($raw, $patch)), 'updated_at' => now()]);
    }

    /** Isang connection lang (ang ref nito) ang binubura; ang ibang Facebook ng parehong user ay naiiwan. */
    public function disconnect(int|string $ref): void
    {
        DB::table('app_settings')->where('key', self::PREFIX . $ref)->delete();
    }

    /** Isang connection ayon sa ref (may naka-encrypt na token pa rin), o null. */
    public function find(int|string $ref): ?array
    {
        $ref  = (string) $ref;
        $user = self::userOf($ref);
        $raw  = $user === null ? null : $this->raw($ref);

        return $raw === null ? null : $raw + ['user_id' => $user, 'ref' => $ref];
    }

    /** Ang pinakabagong connection ng user (may naka-encrypt na token pa rin, para sa token()), o null. */
    public function get(int $userId): ?array
    {
        $mine = $this->of($userId);

        return $mine ? end($mine) : null;
    }

    /** @return array<string, array> ref => connection ng user, ayon sa pagkakagawa ng row */
    public function of(int $userId): array
    {
        return array_filter($this->all(), fn ($c) => $c['user_id'] === $userId);
    }

    /**
     * Lahat ng connection ng lahat ng user: ref => connection (may user_id at ref), ayon sa user id saka sa row, kaya ang
     * unang nakakabasa ng isang ad account ay gaya ng dati (ang pinakamababang user id).
     * @return array<string, array>
     */
    public function all(): array
    {
        $out = [];
        foreach (DB::table('app_settings')->where('key', 'like', self::PREFIX . '%')->orderBy('id')->get(['key', 'value']) as $r) {
            $ref  = substr($r->key, strlen(self::PREFIX));
            $id   = self::userOf($ref);
            $data = json_decode((string) $r->value, true);
            if ($id !== null && is_array($data)) $out[$ref] = $data + ['user_id' => $id, 'ref' => $ref];
        }
        uasort($out, fn ($a, $b) => $a['user_id'] <=> $b['user_id']);

        return $out;
    }

    /**
     * Bawat ad account ng lahat ng connection ($all = ang all() na nabasa na), isang beses: id => [id, name, who (mga
     * Facebook name na nakakabasa)].
     * @return array<string, array{id:string, name:string, who:list<string>}>
     */
    public function accounts(?array $all = null): array
    {
        $out = [];
        foreach ($all ?? $this->all() as $c) {
            $who = (string) ($c['fb_user_name'] ?? ('user ' . $c['user_id']));
            foreach ($c['ad_accounts'] ?? [] as $acc) {
                $id = (string) ($acc['id'] ?? '');
                if ($id === '') continue;
                $out[$id] ??= ['id' => $id, 'name' => (string) ($acc['name'] ?? ''), 'who' => []];
                if (!in_array($who, $out[$id]['who'], true)) $out[$id]['who'][] = $who;
            }
        }
        foreach (self::names(array_column($out, 'name', 'id')) as $id => $name) $out[(string) $id]['name'] = $name;

        return $out;
    }

    /**
     * Ang ipinapakitang pangalan: ang galing sa Meta, maliban kung wala o ID lang (may act_ man o wala); saka ang active
     * na ad_accounts row (/ads_manager/ad_account) ng ID na iyon, may act_ man o wala; saka ang ID. Binabasa lang ang ad_accounts.
     * @param array<string, string> $metaNames id => pangalan mula sa Meta
     * @return array<string, string> id => ipinapakitang pangalan
     */
    public static function names(array $metaNames): array
    {
        $bare = fn ($id) => preg_replace('/^act_/', '', trim((string) $id));
        $out = $missing = [];
        foreach ($metaNames as $id => $name) {
            $name = trim((string) $name);
            $out[$id] = $name;
            if ($name === '' || $bare($name) === $bare($id)) $missing[$bare($id)] = $id;
        }
        if (!$missing) return $out;

        $page = [];
        if (Schema::hasTable('ad_accounts')) {
            $ids = array_map('strval', array_keys($missing));
            foreach (DB::table('ad_accounts')->where('active', true)->whereIn('ad_account_id', array_merge($ids, array_map(fn ($i) => 'act_' . $i, $ids)))
                ->orderBy('id')->get(['ad_account_id', 'name']) as $r) {
                $page[$bare($r->ad_account_id)] ??= trim((string) $r->name);
            }
        }
        foreach ($missing as $b => $id) $out[$id] = ($page[$b] ?? '') !== '' ? $page[$b] : (string) $id;

        return $out;
    }

    public function token(array $connection): ?string
    {
        try {
            return Crypt::decryptString((string) ($connection['token'] ?? ''));
        } catch (Throwable) {
            return null; // naka-encrypt sa ibang key: parang walang token
        }
    }

    /** Pula na Reconnect: expired (190) o mag-e-expire sa loob ng WARN_DAYS. */
    public function needsReconnect(array $c): bool
    {
        if (($c['status'] ?? 'ok') === 'expired') return true;
        $exp = (int) ($c['expires_at'] ?? 0);

        return $exp > 0 && $exp < now()->addDays(self::WARN_DAYS)->timestamp;
    }

    /** Ang ipinapakita sa page: walang token. */
    public function strip(array $c): array
    {
        unset($c['token']);

        return $c;
    }

    /**
     * Talaan ng connect / disconnect / compare / write mode / ad accounts: sino at kailan (upload_logs, type
     * ads_manager_api_event). $details (hal. before / after) ay nasa protected_details.
     */
    public static function event(string $what, ?int $userId, ?array $details = null): void
    {
        UploadLog::create([
            'type' => 'ads_manager_api_event', 'disk' => 'api', 'path' => 'meta', 'original_name' => mb_substr($what, 0, 250),
            'status' => 'done', 'user_id' => $userId, 'started_at' => now(), 'finished_at' => now(), 'protected_details' => $details,
        ]);
    }

    /** Ang user id sa ref ("12" o "12.<fb_user_id>"), o null kapag hindi ref ng connection. */
    private static function userOf(string $ref): ?int
    {
        return preg_match('/^([1-9]\d*)(?:\.[A-Za-z0-9_-]+)?$/D', $ref, $m) ? (int) $m[1] : null;
    }

    private function raw(string $ref): ?array
    {
        $v = DB::table('app_settings')->where('key', self::PREFIX . $ref)->value('value');
        $d = $v === null ? null : json_decode((string) $v, true);

        return is_array($d) ? $d : null;
    }
}
