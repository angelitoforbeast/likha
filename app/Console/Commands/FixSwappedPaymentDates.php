<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Itinatama ang Meta payments na baligtad ang day at month (luma: "Billing Report" files na binasa bilang d/m).
 *
 * Baligtad ang row kapag ang stored day ay 12 pababa, at ang baligtad na basa T ay "plausible" (0–35 araw
 * bago ang created_at) pero ang stored S ay hindi. Kapag pareho, ang mas malapit sa created_at.
 * Kasama ang rollover (5/31 na naging 2028-07-05): T = (Y0, month = S.day, day = (S.year − Y0)×12 + S.month),
 * Y0 = taon ng created_at o ang taon bago nito, kung totoong petsa.
 *
 * Default ay dry-run (walang binabago). --apply: backup table muna, tapos date lang ang ina-update, isang transaction.
 */
class FixSwappedPaymentDates extends Command
{
    protected $signature = 'payments:fix-swapped-dates
                            {--dry-run : Ipakita lang ang babaguhin (default)}
                            {--apply : Gumawa ng backup table at itama ang date}
                            {--expect= : Tumangging tumakbo kapag iba ang bilang}';

    protected $description = 'Find and fix Meta payment rows whose day and month were swapped on import';

    private const TABLE = 'payment_activity_ads_manager';
    private const PLAUSIBLE_DAYS = 35;

    public function handle(): int
    {
        if (!Schema::hasTable(self::TABLE)) {
            $this->error('Walang ' . self::TABLE . ' table.');
            return self::FAILURE;
        }
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Piliin lang ang isa: --dry-run o --apply.');
            return self::FAILURE;
        }

        $fixes = $this->findFixes();
        $count = count($fixes);
        $total = array_sum(array_column($fixes, 'amount'));

        $this->info(sprintf('%d row(s) na baligtad ang day/month, total %s.', $count, number_format($total, 2)));
        foreach (array_slice($fixes, 0, 10) as $f) {
            $this->line(sprintf('  #%d  %s → %s  %s  card %s', $f['id'], $f['stored'], $f['corrected'], number_format($f['amount'], 2), $f['card_last4'] ?: '-'));
        }

        $csvPath = $this->writeCsv($fixes);
        $this->line("CSV ng lahat ng babaguhin: storage/app/private/{$csvPath}");

        $expect = $this->option('expect');
        if ($expect !== null && (string) $count !== trim((string) $expect)) {
            $this->error("Tumanggi: {$count} ang nakita, expected {$expect}. Walang binago.");
            return self::FAILURE;
        }

        if (!$this->option('apply')) {
            $this->line('Dry-run lang: walang binago. Gamitin ang --apply para itama.');
            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('Walang itatama.');
            return self::SUCCESS;
        }

        $backup = self::TABLE . '_bak_' . now()->format('Ymd_Hi');
        if (Schema::hasTable($backup)) {
            $this->error("May {$backup} na. Maghintay ng isang minuto at ulitin.");
            return self::FAILURE;
        }
        $this->backupTable($backup);

        // Date lang, by id, at kung hindi pa nagbago ang stored date mula nang mabasa.
        DB::transaction(function () use ($fixes) {
            foreach ($fixes as $f) {
                $n = DB::table(self::TABLE)->where('id', $f['id'])->where('date', $f['stored'])->update(['date' => $f['corrected']]);
                if ($n !== 1) {
                    throw new \RuntimeException("Row #{$f['id']} nagbago habang tumatakbo; walang binago.");
                }
            }
        });

        $by = getenv('USER') ?: (getenv('USERNAME') ?: get_current_user());
        Log::info('[PAYMENT] payments:fix-swapped-dates applied', ['by' => $by, 'count' => $count, 'total' => $total, 'backup' => $backup, 'csv' => $csvPath]);
        $this->info("Naitama ang {$count} row(s). Backup: {$backup}.");

        return self::SUCCESS;
    }

    /** @return array<int, array{id:int, stored:string, corrected:string, amount:float, card_last4:string, created_at:string, source_filename:string}> */
    private function findFixes(): array
    {
        $fixes = [];

        $rows = DB::table(self::TABLE)
            ->select(['id', 'date', 'amount', 'payment_method', 'created_at', 'source_filename'])
            ->whereNotNull('date')
            ->whereNotNull('created_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $r) {
            $corrected = $this->correctedDate(substr((string) $r->date, 0, 10), substr((string) $r->created_at, 0, 10));
            if ($corrected === null) continue;

            $fixes[] = [
                'id'         => (int) $r->id,
                'stored'     => substr((string) $r->date, 0, 10),
                'corrected'  => $corrected,
                'amount'     => (float) $r->amount,
                'card_last4' => preg_match('/(\d{4})\s*$/', (string) $r->payment_method, $m) ? $m[1] : '',
                'created_at' => (string) $r->created_at,
                'source_filename' => (string) $r->source_filename,
            ];
        }

        return $fixes;
    }

    /** Ang tamang date (Y-m-d) kung baligtad ang stored, kung hindi null. */
    private function correctedDate(string $stored, string $created): ?string
    {
        $s = Carbon::createFromFormat('!Y-m-d', $stored);
        $c = Carbon::createFromFormat('!Y-m-d', $created);
        if (!$s || !$c || $s->day > 12) return null;

        $best = null;
        $bestGap = null;
        foreach ([$c->year, $c->year - 1] as $y0) {
            $month = $s->day;
            $day   = ($s->year - $y0) * 12 + $s->month;
            if ($day < 1 || !checkdate($month, $day, $y0)) continue;

            $t   = Carbon::create($y0, $month, $day);
            $gap = $this->gap($t, $c);
            if ($t->eq($s) || $gap === null) continue;
            if ($bestGap === null || $gap < $bestGap) {
                $best = $t;
                $bestGap = $gap;
            }
        }
        if ($best === null) return null;

        $storedGap = $this->gap($s, $c);
        if ($storedGap !== null && $storedGap <= $bestGap) return null; // ang stored ay plausible at mas malapit (o pantay)

        return $best->toDateString();
    }

    /** Ilang araw bago ang created_at, kung 0–35; null kung hindi plausible. */
    private function gap(Carbon $date, Carbon $created): ?int
    {
        $days = (int) $date->diffInDays($created, false);
        return ($days >= 0 && $days <= self::PLAUSIBLE_DAYS) ? $days : null;
    }

    private function writeCsv(array $fixes): string
    {
        $path = 'payment_date_fixes/fix-swapped-dates_' . now()->format('Ymd_His') . '.csv';

        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['id', 'stored', 'corrected', 'amount', 'card_last4', 'created_at', 'source_filename'], ',', '"', '\\');
        foreach ($fixes as $f) {
            fputcsv($out, [$f['id'], $f['stored'], $f['corrected'], number_format($f['amount'], 2, '.', ''), $f['card_last4'], $f['created_at'], $f['source_filename']], ',', '"', '\\');
        }
        rewind($out);
        Storage::disk('local')->put($path, stream_get_contents($out));
        fclose($out);

        return $path;
    }

    /** Buong kopya ng table bago galawin (maliit na table, ~2.4k rows). Ang pangalan ay galing sa code, hindi sa input. */
    private function backupTable(string $backup): void
    {
        $driver = DB::getDriverName();
        $table  = self::TABLE;

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("CREATE TABLE `{$backup}` LIKE `{$table}`");
            DB::statement("INSERT INTO `{$backup}` SELECT * FROM `{$table}`");
        } else {
            // pgsql at sqlite: plain copy (walang shared sequence o index na madadala)
            DB::statement("CREATE TABLE \"{$backup}\" AS SELECT * FROM \"{$table}\"");
        }

        $copied = DB::table($backup)->count();
        $source = DB::table($table)->count();
        if ($copied !== $source) {
            throw new \RuntimeException("Backup {$backup} has {$copied} rows, table has {$source}; nothing changed.");
        }
        $this->line("Backup: {$backup} ({$copied} rows).");
    }
}
