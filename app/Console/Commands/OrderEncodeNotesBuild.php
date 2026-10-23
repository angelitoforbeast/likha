<?php

namespace App\Console\Commands;

use App\Services\OrderEncode\LocationList;
use App\Services\OrderEncode\NotesBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Ang pagtuturo ng encoder: gumagawa o nag-a-update ng address_notes mula sa mga itinamang at na-deliver na order. Ligtas ulitin
 * (bawat order ay isang beses lang nabibilang). Ang bagong note ay pending; ang awtomatikong apruba ay nasa NotesBuilder.
 */
class OrderEncodeNotesBuild extends Command
{
    protected $signature = 'order-encode:notes-build {--days=60 : how many days back to read}';
    protected $description = 'Build the address notes (what staff, Astra and J&T delivery taught us) from macro_output';

    public function handle(LocationList $list): int
    {
        if (!Schema::hasTable('address_notes')) {
            $this->warn('address_notes does not exist yet (run the migration). Nothing to build.');

            return self::SUCCESS;
        }
        if (!$list->has() || !$list->rows()) {
            $this->warn('The address list (jnt_address.txt) is missing or empty. Nothing to build.');

            return self::SUCCESS;
        }
        $days = (int) $this->option('days');
        if ($days < 1 || $days > 400) {
            $this->error('--days must be between 1 and 400.');

            return self::FAILURE;
        }

        $s = (new NotesBuilder($list))->build($days);
        $this->info(sprintf('orders read %d, observations %d, notes created %d, updated %d, auto-approved %d, skipped (no written place %d, not a J&T address %d)',
            $s['orders'], $s['observations'], $s['created'], $s['updated'], $s['approved'], $s['skipped_no_key'], $s['skipped_not_jnt']));

        return self::SUCCESS;
    }
}
