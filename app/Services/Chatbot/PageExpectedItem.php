<?php

namespace App\Services\Chatbot;

use App\Services\PageSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang item at presyo na talagang binebenta ng page, para sa content check:
 * 1. orders: ang pinakamadalas na pares ng ITEM_NAME + COD sa macro_output ng huling 3 araw (ngayon at 2 araw bago, Manila);
 * 2. ads: ang pinakabagong item_name sa ads_manager_reports (walang presyo);
 * 3. item_settings: ang pinakabagong page_item_settings na epektibo na (item + mode_cod_int).
 * Ang source ay kasama sa sagot para makita kung saan galing.
 */
class PageExpectedItem
{
    public const DAYS = 3;

    /** @var array<string, array<string, array{item: string, price: ?int, count: int, last: string}>>|null page key => pares => bilang (isang basa kada instance) */
    private ?array $orders = null;

    public function __construct(private PageSettings $settings)
    {
    }

    /** @return array{item: string, price: ?int, source: string, orders: int}|null */
    public function for(string $page): ?array
    {
        $pk = $this->settings->pageKey($page);
        $pairs = $this->orders()[$pk] ?? [];
        if ($pairs) {
            usort($pairs, fn ($a, $b) => [$b['count'], $b['last'], $a['item']] <=> [$a['count'], $a['last'], $b['item']]);
            return ['item' => $pairs[0]['item'], 'price' => $pairs[0]['price'], 'source' => 'orders', 'orders' => array_sum(array_column($pairs, 'count'))];
        }

        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        if (Schema::hasTable('ads_manager_reports') && Schema::hasColumn('ads_manager_reports', 'item_name')) {
            $item = DB::table('ads_manager_reports')->whereRaw("LOWER($trim(page_name)) = ?", [$pk])
                ->whereNotNull('item_name')->whereRaw("$trim(item_name) <> ''")->orderByDesc('day')->orderByDesc('id')->value('item_name');
            if ($item !== null) return ['item' => self::norm((string) $item), 'price' => null, 'source' => 'ads', 'orders' => 0];
        }

        if (Schema::hasTable('page_item_settings') && Schema::hasColumn('page_item_settings', 'mode_cod_int')) {
            $row = DB::table('page_item_settings')->whereRaw("LOWER($trim(page_name)) = ?", [$pk])
                ->where('effective_date', '<=', now('Asia/Manila')->toDateString())->orderByDesc('effective_date')->orderByDesc('id')->first(['item_name', 'mode_cod_int']);
            if ($row !== null && trim((string) $row->item_name) !== '') {
                return ['item' => self::norm((string) $row->item_name), 'price' => $row->mode_cod_int === null ? null : (int) $row->mode_cod_int, 'source' => 'item_settings', 'orders' => 0];
            }
        }

        return null;
    }

    /**
     * Ang mga pares ng lahat ng page sa huling 3 araw, sa isang naka-group na query (ang index ay ts_date, PAGE); ang
     * pangalan ng page ay tinutugma sa PHP (pageKey), dahil may dobleng espasyo minsan ang PAGE.
     */
    private function orders(): array
    {
        if ($this->orders !== null) return $this->orders;
        $this->orders = [];
        if (!Schema::hasTable('macro_output') || !Schema::hasColumn('macro_output', 'ITEM_NAME') || !Schema::hasColumn('macro_output', 'COD')) return $this->orders;

        $g = DB::getQueryGrammar();
        [$page, $item, $cod] = [$g->wrap('PAGE'), $g->wrap('ITEM_NAME'), $g->wrap('COD')];
        $rows = DB::table('macro_output')
            ->where('ts_date', '>=', now('Asia/Manila')->subDays(self::DAYS - 1)->toDateString())
            ->whereNotNull('PAGE')->whereNotNull('ITEM_NAME')
            ->selectRaw("$page AS page, $item AS item, $cod AS cod, COUNT(*) AS c, MAX(ts_date) AS last")
            ->groupBy('PAGE', 'ITEM_NAME', 'COD')->get();
        foreach ($rows as $r) {
            $name = self::norm((string) $r->item);
            if ($name === '') continue;
            $digits = preg_replace('/[^0-9.]/', '', (string) $r->cod);
            $price = is_numeric($digits) ? (int) round((float) $digits) : null;
            $pk = $this->settings->pageKey((string) $r->page);
            $key = $name . '|' . $price;
            $cur = $this->orders[$pk][$key] ?? ['item' => $name, 'price' => $price, 'count' => 0, 'last' => ''];
            $cur['count'] += (int) $r->c;
            $cur['last'] = max($cur['last'], (string) $r->last);
            $this->orders[$pk][$key] = $cur;
        }

        return $this->orders;
    }

    public static function norm(string $item): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($item)), 'UTF-8');
    }
}
