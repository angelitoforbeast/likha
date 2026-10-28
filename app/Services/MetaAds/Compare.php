<?php

namespace App\Services\MetaAds;

use Illuminate\Support\Facades\DB;

/**
 * Shadow: ang mga numero ng API laban sa mga row ng upload sa ads_manager_reports (parehong day + ad_id), kada ad
 * account. Walang isinusulat sa ads_manager_reports.
 */
class Compare
{
    /** Ang mga numerong inihahambing (column ng ads_manager_reports). */
    public const FIELDS = ['amount_spent_php', 'results', 'reach', 'link_clicks', 'messaging_conversations_started'];
    /** Ilang row ang ipinapakita kada listahan (lahat ay binibilang pa rin). */
    public const LIST_MAX = 50;

    public function __construct(private Connections $connections, private Insights $insights)
    {
    }

    /** @return array{from:string, to:string, accounts:array, problems:array} */
    public function run(string $from, string $to): array
    {
        $accounts = $problems = [];
        // Ang mga naka-check na account; kapag lahat ay naka-uncheck, lahat (para makatulong sa pagpili).
        $all      = $this->connections->all();
        $selected = array_flip(Sync::accounts(Sync::current($all)));
        $names    = array_column($this->connections->accounts($all), 'name', 'id');
        foreach ($all as $userId => $c) {
            $who   = (string) ($c['fb_user_name'] ?? ('user ' . $userId));
            $token = $this->connections->token($c);
            if (($c['status'] ?? 'ok') === 'expired' || $token === null) {
                $problems[] = "{$who}: needs Reconnect";
                continue;
            }
            foreach ($c['ad_accounts'] ?? [] as $acc) {
                $id = (string) ($acc['id'] ?? '');
                if ($id === '' || isset($accounts[$id]) || ($selected && !isset($selected[$id]))) continue;
                try {
                    $accounts[$id] = $this->account($id, (string) ($names[$id] ?? $id), $who, $this->insights->rows($id, $from, $to, $token, false), $from, $to);
                } catch (GraphException $e) {
                    if ($e->tokenExpired()) {
                        $this->connections->update($userId, ['status' => 'expired', 'last_error' => $e->getMessage()]);
                        $problems[] = "{$who}: token expired (Reconnect)";
                        break;
                    }
                    $problems[] = "act_{$id}: " . ($e->rateLimited() ? 'rate limited, skipped' : $e->getMessage());
                }
            }
        }

        return ['from' => $from, 'to' => $to, 'accounts' => array_values($accounts), 'problems' => $problems];
    }

    private function account(string $id, string $name, string $who, array $apiRows, string $from, string $to): array
    {
        $api = [];
        foreach ($apiRows as $r) $api[$r['day'] . '|' . $r['ad_id']] = $r;

        // Ang mga upload row ng account: ang ad na nasa API, o ang ad na naka-tala sa account na ito (ad_campaign_creatives).
        $adIds = array_values(array_unique(array_map(fn ($r) => $r['ad_id'], $apiRows)));
        $q = DB::table('ads_manager_reports')->whereBetween('day', [$from, $to])
            ->where(function ($w) use ($adIds, $id) {
                $w->whereIn('ad_id', DB::table('ad_campaign_creatives')->where('account_id', $id)->whereNotNull('ad_id')->select('ad_id'));
                foreach (array_chunk($adIds, 500) as $chunk) $w->orWhereIn('ad_id', $chunk);
            });
        $upload = [];
        foreach ($q->get(array_merge(['day', 'ad_id'], self::FIELDS)) as $r) {
            $upload[substr((string) $r->day, 0, 10) . '|' . $r->ad_id] = (array) $r;
        }

        $onlyApi = $onlyUpload = $differ = [];
        $matched = 0;
        foreach ($api as $k => $a) {
            if (!isset($upload[$k])) { $onlyApi[] = self::brief($a); continue; }
            $diffs = [];
            foreach (self::FIELDS as $f) {
                $av = round((float) ($a[$f] ?? 0), 2);
                $uv = round((float) ($upload[$k][$f] ?? 0), 2);
                if (abs($av - $uv) >= 0.005) $diffs[$f] = ['api' => $av, 'upload' => $uv, 'pct' => $uv != 0.0 ? round(($av - $uv) / $uv * 100, 1) : null];
            }
            if ($diffs) $differ[] = ['day' => $a['day'], 'ad_id' => $a['ad_id'], 'fields' => $diffs];
            else $matched++;
        }
        foreach ($upload as $k => $u) {
            if (!isset($api[$k])) $onlyUpload[] = self::brief($u);
        }

        $sum = fn (array $rows) => round(array_sum(array_map(fn ($r) => (float) ($r['amount_spent_php'] ?? 0), $rows)), 2);

        return [
            'account_id' => $id, 'name' => $name, 'who' => $who,
            'api_rows' => count($api), 'upload_rows' => count($upload), 'matched' => $matched,
            'api_spend' => $sum($api), 'upload_spend' => $sum($upload),
            'only_api_count' => count($onlyApi), 'only_upload_count' => count($onlyUpload), 'differ_count' => count($differ),
            'only_api' => array_slice($onlyApi, 0, self::LIST_MAX),
            'only_upload' => array_slice($onlyUpload, 0, self::LIST_MAX),
            'differ' => array_slice($differ, 0, self::LIST_MAX),
        ];
    }

    private static function brief(array $r): array
    {
        return ['day' => substr((string) $r['day'], 0, 10), 'ad_id' => (string) $r['ad_id'], 'spend' => round((float) ($r['amount_spent_php'] ?? 0), 2)];
    }
}
