<?php

namespace App\Console\Commands;

use App\Services\Chatblast;
use Illuminate\Console\Command;

/** Kapareho ng "Kunin ang mga bago" ng /chatblast, para sa automation sa susunod. Hindi naka-schedule. */
class BuildChatblast extends Command
{
    protected $signature   = 'chatblast:build {--kind= : Isang campaign lang (ang kind nito, hal. delivering, in_transit); lahat ng naka-on kapag wala}';
    protected $description = 'Add /chatblast rows for the campaigns\' waybills (no message is sent)';

    public function handle(Chatblast $chatblast): int
    {
        $kind = (string) $this->option('kind');
        if ($kind !== '' && !$chatblast->campaign($kind)) {
            $this->error('Hindi kilalang campaign: ' . $kind);
            return self::FAILURE;
        }

        $r = $chatblast->build($kind ?: null);
        if (!empty($r['busy'])) {
            $this->error('May tumatakbong pagkuha ng listahan; subukan ulit pagkatapos nito.');
            return self::FAILURE;
        }
        $this->line(sprintf('%d bago (%d ready, %d walang PSID, %d walang config); %d na-update, %d nilaktawan (iba na ang status); J&T: %d na-track, %d rider pending',
            $r['added'], $r['ready'], $r['no_psid'], $r['no_config'], $r['updated'], $r['skipped'], $r['tracked'], $r['rider_pending']));

        return self::SUCCESS;
    }
}
