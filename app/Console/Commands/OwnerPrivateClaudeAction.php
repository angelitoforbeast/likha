<?php

namespace App\Console\Commands;

use App\Services\PageDayClaudeActionService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Tanging paraan para magsulat ng "Claude Action" / "Claude Reason" ng isang (page, araw).
 * Walang HTTP route na sumusulat dito; ang logic ay nasa PageDayClaudeActionService.
 */
class OwnerPrivateClaudeAction extends Command
{
    protected $signature = 'owner-private:claude-action {page_key} {ts_date} {--action=} {--reason=} {--source=} {--clear}';

    protected $description = 'Set or clear the Claude Action / Reason note of one page-day on /owner/private (CEO sees it)';

    public function handle(PageDayClaudeActionService $service): int
    {
        $pageKey = (string) $this->argument('page_key');
        $tsDate  = (string) $this->argument('ts_date');
        $action  = $this->option('action');
        $reason  = $this->option('reason');
        $source  = $this->option('source');

        try {
            if ($this->option('clear')) {
                if ($action !== null || $reason !== null) {
                    throw new InvalidArgumentException('--clear cannot be combined with --action or --reason.');
                }
                $result = $service->clear($pageKey, $tsDate, $source);
            } else {
                if ($action === null && $reason === null) {
                    throw new InvalidArgumentException('Pass --action and/or --reason, or --clear.');
                }
                $result = $service->save($pageKey, $tsDate, $action, $reason, $source);
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($result['status'] === 'nothing'
            ? "nothing to clear for {$result['page_key']} {$result['ts_date']}"
            : "{$result['status']} Claude action for {$result['page_key']} {$result['ts_date']}");

        return self::SUCCESS;
    }
}
