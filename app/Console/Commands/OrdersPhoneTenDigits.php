<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ang mga lumang order na ang PHONE NUMBER ay 09XXXXXXXXX (11 digits) → 9XXXXXXXXX (10 digits), ang anyo ng website mula
 * pa sa sheet (ang hinahanap ng Validate, ng duplicate check, ng whitelist at ni Astra). Ang eksaktong ^09\d{9}$ lang; ang
 * ibang haba o anyo ay iniiwan para sa tao.
 *
 *  - Dry run ang default: bilang kada araw at 5 halimbawa, walang binabago.
 *  - --apply: chunked, idempotent (ang pangalawang takbo ay 0), at bawat palit ay nasa storage/logs/orders-phone-ten-digits.log.
 *  - Query builder lang (hindi Eloquent): hindi ginagalaw ang updated_at, walang linya sa HISTORICAL LOGS (ang linya ng hindi
 *    "Likha encode" ay ituturing ng encode na edit ng staff at hindi na nito babaguhin ang phone), walang edited_* flag.
 *
 *   php artisan orders:phone-ten-digits            (dry run)
 *   php artisan orders:phone-ten-digits --apply
 */
class OrdersPhoneTenDigits extends Command
{
    protected $signature = 'orders:phone-ten-digits
                            {--apply : i-convert talaga (default: dry run, walang binabago)}
                            {--chunk=500 : ilang row kada batch}';
    protected $description = 'macro_output PHONE NUMBER na 09XXXXXXXXX → 9XXXXXXXXX (dry run ang default)';

    private const COL = 'PHONE NUMBER';
    public const LOG = 'logs/orders-phone-ten-digits.log';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(1, (int) $this->option('chunk'));
        $perDay = [];
        $samples = [];
        $changed = 0;
        $log = storage_path(self::LOG);

        // LIKE '09' + 9 na kahit-ano = 11 characters na nagsisimula sa 09 (mysql, pgsql, sqlite); ang regex sa PHP ang huling sala.
        DB::table('macro_output')->where(self::COL, 'like', '09_________')->select(['id', self::COL, 'ts_date'])
            ->chunkById($chunk, function ($rows) use ($apply, $log, &$perDay, &$samples, &$changed) {
                $lines = [];
                foreach ($rows as $r) {
                    $before = (string) $r->{self::COL};
                    if (!preg_match('/^09\d{9}$/', $before)) continue;
                    $after = substr($before, 1);
                    $day = $r->ts_date ? substr((string) $r->ts_date, 0, 10) : '(walang ts_date)';
                    if (count($samples) < 5) $samples[] = [$r->id, $before, $after, $day];

                    if ($apply) {
                        // Kapag nabago ng iba ang phone habang tumatakbo, hindi ito ginagalaw.
                        $n = DB::table('macro_output')->where('id', $r->id)->where(self::COL, $before)->update([self::COL => $after]);
                        if ($n !== 1) continue;
                        $changed++;
                        $lines[] = now('Asia/Manila')->format('Y-m-d H:i:s') . "\tid=" . $r->id . "\tbefore=" . $before . "\tafter=" . $after;
                    }
                    $perDay[$day] = ($perDay[$day] ?? 0) + 1;
                }
                if ($lines) file_put_contents($log, implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
            });

        ksort($perDay);
        $total = array_sum($perDay);
        $this->line(($apply ? 'APPLY' : 'DRY RUN (walang binago; --apply para i-convert)') . ': ' . $total . ' row na 09XXXXXXXXX'
            . ($apply ? ' ang na-convert sa 9XXXXXXXXX' : ''));
        if ($perDay) $this->table(['ts_date', 'rows'], array_map(fn ($d, $n) => [$d, $n], array_keys($perDay), $perDay));
        if ($samples) {
            $this->line('Halimbawa (id: before → after, ts_date):');
            foreach ($samples as [$id, $before, $after, $day]) $this->line("  #{$id}: {$before} → {$after} ({$day})");
        }
        if ($apply) $this->line('Changed: ' . $changed . ($changed ? ' (log: storage/' . self::LOG . ')' : ''));

        return self::SUCCESS;
    }
}
