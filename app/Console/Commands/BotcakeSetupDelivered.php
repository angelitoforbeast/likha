<?php

namespace App\Console\Commands;

use App\Models\PageSetting;
use App\Services\ChatblastSetupCheck;
use Illuminate\Console\Command;

/**
 * Ang delivered check ng bagong chatblast flow (kada oras sa :45): ang mga page na hindi pa kumpirmado (pending o
 * not delivered) lang. Delivered = tapos na; hindi = X sa Check column at hindi Ready ang page.
 */
class BotcakeSetupDelivered extends Command
{
    protected $signature   = 'botcake:setup-delivered';
    protected $description = 'Check in Botcake that the chatblast flow set by the setup check delivers, for pages not yet confirmed';

    public function handle(ChatblastSetupCheck $check): int
    {
        $pages = PageSetting::where('active', true)->where('provider', 'botcake')->where('key', ChatblastSetupCheck::DELIVERY_KEY)
            ->where('page_name', '!=', ChatblastSetupCheck::ALL_PAGE)->get(['page_name', 'value'])
            ->filter(fn ($s) => in_array(json_decode((string) $s->value, true)['status'] ?? '', ['pending', 'not_delivered'], true))
            ->pluck('page_name')->unique()->values();
        $counts = [];
        foreach ($pages as $page) {
            $r = $check->deliveryCheck((string) $page);
            $counts[$r] = ($counts[$r] ?? 0) + 1;
        }
        $this->info('Delivered check: ' . ($counts ? implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($counts), $counts)) : 'no page to check'));

        return self::SUCCESS;
    }
}
