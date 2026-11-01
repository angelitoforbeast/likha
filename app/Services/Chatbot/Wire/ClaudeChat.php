<?php

namespace App\Services\Chatbot\Wire;

use App\Boardroom\Providers\AnthropicAdapter;
use App\Boardroom\Providers\ProviderRequest;

/**
 * Claude (Anthropic Messages API) para sa chatbot: ang mga system message ay pinagsasama sa "system", ang usapan ay user at
 * assistant na salitan (ang magkasunod na pareho ay pinagsasama; ang assistant sa unahan ay inaalis, dahil user ang dapat na
 * una). Walang thinking: maikling sagot sa customer.
 */
class ClaudeChat extends AnthropicAdapter
{
    use ChatWire;

    protected function chatBody(string $model, array $messages, int $maxTokens): array
    {
        $system = [];
        $turns = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            $text = (string) ($m['content'] ?? '');
            if ($role === 'system') {
                $system[] = $text;
                continue;
            }
            $role = $role === 'assistant' ? 'assistant' : 'user';
            if ($turns && $turns[array_key_last($turns)]['role'] === $role) {
                $turns[array_key_last($turns)]['content'] .= "\n\n" . $text;
                continue;
            }
            if (!$turns && $role === 'assistant') continue;
            $turns[] = ['role' => $role, 'content' => $text];
        }
        $body = $this->buildPayload(new ProviderRequest('anthropic', $model, implode("\n\n", $system), '', ['max_output_tokens' => $maxTokens]));
        $body['messages'] = $turns ?: [['role' => 'user', 'content' => '.']];
        if ($body['system'] === '') unset($body['system']);

        return $body;
    }

    /** Ang usage ng sagot (input, kasama ang cache, at output), o null kapag walang usage block. @return array{0: ?int, 1: ?int} */
    public static function usage(array $json): array
    {
        $u = $json['usage'] ?? null;
        if (!is_array($u) || !isset($u['input_tokens'], $u['output_tokens'])) return [null, null];

        return [(int) $u['input_tokens'] + (int) ($u['cache_creation_input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0), (int) $u['output_tokens']];
    }
}
