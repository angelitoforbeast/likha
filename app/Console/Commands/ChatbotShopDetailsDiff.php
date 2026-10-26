<?php

namespace App\Console\Commands;

use App\Models\PageSetting;
use App\Services\Chatbot\ShopDetails;
use App\Services\ChatbotFields;
use Illuminate\Console\Command;

/**
 * Minsanan, basa lang: ang mga page na ang lumang tina-type na SHOP DETAILS ay iba sa binubuo na ngayon (ShopDetails),
 * para masuri ng staff ang ITEM NAME IN JNT DASHBOARD, PRICE at QUANTITY nila. Walang binabago.
 */
class ChatbotShopDetailsDiff extends Command
{
    protected $signature = 'chatbot:shop-details-diff';

    protected $description = 'List pages whose old typed SHOP DETAILS differs from the one built from their fields (read-only)';

    public function handle(ShopDetails $shop): int
    {
        $stored = PageSetting::where('provider', ChatbotFields::PROVIDER)->where('key', ShopDetails::KEY)->where('active', true)
            ->orderBy('page_name')->get();
        $rows = [];
        foreach ($stored as $s) {
            $old = trim(str_replace("\r\n", "\n", (string) $s->plainValue()));
            $new = $shop->for((string) $s->page_name);
            if ($old !== $new) $rows[] = [$s->page_name, $old, $new];
        }
        if ($rows) $this->table(['Page', 'Old (typed)', 'New (built)'], $rows);
        $this->line(count($rows) . ' of ' . $stored->count() . ' pages differ.');

        return self::SUCCESS;
    }
}
