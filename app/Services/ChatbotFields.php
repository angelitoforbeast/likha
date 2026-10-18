<?php

namespace App\Services;

use App\Models\PageSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang mga botfield ng /chatbot: bawat isa ay key sa page_settings (provider chatbot), isang row kada page. Ang mga column
 * ay ang mga default (ayon sa sheet ng owner, sa ganitong ayos), ang idinagdag sa "+ Column" (app_settings, para lumabas
 * kahit wala pang value), at ang anumang key na gamit na sa provider chatbot. Ang ACTIVE (key active) ay sa switch lang.
 */
class ChatbotFields
{
    public const PROVIDER = 'chatbot';
    public const RESERVED = ['active'];
    public const ADDED_SETTING = 'chatbot.columns'; // app_settings: JSON {key: label}
    public const MAX_VALUE = 10000;

    /** @return array<string, string> key => label, sa ayos ng sheet */
    public static function defaults(): array
    {
        $cols = [
            'flow' => 'FLOW', 'price' => 'PRICE', 'quantity' => 'QUANTITY', 'item_name_in_jnt_dashboard' => 'ITEM NAME IN JNT DASHBOARD',
            'main_flow' => 'Main Flow', 'main_flow_2' => 'Main Flow 2', 'fill_up_form' => 'Fill-Up Form', 'loop_3_form' => 'Loop 3 Form',
            'prompt_1' => 'Prompt 1', 'prompt_2' => 'Prompt 2', 'prompt_3' => 'Prompt 3', 'picture' => 'PICTURE',
        ];
        for ($i = 1; $i <= 20; $i++) $cols['seq_' . $i] = 'Seq ' . $i;
        for ($i = 1; $i <= 20; $i++) $cols['seq_img_' . $i] = 'Seq Img ' . $i;

        return $cols;
    }

    /** 'image' (URL na may preview), 'long' (modal na editor), o 'text'. */
    public static function kind(string $key): string
    {
        if ($key === 'picture' || preg_match('/^seq_img_\d+$/', $key)) return 'image';
        if (preg_match('/^(prompt|seq)_\d+$/', $key)) return 'long';

        return 'text';
    }

    /** Ang key ng isang pangalan ng column: maliliit na titik, numero at _ ("Promo Text!" => promo_text). */
    public static function keyOf(string $name): string
    {
        return mb_substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_'), 0, 100);
    }

    /** @return array<int, array{key: string, label: string, kind: string}> */
    public function columns(): array
    {
        $labels = self::defaults() + $this->added();
        $inUse = PageSetting::where('provider', self::PROVIDER)->where('active', true)->distinct()->pluck('key');
        foreach ($inUse as $key) {
            if (!in_array($key, self::RESERVED, true)) $labels[$key] ??= ucwords(str_replace('_', ' ', $key));
        }

        $out = [];
        foreach ($labels as $key => $label) $out[] = ['key' => (string) $key, 'label' => $label, 'kind' => self::kind((string) $key)];

        return $out;
    }

    public function isColumn(string $key): bool
    {
        return in_array($key, array_column($this->columns(), 'key'), true);
    }

    /**
     * Bagong column para sa lahat ng page. Ang dati nang column (default man o idinagdag) ay ibinabalik lang.
     * @return array{key: string, label: string}|null null = walang magagamit na pangalan o nakalaan (ACTIVE)
     */
    public function add(string $name): ?array
    {
        $label = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N} \-]/u', '', $name)));
        $key = self::keyOf($label);
        if ($key === '' || in_array($key, self::RESERVED, true)) return null;

        $existing = collect($this->columns())->firstWhere('key', $key);
        if ($existing) return ['key' => $key, 'label' => $existing['label']];

        $added = $this->added() + [$key => mb_substr($label, 0, 100)];
        DB::table('app_settings')->updateOrInsert(['key' => self::ADDED_SETTING], ['value' => json_encode($added), 'updated_at' => now(), 'created_at' => now()]);

        return ['key' => $key, 'label' => $added[$key]];
    }

    /** @return array<string, string> */
    private function added(): array
    {
        if (!Schema::hasTable('app_settings')) return [];
        $json = json_decode((string) DB::table('app_settings')->where('key', self::ADDED_SETTING)->value('value'), true);

        return is_array($json) ? array_filter($json, fn ($l, $k) => is_string($l) && is_string($k) && self::keyOf($k) === $k, ARRAY_FILTER_USE_BOTH) : [];
    }
}
