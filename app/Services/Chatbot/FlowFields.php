<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Services\ChatbotFields;
use App\Services\PageSettings;

/**
 * Ang mga field na kailangan ng isang flow sa isang page (para sa Forbeast: template code at ang page values API), at ang
 * paglalarawan ng bawat field (group, uri, kailangan ba, hint, haba, sino ang huling sumulat). Ang "ginagamit" ay gaya
 * ng Check column (ChatbotPageCheck::usedKeys): bawat {{Pangalan}} ng published na graph at ng mga flow ng sequence nito,
 * at ang botfield na binabanggit ng ginagamit na botfield. Ang SHOP DETAILS ay binubuo kaya ang mga bahagi nito ang
 * kailangan; ang Seq N at Seq Img N ay laging magkapares.
 */
class FlowFields
{
    public const GROUPS = ['Product', 'Forms and messages', 'Sequence', 'Comment replies', 'Shop details', 'Other'];
    public const LIKHA = 'likha';
    public const FORBEAST = 'forbeast';
    /** Hindi kailanman required: walang laman = walang ipapadala (gaya ng Check). */
    private const OPTIONAL = '/^(seq_(img_)?(1[1-9]|20)|after_order_(img_)?\d+)$/';
    private const PRODUCT = ['item_name_in_jnt_dashboard', 'price', 'quantity', 'picture'];
    private const FORMS = ['main_flow', 'main_flow_2', 'fill_up_form', 'loop_3_form'];
    private const NUMBERS = ['price', 'quantity'];
    private const HINTS = [
        'item_name_in_jnt_dashboard' => 'The item name exactly as it is in the J&T dashboard.',
        'price' => 'The price in pesos, e.g. 1299.',
        'quantity' => 'How many come in one order, e.g. 1.',
        'picture' => 'A link to the product picture (https).',
        'main_flow' => 'A message the flow sends.',
        'main_flow_2' => 'A message the flow sends.',
        'fill_up_form' => 'The order form the customer fills in (name, phone, address, landmark).',
        'loop_3_form' => 'The order form sent again when an answer is missing.',
        'comment_pm' => 'The private message sent to someone who comments.',
        ShopDetails::KEY => ShopDetails::NOTE,
    ];

    public function __construct(private PageSettings $settings, private ChatbotFields $fields)
    {
    }

    /**
     * Ang flow na nakatali sa page (chatbot.flow_id), naka-On man o naka-Off ang bot; null kapag wala o na-delete.
     * Ang flow ng mga ad group ay hindi: ang page lang.
     */
    public function pageFlow(string $page): ?ChatbotFlow
    {
        $id = trim((string) $this->settings->get($page, ChatbotFields::PROVIDER, 'flow_id'));

        return ctype_digit($id) && \Illuminate\Support\Facades\Schema::hasTable('chatbot_flows') ? ChatbotFlow::find((int) $id) : null;
    }

    /**
     * Ang mga active na botfield row ng page, isa bawat key (ang pinakabago ayon sa id, gaya ng PageSettings::get).
     * @return array<string, PageSetting>
     */
    public function rows(string $page): array
    {
        return $this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('active', true)->orderBy('id')->get()->keyBy('key')->all();
    }

    /**
     * Ang mga field na kailangan ng flow sa page, naka-ayos ayon sa group at sa ayos ng mga column ng site.
     * @param array<int, array{key: string, label: string, kind: string}>|null $columns ChatbotFields::columns()
     * @param array<string, PageSetting>|null $rows rows()
     * @return array<int, array<string, mixed>>
     */
    public function forFlow(ChatbotFlow $flow, string $page, ?array $columns = null, ?array $rows = null): array
    {
        $columns ??= $this->fields->columns();
        $rows ??= $this->rows($page);
        $labels = array_column($columns, 'label', 'key');
        $out = [];
        foreach ($this->needs($flow, $page, $columns, $rows) as $key => $required) {
            $out[] = $this->field($key, $labels[$key] ?? $key, $page, $rows, $required);
        }

        return $out;
    }

    /**
     * key => required, ng bawat field na kailangan ng flow, sa ayos na ibinabalik sa Forbeast.
     * @return array<string, bool>
     */
    public function needs(ChatbotFlow $flow, string $page, array $columns, array $rows): array
    {
        $values = [];
        foreach ($rows as $key => $s) if (!in_array($key, ChatbotFields::RESERVED, true)) $values[$key] = trim((string) $s->plainValue());
        $values[ShopDetails::KEY] = ShopDetails::build($page, $values);
        $labels = array_column($columns, 'label', 'key');

        $need = [];
        foreach (array_keys(ChatbotPageCheck::usedKeys($flow, $columns, $values)) as $key) {
            if (!isset($labels[$key]) || in_array($key, ChatbotFields::RESERVED, true)) continue;
            $need[$key] = $key !== ShopDetails::KEY && !preg_match(self::OPTIONAL, $key);
        }
        // Ang SHOP DETAILS ay binubuo mula sa item, presyo at dami: iyon ang pinupunan.
        if (isset($need[ShopDetails::KEY])) {
            foreach (ShopDetails::PARTS as $part) if (isset($labels[$part])) $need[$part] = true;
        }
        // Ang Seq N at Seq Img N ay magkapares: ang kapares na hindi ginagamit ay kasama, hindi required.
        foreach (array_keys($need) as $key) {
            if (!preg_match('/^seq_(img_)?(\d+)$/', $key, $m)) continue;
            $other = $m[1] ? 'seq_' . $m[2] : 'seq_img_' . $m[2];
            if (isset($labels[$other])) $need[$other] ??= false;
        }

        $order = $this->order($columns);
        uksort($need, fn ($a, $b) => $order($a) <=> $order($b));

        return $need;
    }

    /**
     * Ang isang field gaya ng ibinabalik sa Forbeast: ang dating anyo (key, label, type, value, editable, updated_at) at ang
     * mga bagong paglalarawan (group, kind, required, hint, max_length, read_only, pair, updated_by).
     * @param array<string, PageSetting> $rows
     */
    public function field(string $key, string $label, string $page, array $rows, bool $required): array
    {
        $at = fn (?PageSetting $s) => $s?->created_at?->toIso8601String();
        $by = fn (?PageSetting $s) => $s === null ? null : ((string) $s->source !== '' ? self::FORBEAST : self::LIKHA);
        if ($key === ShopDetails::KEY) {
            $parts = array_filter(array_map(fn ($k) => $rows[$k] ?? null, array_combine(ShopDetails::PARTS, ShopDetails::PARTS)));
            $last = collect($parts)->sortBy('created_at')->last();
            $value = ShopDetails::build($page, array_map(fn ($s) => (string) $s->plainValue(), $parts));
            $row = $last;
        } else {
            $row = $rows[$key] ?? null;
            $value = $row?->plainValue();
        }
        $kind = self::kind($key);
        $readOnly = $key === ShopDetails::KEY;

        // Ang dating anyo muna (ang kontrata ng page values API), saka ang mga bagong paglalarawan.
        return [
            'key' => $key, 'label' => $label, 'type' => $kind === 'image' ? 'image' : (ChatbotFields::kind($key) === 'long' || $readOnly ? 'textarea' : 'text'),
            'value' => $value, 'editable' => !$readOnly, 'updated_at' => $at($row),
            'group' => self::group($key), 'kind' => $kind, 'required' => $required, 'hint' => self::hint($key, $label), 'max_length' => self::maxLength($key),
            'read_only' => $readOnly, 'pair' => preg_match('/^seq_(?:img_)?(\d+)$/', $key, $m) ? (int) $m[1] : null, 'updated_by' => $by($row),
        ];
    }

    public static function group(string $key): string
    {
        return match (true) {
            in_array($key, self::PRODUCT, true) => 'Product',
            in_array($key, self::FORMS, true), (bool) preg_match('/^after_order_(img_)?\d+$/', $key) => 'Forms and messages',
            (bool) preg_match('/^seq_(img_)?\d+$/', $key) => 'Sequence',
            (bool) preg_match('/^comment_reply_\d+$/', $key), $key === 'comment_pm' => 'Comment replies',
            $key === ShopDetails::KEY => 'Shop details',
            default => 'Other',
        };
    }

    /** text | long_text | image | number */
    public static function kind(string $key): string
    {
        $kind = ChatbotFields::kind($key);
        if ($kind === 'image') return 'image';
        if (in_array($key, self::NUMBERS, true)) return 'number';

        return $kind === 'long' || $kind === 'auto' || in_array($key, self::FORMS, true) ? 'long_text' : 'text';
    }

    public static function maxLength(string $key): ?int
    {
        return match (true) {
            $key === ShopDetails::KEY => null,
            $key === CommentReplies::PM => GatewayComments::MAX_PM,
            in_array($key, CommentReplies::VARIANTS, true) => GatewayComments::MAX_REPLY,
            default => ChatbotFields::MAX_VALUE,
        };
    }

    private static function hint(string $key, string $label): string
    {
        if (isset(self::HINTS[$key])) return self::HINTS[$key];
        if (preg_match('/^seq_img_(\d+)$/', $key, $m)) return 'A link to the picture sent with follow-up ' . $m[1] . ' (https).';
        if (preg_match('/^seq_(\d+)$/', $key, $m)) return 'Follow-up message ' . $m[1] . '.';
        if (preg_match('/^after_order_img_(\d+)$/', $key, $m)) return 'A link to the picture sent with after-order message ' . $m[1] . ' (https).';
        if (preg_match('/^after_order_(\d+)$/', $key, $m)) return 'Message ' . $m[1] . ' sent after the order is uploaded.';
        if (preg_match('/^comment_reply_\d+$/', $key)) return 'A public reply to a comment (one of the filled ones is picked).';
        if (preg_match('/^prompt_\d+$/', $key)) return 'Instructions for the AI step of the flow.';

        return 'Used by the flow as {{' . $label . '}}.';
    }

    /**
     * Ang ayos: group, saka (sa Sequence) ang numero na Seq muna bago Seq Img, saka ang ayos ng page values API at ng mga
     * column ng site.
     * @return callable(string): array
     */
    private function order(array $columns): callable
    {
        $base = array_flip(array_values(array_unique(array_merge(array_keys(app(PageValues::class)->columns($columns)), array_column($columns, 'key')))));

        return function (string $key) use ($base): array {
            $group = array_search(self::group($key), self::GROUPS, true);
            if (preg_match('/^seq_(img_)?(\d+)$/', $key, $m)) return [$group, (int) $m[2], $m[1] ? 1 : 0];

            return [$group, $base[$key] ?? PHP_INT_MAX, 0];
        };
    }
}
