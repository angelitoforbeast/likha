<?php

namespace Tests\Feature\Chatblast;

/**
 * Ang Sequences ng /chatbot/speed ay binubuksan ng script ng page mula sa sariling endpoint nito (ang section, saka ang
 * chart). Para sa mga test ng buong screen: ang page gaya ng nakikita pagkatapos ng script.
 */
trait LoadsSpeedSequences
{
    /** Ang page na may Sequences section mula sa endpoint (gaya ng ginagawa ng script). */
    protected function speedPage(string $query = ''): string
    {
        return $this->withSequences($this->get('/chatbot/speed' . ($query !== '' ? '?' . $query : ''))->assertOk()->getContent());
    }

    /** Ang loading line ng Sequences ay pinapalitan ng HTML ng endpoint, mula sa URL na nasa page mismo. */
    protected function withSequences(string $html): string
    {
        $this->assertSame(1, preg_match('#data-sp-seq="([^"]+)"#', $html, $m), 'the page has the Sequences endpoint');
        $body = (string) $this->getJson(html_entity_decode($m[1], ENT_QUOTES))->assertOk()->json('html');
        $out = preg_replace_callback('#(<div data-sp-seq-body>)\s*<p\b[^>]*data-sp-seq-state="loading"[^>]*>.*?</p>\s*(</div>)#s', fn ($x) => $x[1] . $body . $x[2], $html, 1, $n);
        $this->assertSame(1, $n, 'the page has the Sequences loading line');

        return $out;
    }

    /**
     * Ang mga numero nang walang "Not sent" (bago ito sa screen, wala sa lumang code): para sa paghahambing ng lahat ng
     * iba pang numero sa lumang code.
     */
    protected static function withoutNotSent(array $v): array
    {
        foreach ($v as $k => $x) {
            if ($k === 'not_sent') unset($v[$k]);
            elseif (is_array($x)) $v[$k] = self::withoutNotSent($x);
        }

        return $v;
    }

    /** Ang JSON ng endpoint: ang section ({filters, sequences, html}) o ang chart ({date, prev_date, seq}). */
    protected function speedSequences(string $query = '', bool $chart = false): array
    {
        parse_str($query, $q);

        return $this->getJson('/chatbot/speed/sequences?' . http_build_query($q + ($chart ? ['part' => 'chart'] : [])))->assertOk()->json();
    }
}
