<?php

namespace App\Services;

use App\Models\BotcakePsid;
use App\Models\MacroOutput;
use Illuminate\Support\Facades\DB;

/**
 * Kinakabitan ng PSID ang orders ng isang page (huling 30 araw, walang laman ang botcake_psid) mula sa mga
 * available na Botcake customer ng page: (1) pangalan na iisang psid lang ang may-ari, (2) kung hindi, phone na
 * iisang customer lang ang may-ari, (3) dalawa o higit pang kandidato = walang isusulat (ambiguous). Mas masama
 * ang waybill / COD sa maling tao kaysa walang mensahe. Hindi kailanman pinapalitan ang may laman na.
 */
class BotcakeCustomerMatcher
{
    public const DAYS = 30;

    public function __construct(private PageSettings $settings)
    {
    }

    public function normName(?string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s)), 'UTF-8');
    }

    /** Phone sa anyong 09XXXXXXXXX, o '' kapag hindi PH mobile. */
    public function normPhone(?string $s): string
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
     * @param bool $write false = silip lang (para sa page): walang isusulat
     * @return array{by_name:int, by_phone:int, ambiguous:int, no_match:int, unmatched:array<int, array{id:int, fb_name:string, phone:string, ts_date:?string, reason:string}>}
     */
    public function matchPage(string $page, bool $write = true): array
    {
        $out = ['by_name' => 0, 'by_phone' => 0, 'ambiguous' => 0, 'no_match' => 0, 'unmatched' => []];
        $key  = $this->settings->pageKey($page);
        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';

        // Kandidato: available na API rows ng page. pangalan → psids, phone → psids.
        $byName = [];
        $byPhone = [];
        BotcakePsid::where('source', 'api')->where('status', 'available')
            ->whereRaw("LOWER($trim(page)) = ?", [$key])
            ->select('id', 'psid', 'fb_name', 'phone_numbers')
            ->chunkById(2000, function ($rows) use (&$byName, &$byPhone) {
                foreach ($rows as $r) {
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

        MacroOutput::query()
            ->whereRaw('LOWER(' . $trim . '(' . $this->col('PAGE') . ')) = ?', [$key])
            ->where('ts_date', '>=', $since)
            ->where(fn ($w) => $w->whereNull('botcake_psid')->orWhere('botcake_psid', ''))
            ->selectRaw("id, fb_name, ts_date, $phoneCol AS phone")
            ->orderBy('id')
            ->chunkById(1000, function ($orders) use (&$out, $byName, $byPhone, $write) {
                foreach ($orders as $o) {
                    $nameHits  = array_keys($byName[$this->normName($o->fb_name)] ?? []);
                    $phone     = $this->normPhone($o->phone);
                    $phoneHits = $phone !== '' ? array_keys($byPhone[$phone] ?? []) : [];

                    if (count($nameHits) === 1) {
                        [$psid, $how] = [$nameHits[0], 'by_name'];
                    } elseif (count($phoneHits) === 1) {
                        [$psid, $how] = [$phoneHits[0], 'by_phone'];
                    } else {
                        $how = (count($nameHits) > 1 || count($phoneHits) > 1) ? 'ambiguous' : 'no_match';
                        $out[$how]++;
                        $out['unmatched'][] = [
                            'id' => (int) $o->id, 'fb_name' => (string) $o->fb_name, 'phone' => (string) $o->phone,
                            'ts_date' => $o->ts_date ? (string) $o->ts_date : null, 'reason' => $how,
                        ];
                        continue;
                    }

                    if ($write) {
                        // Muling sinusuri ang laman sa mismong update: hindi kailanman pinapalitan ang may PSID na.
                        $done = MacroOutput::whereKey($o->id)
                            ->where(fn ($w) => $w->whereNull('botcake_psid')->orWhere('botcake_psid', ''))
                            ->update(['botcake_psid' => $psid]);
                        if ($done) $out[$how]++;
                    } else {
                        $out[$how]++;
                    }
                }
            }, 'id');

        return $out;
    }

    /**
     * Para sa oras-oras na sheet import: lahat ng page na may Botcake page id at token.
     * @return array{by_name:int, by_phone:int, ambiguous:int, no_match:int}
     */
    public function matchPagesWithTokens(): array
    {
        $total = ['by_name' => 0, 'by_phone' => 0, 'ambiguous' => 0, 'no_match' => 0];
        foreach ($this->settings->pagesWith('botcake', ['page_id', 'token']) as $page) {
            $r = $this->matchPage($page);
            foreach ($total as $k => $_) $total[$k] += $r[$k];
        }

        return $total;
    }
}
