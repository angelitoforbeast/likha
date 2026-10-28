<?php

namespace App\Services\MetaAds;

use App\Models\UploadLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang Facebook na naka-connect sa bawat Likha user (Meta Ads). Isang app_settings row kada tao,
 * key = meta_ads.connection.<user id>, value = JSON: token (naka-encrypt), fb_user_id, fb_user_name, expires_at (unix),
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

    public function appId(): ?string
    {
        $id = trim((string) config('services.meta_ads.app_id'));

        return $id !== '' ? $id : null;
    }

    /** Facebook Login for Business: ang configuration (permissions) sa Meta app. Kapag wala, scope=ads_read pa rin. */
    public function configId(): ?string
    {
        $id = trim((string) config('services.meta_ads.config_id'));

        return $id !== '' ? $id : null;
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
        $app = ['client_id' => $this->appId(), 'client_secret' => (string) config('services.meta_ads.app_secret')];

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
        DB::table('app_settings')->updateOrInsert(['key' => self::PREFIX . $userId], ['value' => json_encode($data), 'updated_at' => now(), 'created_at' => now()]);

        return $this->strip($data);
    }

    public function update(int $userId, array $patch): void
    {
        $raw = $this->raw($userId);
        if ($raw === null) return;
        DB::table('app_settings')->where('key', self::PREFIX . $userId)->update(['value' => json_encode(array_merge($raw, $patch)), 'updated_at' => now()]);
    }

    public function disconnect(int $userId): void
    {
        DB::table('app_settings')->where('key', self::PREFIX . $userId)->delete();
    }

    /** Ang connection (may naka-encrypt na token pa rin, para sa token()), o null. */
    public function get(int $userId): ?array
    {
        $raw = $this->raw($userId);

        return $raw === null ? null : $raw + ['user_id' => $userId];
    }

    /** @return array<int, array> user id => connection, ayon sa user id */
    public function all(): array
    {
        $out = [];
        foreach (DB::table('app_settings')->where('key', 'like', self::PREFIX . '%')->orderBy('id')->get(['key', 'value']) as $r) {
            $id   = (int) substr($r->key, strlen(self::PREFIX));
            $data = json_decode((string) $r->value, true);
            if ($id > 0 && is_array($data)) $out[$id] = $data + ['user_id' => $id];
        }
        ksort($out);

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
        foreach ($all ?? $this->all() as $userId => $c) {
            $who = (string) ($c['fb_user_name'] ?? ('user ' . $userId));
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

    private function raw(int $userId): ?array
    {
        $v = DB::table('app_settings')->where('key', self::PREFIX . $userId)->value('value');
        $d = $v === null ? null : json_decode((string) $v, true);

        return is_array($d) ? $d : null;
    }
}
