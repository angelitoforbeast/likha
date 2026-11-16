<?php

namespace App\Services\MetaAds;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ang Data Deletion Callback ng Meta app (spec 282). Ang Meta ay nagpo-POST ng signed_request kapag inalis ng tao ang
 * app sa Facebook settings o humingi siya ng deletion: "<sig>.<payload>", parehong base64url; ang sig ay HMAC-SHA256 ng
 * payload (ang base64url na string mismo) gamit ang App Secret (AppConfig). Ang payload ay may algorithm at user_id
 * (app-scoped Facebook ID).
 *
 * Bawat valid na request ay isang app_settings row: key meta_ads.deletion.<code>, value JSON {deleted_at, count,
 * fb_user_sha256}. Walang Facebook ID na naka-save nang buo (sha256 lang), walang token, walang pangalan.
 */
class DataDeletion
{
    public const PREFIX = 'meta_ads.deletion.';

    public function __construct(private Connections $connections)
    {
    }

    /**
     * Ang user_id sa isang valid na signed_request, o null kapag mali (pirma, algorithm, hugis) o walang App Secret.
     * Walang sinasabi kung bakit: ang tumatawag ay sumasagot ng 400 lang.
     */
    public function userId(mixed $signedRequest): ?string
    {
        $secret = AppConfig::appSecret();
        if ($secret === null || !is_string($signedRequest) || substr_count($signedRequest, '.') !== 1) return null;

        [$encodedSig, $payload] = explode('.', $signedRequest, 2);
        $sig  = self::base64url($encodedSig);
        $json = self::base64url($payload);
        if ($sig === null || $json === null) return null;

        $data = json_decode($json, true);
        if (!is_array($data) || ($data['algorithm'] ?? null) !== 'HMAC-SHA256') return null;
        if (!hash_equals(hash_hmac('sha256', $payload, $secret, true), $sig)) return null;

        $userId = $data['user_id'] ?? null;
        if (!is_string($userId) && !is_int($userId)) return null;
        $userId = trim((string) $userId);

        return $userId !== '' ? $userId : null;
    }

    /**
     * Binubura ang bawat connection na may ganitong fb_user_id (Connections::disconnect) at itinatala ang deletion.
     * Ibinabalik ang confirmation code.
     */
    public function delete(string $fbUserId): string
    {
        $count = 0;
        foreach ($this->connections->all() as $userId => $c) {
            if ((string) ($c['fb_user_id'] ?? '') !== $fbUserId) continue;
            $this->connections->disconnect((int) $userId);
            $count++;
        }

        $code = bin2hex(random_bytes(10));
        DB::table('app_settings')->insert([
            'key'        => self::PREFIX . $code,
            'value'      => json_encode(['deleted_at' => now()->toIso8601String(), 'count' => $count, 'fb_user_sha256' => hash('sha256', $fbUserId)]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Para makita sa "API runs and changes" ng card kung bakit nawala ang isang connection. Code at bilang lang.
        Connections::event("meta data deletion: {$count} connection(s) removed (code {$code})", null, ['code' => $code, 'count' => $count]);

        return $code;
    }

    /** Ang naitalang deletion ng code na ito ({deleted_at, count}), o null. */
    public function find(string $code): ?array
    {
        if (!preg_match('/^[0-9a-f]{20}$/', $code)) return null;
        try {
            $v = DB::table('app_settings')->where('key', self::PREFIX . $code)->value('value');
        } catch (Throwable) {
            return null;
        }
        $d = $v === null ? null : json_decode((string) $v, true);

        return is_array($d) ? ['deleted_at' => (string) ($d['deleted_at'] ?? ''), 'count' => (int) ($d['count'] ?? 0)] : null;
    }

    public static function statusUrl(string $code): string
    {
        return rtrim((string) config('app.url'), '/') . '/meta/data-deletion/' . $code;
    }

    private static function base64url(string $s): ?string
    {
        if ($s === '' || !preg_match('/^[A-Za-z0-9_-]+=*$/', $s)) return null;
        $out = base64_decode(strtr($s, '-_', '+/'), true);

        return $out === false ? null : $out;
    }
}
