<?php

namespace App\Console\Commands;

use App\Support\ItemBaseKey;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mungkahi ng category kada item gamit ang keyword rules (config/item_categories.php).
 * Default = dry run (walang isinusulat). `--apply` = mag-insert LANG ng assignment para sa
 * mga item na wala pang category — hindi kailanman nag-a-update ng existing.
 * Items = ITEM_NAME sa macro_output (huling 90 araw) + supply_order_items + item_supplier_quotes.
 */
class SuggestItemCategories extends Command
{
    protected $signature   = 'items:suggest-categories {--apply : I-save ang mga mungkahi (para lang sa walang category pa)}';
    protected $description = 'Suggest (dry run) or apply item categories from keyword rules';

    public function handle(): int
    {
        if (! Schema::hasTable('item_categories') || ! Schema::hasTable('item_category_assignments')) {
            $this->error('item_categories / item_category_assignments table wala pa — patakbuhin: php artisan migrate --force');
            return self::FAILURE;
        }

        $items = $this->collectItems(); // key => base
        uasort($items, fn ($a, $b) => strcmp(mb_strtolower($a, 'UTF-8'), mb_strtolower($b, 'UTF-8')));

        $rules    = (array) config('item_categories.rules', []);
        $existing = DB::table('item_category_assignments as a')
            ->join('item_categories as c', 'c.id', '=', 'a.category_id')
            ->pluck('c.name', 'a.item_key')->all();

        $matched = []; // key => [base, category]
        $unmatched = [];
        foreach ($items as $key => $base) {
            $category = $this->match($base, $rules);
            if ($category === null) { $unmatched[] = $base; continue; }
            $matched[$key] = [$base, $category];
        }

        foreach ($matched as $key => [$base, $category]) {
            $this->line("$base → $category" . (isset($existing[$key]) ? " [may category na: {$existing[$key]}]" : ''));
        }
        $this->line('Walang tugma (' . count($unmatched) . '):');
        foreach ($unmatched as $base) $this->line($base);

        if (! $this->option('apply')) {
            $this->line('Walang isinulat. Gamitin ang --apply para i-save ang mga mungkahi (hindi ginagalaw ang may category na).');
            return self::SUCCESS;
        }

        $ids   = DB::table('item_categories')->pluck('id', 'name')->all();
        $saved = 0;
        foreach ($matched as $key => [$base, $category]) {
            if (isset($existing[$key])) continue;
            if (! isset($ids[$category])) {
                $this->warn("Walang category na \"$category\" sa item_categories — nilaktawan: $base");
                continue;
            }
            DB::table('item_category_assignments')->insert([
                'item_key' => $key, 'category_id' => $ids[$category], 'updated_by' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $saved++;
        }
        $this->line("Na-save: $saved");

        return self::SUCCESS;
    }

    /** @return array<string,string> base key => display base (una ang nakita, original case) */
    private function collectItems(): array
    {
        $since = Carbon::now('Asia/Manila')->subDays(90)->toDateString();
        $names = [];

        if (Schema::hasTable('macro_output')) {
            $names = array_merge($names, DB::table('macro_output')->where('ts_date', '>=', $since)
                ->whereNotNull('ITEM_NAME')->distinct()->orderBy('ITEM_NAME')->pluck('ITEM_NAME')->all());
        }
        foreach (['supply_order_items', 'item_supplier_quotes'] as $table) {
            if (Schema::hasTable($table)) {
                $names = array_merge($names, DB::table($table)->whereNotNull('item_name')
                    ->distinct()->orderBy('item_name')->pluck('item_name')->all());
            }
        }

        $items = [];
        foreach ($names as $name) {
            $p = ItemBaseKey::parse((string) $name);
            if ($p['key'] === '') continue;
            $items[$p['key']] ??= $p['base'];
        }

        return $items;
    }

    /** Unang category na may keyword na nasa pangalan (lowercase, may espasyo sa magkabilang dulo). */
    private function match(string $base, array $rules): ?string
    {
        $haystack = ' ' . mb_strtolower($base, 'UTF-8') . ' ';
        foreach ($rules as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, mb_strtolower($keyword, 'UTF-8'))) return $category;
            }
        }

        return null;
    }
}
