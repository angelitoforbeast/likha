<?php

namespace App\Services;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidSetting;
use App\Models\MacroOutput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ang Botcake PSID na naghihintay ng order: itinatala sa botcake_psids, sinusubukan ulit kada import run,
 * at kinokopya sa reorder ng parehong customer. Ang macro_output.botcake_psid pa rin ang sinusulatan, gaya dati.
 *
 * Conflict: kapag dalawa o higit pang magkaibang PSID ang iisang PAGE + fb_name, walang ikinakabit:
 * mas mabuti nang walang message kaysa maipadala ang waybill at COD sa maling tao.
 */
class BotcakePsidMatcher
{
    public const WAITING  = 'waiting';
    public const MATCHED  = 'matched';
    public const EXPIRED  = 'expired';
    public const CONFLICT = 'conflict';

    public const WAIT_DAYS = 30;
    public const REORDER_DAYS = 30;

    /** Text sa column J ng sheet ayon sa status ng record. */
    public const SHEET_LABELS = [
        self::MATCHED  => 'imported',
        self::WAITING  => 'waiting',
        self::CONFLICT => 'conflict',
        self::EXPIRED  => 'expired',
    ];

    /** Mga J text na puwedeng burahin sa sheet (kapag kapareho ng record ang laman). */
    public const DELETABLE_LABELS = ['imported', 'waiting', 'conflict', 'expired'];

    public function norm(?string $s): string
    {
        return preg_replace('/\s+/', ' ', trim((string) ($s ?? '')));
    }

    protected function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }

    protected function col(string $name): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return '"' . str_replace('"', '""', $name) . '"';
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Ang order rows ng customer, gaya ng dating rule: eksaktong PAGE + fb_name muna, saka case-insensitive.
     * Null kapag wala pang order. Ang $since ay naglilimita sa case-insensitive na hanap (ts_date) para hindi
     * buong macro_output ang binabasa kada retry ng waiting.
     */
    public function orderQuery(string $page, string $name, ?string $since = null): ?Builder
    {
        $exact = MacroOutput::where('PAGE', $page)->where('fb_name', $name);
        if ((clone $exact)->exists()) return $exact;

        $ci = MacroOutput::whereRaw('LOWER(' . $this->col('PAGE') . ') = ?', [$this->lower($page)])
            ->whereRaw('LOWER(' . $this->col('fb_name') . ') = ?', [$this->lower($name)]);
        if ($since !== null) {
            $ci->where('ts_date', '>=', $since);
        }

        return (clone $ci)->exists() ? $ci : null;
    }

    protected function tableQuery(string $page, string $name): Builder
    {
        // Sheet records lang: ang Botcake API customers (source api) ay lookup, hindi kasaysayan ng sheet.
        return BotcakePsid::where('source', 'sheet')
            ->whereRaw('LOWER(page) = ?', [$this->lower($page)])
            ->whereRaw('LOWER(fb_name) = ?', [$this->lower($name)]);
    }

    protected function emptyPsid(Builder $q): Builder
    {
        return $q->where(function ($w) {
            $w->whereNull('botcake_psid')->orWhere('botcake_psid', '');
        });
    }

    /**
     * Isang sheet row (kumpleto ang page, pangalan at PSID): itala at subukang ikabit.
     * @return string ang status ng record
     */
    public function recordSheetRow(BotcakePsidSetting $setting, string $page, string $name, string $psid, string $sheetDate): string
    {
        $rec = $this->recordWaiting($setting, $page, $name, $psid, $sheetDate);

        if ($rec->status === self::WAITING) {
            $this->evaluate($rec, null);
        }

        return $rec->status;
    }

    /**
     * Lumang sheet row na "imported" na (naisulat na sa macro_output noon): itala bilang matched para mabura
     * rin sa sheet. Walang sinusulat sa macro_output; ang matched_rows ay ang bilang ng order row na may hawak
     * na ng PSID ngayon. Kapag may ibang PSID nang naitala para sa customer → conflict, gaya ng dati.
     * @return string|null ang status, o null kapag naitala na ang row
     */
    public function recordImported(BotcakePsidSetting $setting, string $page, string $name, string $psid, string $sheetDate): ?string
    {
        $exists = BotcakePsid::where('setting_id', $setting->id)
            ->where('page', $page)->where('fb_name', $name)->where('psid', $psid)
            ->where('sheet_date', $sheetDate)
            ->exists();
        if ($exists) return null;

        $otherPsid = $this->tableQuery($page, $name)->where('psid', '<>', $psid)->exists();
        $orders = $this->orderQuery($page, $name);
        $holding = $orders ? (clone $orders)->where('botcake_psid', $psid)->count() : 0;

        BotcakePsid::create([
            'setting_id'      => $setting->id,
            'page'            => $page,
            'fb_name'         => $name,
            'psid'            => $psid,
            'sheet_date'      => $sheetDate,
            'status'          => $otherPsid ? self::CONFLICT : self::MATCHED,
            'first_seen_at'   => now(),
            'matched_at'      => $otherPsid ? null : now(),
            'matched_rows'    => $otherPsid ? 0 : $holding,
            'last_checked_at' => now(),
        ]);

        if ($otherPsid) {
            $this->tableQuery($page, $name)->where('status', self::WAITING)
                ->update(['status' => self::CONFLICT, 'last_checked_at' => now()]);
            return self::CONFLICT;
        }

        return self::MATCHED;
    }

    /** Itala ang sheet row bilang waiting; ang parehong row na naitala na ay hindi dinodoble. */
    public function recordWaiting(BotcakePsidSetting $setting, string $page, string $name, string $psid, string $sheetDate): BotcakePsid
    {
        $rec = BotcakePsid::where('setting_id', $setting->id)
            ->where('page', $page)->where('fb_name', $name)->where('psid', $psid)
            ->where('sheet_date', $sheetDate)
            ->first();

        // Dobleng row sa sheet: iisang record lang.
        if (!$rec) {
            $rec = BotcakePsid::create([
                'setting_id'    => $setting->id,
                'page'          => $page,
                'fb_name'       => $name,
                'psid'          => $psid,
                'sheet_date'    => $sheetDate,
                'status'        => self::WAITING,
                'first_seen_at' => now(),
            ]);
        }

        return $rec;
    }

    /**
     * Ikabit kung may order, o markahan conflict. Hindi nag-e-expire dito.
     * Isinusulat lang sa mga order row na walang laman ang botcake_psid.
     */
    protected function evaluate(BotcakePsid $rec, ?string $since): void
    {
        $orders = $this->orderQuery($rec->page, $rec->fb_name, $since);

        $known = $this->tableQuery($rec->page, $rec->fb_name)->distinct()->pluck('psid')->all();
        if ($orders) {
            $onOrders = (clone $orders)->whereNotNull('botcake_psid')->where('botcake_psid', '<>', '')
                ->distinct()->pluck('botcake_psid')->all();
            $known = array_merge($known, $onOrders);
        }
        $known = array_values(array_unique(array_merge($known, [$rec->psid])));

        if (count($known) > 1) {
            $rec->update(['status' => self::CONFLICT, 'last_checked_at' => now()]);
            // Ang ibang naghihintay na PSID ng parehong customer ay hindi na rin ikakabit.
            $this->tableQuery($rec->page, $rec->fb_name)->where('status', self::WAITING)
                ->update(['status' => self::CONFLICT, 'last_checked_at' => now()]);
            return;
        }

        if (!$orders) {
            $rec->update(['last_checked_at' => now()]);
            return;
        }

        $affected = $this->emptyPsid(clone $orders)->update(['botcake_psid' => $rec->psid]);

        $rec->update([
            'status'          => self::MATCHED,
            'matched_at'      => now(),
            'matched_rows'    => $affected,
            'last_checked_at' => now(),
        ]);
    }

    /**
     * Subukan ulit ang lahat ng waiting. Walang order pa rin at lampas 30 araw → expired.
     * @return array{matched:int, expired:int, conflicts:int}
     */
    public function retryWaiting(): array
    {
        $counts = ['matched' => 0, 'expired' => 0, 'conflicts' => 0];
        $expireBefore = now()->subDays(self::WAIT_DAYS);

        BotcakePsid::where('status', self::WAITING)->orderBy('id')->chunkById(200, function ($recs) use (&$counts, $expireBefore) {
            foreach ($recs as $rec) {
                $rec->refresh(); // baka naging conflict na dahil sa naunang record ng parehong customer
                if ($rec->status !== self::WAITING) continue;

                // Ang order ay dumarating pagkatapos ng PSID: sapat na ang ts_date mula 2 araw bago ito unang nakita.
                $since = $rec->first_seen_at?->copy()->subDays(2)->toDateString();
                $this->evaluate($rec, $since);

                if ($rec->status === self::MATCHED) {
                    $counts['matched']++;
                } elseif ($rec->status === self::CONFLICT) {
                    $counts['conflicts']++;
                } elseif ($rec->first_seen_at && $rec->first_seen_at->lt($expireBefore)) {
                    $rec->update(['status' => self::EXPIRED]);
                    $counts['expired']++;
                }
            }
        });

        return $counts;
    }

    /**
     * Reorder: ang macro_output row (huling 30 araw) na walang PSID, na ang PAGE + fb_name ay may iisang
     * PSID sa ibang row, ay kinokopyahan nito. Laktaw kapag may conflict ang customer sa botcake_psids.
     * @return int bilang ng row na nasulatan
     */
    public function copyReorders(): int
    {
        $since = now('Asia/Manila')->subDays(self::REORDER_DAYS)->toDateString();

        $customers = $this->emptyPsid(MacroOutput::query())
            ->where('ts_date', '>=', $since)
            ->whereNotNull('PAGE')->where('PAGE', '<>', '')
            ->whereNotNull('fb_name')->where('fb_name', '<>', '')
            ->select('PAGE', 'fb_name')->distinct()->get();

        $copied = 0;
        foreach ($customers as $c) {
            $page = (string) $c->PAGE;
            $name = (string) $c->fb_name;

            $psids = MacroOutput::where('PAGE', $page)->where('fb_name', $name)
                ->whereNotNull('botcake_psid')->where('botcake_psid', '<>', '')
                ->distinct()->pluck('botcake_psid')->all();
            if (count($psids) !== 1) continue;

            if ($this->tableQuery($this->norm($page), $this->norm($name))->where('status', self::CONFLICT)->exists()) continue;

            $copied += $this->emptyPsid(MacroOutput::where('PAGE', $page)->where('fb_name', $name))
                ->where('ts_date', '>=', $since)
                ->update(['botcake_psid' => $psids[0]]);
        }

        return $copied;
    }

    /**
     * Burahin sa sheet ang mga row na naitala na (J imported/waiting/conflict/expired at kapareho ng record ang
     * page + pangalan + PSID + petsa). Binabasa ulit ang sheet bago burahin; header at blank J ay hindi kasama.
     * @return int bilang ng row na binura
     */
    public function cleanSheet(BotcakePsidSheet $sheet, BotcakePsidSetting $setting, string $spreadsheetId, string $sheetName): int
    {
        $rows = $sheet->readRows($spreadsheetId, $sheetName);

        $candidates = [];
        foreach ($rows as $rowNo => $row) {
            if ($rowNo < 2) continue;
            $j = $this->lower(trim((string) ($row[9] ?? '')));
            if (!in_array($j, self::DELETABLE_LABELS, true)) continue;

            $psid = $this->norm($row[2] ?? '');
            if ($psid === '') continue;
            $candidates[$rowNo] = [$this->norm($row[0] ?? ''), $this->norm($row[1] ?? ''), $psid, trim((string) ($row[3] ?? ''))];
        }
        if (empty($candidates)) return 0;

        $recorded = [];
        foreach (array_chunk(array_values(array_unique(array_column($candidates, 2))), 500) as $psids) {
            BotcakePsid::where('setting_id', $setting->id)->whereIn('psid', $psids)
                ->get(['page', 'fb_name', 'psid', 'sheet_date'])
                ->each(function ($r) use (&$recorded) {
                    $recorded[$this->key([$r->page, $r->fb_name, $r->psid, (string) $r->sheet_date])] = true;
                });
        }

        $toDelete = [];
        foreach ($candidates as $rowNo => $content) {
            if (isset($recorded[$this->key($content)])) $toDelete[] = $rowNo;
        }
        if (empty($toDelete)) return 0;

        $sheet->deleteRowRanges($spreadsheetId, $sheetName, $this->rangesBottomUp($toDelete));

        Log::info('BotcakePsid: deleted processed sheet rows', [
            'setting_id' => $setting->id,
            'deleted'    => count($toDelete),
        ]);

        return count($toDelete);
    }

    protected function key(array $parts): string
    {
        return implode("\x1F", $parts);
    }

    /**
     * Magkakatabing row → isang range; mula ibaba pataas.
     * @param int[] $rowNos
     * @return array<int, array{0:int,1:int}>
     */
    protected function rangesBottomUp(array $rowNos): array
    {
        rsort($rowNos);
        $ranges = [];
        foreach ($rowNos as $n) {
            $last = count($ranges) - 1;
            if ($last >= 0 && $ranges[$last][0] === $n + 1) {
                $ranges[$last][0] = $n;
            } else {
                $ranges[] = [$n, $n];
            }
        }
        return $ranges;
    }
}
