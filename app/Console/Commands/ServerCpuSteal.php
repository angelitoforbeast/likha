<?php

namespace App\Console\Commands;

use App\Services\Chatbot\CpuSteal;
use Illuminate\Console\Command;

/** Spec 273: one CPU steal sample (two reads of /proc/stat about 5 s apart), stored for the Problems panel. */
class ServerCpuSteal extends Command
{
    protected $signature = 'server:cpu-steal';

    protected $description = 'Sample the CPU steal % of this server (for the Problems panel)';

    public function handle(CpuSteal $steal): int
    {
        $pct = $steal->sample();
        $this->line($pct === null ? 'CPU steal: /proc/stat cannot be read here; nothing stored.'
            : 'CPU steal '.$pct.'% (last hour average '.CpuSteal::hourAverage().'%)');

        return self::SUCCESS;
    }
}
