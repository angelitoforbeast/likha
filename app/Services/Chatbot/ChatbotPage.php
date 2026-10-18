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
     * Ang flow ng page kapag naka-On ang bot at may flow na umiiral sa pangalang iyon; kung hindi null. Wala pang
     * table (hindi pa na-migrate) = null: may mga page na may FLOW na text na mula pa sa page list.
     */
    public function flow(string $page): ?ChatbotFlow
    {
        if ($this->settings->get($page, 'chatbot', 'active') === '0') return null;
        $name = trim((string) $this->settings->get($page, 'chatbot', 'flow'));
        if ($name === '' || !($this->ready ??= Schema::hasTable('chatbot_flows'))) return null;

        return ChatbotFlow::where('name', $name)->first();
    }

    /** @return array<string, string> key at label => value */
    public function botfields(string $page): array
    {
        $labels = array_column($this->fields->columns(), 'label', 'key');
        $out = [];
        foreach ($this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('active', true)->orderBy('id')->get() as $s) {
            if ($s->key === 'active') continue;
            $value = (string) $s->plainValue();
            $out[$s->key] = $value;
            if (isset($labels[$s->key])) $out[$labels[$s->key]] = $value;
        }

        return $out;
    }
}
