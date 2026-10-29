<?php

namespace App\Services\Chatbot;

use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ang mga botfield ng page na nababasa at napapalitan mula sa Forbeast (/api/gateway/pages/{id}/values), sa ayos ng
 * kontrata: ang mga column lang na mayroon sa site na ito (seq_N / seq_img_N hanggang sa sariling bilang). Hindi kailanman
 * ang prompt, FLOW / flow_id, AI settings, auto_switch, provider, secret o botcake.*. Ang SHOP DETAILS ay basa lang
 * (binubuo, ShopDetails). Ang sulat ay ang karaniwang PageSettings::set / deactivate (may history), may source na actor.
 */
class PageValues
{
    /** Ang "by" ng value na isinulat o binura mula sa Forbeast (source / replaced_source). */
    public const VIA = 'via Forbeast: ';
    private const HEAD = ['main_flow', 'main_flow_2', 'fill_up_form', 'loop_3_form', 'item_name_in_jnt_dashboard', 'price', 'quantity', 'picture'];
    private const TAIL = ['comment_reply_1', 'comment_reply_2', 'comment_reply_3', 'comment_pm', 'after_order_1'];

    public function __construct(private PageSettings $settings, private ChatbotFields $fields, private FlowFields $flowFields)
    {
    }

    public static function by(?string $source): ?string
    {
        return $source !== null && $source !== '' ? self::VIA . $source : null;
    }

    /**
     * @param array<int, array{key: string, label: string}>|null $all ChatbotFields::columns(), kapag nabasa na
     * @return array<string, string> key => label (gaya ng nasa /chatbot), sa ayos ng kontrata
     */
    public function columns(?array $all = null): array
    {
        $labels = array_column($all ?? $this->fields->columns(), 'label', 'key');
        $numbered = function (string $prefix) use ($labels): array {
            $keys = array_values(array_filter(array_keys($labels), fn ($k) => (bool) preg_match('/^' . $prefix . '\d+$/', (string) $k)));
            usort($keys, fn ($a, $b) => (int) substr($a, strlen($prefix)) <=> (int) substr($b, strlen($prefix)));

            return $keys;
        };
        $out = [];
        foreach (array_merge(self::HEAD, $numbered('seq_'), $numbered('seq_img_'), self::TAIL, [ShopDetails::KEY]) as $key) {
            if (isset($labels[$key])) $out[$key] = $labels[$key];
        }

        return $out;
    }

    /**
     * Ang mga key na napapalitan: lahat ng nasa listahan maliban sa SHOP DETAILS, at ang iba pang botfield na kailangan ng
     * flow ng page ($needed, key => label; hal. isang Prompt na binabasa ng flow), para mapunan ang bawat kailangan.
     * @return array<string, string>
     */
    public function editable(?array $columns = null, array $needed = []): array
    {
        return array_diff_key(($columns ?? $this->columns()) + $needed, [ShopDetails::KEY => true]);
    }

    /**
     * Ang mga field ng listahan na may value ng page (FlowFields::field). $required: key => bool, ayon sa flow ng page.
     * @return array<int, array<string, mixed>>
     */
    public function read(string $page, ?array $columns = null, array $required = []): array
    {
        $rows = $this->flowFields->rows($page);
        $out = [];
        foreach ($columns ?? $this->columns() as $key => $label) $out[] = $this->flowFields->field($key, $label, $page, $rows, $required[$key] ?? false);

        return $out;
    }

    /**
     * Isinusulat ang mga value (key => string|null; ang blangko o null ay binubura ang value, gaya ng cell sa /chatbot),
     * lahat o wala sa isang transaction. Ang mga key at value ay nasuri na (editable, ChatbotFields::valueError).
     * @param array<string, ?string> $values
     */
    public function save(string $page, array $values, string $actor): void
    {
        DB::transaction(function () use ($page, $values, $actor) {
            foreach ($values as $key => $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    $actives = $this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('key', $key)->where('active', true)->get();
                    foreach ($actives as $s) $this->settings->deactivate($s, null, $actor);
                    continue;
                }
                $this->settings->set($page, ChatbotFields::PROVIDER, $key, $value, false, null, false, $actor);
            }
        });
        // Ang takbo ng bot ay binabasa ang labels mula sa cache (LABELS_SECONDS) at ang settings ng page mula sa cache
        // nito: parehong binubura, para ang susunod na chat ay may bagong values agad.
        Cache::forget(ChatbotFields::LABELS_CACHE);
        PageSettings::forget($page);
    }
}
