<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotFlow;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Schema;

/**
 * Ang kailangan ng isang takbo mula sa page: kung naka-On ang bot at kung aling flow, at ang mga botfield (ayon sa key
 * at sa label ng column, hal. prompt_1 at "Prompt 1"). Isang query bawat isa, isang beses kada takbo.
 */
class ChatbotPage
{
    public function __construct(private PageSettings $settings, private ChatbotFields $fields)
    {
    }

    private ?bool $ready = null;

    /**
     * Ang flow ng page kapag naka-On ang bot at may flow na pinili sa dropdown (chatbot.flow_id); kung hindi null. Ang
     * FLOW na text lang (mula pa sa page list, bago ang mga flow) ay hindi nagpapatakbo ng anuman, kahit may flow na
     * kapangalan. Wala pang table (hindi pa na-migrate) = null.
     */
    public function flow(string $page): ?ChatbotFlow
    {
        if ($this->settings->get($page, 'chatbot', 'active') === '0') return null;
        $id = trim((string) $this->settings->get($page, 'chatbot', 'flow_id'));
        if (!ctype_digit($id) || !($this->ready ??= Schema::hasTable('chatbot_flows'))) return null;

        return ChatbotFlow::find((int) $id);
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

    /** @return array<string, string> key at label => value */
    public function botfields(string $page): array
    {
        $labels = array_column($this->fields->columns(), 'label', 'key');
        $out = [];
        $ids = [];
        foreach ($this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('active', true)->orderBy('id')->get() as $s) {
            if (in_array($s->key, ChatbotFields::RESERVED, true)) continue;
            $value = (string) $s->plainValue();
            $out[$s->key] = $value;
            if (isset($labels[$s->key])) $out[$labels[$s->key]] = $value;
            // Ang version ng botfield (Results): ang id ng row na binasa na rito, walang dagdag na query.
            $ids[ChatbotVars::norm($s->key)] = $ids[ChatbotVars::norm($labels[$s->key] ?? $s->key)] = ['key' => $s->key, 'id' => (int) $s->id];
        }
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
