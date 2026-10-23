<?php

namespace App\Console\Commands;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\OrderEncode\FinalComparer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Ang mga order na tapos nang i-encode ng Likha (galing sa Likha import, o shadow ng flow na may macro_output_id) ay inihahambing
 * sa final na order sa macro_output. Naghihintay hangga't hindi napupunan ng Macro import ang order (STATUS, APP SCRIPT CHECKER o
 * PROVINCE); pagkalipas ng 72 oras: sheet_never_finished. Isang beses lang bawat order; walang sinusulat sa macro_output.
 */
class OrderEncodeCompareFinal extends Command
{
    protected $signature = 'order-encode:compare-final';
    protected $description = 'Compare the Likha encode of imported orders with the final order in macro_output';

    public function handle(): int
    {
        if (!Schema::hasTable('order_encodes') || !Schema::hasColumn('order_encodes', 'final_compare')) {
            $this->warn('order_encodes.final_compare does not exist yet (run the migration). Nothing to compare.');

            return self::SUCCESS;
        }

        $giveUp = now()->subHours((int) config('order_encode.final_give_up_hours', 72));
        $counts = ['done' => 0, 'waiting' => 0, 'never' => 0];

        OrderEncode::whereIn('mode', ['import', 'shadow'])->whereNotNull('macro_output_id')->where('status', 'done')->whereNull('final_compare')
            ->chunkById(200, function ($orders) use ($giveUp, &$counts) {
                $macros = MacroOutput::whereIn('id', $orders->pluck('macro_output_id'))->get()->keyBy('id');
                foreach ($orders as $o) {
                    $m = $macros[$o->macro_output_id] ?? null;
                    if ($m && FinalComparer::filled($m)) {
                        $o->update(['final_compare' => FinalComparer::compare($o, $m)]);
                        $counts['done']++;
                    } elseif ($o->created_at <= $giveUp) {
                        $o->update(['final_compare' => ['state' => 'sheet_never_finished', 'at' => now()->toIso8601String()]]);
                        $counts['never']++;
                    } else {
                        $counts['waiting']++;
                    }
                }
            });

        $this->info(sprintf('compared %d, waiting %d, sheet never finished %d', $counts['done'], $counts['waiting'], $counts['never']));

        return self::SUCCESS;
    }
}
