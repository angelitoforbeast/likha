<?php

namespace App\Services\MetaAds;

use App\Models\BotcakeChat;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang Ads Manager report (per ad, per day) mula sa Marketing API, sa parehong mga column na pinupuno ng XLSX upload
 * (ProcessAdsManagerReportsUpload): isang row kada day + ad_id.
 */
class Insights
{
    public const FIELDS = 'account_id,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,reach,impressions,'
        . 'inline_link_clicks,spend,actions,cost_per_action_type,results,cost_per_result,attribution_setting,date_start,date_stop';

    public const AD_FIELDS = 'id,effective_status,'
        . 'adset{id,effective_status,daily_budget,lifetime_budget,start_time,end_time,optimization_goal},'
        . 'campaign{id,effective_status},creative{id,title,body,object_story_spec,page_welcome_message,actor_id,effective_object_story_id}';

    public const MESSAGING = 'onsite_conversion.messaging_conversation_started_7d';
    /** Purchases: ang unang meron sa actions (dapat patunayan sa pilot kung alin ang tugma sa export). */
    public const PURCHASE_KEYS = ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_web_purchase'];

    /**
     * Ang Result type ng export, mula sa optimization_goal ng ad set (walang sariling field ang API). Sa production
     * (Oct 26, 208 row): MESSAGING_PURCHASE_CONVERSION → "Meta purchases" kahit OUTCOME_SALES o OUTCOME_ENGAGEMENT ang
     * campaign; sa 30 araw ng upload ay "Meta purchases", "Messaging conversations started" at "Meta purchase
     * conversion value" lang ang lumalabas.
     */
    public const RESULT_TYPES = [
        'MESSAGING_PURCHASE_CONVERSION' => 'Meta purchases',
        'OFFSITE_CONVERSIONS'           => 'Meta purchases',
        'ONSITE_CONVERSIONS'            => 'Meta purchases',
        'VALUE'                         => 'Meta purchase conversion value',
        'CONVERSATIONS'                 => 'Messaging conversations started',
        'LINK_CLICKS'         => 'Link clicks',
        'LANDING_PAGE_VIEWS'  => 'Landing page views',
        'REACH'               => 'Reach',
        'IMPRESSIONS'         => 'Impressions',
        'POST_ENGAGEMENT'     => 'Post engagements',
        'THRUPLAY'            => 'ThruPlays',
        'LEAD_GENERATION'     => 'Leads',
    ];

    /**
     * effective_status ng ad set → ad_set_delivery ng export; ang iba (ARCHIVED, DELETED, WITH_ISSUES, IN_PROCESS, …)
     * ay not_delivering. Sa production (Oct 26): ACTIVE → active, PAUSED → inactive, CAMPAIGN_PAUSED → not_delivering.
     * Ang campaign_delivery ay active lang kapag ACTIVE ang campaign at ang ad set ng ad (ACTIVE campaign + PAUSED ad set
     * = not_delivering sa export); kung hindi, not_delivering. Ang bihirang campaign "inactive" ng export ay hindi ginagawa.
     */
    public const AD_SET_DELIVERY = ['ACTIVE' => 'active', 'PAUSED' => 'inactive'];
    public const NOT_DELIVERING = 'not_delivering';

    public const ATTRIBUTION = [
        '7d_click_1d_view' => '7-day click or 1-day view',
        '7d_click'         => '7-day click',
        '1d_click'         => '1-day click',
        '1d_click_1d_view' => '1-day click or 1-day view',
    ];

    public function __construct(private Graph $graph)
    {
    }

    /**
     * Ang mga row ng isang ad account sa from..to. $details = true: kasama ang ad set, campaign, creative at pangalan ng
     * page (kailangan sa pagsulat; ang Compare ay mga numero lang). @throws GraphException
     */
    public function rows(string $accountId, string $from, string $to, string $token, bool $details = true): array
    {
        $insights = $this->graph->all('act_' . $accountId . '/insights', [
            'level'          => 'ad',
            'time_increment' => 1,
            'time_range'     => json_encode(['since' => $from, 'until' => $to]),
            'fields'         => self::FIELDS,
            'limit'          => 500,
        ], $token);

        $ads = $pages = [];
        if ($details && $insights) {
            $ads   = $this->ads($accountId, array_values(array_unique(array_map(fn ($r) => (string) ($r['ad_id'] ?? ''), $insights))), $token);
            $pages = $this->pageNames($ads);
        }

        $out = [];
        foreach ($insights as $r) {
            $row = $this->map($accountId, $r, $ads[(string) ($r['ad_id'] ?? '')] ?? null, $pages);
            if ($row !== null) $out[] = $row;
        }

        return $out;
    }

    /** ad id => detalye, 50 kada tawag. */
    private function ads(string $accountId, array $adIds, string $token): array
    {
        $out = [];
        foreach (array_chunk(array_filter($adIds), 50) as $chunk) {
            $list = $this->graph->all('act_' . $accountId . '/ads', [
                'fields'    => self::AD_FIELDS,
                'filtering' => json_encode([['field' => 'ad.id', 'operator' => 'IN', 'value' => $chunk]]),
                'limit'     => 100,
            ], $token);
            foreach ($list as $ad) $out[(string) ($ad['id'] ?? '')] = $ad;
        }

        return $out;
    }

    /**
     * page id => pangalan ng Likha page: ang active na botcake.page_id sa page_settings (ang tanging Facebook page ID na
     * naka-tala sa Likha; pareho ng mapa ng Botcake chats). Hindi tinatanong ang Meta: ang Page name ay kailangan ng
     * pages_read_engagement, na wala sa app.
     */
    private function pageNames(array $ads): array
    {
        if (!$ads || !Schema::hasTable('page_settings')) return [];
        $map = BotcakeChat::pageMap();
        $out = [];
        foreach ($ads as $ad) {
            $id = self::pageId($ad);
            if ($id !== null && isset($map[$id])) $out[$id] = (string) $map[$id];
        }

        return $out;
    }

    /** Ang page ng ad: object_story_spec.page_id, kung wala ay actor_id, kung wala ay ang <page id> ng "<page id>_<post id>". */
    public static function pageId(?array $ad): ?string
    {
        $cr = $ad['creative'] ?? [];
        foreach ([$cr['object_story_spec']['page_id'] ?? null, $cr['actor_id'] ?? null, explode('_', (string) ($cr['effective_object_story_id'] ?? ''))[0]] as $id) {
            $id = self::str($id);
            if ($id !== null) return $id;
        }

        return null;
    }

    /** Isang insights row (+ detalye ng ad) → mga column ng ads_manager_reports, gaya ng normalizeRow ng upload. */
    public function map(string $accountId, array $r, ?array $ad, array $pages): ?array
    {
        $day  = (string) ($r['date_start'] ?? '');
        $adId = trim((string) ($r['ad_id'] ?? ''));
        if ($day === '' || $adId === '') return null;

        $spend   = self::dec($r['spend'] ?? null);
        $results = self::listValue($r['results'] ?? null);
        $cpr     = self::listValue($r['cost_per_result'] ?? null, true);
        if ($cpr === null && $results) $cpr = round((float) $spend / $results, 2);

        $row = [
            'day'                             => $day,
            'ad_id'                           => $adId,
            'account_id'                      => (string) ($r['account_id'] ?? $accountId),
            'campaign_id'                     => self::str($r['campaign_id'] ?? null),
            'campaign_name'                   => self::str($r['campaign_name'] ?? null),
            'ad_set_id'                       => self::str($r['adset_id'] ?? null),
            'ad_set_name'                     => self::str($r['adset_name'] ?? null),
            'amount_spent_php'                => $spend,
            'reach'                           => self::int($r['reach'] ?? null),
            'impressions'                     => self::int($r['impressions'] ?? null),
            'link_clicks'                     => self::int($r['inline_link_clicks'] ?? null),
            'messaging_conversations_started' => self::action($r['actions'] ?? [], [self::MESSAGING]),
            'purchases'                       => self::action($r['actions'] ?? [], self::PURCHASE_KEYS),
            'results'                         => $results,
            'cost_per_result'                 => $cpr,
            'attribution_setting'             => isset($r['attribution_setting']) ? (self::ATTRIBUTION[$r['attribution_setting']] ?? (string) $r['attribution_setting']) : null,
            'reporting_starts'                => $day . ' 00:00:00',
            'reporting_ends'                  => (string) ($r['date_stop'] ?? $day) . ' 00:00:00',
        ];

        if ($ad !== null) {
            $set = $ad['adset'] ?? [];
            $cr  = $ad['creative'] ?? [];
            $oss = $cr['object_story_spec'] ?? [];
            $goal = strtoupper((string) ($set['optimization_goal'] ?? ''));

            $row['page_id']            = self::pageId($ad); // hindi column: para sa talaan ng nilaktawan (Sync)
            $row['page_name']          = $pages[(string) $row['page_id']] ?? null;
            $row['ad_set_delivery']    = self::delivery($set['effective_status'] ?? null);
            $campaign                  = self::delivery($ad['campaign']['effective_status'] ?? null);
            $row['campaign_delivery']  = $campaign === null ? null
                : ($campaign === 'active' && $row['ad_set_delivery'] === 'active' ? 'active' : self::NOT_DELIVERING);
            $row['result_type']        = $goal === '' ? null : (self::RESULT_TYPES[$goal] ?? ucfirst(strtolower(str_replace('_', ' ', $goal))));
            [$row['ad_set_budget'], $row['ad_set_budget_type']] = self::budget($set);
            $row['starts']             = self::time($set['start_time'] ?? null);
            $row['ends']               = self::time($set['end_time'] ?? null);
            $row['headline']           = self::str($cr['title'] ?? ($oss['link_data']['name'] ?? ($oss['video_data']['title'] ?? null)));
            $row['body_ad_settings']   = self::str($cr['body'] ?? ($oss['link_data']['message'] ?? ($oss['video_data']['message'] ?? null)));
            [$row['welcome_message'], $row['quick_reply_1'], $row['quick_reply_2'], $row['quick_reply_3']] =
                self::welcome($cr['page_welcome_message'] ?? ($oss['link_data']['page_welcome_message'] ?? ($oss['video_data']['page_welcome_message'] ?? null)));
        }

        return $row;
    }

    /** Ang effective_status → salita ng export (AD_SET_DELIVERY, kung wala ay not_delivering); walang status = null. */
    private static function delivery(?string $status): ?string
    {
        if ($status === null || $status === '') return null;

        return self::AD_SET_DELIVERY[strtoupper($status)] ?? self::NOT_DELIVERING;
    }

    /** Ang budget ay nasa sentimo (minor unit) sa API; ang export ay piso. */
    private static function budget(array $set): array
    {
        if ((int) ($set['daily_budget'] ?? 0) > 0) return [round((int) $set['daily_budget'] / 100, 2), 'Daily'];
        if ((int) ($set['lifetime_budget'] ?? 0) > 0) return [round((int) $set['lifetime_budget'] / 100, 2), 'Lifetime'];

        return [null, 'Using campaign budget'];
    }

    /** Welcome message at hanggang 3 quick reply / ice breaker mula sa page_welcome_message (JSON). */
    private static function welcome(mixed $raw): array
    {
        $none = [null, null, null, null];
        if ($raw === null || $raw === '') return $none;
        $j = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($j)) return [self::str($raw), null, null, null];

        $msg  = $j['text_format']['message'] ?? $j['message'] ?? [];
        $text = self::str($msg['text'] ?? null);
        $replies = [];
        foreach (array_merge($msg['ice_breakers'] ?? [], $msg['quick_replies'] ?? []) as $q) {
            $t = self::str(is_array($q) ? ($q['title'] ?? null) : $q);
            if ($t !== null) $replies[] = $t;
        }

        return [$text, $replies[0] ?? null, $replies[1] ?? null, $replies[2] ?? null];
    }

    /** Ang kabuuan ng values ng results / cost_per_result ([{indicator, values:[{value}]}]). */
    private static function listValue(mixed $list, bool $decimal = false): int|float|null
    {
        if (!is_array($list) || $list === []) return null;
        $sum = null;
        foreach ($list as $item) {
            foreach (($item['values'] ?? []) as $v) {
                if (isset($v['value']) && is_numeric($v['value'])) $sum = ($sum ?? 0) + (float) $v['value'];
            }
            if ($decimal) break; // isang cost per result lang ang may saysay
        }
        if ($sum === null) return null;

        return $decimal ? round($sum, 2) : (int) round($sum);
    }

    private static function action(array $actions, array $keys): ?int
    {
        foreach ($keys as $k) {
            foreach ($actions as $a) {
                if (($a['action_type'] ?? null) === $k && is_numeric($a['value'] ?? null)) return (int) round((float) $a['value']);
            }
        }

        return null;
    }

    private static function time(?string $iso): ?string
    {
        if ($iso === null || $iso === '') return null;
        try {
            return Carbon::parse($iso)->timezone('Asia/Manila')->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private static function str(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' ? null : $v;
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    private static function dec(mixed $v): ?float
    {
        return is_numeric($v) ? round((float) $v, 2) : null;
    }
}
