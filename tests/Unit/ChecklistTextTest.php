<?php

namespace Tests\Unit;

use App\Support\ChecklistText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 270: ang instructions ng checklist task: may linya, clickable ang link, at ligtas sa XSS. */
class ChecklistTextTest extends TestCase
{
    private const A = ' target="_blank" rel="noopener" class="text-blue-600 underline break-all"';

    private function html(?string $text): string
    {
        return ChecklistText::render($text)->toHtml();
    }

    public function test_s_270_4_a_one_line_description_is_the_same_escaped_text_as_before(): void
    {
        $this->assertSame('Linisin ang bodega bago mag-10', $this->html('Linisin ang bodega bago mag-10'));
        $this->assertSame('Tom &amp; Jerry &quot;sale&quot; 1/2 at 24/7, and/or w/ box', $this->html('Tom & Jerry "sale" 1/2 at 24/7, and/or w/ box'));
        $this->assertSame('', $this->html(null));
        $this->assertSame('', $this->html(''));
    }

    public function test_s_270_2_line_breaks_are_kept_and_numbered_lines_stay_text(): void
    {
        $this->assertSame(
            "1) Buksan ang page<br>\n2) Tingnan ang output<br>\n- Isulat ang bilang",
            $this->html("1) Buksan ang page\r\n2) Tingnan ang output\n- Isulat ang bilang"),
        );
    }

    /** [text, inaasahang HTML] */
    public static function links(): array
    {
        return [
            'full https url' => [
                'Buksan https://likhaaitech.com/ads_manager/daily-output?d=2026-11-13&p=1 ngayon',
                'Buksan <a href="https://likhaaitech.com/ads_manager/daily-output?d=2026-11-13&amp;p=1"'.self::A.'>https://likhaaitech.com/ads_manager/daily-output?d=2026-11-13&amp;p=1</a> ngayon',
            ],
            'http url' => [
                'http://example.com/x',
                '<a href="http://example.com/x"'.self::A.'>http://example.com/x</a>',
            ],
            'in-app host goes to the current host' => [
                'Tingnan sa likhaaitech.com/ads_manager/daily-output',
                'Tingnan sa <a href="/ads_manager/daily-output"'.self::A.'>likhaaitech.com/ads_manager/daily-output</a>',
            ],
            'in-app host with www and no path' => [
                'Pumunta sa www.likhaaitech.com.',
                'Pumunta sa <a href="/"'.self::A.'>www.likhaaitech.com</a>.',
            ],
            'in-app path' => [
                'Open /ads_manager/daily-output, then check.',
                'Open <a href="/ads_manager/daily-output"'.self::A.'>/ads_manager/daily-output</a>, then check.',
            ],
            'in-app path in parentheses' => [
                '(see /encoding/timetable)',
                '(see <a href="/encoding/timetable"'.self::A.'>/encoding/timetable</a>)',
            ],
            'url with balanced parentheses keeps them' => [
                'https://en.wikipedia.org/wiki/Foo_(bar).',
                '<a href="https://en.wikipedia.org/wiki/Foo_(bar)"'.self::A.'>https://en.wikipedia.org/wiki/Foo_(bar)</a>.',
            ],
            'not the host when part of another name or an email' => [
                'likhaaitech.com.ph at hr@likhaaitech.com',
                'likhaaitech.com.ph at hr@likhaaitech.com',
            ],
        ];
    }

    #[DataProvider('links')]
    public function test_s_270_2_links_are_clickable(string $text, string $html): void
    {
        $this->assertSame($html, $this->html($text));
    }

    public function test_s_270_2_several_lines_with_links(): void
    {
        $html = $this->html("1) Buksan /ads_manager/daily-output\n2) I-compare sa https://docs.google.com/x");

        $this->assertSame(
            '1) Buksan <a href="/ads_manager/daily-output"'.self::A.'>/ads_manager/daily-output</a><br>'
            ."\n".'2) I-compare sa <a href="https://docs.google.com/x"'.self::A.'>https://docs.google.com/x</a>',
            $html,
        );
    }

    /** [text, inaasahang HTML]: escaped lahat, walang link maliban sa http(s) at sariling path. */
    public static function unsafe(): array
    {
        return [
            'script tag' => [
                '<script>alert(1)</script>',
                '&lt;script&gt;alert(1)&lt;/script&gt;',
            ],
            'attribute break-out' => [
                '"><img src=x onerror=alert(1)>',
                '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;',
            ],
            'javascript scheme' => [
                'javascript:alert(1)',
                'javascript:alert(1)',
            ],
            'javascript scheme with slashes' => [
                'javascript://x/%0Aalert(1) JAVASCRIPT:/x',
                'javascript://x/%0Aalert(1) JAVASCRIPT:/x',
            ],
            'data scheme' => [
                'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
                'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
            ],
            'other schemes' => [
                'ftp://x.com vbscript:msgbox file:///etc/passwd',
                'ftp://x.com vbscript:msgbox file:///etc/passwd',
            ],
            'protocol-relative path is not a link' => [
                '//evil.example/x and /\evil.example',
                '//evil.example/x and /\evil.example',
            ],
            'url cannot break out of the href' => [
                'https://x.com/"onmouseover="alert(1) https://x.com/<script>',
                '<a href="https://x.com/"'.self::A.'>https://x.com/</a>&quot;onmouseover=&quot;alert(1) <a href="https://x.com/"'.self::A.'>https://x.com/</a>&lt;script&gt;',
            ],
            'in-app path cannot break out' => [
                "/ads_manager/'onclick='alert(1)",
                '<a href="/ads_manager/"'.self::A.'>/ads_manager/</a>&#039;onclick=&#039;alert(1)',
            ],
            'already-escaped entities stay visible as text' => [
                '&lt;b&gt;',
                '&amp;lt;b&amp;gt;',
            ],
            'bare scheme without host' => [
                'https:// at http://"x',
                'https:// at http://&quot;x',
            ],
        ];
    }

    #[DataProvider('unsafe')]
    public function test_s_270_3_everything_is_escaped_and_only_http_and_own_paths_become_links(string $text, string $html): void
    {
        $out = $this->html($text);

        $this->assertSame($html, $out);
        $this->assertDoesNotMatchRegularExpression('~<(?!a href="(?:https?://|/[A-Za-z0-9_-])|/a>|br>)~i', $out);
    }

    public function test_broken_utf8_is_escaped_text_without_links(): void
    {
        $this->assertSame("a &lt;b&gt; \u{FFFD} /x", $this->html("a <b> \xC3 /x"));
    }
}
