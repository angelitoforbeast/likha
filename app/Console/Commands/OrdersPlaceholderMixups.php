<?php

namespace App\Console\Commands;

use App\Services\AstraEncoder;
use App\Services\BotcakeCustomerMatcher;
use App\Services\OrderPhone;
use App\Services\PlaceholderName;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Spec 307: ang mga order na "Facebook User" (o ibang placeholder na pangalan) na ang FULL NAME o PHONE NUMBER ay kapareho
 * ng customer ng ibang row sa parehong page at araw, at wala sa sariling usapan (all_user_input) o form (CXD) ng row. Iyon
 * ang mga row na maaaring nakuhanan ng pangalan o phone ng ibang customer. Basa lang (SELECT): walang inaayos, at bilang at
 * id lang ang inilalabas, hindi kailanman ang pangalan, phone o address.
 */
class OrdersPlaceholderMixups extends Command
{
    protected $signature = 'orders:placeholder-mixups
        {--from= : unang araw (Y-m-d, ts_date)}
        {--to= : huling araw (Y-m-d, ts_date)}
        {--page= : iisang page lang}';

    protected $description = 'Read-only list of placeholder-name orders ("Facebook User") whose FULL NAME or PHONE NUMBER looks copied from another customer of the same page and day (spec 307)';

    private const FIELDS = ['FULL NAME', 'PHONE NUMBER'];

    public function handle(): int
    {
        $from = $this->day((string) $this->option('from'));
        $to = $this->day((string) $this->option('to'));
        if ($from === null || $to === null || $from > $to) {
            $this->error('Give --from and --to as Y-m-d, with --from on or before --to.');

            return self::FAILURE;
        }
        $page = trim((string) $this->option('page'));

        $found = [];
        $checked = 0;
        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            [$dayFound, $dayChecked] = $this->checkDay($d->toDateString(), $page);
            array_push($found, ...$dayFound);
            $checked += $dayChecked;
        }

        $ids = array_values(array_unique(array_column($found, 0)));
        if ($found) {
            $this->table(['id', 'page', 'day', 'field', 'likely from', 'from has it'], $found);
        }
        $perField = array_count_values(array_column($found, 3));
        $this->line('Placeholder rows checked: ' . $checked);
        $this->line('Rows reported: ' . count($ids) . ' (' . implode(', ', array_map(fn ($f) => $f . ': ' . ($perField[$f] ?? 0), self::FIELDS)) . ')');
        $this->line('Ids: ' . implode(',', $ids));

        return self::SUCCESS;
    }

    /** Ang Y-m-d na araw, o null kapag mali ang anyo. */
    private function day(string $s): ?string
    {
        $s = trim($s);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
        $d = \DateTime::createFromFormat('!Y-m-d', $s);

        return $d && $d->format('Y-m-d') === $s ? $s : null;
    }

    /**
     * Isang araw: (1) ang maiikling column ng lahat ng row ng araw, para malaman kung aling FULL NAME at phone ang nasa ibang row
     * ng parehong page; (2) ang usapan at form ng mga pinaghihinalaan at ng mga kandidato lang.
     * @return array{0: array<int, array<int, string>>, 1: int} [mga row ng ulat, bilang ng placeholder rows]
     */
    private function checkDay(string $day, string $page): array
    {
        $q = DB::table('macro_output')
            ->where('ts_date', '>=', $day)->where('ts_date', '<=', $day . ' 23:59:59')
            ->orderBy('id');
        if ($page !== '') $q->where('PAGE', $page);
        $rows = $q->get(['id', 'PAGE', 'fb_name', 'FULL NAME', 'PHONE NUMBER']);

        // Ang value ng bawat row ayon sa page: FULL NAME / PHONE NUMBER (normalized) → mga id.
        $byValue = [];
        $values = [];
        foreach ($rows as $r) {
            $pk = mb_strtolower(trim((string) $r->PAGE));
            foreach ($this->rowValues($r) as $field => $v) {
                $byValue[$pk][$field][$v][] = (int) $r->id;
                $values[(int) $r->id][$field] = $v;
            }
        }

        $suspects = [];
        $checked = 0;
        foreach ($rows as $r) {
            if (!PlaceholderName::is($r->fb_name)) continue;
            $checked++;
            $pk = mb_strtolower(trim((string) $r->PAGE));
            foreach ($values[(int) $r->id] ?? [] as $field => $v) {
                $others = array_values(array_diff($byValue[$pk][$field][$v] ?? [], [(int) $r->id]));
                if ($others) $suspects[] = ['id' => (int) $r->id, 'page' => (string) $r->PAGE, 'field' => $field, 'value' => $v, 'others' => $others];
            }
        }
        if (!$suspects) return [[], $checked];

        $texts = $this->texts(array_merge(array_column($suspects, 'id'), ...array_column($suspects, 'others')));
        $out = [];
        foreach ($suspects as $s) {
            if ($this->has($texts[$s['id']] ?? '', $s['field'], $s['value'])) continue;
            $from = null;
            foreach ($s['others'] as $id) {
                if ($this->has($texts[$id] ?? '', $s['field'], $s['value'])) { $from = $id; break; }
            }
            $out[] = [(string) $s['id'], $s['page'], $day, $s['field'], (string) ($from ?? $s['others'][0]), $from !== null ? 'yes' : 'no'];
        }

        return [$out, $checked];
    }

    /** Ang FULL NAME (hindi blangko at hindi placeholder) at ang phone (PH mobile) ng row, normalized. */
    private function rowValues(object $r): array
    {
        $v = [];
        $name = (string) $r->{'FULL NAME'};
        if (!PlaceholderName::is($name)) $v['FULL NAME'] = BotcakeCustomerMatcher::normName($name);
        $phone = BotcakeCustomerMatcher::normPhone((string) $r->{'PHONE NUMBER'});
        if ($phone !== '') $v['PHONE NUMBER'] = $phone;

        return $v;
    }

    /**
     * Ang sariling sinulat ng customer ng bawat row: all_user_input at ang CXD na walang ASTRA block (ang block ni Astra ay
     * may hawak ng kinopyang value mismo, kaya hindi ito patunay). Binabasa nang paisa-isang 500 id.
     * @param int[] $ids
     * @return array<int, string>
     */
    private function texts(array $ids): array
    {
        $texts = [];
        $mark = '/' . preg_quote(AstraEncoder::BLOCK_MARK, '/') . '.*?\n---(\n|$)/su';
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            foreach (DB::table('macro_output')->whereIn('id', $chunk)->get(['id', 'all_user_input', 'CXD']) as $r) {
                $cxd = str_replace("\r\n", "\n", (string) $r->CXD);
                $texts[(int) $r->id] = (string) $r->all_user_input . "\n" . (preg_replace($mark, '', $cxd) ?? $cxd);
            }
        }

        return $texts;
    }

    /** Nasa text ba ang value: ang pangalan (anumang laki ng titik at espasyo) o ang phone (anumang anyo ng PH mobile). */
    private function has(string $text, string $field, string $value): bool
    {
        if ($field === 'PHONE NUMBER') return in_array($value, OrderPhone::numbers($text), true);

        return str_contains(BotcakeCustomerMatcher::normName($text), $value);
    }
}
