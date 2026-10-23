<?php

namespace App\Services;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\MacroOutput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kinakabitan ng PSID ang orders ng isang page (huling 30 araw, walang laman ang botcake_psid) mula sa mga
 * available na Botcake customer ng page: (1) pangalan na iisang psid lang ang may-ari; (2) dalawa o higit pa sa
 * pangalan: ang phone ang nagpapasya kung iisa sa kanila ang may phone na iyon; walang kandidato sa pangalan: ang phone
 * na iisang customer lang ang may-ari; (3) kung hindi: ambiguous o no_match, walang isusulat. Hindi kailanman pumapalit
 * ang phone ng ibang tao sa ambiguous na pangalan: mas masama ang waybill / COD sa maling tao kaysa walang mensahe.
 * Order na walang pangalan at walang tugma: ang phone nito ang hinahanap sa customer values ng page, saka sa chat text (psidsByOrderPhone).
 * Kasabay ng PSID, isinusulat ang ad_id ng customer sa macro_output.botcake_ad_id, isang beses lang: ang ad na
 * nagdala ng customer bago umorder ang may kredito, kahit magbago pa ang ads niya.
 */
class BotcakeCustomerMatcher
{
    public const DAYS = 30;

    public function __construct(private PageSettings $settings)
    {
    }

    public static function normName(?string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s)), 'UTF-8');
    }

    /** Phone sa anyong 09XXXXXXXXX, o '' kapag hindi PH mobile. */
    public static function normPhone(?string $s): string
    {
        $d = preg_replace('/\D+/', '', (string) $s);
        if (strlen($d) === 12 && str_starts_with($d, '639')) $d = '0' . substr($d, 2);
        if (strlen($d) === 10 && str_starts_with($d, '9')) $d = '0' . $d;

        return preg_match('/^09\d{9}$/', $d) ? $d : '';
    }

    private function col(string $name): string
    {
        return DB::getDriverName() === 'pgsql' ? '"' . $name . '"' : '`' . $name . '`';
    }

    /**
     * @return array{by_name:int, by_phone:int, ambiguous:int, no_match:int, ad_filled:int}
     */
    public function matchPage(string $page): array
    {
        $out = ['by_name' => 0, 'by_phone' => 0, 'ambiguous' => 0, 'no_match' => 0, 'ad_filled' => 0];
        $key  = $this->settings->pageKey($page);
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';

        // Kandidato: available na API rows ng page (hindi inactive). pangalan → psids, phone → psids.
        $byName = [];
        $byPhone = [];
        $adOf = [];
        BotcakePsid::where('source', 'api')->where('status', 'available')
            ->whereRaw("LOWER($trim(page)) = ?", [$key])
            ->select('id', 'psid', 'fb_name', 'phone_numbers', 'ad_id')
            ->chunkById(2000, function ($rows) use (&$byName, &$byPhone, &$adOf) {
                foreach ($rows as $r) {
                    $adOf[$r->psid] = $r->ad_id;
                    $n = $this->normName($r->fb_name);
                    if ($n !== '') $byName[$n][$r->psid] = true;
                    foreach ((array) $r->phone_numbers as $ph) {
                        $p = $this->normPhone(is_scalar($ph) ? (string) $ph : '');
                        if ($p !== '') $byPhone[$p][$r->psid] = true;
                    }
                }
            });

        $since = now('Asia/Manila')->subDays(self::DAYS)->toDateString();
        $phoneCol = $this->col('PHONE NUMBER');
        $hasAdCol = Schema::hasColumn('macro_output', 'botcake_ad_id');
        $noName = [];

        MacroOutput::query()
            ->whereRaw('LOWER(' . $trim . '(' . $this->col('PAGE') . ')) = ?', [$key])
            ->where('ts_date', '>=', $since)
            ->where(fn ($w) => $w->whereNull('botcake_psid')->orWhere('botcake_psid', ''))
            ->selectRaw("id, ts_date, fb_name, $phoneCol AS phone")
            ->orderBy('id')
            ->chunkById(1000, function ($orders) use (&$out, &$noName, $byName, $byPhone, $adOf, $hasAdCol) {
                foreach ($orders as $o) {
                    [$psid, $how] = $this->decide(
                        array_keys($byName[$this->normName($o->fb_name)] ?? []),
                        ($phone = $this->normPhone($o->phone)) !== '' ? array_keys($byPhone[$phone] ?? []) : []
                    );

                    if ($psid === null) {
                        // Walang pangalan at walang tugma sa mga Botcake customer: susubukan sa phone (customer values, chat text).
                        if ($how === 'no_match' && trim((string) $o->fb_name) === '' && $phone !== '') {
                            $noName[$o->id] = [$phone, (string) $o->ts_date];
                            continue;
                        }
                        $out[$how]++;
                        continue;
                    }

                    if ($this->writePsid($o->id, $psid, $adOf, $hasAdCol)) $out[$how]++;
                }
            }, 'id');

        if ($noName) {
            $found = $this->psidsByOrderPhone($key, $noName);
            foreach ($noName as $id => $_) {
                if (isset($found[$id]) && $this->writePsid($id, $found[$id], $adOf, $hasAdCol)) $out['by_phone']++;
                else $out['no_match']++;
            }
        }

        // Ang may PSID na pero walang ad id (hal. PSID mula sa lumang sheet, na walang ad id): kunin ang ad_id ng API
        // customer ng parehong page at PSID. Kailangan ito ng per-ad na report. Plain SELECT muna ng mga id (walang lock),
        // saka UPDATE ayon sa id, hanggang 1000 bawat isa: hindi nila-lock ng UPDATE ang lahat ng bagong order ng page.
        // Ang PSID ay hindi ginagalaw, at ang may ad id na ay hindi pinapalitan (sinusuri sa mismong UPDATE).
        if ($hasAdCol) {
            $adSql = "SELECT bp.ad_id FROM botcake_psids bp WHERE bp.source = 'api' AND LOWER($trim(bp.page)) = ?"
                . " AND bp.psid = macro_output.botcake_psid AND bp.ad_id IS NOT NULL AND bp.ad_id <> ''";
            $emptyAd = "(botcake_ad_id IS NULL OR botcake_ad_id = '')";

            MacroOutput::query()
                ->whereRaw('LOWER(' . $trim . '(' . $this->col('PAGE') . ')) = ?', [$key])
                ->where('ts_date', '>=', $since)
                ->whereNotNull('botcake_psid')->where('botcake_psid', '<>', '')
                ->whereRaw($emptyAd)
                ->whereRaw("EXISTS ($adSql)", [$key])
                ->select('id')
                ->chunkById(1000, function ($orders) use (&$out, $adSql, $emptyAd, $key) {
                    $ids = $orders->pluck('id')->all();
                    $out['ad_filled'] += DB::update(
                        "UPDATE macro_output SET botcake_ad_id = ($adSql ORDER BY bp.id DESC LIMIT 1)"
                        . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ") AND $emptyAd AND EXISTS ($adSql)",
                        array_merge([$key], $ids, [$key])
                    );
                }, 'id');
        }

        return $out;
    }

    /**
     * Muling sinusuri ang laman sa mismong update: hindi kailanman pinapalitan ang may PSID na.
     * Ang botcake_ad_id ay naitatala isang beses lang (COALESCE): hindi napapalitan kahit ma-match ulit.
     */
    private function writePsid(int $id, string $psid, array $adOf, bool $hasAdCol): bool
    {
        return (bool) ($hasAdCol
            ? DB::update(
                "UPDATE macro_output SET botcake_psid = ?, botcake_ad_id = COALESCE(botcake_ad_id, ?) WHERE id = ? AND (botcake_psid IS NULL OR botcake_psid = '')",
                [$psid, $adOf[$psid] ?? null, $id]
            )
            : MacroOutput::whereKey($id)
                ->where(fn ($w) => $w->whereNull('botcake_psid')->orWhere('botcake_psid', ''))
                ->update(['botcake_psid' => $psid]));
    }

    /**
     * Para sa mga order na walang pangalan: ang PSID ayon sa phone, sa pagkakasunod (1) GPT PHONE NUMBER sa customer values
     * ng page, (2) mensahe ng customer ng page (active lang) na may phone na iyon. Iisang PSID lang ang tinatanggap sa bawat
     * hakbang; kapag dalawa o higit pa sa (1), humihinto na: hindi na bumababa sa chat text. Walang kandidato = walang sagot.
     * @param array<int, array{0:string, 1:string}> $orders order id => [phone, ts_date]
     * @return array<int, string> order id => psid
     */
    private function psidsByOrderPhone(string $pageKey, array $orders): array
    {
        $pageId = null;
        foreach (BotcakeChat::pageMap() as $id => $name) {
            if ($this->settings->pageKey($name) === $pageKey) { $pageId = (string) $id; break; }
        }
        if ($pageId === null) return [];

        $phones = array_values(array_unique(array_column($orders, 0)));
        $byValue = [];
        if (Schema::hasTable('chatbot_customer_values')) {
            DB::table('chatbot_customer_values')->where('page_id', (int) $pageId)->where('key', 'gpt phone number')
                ->whereNotNull('value')->select('id', 'psid', 'value')
                ->chunkById(2000, function ($rows) use (&$byValue, $phones) {
                    foreach ($rows as $r) {
                        $p = $this->normPhone((string) $r->value);
                        if ($p !== '' && in_array($p, $phones, true)) $byValue[$p][(string) $r->psid] = true;
                    }
                }, 'id');
        }

        $dates = array_filter(array_column($orders, 1));
        $from = $dates ? \Carbon\Carbon::parse(min($dates))->subDays(3)->startOfDay() : null;
        $to = $dates ? \Carbon\Carbon::parse(max($dates))->addDays(2)->startOfDay() : null;
        $byChat = [];
        foreach ($phones as $phone) {
            if (isset($byValue[$phone]) || !$from || !Schema::hasTable('botcake_chats')) continue;
            // Unang salaan sa huling 4 na digit ng phone; ang eksaktong pagsusuri ay sa PHP (OrderPhone::numbers).
            BotcakeChat::active()->where('page_id', $pageId)->whereBetween('received_at', [$from, $to])
                ->where('message', 'like', '%' . substr($phone, -4) . '%')
                ->select('id', 'psid', 'message')
                ->chunkById(1000, function ($rows) use (&$byChat, $phone) {
                    foreach ($rows as $r) {
                        if (in_array($phone, OrderPhone::numbers($r->message), true)) $byChat[$phone][(string) $r->psid] = true;
                    }
                }, 'id');
        }

        $out = [];
        foreach ($orders as $id => [$phone]) {
            $hits = array_keys($byValue[$phone] ?? $byChat[$phone] ?? []);
            if (count($hits) === 1 && $hits[0] !== '') $out[$id] = $hits[0];
        }

        return $out;
    }
    /**
     * @param array<int, string> $nameHits mga psid na may parehong pangalan
     * @param array<int, string> $phoneHits mga psid na may parehong phone
     * @return array{0:?string, 1:string} [psid o null, by_name|by_phone|ambiguous|no_match]
     */
    public function decide(array $nameHits, array $phoneHits): array
    {
        if (count($nameHits) === 1) return [$nameHits[0], 'by_name'];

        if (count($nameHits) > 1) {
            // Ang phone ay nagpapasya lang sa pagitan ng mga may parehong pangalan, hindi nagdadala ng ibang tao.
            $both = array_values(array_intersect($nameHits, $phoneHits));
            return count($both) === 1 ? [$both[0], 'by_phone'] : [null, 'ambiguous'];
        }

        if (count($phoneHits) === 1) return [$phoneHits[0], 'by_phone'];

        return [null, count($phoneHits) > 1 ? 'ambiguous' : 'no_match'];
    }

    /**
     * Para sa oras-oras na sheet import: lahat ng page na may Botcake page id at token.
     * @return array{by_name:int, by_phone:int, ambiguous:int, no_match:int, ad_filled:int}
     */
    public function matchPagesWithTokens(): array
    {
        $total = ['by_name' => 0, 'by_phone' => 0, 'ambiguous' => 0, 'no_match' => 0, 'ad_filled' => 0];
        foreach ($this->settings->pagesWith('botcake', ['page_id', 'token']) as $page) {
            $r = $this->matchPage($page);
            foreach ($total as $k => $_) $total[$k] += $r[$k];
        }

        return $total;
    }
}
