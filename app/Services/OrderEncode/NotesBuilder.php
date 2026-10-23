<?php

namespace App\Services\OrderEncode;

use App\Models\AddressNote;
use App\Models\OrderEncode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang pagtuturo ng encoder (spec 135): mula sa mga order sa macro_output na (a) itinama ng staff (HISTORICAL LOGS na may
 * PROVINCE / CITY / BARANGAY), (b) itinama ng Astra o AI Fix (ai_checker_logs, outcome fixed) o (c) na-deliver ng J&T
 * (from_jnts.status = Delivered), gumagawa ng address_notes: ang isinulat ng customer (written_key, mula sa extracted details
 * na binasa ng parser ng spec 130) at ang huling PROVINCE / CITY / BARANGAY, kung nasa J&T list.
 *
 * Ligtas ulitin: bawat order ay isang beses lang nabibilang sa note (ang ledger ng note), kaya ang --days=3 araw-araw at ang
 * --days=60 ay hindi nagdodoble. Ang note na may reviewed_at ay hindi ginagalaw ang status at ang sagot.
 */
class NotesBuilder
{
    public const FIELDS = ['PROVINCE', 'CITY', 'BARANGAY'];
    public const FIX_SOURCES = ['staff_fix', 'astra_fix'];
    /** Ilang araw bago ang saklaw ang isinasama sa paghahanap ng itinamang order (ang pagtatama ay nangyayari pagkatapos ng order). */
    private const PAD_DAYS = 7;
    private const CHUNK = 500;
    private const EXAMPLES = 20;

    /** @var array<string, array{0: string, 1: string, 2: string}>|null */
    private ?array $jnt = null;

    public function __construct(private LocationList $list)
    {
    }

    /** @return array{orders: int, observations: int, created: int, updated: int, approved: int, skipped_no_key: int, skipped_not_jnt: int} */
    public function build(int $days): array
    {
        $stats = ['orders' => 0, 'observations' => 0, 'created' => 0, 'updated' => 0, 'approved' => 0, 'skipped_no_key' => 0, 'skipped_not_jnt' => 0];
        if (!Schema::hasTable('address_notes') || !Schema::hasTable('macro_output')) return $stats;

        $since = Carbon::now('Asia/Manila')->subDays(max(1, $days))->startOfDay();
        $flags = $this->staffFixes($since) + [];
        foreach ($this->astraFixes($since) as $id => $_) $flags[$id]['astra_fix'] = true;
        foreach ($this->delivered($since) as $id => $_) $flags[$id]['delivered'] = true;
        $stats['orders'] = count($flags);

        $byKey = [];
        foreach (array_chunk(array_keys($flags), self::CHUNK) as $ids) {
            foreach (DB::table('macro_output')->whereIn('id', $ids)->get(['id', 'PROVINCE', 'CITY', 'BARANGAY', 'extracted_details', 'CXD']) as $row) {
                $parts = $this->written($row);
                $key = Written::key($parts);
                if (Written::isEmptyKey($key)) {
                    $stats['skipped_no_key']++;
                    continue;
                }
                $answer = $this->jntLabels($row->PROVINCE, $row->CITY, $row->BARANGAY);
                if ($answer === null) {
                    $stats['skipped_not_jnt']++;
                    continue;
                }
                $f = $flags[$row->id];
                $sources = array_keys(array_filter(['staff_fix' => $f['staff_fix'] ?? false, 'astra_fix' => $f['astra_fix'] ?? false, 'delivered' => $f['delivered'] ?? false]));
                $byKey[$key]['parts'] ??= $parts;
                $byKey[$key]['obs'][] = ['id' => (int) $row->id, 'answer' => $answer, 'sources' => $sources, 'delivered' => (bool) ($f['delivered'] ?? false)];
                $stats['observations']++;
            }
        }

        foreach ($byKey as $key => $data) {
            $result = $this->apply($key, $data['parts'], $data['obs']);
            $stats[$result['new'] ? 'created' : 'updated']++;
            $stats['approved'] += $result['approved'] ? 1 : 0;
        }

        return $stats;
    }

    // ---- saan galing ang mga order ---------------------------------------------------------------------------------------

    /** @return array<int, array{staff_fix: true}> ang mga order na may linya ng HISTORICAL LOGS para sa lugar, mula sa $since */
    private function staffFixes(Carbon $since): array
    {
        $found = [];
        $grammar = DB::getQueryGrammar();
        $col = $grammar->wrap('HISTORICAL LOGS');
        $q = DB::table('macro_output')->whereNotNull('HISTORICAL LOGS');
        if (Schema::hasColumn('macro_output', 'ts_date')) $q->where('ts_date', '>=', $since->copy()->subDays(self::PAD_DAYS)->toDateString());
        $q->where(function ($w) use ($col) {
            foreach (self::FIELDS as $f) $w->orWhereRaw($col . ' LIKE ?', ['%|' . $f . '|%']);
        });

        $q->orderBy('id')->chunk(self::CHUNK, function ($rows) use (&$found, $since) {
            foreach ($rows as $r) {
                foreach (preg_split('/\r\n|\n|\r/', (string) $r->{'HISTORICAL LOGS'}) as $line) {
                    $p = explode('|', $line);
                    if (count($p) < 5 || !in_array(trim($p[2]), self::FIELDS, true)) continue;
                    $at = Carbon::createFromFormat('Y-m-d H:i:s', trim($p[0]), 'Asia/Manila') ?: null;
                    if ($at && $at->lt($since)) continue;
                    $found[(int) $r->id] = ['staff_fix' => true];
                    break;
                }
            }
        });

        return $found;
    }

    /** @return array<int, true> */
    private function astraFixes(Carbon $since): array
    {
        if (!Schema::hasTable('ai_checker_logs')) return [];

        return DB::table('ai_checker_logs')->where('outcome', 'fixed')->where('created_at', '>=', $since)->whereNotNull('macro_output_id')
            ->distinct()->pluck('macro_output_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** @return array<int, true> */
    private function delivered(Carbon $since): array
    {
        if (!Schema::hasTable('from_jnts')) return [];

        $q = DB::table('macro_output')->whereNotNull('waybill')->whereExists(function ($e) use ($since) {
            $e->select(DB::raw(1))->from('from_jnts')->whereColumn('from_jnts.waybill_number', 'macro_output.waybill')->where('from_jnts.status', 'Delivered');
            if (Schema::hasColumn('from_jnts', 'updated_at')) $e->where('from_jnts.updated_at', '>=', $since);
        });

        return $q->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    // ---- ang isinulat at ang sagot ---------------------------------------------------------------------------------------

    /** @return array{province: string, city: string, barangay: string} */
    private function written(object $row): array
    {
        $text = trim((string) ($row->extracted_details ?? '')) !== '' ? (string) $row->extracted_details : (string) ($row->CXD ?? '');
        $p = Parse::addressParts($text);
        $parts = ['province' => $p['province'], 'city' => $p['city'], 'barangay' => $p['barangay']];
        if (!Written::isEmptyKey(Written::key($parts))) return $parts;

        // Walang extracted details: ang nabasang bahagi ng encode ng Likha, kung may encode ang order.
        $encode = OrderEncode::where('macro_output_id', $row->id)->first();
        $step = collect($encode?->steps ?? [])->firstWhere('step', 'parse_parts');

        return $step ? ['province' => (string) ($step['province'] ?? ''), 'city' => (string) ($step['city'] ?? ''), 'barangay' => (string) ($step['barangay'] ?? '')] : $parts;
    }

    /** @return array{0: string, 1: string, 2: string}|null ang PROVINCE, CITY, BARANGAY ng J&T file na katumbas ng tatlong value, o null */
    public function jntLabels(?string $province, ?string $city, ?string $barangay): ?array
    {
        if ($this->jnt === null) {
            $this->jnt = [];
            foreach ($this->list->rows() as $r) $this->jnt[self::norm($r[0], $r[1], $r[2])] ??= $r;
        }

        return $this->jnt[self::norm((string) $province, (string) $city, (string) $barangay)] ?? null;
    }

    private static function norm(string $p, string $c, string $b): string
    {
        return Text::normProv($p) . '|' . Text::normPlace($c) . '|' . Text::normPlace($b);
    }

    // ---- ang note --------------------------------------------------------------------------------------------------------

    /**
     * @param array{province: string, city: string, barangay: string} $parts
     * @param array<int, array{id: int, answer: array{0: string, 1: string, 2: string}, sources: array<int, string>, delivered: bool}> $obs
     * @return array{new: bool, approved: bool}
     */
    private function apply(string $key, array $parts, array $obs): array
    {
        $note = AddressNote::where('written_key', $key)->first();
        $new = $note === null;
        $reviewed = $note?->reviewed_at !== null;
        $ledger = $note ? (array) $note->ledger : [];

        // ang mga sagot at ang bilang nila; ang una ay ang kasalukuyang sagot ng note
        $tallies = [];
        if ($note) {
            $tallies[self::hash([$note->province, $note->city, $note->barangay])] = ['a' => [$note->province, $note->city, $note->barangay], 'seen' => (int) $note->seen, 'delivered' => (int) $note->delivered];
            foreach ((array) $note->conflicts as $o) {
                $tallies[self::hash([$o['province'], $o['city'], $o['barangay']])] = ['a' => [$o['province'], $o['city'], $o['barangay']], 'seen' => (int) $o['seen'], 'delivered' => (int) $o['delivered']];
            }
        }
        $currentHash = $note ? self::hash([$note->province, $note->city, $note->barangay]) : null;

        $sources = $note ? $note->sources() : [];
        $ids = $note ? (array) $note->order_ids : [];
        foreach ($obs as $o) {
            $sources = array_merge($sources, $o['sources']);
            $h = self::hash($o['answer']);
            $had = $ledger[$o['id']] ?? null;
            if ($had === null) {
                $tallies[$h] ??= ['a' => $o['answer'], 'seen' => 0, 'delivered' => 0];
                $tallies[$h]['seen']++;
                if ($o['delivered']) $tallies[$h]['delivered']++;
                $ledger[$o['id']] = $h . ':' . ($o['delivered'] ? 1 : 0);
                array_unshift($ids, $o['id']);
            } elseif ($o['delivered'] && str_ends_with((string) $had, ':0')) {
                $eh = explode(':', (string) $had)[0];
                if (isset($tallies[$eh])) $tallies[$eh]['delivered']++;
                $ledger[$o['id']] = $eh . ':1';
            }
        }
        if (!$tallies) return ['new' => $new, 'approved' => false];

        // ang sagot ng note: ang pinakamarami ang na-deliver, saka ang pinakamarami ang nakita; ang na-review ay hindi ginagalaw
        $hashes = array_keys($tallies);
        $primary = $currentHash !== null && $reviewed ? $currentHash : $hashes[0];
        if (!$reviewed) {
            foreach ($hashes as $h) {
                if ([$tallies[$h]['delivered'], $tallies[$h]['seen']] > [$tallies[$primary]['delivered'], $tallies[$primary]['seen']]) $primary = $h;
            }
        }
        $others = [];
        foreach ($tallies as $h => $t) {
            if ($h !== $primary) $others[] = ['province' => $t['a'][0], 'city' => $t['a'][1], 'barangay' => $t['a'][2], 'seen' => $t['seen'], 'delivered' => $t['delivered']];
        }
        usort($others, fn ($x, $y) => [$y['delivered'], $y['seen']] <=> [$x['delivered'], $x['seen']]);

        [$brgy, $city, $prov] = Written::split($key);
        $t = $tallies[$primary];
        $changed = $currentHash !== null && $currentHash !== $primary;
        $note ??= new AddressNote(['written_key' => $key, 'written_brgy' => $brgy, 'written_city' => $city, 'written_province' => $prov,
            'written_example' => Written::example($parts), 'status' => 'pending']);
        $note->fill([
            'province' => $t['a'][0], 'city' => $t['a'][1], 'barangay' => $t['a'][2], 'seen' => $t['seen'], 'delivered' => $t['delivered'],
            'conflict' => $others !== [], 'conflicts' => $others ?: null,
            'source' => implode(',', array_values(array_unique($sources))),
            'order_ids' => array_slice(array_values(array_unique($ids)), 0, self::EXAMPLES), 'ledger' => $ledger,
        ]);

        // pag-apruba: delivered ≥ 2, walang conflict, may pagtatama; ang na-review ng tao ay hindi ginagalaw ang status
        $approved = false;
        if ($note->reviewed_at === null) {
            $good = $note->delivered >= 2 && !$note->conflict && array_intersect(self::FIX_SOURCES, $sources) !== [];
            if ($note->status === 'pending' && $good) {
                $note->fill(['status' => 'approved', 'reviewed_by' => 'auto', 'review_note' => 'Auto: delivered ≥ 2, no conflict, corrected by staff or Astra']);
                $approved = true;
            } elseif ($note->status === 'approved' && $note->reviewed_by === 'auto' && (!$good || $changed)) {
                $note->fill(['status' => 'pending', 'reviewed_by' => null, 'review_note' => null]);
            }
        }
        $note->save();

        return ['new' => $new, 'approved' => $approved];
    }

    /** @param array<int, string> $answer */
    private static function hash(array $answer): string
    {
        return substr(md5(Text::lower(implode('|', array_map(fn ($s) => Text::jsTrim($s), $answer)))), 0, 10);
    }
}
