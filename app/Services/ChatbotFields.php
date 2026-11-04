<?php

namespace App\Services;

use App\Models\PageSetting;
use App\Services\Chatbot\ShopDetails;
use Illuminate\Support\Facades\Cache;
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
    public const RESERVED = ['active', 'flow_id', 'mock_ai', 'mock_ai_latency_ms', 'provider', 'gateway', 'gateway_app', 'auto_switch',
        'ai_provider', 'ai_fallback', 'ai_local_model', 'ai_local_timeout', 'ai_local_concurrency', 'ai_breaker_failures', 'ai_breaker_seconds', 'ai_local_reasoning_effort']; // flow_id: ang flow na pinili sa dropdown (id), system lang; mock_ai: pekeng AI ng stress-test page (CLI lang); provider: ang select na Botcake / Forbeast Gateway; gateway: aling gateway (forbeast / incepxionbot, Gateways); gateway_app: ang address ng forbeast gateway (Gateways::routes); auto_switch: ang talaan ng kusang lipat (ForbeastWatch); ai_*: ang AI ng page at ng site (ChatbotAiSettings)
    public const ADDED_SETTING = 'chatbot.columns'; // app_settings: JSON {key: label}
    public const MAX_VALUE = 50000; // letra: ang mahahabang AI prompt (hal. 14,113)
    public const MAX_BYTES = 60000; // ang TEXT ng MySQL ay 65,535 bytes: ang ñ at emoji ay 2-4 bytes, kaya may sariling check (hindi pinuputol)
    public const MAX_ADDED = 200; // hanggang ilang column ang maidadagdag sa "+ Column" (walang tanggalan sa screen)
    public const LABELS_CACHE = 'chatbot-fields:labels';
    public const LABELS_SECONDS = 60;

    /** @return array<string, string> key => label, sa ayos ng sheet */
    public static function defaults(): array
    {
        $cols = [
            'flow' => 'FLOW', 'price' => 'PRICE', 'quantity' => 'QUANTITY', 'item_name_in_jnt_dashboard' => 'ITEM NAME IN JNT DASHBOARD',
            'main_flow' => 'Main Flow', 'main_flow_2' => 'Main Flow 2', 'fill_up_form' => 'Fill-Up Form', 'loop_3_form' => 'Loop 3 Form',
            'prompt_1' => 'Prompt 1', 'prompt_2' => 'Prompt 2', 'prompt_3' => 'Prompt 3', 'picture' => 'PICTURE',
        ];
        // Magkatabi ang larawan at text ng bawat step (larawan muna, gaya ng padala): Seq Img 1, Seq 1, Seq Img 2, …
        for ($i = 1; $i <= 20; $i++) {
            $cols['seq_img_' . $i] = 'Seq Img ' . $i;
            $cols['seq_' . $i] = 'Seq ' . $i;
        }
        // Ang After Order Sequence (nagsisimula kapag na-upload ang order): larawan muna, gaya ng Seq.
        for ($i = 1; $i <= 4; $i++) {
            $cols['after_order_img_' . $i] = 'After Order Img ' . $i;
            $cols['after_order_' . $i] = 'After Order ' . $i;
        }
        // Ang sagot sa comment (page na nasa Forbeast): ang public reply (isa sa mga may laman, random) at ang isang private message.
        for ($i = 1; $i <= 3; $i++) $cols['comment_reply_' . $i] = 'Comment Reply ' . $i;
        $cols['comment_pm'] = 'Comment PM';

        return $cols;
    }

    /** 'image' (URL na may preview), 'long' (modal na editor), o 'text'. */
    public static function kind(string $key): string
    {
        if ($key === 'flow') return 'flow'; // dropdown ng mga flow (/chatbot)
        if ($key === 'shop_details') return 'auto'; // binubuo mula sa ITEM NAME IN JNT DASHBOARD, PRICE at QUANTITY: basa lang
        if ($key === 'picture' || preg_match('/^(seq|after_order)_img_\d+$/', $key)) return 'image';
        if (preg_match('/^(prompt|seq|after_order|comment_reply)_\d+$/', $key) || $key === 'comment_pm') return 'long';

        return 'text';
    }

    /**
     * Ang mali sa value ng isang botfield (haba, bytes, link ng larawan), o null. Iisa para sa /chatbot (cell, paste,
     * restore) at sa page values API. $schemes: ang tinatanggap na simula ng link ng larawan.
     */
    public static function valueError(string $key, string $value, array $schemes = ['http', 'https']): ?string
    {
        if ($key === ShopDetails::KEY) return ShopDetails::READ_ONLY;
        if (mb_strlen($value) > self::MAX_VALUE) return 'At most ' . self::MAX_VALUE . ' characters.';
        if (strlen($value) > self::MAX_BYTES) return 'Too long: at most ' . self::MAX_BYTES . ' bytes. Accented letters and emoji take 2 to 4 bytes each.';
        if ($value !== '' && self::kind($key) === 'image' && !(filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), $schemes, true))) {
            return 'Use an image link that starts with ' . implode(' or ', array_map(fn ($s) => $s . '://', $schemes)) . '.';
        }

        return null;
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
            if (!in_array($key, self::RESERVED, true)) $labels[$key] ??= $key === ShopDetails::KEY ? ShopDetails::LABEL : ucwords(str_replace('_', ' ', $key));
        }
        // Ang SHOP DETAILS ay laging column (binubuo, Chatbot\ShopDetails), sa dati nitong puwesto (o sa dulo): ang paste ng sheet ay hindi naurong.
        $labels[ShopDetails::KEY] ??= ShopDetails::LABEL;

        $out = [];
        foreach ($labels as $key => $label) $out[] = ['key' => (string) $key, 'label' => $label, 'kind' => self::kind((string) $key)];

        return $out;
    }

    /**
     * key => label ng bawat column, para sa takbo ng bot (ChatbotPage::botfields). Naka-cache nang LABELS_SECONDS: ang
     * columns() ay binabasa ang lahat ng chatbot setting ng lahat ng page, at sa 50–100 chat/s iyon ang pinakamabigat na
     * query ng bawat takbo. Ang "+ Column" (add) ay binubura ang cache, kaya agad na may label ang bagong column.
     * @return array<string, string>
     */
    public function labels(): array
    {
        return Cache::remember(self::LABELS_CACHE, self::LABELS_SECONDS, fn () => array_column($this->columns(), 'label', 'key'));
    }

    public function isColumn(string $key): bool
    {
        return in_array($key, array_column($this->columns(), 'key'), true);
    }

    /**
     * Bagong column para sa lahat ng page. Ang dati nang column (default man o idinagdag) ay ibinabalik lang.
     * @return array{key: string, label: string}|null null = walang magagamit na pangalan, nakalaan (ACTIVE), o puno na (MAX_ADDED)
     */
    public function add(string $name): ?array
    {
        $label = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N} \-]/u', '', $name)));
        $key = self::keyOf($label);
        if ($key === '' || in_array($key, self::RESERVED, true) || PageSettings::keyLike(self::PROVIDER, $key)) return null;

        $existing = collect($this->columns())->firstWhere('key', $key);
        if ($existing) return ['key' => $key, 'label' => $existing['label']];

        // Naka-lock ang row habang binabasa at isinusulat, para hindi mawala ang isa sa dalawang sabay na dagdag.
        $col = DB::transaction(function () use ($key, $label) {
            if (!DB::table('app_settings')->where('key', self::ADDED_SETTING)->exists()) {
                DB::table('app_settings')->insertOrIgnore(['key' => self::ADDED_SETTING, 'value' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('app_settings')->where('key', self::ADDED_SETTING)->lockForUpdate()->first();
            $added = $this->added();
            if (isset($added[$key])) return ['key' => $key, 'label' => $added[$key]];
            if (count($added) >= self::MAX_ADDED) return null;

            $added[$key] = mb_substr($label, 0, 100);
            DB::table('app_settings')->where('key', self::ADDED_SETTING)->update(['value' => json_encode($added), 'updated_at' => now()]);

            return ['key' => $key, 'label' => $added[$key]];
        });
        Cache::forget(self::LABELS_CACHE); // pagkatapos ng commit; ang takbong nagbasa bago nito ay maaaring magbalik ng luma hanggang LABELS_SECONDS

        return $col;
    }

    /** @return array<string, string> */
    private function added(): array
    {
        if (!Schema::hasTable('app_settings')) return [];
        $json = json_decode((string) DB::table('app_settings')->where('key', self::ADDED_SETTING)->value('value'), true);

        return is_array($json) ? array_filter($json, fn ($l, $k) => is_string($l) && is_string($k) && self::keyOf($k) === $k, ARRAY_FILTER_USE_BOTH) : [];
    }
}
