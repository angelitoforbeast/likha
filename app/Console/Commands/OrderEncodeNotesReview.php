<?php

namespace App\Console\Commands;

use App\Services\OrderEncode\NoteFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Ang pagrerepaso ng address_notes sa script: aprubahan ang mga pending na note na tumutugma sa filter. Hindi ginagalaw ang mga
 * note na may conflict (kailangan ng tingin ng tao). --dry-run ay nagbibilang lang.
 */
class OrderEncodeNotesReview extends Command
{
    protected $signature = 'order-encode:notes-review
        {--approve-where= : filter, e.g. "source=staff_fix,conflict=no,delivered>=2"}
        {--reviewer= : who approves (saved on every note)}
        {--note= : optional review note}
        {--dry-run : count only, change nothing}';
    protected $description = 'Approve pending address notes that match a filter (scripted review)';

    public function handle(): int
    {
        if (!Schema::hasTable('address_notes')) {
            $this->warn('address_notes does not exist yet (run the migration).');

            return self::SUCCESS;
        }
        $reviewer = trim((string) $this->option('reviewer'));
        if ($reviewer === '') {
            $this->error('--reviewer is required.');

            return self::FAILURE;
        }
        try {
            $filter = NoteFilter::parse((string) $this->option('approve-where'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $matching = NoteFilter::query($filter)->where('status', 'pending');
        $skipped = (clone $matching)->where('conflict', true)->count();
        $ok = (clone $matching)->where('conflict', false);
        $n = (clone $ok)->count();

        if ($this->option('dry-run')) {
            $this->info("dry run: {$n} note(s) would be approved by {$reviewer}; {$skipped} with a conflict are left for a person.");

            return self::SUCCESS;
        }
        $ok->update(['status' => 'approved', 'reviewed_by' => mb_substr($reviewer, 0, 100), 'reviewed_at' => now(), 'review_note' => mb_substr(trim((string) $this->option('note')), 0, 500) ?: null]);
        $this->info("approved {$n} note(s) as {$reviewer}; {$skipped} with a conflict are left for a person.");

        return self::SUCCESS;
    }
}
