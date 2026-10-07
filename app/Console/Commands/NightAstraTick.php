<?php

namespace App\Console\Commands;

use App\Services\NightAstraRun;
use Illuminate\Console\Command;

/**
 * Tick ng Astra night run, tinatawag ng scheduler kada minuto: sinisimulan ang run ng gabi
 * kapag oras na at tapos na ang import, at binabantayan ang bawat tumatakbong run (mga row na naiwan ng
 * worker, stop time, mga row na hindi pa na-dispatch, pagtatapos). Hindi nito ibinabalik ang row sa `queued`.
 */
class NightAstraTick extends Command
{
    protected $signature   = 'night:astra-tick';
    protected $description = 'Start tonight\'s Astra run when it is due and watch the running ones';

    public function handle(NightAstraRun $night): int
    {
        $night->tick();

        return self::SUCCESS;
    }
}
