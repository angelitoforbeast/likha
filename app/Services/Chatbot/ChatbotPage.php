<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotFlow;
use App\Services\ChatbotFields;
use App\Services\PageSettings;

/**
 * Ang kailangan ng isang takbo mula sa page: kung naka-On ang bot at kung aling flow, at ang mga botfield (ayon sa key
 * at sa label ng column, hal. prompt_1 at "Prompt 1"). Isang query bawat isa, isang beses kada takbo.
 */
class ChatbotPage
{
    public function __construct(private PageSettings $settings, private ChatbotFields $fields)
    {
    }

    /**
     * Ang flow ng page kapag naka-On ang bot at may flow na pinili sa dropdown (chatbot.flow_id); kung hindi null. Ang
     * FLOW na text lang (mula pa sa page list, bago ang mga flow) ay hindi nagpapatakbo ng anuman, kahit may flow na
     * kapangalan. Wala pang table (hindi pa na-migrate) = null. Ang flow ay mula sa BotConfigCache.
     */
    public function flow(string $page, ?int $group = null): ?ChatbotFlow
    {
        if ($this->settings->get($page, 'chatbot', 'active') === '0') return null;
        // Ang ad group na may sariling flow (AdGroups): iyon ang tumatakbo para sa mga ad nito; ang On/Off ay sa page pa rin.
        // Ang flow ng group na na-delete na: ang flow ng page (hindi tahimik na walang sagot).
        $own = $group !== null ? trim((string) ($this->groups()->groups($page)[$group]['values']['flow_id'] ?? '')) : '';
        if (ctype_digit($own) && ($flow = BotConfigCache::flow((int) $own))) return $flow;
        $id = trim((string) $this->settings->get($page, 'chatbot', 'flow_id'));
        if (!ctype_digit($id)) return null;

        return BotConfigCache::flow((int) $id);
    }

    /** May tatakbo para sa page: ang flow ng page, o (sa Ad-based na page) ang flow ng isa sa mga ad group nito. */
    public function runs(string $page): bool
    {
        if ($this->flow($page)) return true;
        foreach ($this->groups()->activeGroups($page) as $id => $g) {
            if (isset($g['values']['flow_id']) && $this->flow($page, $id)) return true;
        }

        return false;
    }

    private function groups(): AdGroups
    {
        return app(AdGroups::class);
    }

    /** Itali ang page sa isang flow (o alisin kapag null): ang pangalan (FLOW, para makita) at ang id (para tumakbo). */
    public function link(string $page, ?ChatbotFlow $flow, ?int $userId = null): void
    {
        if ($flow === null) {
            foreach (['flow', 'flow_id'] as $key) {
                foreach ($this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('key', $key)->where('active', true)->get() as $s) {
                    $this->settings->deactivate($s, $userId);
                }
            }
            return;
        }
        $this->settings->set($page, ChatbotFields::PROVIDER, 'flow', $flow->name, false, $userId);
        $this->settings->set($page, ChatbotFields::PROVIDER, 'flow_id', (string) $flow->id, false, $userId, true);
    }

    /** @return array<int, string> ang mga page na nakatali sa flow na ito (ayon sa id) */
    public function pagesUsing(int $flowId): array
    {
        return \App\Models\PageSetting::where('provider', ChatbotFields::PROVIDER)->where('key', 'flow_id')->where('active', true)->get(['page_name', 'value', 'is_secret'])
            ->filter(fn ($s) => (string) $s->plainValue() === (string) $flowId)->pluck('page_name')->unique()->sort()->values()->all();
    }

    /** @var array<string, array<string, array{key: string, id: int}>> page key => pangalan (naka-norm) => botfield at row id */
    private static array $ids = [];

    /**
     * $group: ang ad group ng customer (AdGroups); ang bawat value na itinakda nito ang pumapalit sa page value, kasama
     * ang SHOP DETAILS na binubuo mula sa item, presyo at dami. null = ang page values.
     * @return array<string, string> key at label => value
     */
    public function botfields(string $page, ?int $group = null): array
    {
        $labels = $this->fields->labels();
        $out = [];
        $ids = [];
        $rows = $this->settings->allWithIds($page, ChatbotFields::PROVIDER);
        $g = $group !== null ? ($this->groups()->groups($page)[$group] ?? null) : null;
        foreach ($g['values'] ?? [] as $key => $value) {
            if ($key !== 'flow_id') $rows[$key] = ['value' => $value, 'id' => $g['ids'][$key]];
        }
        foreach ($rows as $key => $row) {
            if (in_array($key, ChatbotFields::RESERVED, true) || $key === ShopDetails::KEY) continue; // ang lumang tina-type: hindi na binabasa
            $out[$key] = $row['value'];
            if (isset($labels[$key])) $out[$labels[$key]] = $row['value'];
            // Ang version ng botfield (Results): ang id ng row na binasa na rito, walang dagdag na query.
            $ids[ChatbotVars::norm($key)] = $ids[ChatbotVars::norm($labels[$key] ?? $key)] = ['key' => $key, 'id' => $row['id']];
        }
        $out[ShopDetails::KEY] = $out[ShopDetails::LABEL] = ShopDetails::build($page, $out);
        if (isset($labels[ShopDetails::KEY])) $out[$labels[ShopDetails::KEY]] = $out[ShopDetails::KEY];
        self::$ids[$this->settings->pageKey($page)] = $ids;

        return $out;
    }

    /**
     * Ang mga botfield ng page (pangalan o label, naka-norm => key at id ng page_settings row) mula sa huling botfields()
     * ng page sa prosesong ito; binabasa kapag wala pa.
     * @return array<string, array{key: string, id: int}>
     */
    public static function versionIds(string $page): array
    {
        $key = app(PageSettings::class)->pageKey($page);
        if (!isset(self::$ids[$key])) app(self::class)->botfields($page);

        return self::$ids[$key] ?? [];
    }
}
