<?php

namespace App\Services;

/**
 * Sariling note ng CEO per (page, araw) — nasa sarili niyang tables (page_day_ceo_actions + logs).
 * Pareho ng logic ng PageDayClaudeActionService; ibang table lang. HUWAG itong gamitin sa kahit anong
 * artisan command: ang sumusulat para kay Claude ay hindi dapat makabasa ng note ng CEO.
 */
class PageDayCeoActionService extends PageDayClaudeActionService
{
    protected string $table    = 'page_day_ceo_actions';
    protected string $logTable = 'page_day_ceo_action_logs';
}
