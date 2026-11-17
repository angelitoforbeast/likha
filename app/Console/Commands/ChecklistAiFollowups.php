<?php

namespace App\Console\Commands;

use App\Services\AiFollowups;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Ang Marketing head (spec 293): gumagawa ng draft na follow-up para sa marketing staff na late o not_approved ang
 * checklist task ngayong araw (bawat 30 minuto, 9 AM hanggang 9 PM Manila, via scheduler). Ang expiry ng mga lumang
 * draft at ang Done events ay laging ginagawa; ang pag-draft ay kapag naka-on lang sa /checklist/ai-followups.
 */
class ChecklistAiFollowups extends Command
{
    protected $signature   = 'checklist:ai-followups';
    protected $description = 'Draft AI follow-ups for late or not approved marketing checklist tasks (CEO approves them)';

    public function handle(): int
    {
        if (!Schema::hasTable('checklist_tasks') || !Schema::hasColumn('checklist_tasks', 'origin')) {
            return self::SUCCESS;
        }
        $s = AiFollowups::run(Carbon::now(AiFollowups::TZ));
        $this->info($s['enabled']
            ? "ai-followups: {$s['candidates']} candidate(s), {$s['drafted']} drafted, {$s['skipped']} over the daily cap, {$s['failed']} failed"
                . ($s['budget_reached'] ? ', budget reached' : '')
            : 'ai-followups: off (or no deadline left today), nothing drafted');

        return self::SUCCESS;
    }
}
