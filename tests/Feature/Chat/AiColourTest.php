<?php

namespace Tests\Feature\Chat;

use App\Services\Chat\AiPost;

/**
 * PM-2 (spec 309): ang AI avatar (.lc-av.ai) at ang AI mark (.lc-ai) ay violet, gaya ng chip sa follow-ups page
 * (violet-50 na background, violet-800 na text, violet-200 na gilid). Ang kulay lang ng AI ang nagbago: ang mga tono
 * ng tao, ang asul na accent at ang iba pa ay pareho.
 */
class AiColourTest extends ChatTestCase
{
    private function page(): string
    {
        return $this->actingAs($this->person())->get('/chat')->assertOk()->getContent();
    }

    public function test_the_ai_avatar_and_mark_are_violet(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('--lc-ai-bg: #f5f3ff; --lc-ai-text: #5b21b6; --lc-ai-edge: #ddd6fe;', $html);
        $this->assertStringContainsString('.lc-av.ai { background: var(--lc-ai-bg); color: var(--lc-ai-text); box-shadow: inset 0 0 0 1px var(--lc-ai-edge); }', $html);
        $this->assertMatchesRegularExpression('/\.lc-ai \{[^}]*background: var\(--lc-ai-bg\); color: var\(--lc-ai-text\);[^}]*box-shadow: inset 0 0 0 1px var\(--lc-ai-edge\); \}/', $html);
        foreach (['#fffbeb', '#92400e', '#fde68a'] as $amber) {
            $this->assertStringNotContainsString($amber, $html, "$amber (the old AI amber) is gone");
        }
    }

    public function test_violet_800_on_violet_50_reads_at_avatar_size(): void
    {
        $this->assertGreaterThanOrEqual(4.5, $this->contrast('#5b21b6', '#f5f3ff'));
    }

    public function test_human_tones_and_the_accent_are_unchanged(): void
    {
        $html = $this->page();

        foreach ([
            '.lc-av.t0 { background: #dbeafe; color: #1e3a8a; } .lc-av.t1 { background: #dcfce7; color: #14532d; }',
            '.lc-av.t2 { background: #fef3c7; color: #78350f; } .lc-av.t3 { background: #fce7f3; color: #831843; }',
            '.lc-av.t4 { background: #e0e7ff; color: #312e81; } .lc-av.t5 { background: #ccfbf1; color: #134e4a; }',
            '.lc-av.tn { background: #e5e7eb; color: #374151; }',
            '.lc-av.group { border-radius: 11px; background: #e5e7eb; color: #374151; }',
            '--lc-accent: #2563eb; --lc-accent-hover: #1d4ed8; --lc-accent-soft: #dbeafe; --lc-on-accent: #ffffff;',
        ] as $rule) {
            $this->assertStringContainsString($rule, $html);
        }
    }

    public function test_miras_list_row_gets_the_ai_avatar(): void
    {
        $joy = $this->person('Marketing', 'Joy Ramos');
        AiPost::post('mira', $joy->id, 'Hi Joy');

        $avatar = $this->actingAs($joy)->getJson('/chat/conversations')->assertOk()->json('conversations.0.avatar');
        $this->assertTrue($avatar['ai']);
        // Ang avatar() ng script ay naglalagay ng class "ai" kapag a.ai, bago ang group o tono.
        $this->assertStringContainsString("if (a.ai) cls.push('ai');", $this->page());
    }

    private function contrast(string $fg, string $bg): float
    {
        $l = function (string $hex): float {
            $c = array_map(fn ($v) => hexdec($v) / 255, str_split(ltrim($hex, '#'), 2));
            $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$a, $b] = [$l($fg), $l($bg)];

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
