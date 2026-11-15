<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ResponseSpeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

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
        $body = (string) $this->seqJson(html_entity_decode($m[1], ENT_QUOTES))['html'];
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

        return $this->seqJson('/chatbot/speed/sequences?' . http_build_query($q + ($chart ? ['part' => 'chart'] : [])));
    }

    /**
     * Ang JSON ng endpoint. Ang default na view ng ngayon at kahapon ay galing lang sa warm (spec 277): kapag "building"
     * pa, pinapatakbo ang warm (gaya ng scheduler) at binabasa ulit.
     */
    protected function seqJson(string $url): array
    {
        $json = $this->getJson($url)->assertOk()->json();
        $building = !empty($json['sequences']['building']) || (isset($json['seq']) && !str_contains($url, 'page_id=') && !str_contains($url, 'bot=') && $this->defaultMissing($json));
        if (!$building) return $json;
        Artisan::call('chatbot:speed-warm');

        return $this->getJson($url)->assertOk()->json();
    }

    /** Ang chart ng default na view kapag wala pa ang entry ng araw o ng naunang araw na pinapainit ng warm. */
    private function defaultMissing(array $chart): bool
    {
        foreach ([$chart['date'], $chart['prev_date']] as $d) {
            $f = ResponseSpeed::filters(['date' => $d]);
            if ($f['date'] !== $d) continue;
            if ($d >= now()->subDay()->toDateString() && !is_array(Cache::get(ResponseSpeed::SEQUENCES_KEY . md5(json_encode($f))))) return true;
        }

        return false;
    }
}
