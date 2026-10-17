<?php

namespace App\Console\Commands;

use App\Services\Chatblast;
use Illuminate\Console\Command;

/** Kapareho ng "Kunin ang mga bago" ng /chatblast, para sa automation sa susunod. Hindi naka-schedule. */
class BuildChatblast extends Command
{
    protected $signature   = 'chatblast:build {--kind= : Isang uri lang (delivering, in_transit); lahat kapag wala}';
    protected $description = 'Add /chatblast rows for orders whose waybill is out for delivery (no message is sent)';

    public function handle(Chatblast $chatblast): int
    {
        $kind = (string) $this->option('kind');
        if ($kind !== '' && !isset(Chatblast::KINDS[$kind])) {
            $this->error('Hindi kilalang uri: ' . $kind);
            return self::FAILURE;
        }

        $r = $chatblast->build($kind ?: null);
        $this->line(sprintf('%d bago (%d ready, %d walang PSID, %d walang config); %d na-update, %d nilaktawan (iba na ang status)',
            $r['added'], $r['ready'], $r['no_psid'], $r['no_config'], $r['updated'], $r['skipped']));

        return self::SUCCESS;
    }
}
