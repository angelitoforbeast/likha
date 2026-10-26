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

    public function __construct(private PageSettings $settings, private ChatbotFields $fields)
    {
    }

    public static function by(?string $source): ?string
    {
        return $source !== null && $source !== '' ? self::VIA . $source : null;
    }

    /** @return array<string, string> key => label (gaya ng nasa /chatbot), sa ayos ng kontrata */
    public function columns(): array
    {
        $labels = array_column($this->fields->columns(), 'label', 'key');
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

    /** @return array<string, string> ang mga key na napapalitan (lahat maliban sa SHOP DETAILS) */
    public function editable(): array
    {
        return array_diff_key($this->columns(), [ShopDetails::KEY => true]);
    }

    /** @return array<int, array{key: string, label: string, type: string, value: ?string, editable: bool, updated_at: ?string}> */
    public function read(string $page): array
    {
        // Ayon sa id: ang pinakabagong active row ang masusunod sa parehong key, gaya ng PageSettings::get.
        $rows = $this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('active', true)->orderBy('id')->get()->keyBy('key');
        $at = fn ($s) => $s?->created_at?->toIso8601String();
        $out = [];
        foreach ($this->columns() as $key => $label) {
            if ($key === ShopDetails::KEY) {
                $parts = array_filter(array_map(fn ($k) => $rows[$k] ?? null, array_combine(ShopDetails::PARTS, ShopDetails::PARTS)));
                $last = collect($parts)->sortBy('created_at')->last();
                $out[] = ['key' => $key, 'label' => $label, 'type' => 'textarea',
                    'value' => ShopDetails::build($page, array_map(fn ($s) => (string) $s->plainValue(), $parts)), 'editable' => false, 'updated_at' => $at($last)];
                continue;
            }
            $s = $rows[$key] ?? null;
            $kind = ChatbotFields::kind($key);
            $out[] = ['key' => $key, 'label' => $label, 'type' => $kind === 'image' ? 'image' : ($kind === 'long' ? 'textarea' : 'text'),
                'value' => $s?->plainValue(), 'editable' => true, 'updated_at' => $at($s)];
        }

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
            foreach (array_keys($this->editable()) as $key) {
                if (!array_key_exists($key, $values)) continue;
                $value = trim((string) $values[$key]);
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
