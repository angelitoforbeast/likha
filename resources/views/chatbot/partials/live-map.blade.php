{{--
  Ang neural map ng Live: ang tatlong bot (Inquiries) sa isang gilid, ang AI core sa gitna, at ang Follow-up at Orders sa
  kabila. Iginuguhit dito (server), kaya may laman kahit walang script; ang script ang nagpapalakad ng ilaw sa mga path.
  Dalawang ayos ($L): malapad (kaliwa → gitna → kanan) at makitid (itaas → gitna → ibaba, para sa telepono).
  Mga variable: $L, $c, $bots, $linked, $site, $n, $lastText.
--}}
@php
    // Ang maliliit na node sa paligid ng isang hub: [x, y, may singsing]; pare-pareho bawat load (walang random).
    $sats = function (array $hub, float $s): array {
        $out = [];
        foreach ([[-16, 98], [-4, 66], [14, 104], [30, 80], [148, 92], [176, 66], [194, 100], [206, 84], [52, 110], [128, 112]] as $i => [$deg, $r]) {
            $a = deg2rad($deg);
            $out[] = [round($hub[0] + cos($a) * $r * $s, 1), round($hub[1] + sin($a) * $r * $s, 1), $i % 3 === 0];
        }
        // Sa makitid na mapa, ang dalawang node sa ilalim ay tatama sa bilang: wala ang mga ito roon.
        return $s < .5 ? array_slice($out, 0, 8) : $out;
    };
    $links = [[0, 1], [1, 2], [2, 3], [4, 5], [5, 6], [6, 7], [0, 8], [7, 9], [3, 8], [4, 9]];
    $cluster = function (array $hub, float $s, string $tone) use ($sats, $links): string {
        $p = $sats($hub, $s);
        $svg = '';
        foreach ([0, 1, 2, 3, 4, 5, 6, 7] as $i) $svg .= sprintf('<line class="nm-line" x1="%s" y1="%s" x2="%s" y2="%s"/>', $hub[0], $hub[1], $p[$i][0], $p[$i][1]);
        foreach ($links as [$a, $b]) {
            if (isset($p[$a], $p[$b])) $svg .= sprintf('<line class="nm-line" x1="%s" y1="%s" x2="%s" y2="%s"/>', $p[$a][0], $p[$a][1], $p[$b][0], $p[$b][1]);
        }
        foreach ($p as $i => [$x, $y, $ring]) {
            $svg .= $ring ? sprintf('<circle class="nm-node nm-tw" style="animation-delay:-%ss" cx="%s" cy="%s" r="%s"/><circle class="nm-dot" cx="%s" cy="%s" r="%s"/>', $i * .7, $x, $y, 7 * max($s, .6), $x, $y, 2.4 * max($s, .6))
                : sprintf('<circle class="nm-dot nm-tw" style="animation-delay:-%ss" cx="%s" cy="%s" r="%s"/>', $i * .7, $x, $y, 2.6 * max($s, .6));
        }
        return $svg;
    };
    $w = $L['w'];
    $h = $L['h'];
    [$cx, $cy, $cr] = $L['core'];
@endphp
<svg class="nm nm-{{ $L['key'] }}" viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="xMidYMid meet" role="img" data-map="{{ $L['key'] }}"
     aria-label="Neural map: customer messages from Bot 1, Bot 2 and Botcake flow into the AI core; replies, follow-ups and orders flow out.">
    <defs>
        <pattern id="nm-grid-{{ $L['key'] }}" width="40" height="40" patternUnits="userSpaceOnUse"><path d="M 40 0 L 0 0 0 40" class="nm-gridline"/></pattern>
        <radialGradient id="nm-core-{{ $L['key'] }}"><stop offset="0%" stop-color="#1d3352"/><stop offset="70%" stop-color="#101b2e"/><stop offset="100%" stop-color="#0c1424"/></radialGradient>
        <filter id="nm-glow-{{ $L['key'] }}" x="-100%" y="-100%" width="300%" height="300%"><feGaussianBlur stdDeviation="3" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
    </defs>
    <rect class="nm-bg" width="{{ $w }}" height="{{ $h }}"/>
    <rect width="{{ $w }}" height="{{ $h }}" fill="url(#nm-grid-{{ $L['key'] }})"/>
    @if($L['key'] === 'wide')
        <path class="nm-bracket" d="M 24 70 V 24 H 70 M {{ $w - 70 }} 24 H {{ $w - 24 }} V 70 M 24 {{ $h - 70 }} V {{ $h - 24 }} H 70 M {{ $w - 70 }} {{ $h - 24 }} H {{ $w - 24 }} V {{ $h - 70 }}"/>
    @endif

    {{-- Inquiries: ang heading at ang kabuuang mensahe ngayong araw. --}}
    <text class="nm-h b1" x="{{ $L['inq'][0] }}" y="{{ $L['inq'][1] }}">INQUIRIES</text>
    <text class="nm-sub" x="{{ $L['inq'][0] }}" y="{{ $L['inq'][1] + 20 }}"><tspan data-live-count="messages">{{ $n($c['messages']['today']) }}</tspan> messages today · receive · understand · route</text>

    {{-- Ang mga path: bot → core (ang mensahe papasok, ang kumpirmadong sagot palabas), core → follow-up, core → orders. --}}
    @foreach($bots as $bot => [$name, $sub, $tone])
        <path class="nm-path {{ $tone }}" d="{{ $L['paths'][$bot] }}" data-path="{{ $bot }}"/>
    @endforeach
    <path class="nm-path fu" d="{{ $L['paths']['fu'] }}" data-path="fu"/>
    <path class="nm-path ord" d="{{ $L['paths']['ord'] }}" data-path="ord"/>

    @foreach($bots as $bot => [$name, $sub, $tone])
        @php([$hx, $hy] = $L['bots'][$bot])
        <g class="nm-cluster {{ $tone }}" data-bot-row="{{ $bot }}" data-linked="{{ $linked[$bot] ? 1 : 0 }}" data-hit="{{ $bot }}">
            {!! $cluster([$hx, $hy], $L['scale'], $tone) !!}
            <circle class="nm-halo" cx="{{ $hx }}" cy="{{ $hy }}" r="{{ $L['hub'] + 10 }}"/>
            <circle class="nm-hub" cx="{{ $hx }}" cy="{{ $hy }}" r="{{ $L['hub'] }}"/>
            <path class="nm-icon" d="M {{ $hx - 9 }} {{ $hy - 7 }} h 18 v 11 h -10 l -5 4 v -4 h -3 z"/>
            @if($L['key'] === 'wide')
                <text class="nm-tag" x="{{ $hx }}" y="{{ $hy - $L['hub'] - 18 }}">{{ strtoupper($name) }} · {{ strtoupper($sub) }}</text>
            @else
                <text class="nm-tag" x="{{ $hx }}" y="{{ $hy - $L['hub'] - 26 }}">{{ strtoupper($name) }}</text>
                <text class="nm-tag nm-tag2" x="{{ $hx }}" y="{{ $hy - $L['hub'] - 12 }}">{{ strtoupper($sub) }}</text>
            @endif
            <text class="nm-count" x="{{ $hx }}" y="{{ $hy + $L['hub'] + $L['big'] + 4 }}" font-size="{{ $L['big'] }}" data-live-count="in-{{ $bot }}">{{ $n($c['bots']['in'][$bot]) }}</text>
            <text class="nm-off" x="{{ $hx }}" y="{{ $hy + $L['hub'] + 24 }}">not linked</text>
        </g>
        {{-- Ang kumpirmadong sagot ng bot na ito, malapit sa labasan ng core papunta sa kanya. --}}
        <text class="nm-reply" x="{{ $L['replies'][$bot][0] }}" y="{{ $L['replies'][$bot][1] }}" data-linked="{{ $linked[$bot] ? 1 : 0 }}" data-reply-row="{{ $bot }}">↩ <tspan data-live-count="out-{{ $bot }}">{{ $n($c['bots']['out'][$bot]) }}</tspan> replies</text>
    @endforeach
    <text class="nm-sub nm-unknown" x="{{ $L['unknown'][0] }}" y="{{ $L['unknown'][1] }}" data-live-unknown="in" @if(!$c['bots']['in']['unknown']) hidden @endif>+<tspan data-live-count="in-unknown">{{ $n($c['bots']['in']['unknown']) }}</tspan> messages from before the bot was recorded</text>

    {{-- Ang core. --}}
    <g class="nm-core" data-hit="core" data-live-core>
        <circle class="nm-core-arc" cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $cr + 30 }}"/>
        <circle class="nm-core-spin" cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $cr + 16 }}"/>
        <circle class="nm-core-ring" cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $cr }}" fill="url(#nm-core-{{ $L['key'] }})"/>
        <circle class="nm-core-inner" cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $cr - 14 }}"/>
        <text class="nm-core-name" x="{{ $cx }}" y="{{ $cy - $cr * .12 }}" font-size="{{ round(min($cr * .3, $cr * 1.6 / (max(1, mb_strlen($site)) * .74))) }}">{{ strtoupper($site) }}</text>
        <text class="nm-core-sub" x="{{ $cx }}" y="{{ $cy + $cr * .16 }}" font-size="{{ round($cr * .14) }}">AI CORE</text>
        <text class="nm-core-ai" x="{{ $cx }}" y="{{ $cy + $cr * .45 }}" font-size="{{ round($cr * .15) }}">AI answers <tspan data-live-count="ai">{{ $n($c['ai']['today']) }}</tspan></text>
    </g>
    <text class="nm-sub nm-unknown nm-mid" x="{{ $cx }}" y="{{ $cy + $cr + 52 }}" data-live-unknown="out" @if(!$c['bots']['out']['unknown']) hidden @endif>+<tspan data-live-count="out-unknown">{{ $n($c['bots']['out']['unknown']) }}</tspan> replies with no bot recorded</text>

    {{-- Follow-up at Orders. --}}
    @foreach(['fu' => ['FOLLOW-UP', 'reconnect · continue', 'follow_ups', 'sent today'], 'ord' => ['ORDERS', 'qualify · confirm · record', 'orders', 'orders today']] as $k => [$title, $tagline, $count, $unit])
        @php([$hx, $hy] = $L[$k])
        <g class="nm-cluster {{ $k }}" data-hit="{{ $k }}" @if($k === 'ord') data-live-flash="orders" @else data-live-flash="follow_ups" @endif>
            {!! $cluster([$hx, $hy], $L['scale'], $k) !!}
            <circle class="nm-halo" cx="{{ $hx }}" cy="{{ $hy }}" r="{{ $L['hub'] + 12 }}"/>
            <circle class="nm-hub" cx="{{ $hx }}" cy="{{ $hy }}" r="{{ $L['hub'] + 2 }}"/>
            @if($k === 'fu')
                <path class="nm-icon" d="M {{ $hx - 8 }} {{ $hy }} a 8 8 0 1 0 2.5 -5.8 M {{ $hx - 6 }} {{ $hy - 9 }} v 4 h 4 M {{ $hx }} {{ $hy - 4 }} v 4 l 3 2"/>
            @else
                <path class="nm-icon" d="M {{ $hx - 7 }} {{ $hy }} l 5 5 l 9 -10"/>
            @endif
            <text class="nm-h" x="{{ $hx }}" y="{{ $hy - $L['hub'] - ($L['key'] === 'wide' ? 40 : 26) }}">{{ $title }}</text>
            <text class="nm-sub nm-mid" x="{{ $hx }}" y="{{ $hy - $L['hub'] - ($L['key'] === 'wide' ? 22 : 10) }}">{{ $L['key'] === 'wide' ? $tagline : '' }}</text>
            <text class="nm-count" x="{{ $hx }}" y="{{ $hy + $L['hub'] + $L['big'] + 6 }}" font-size="{{ $k === 'ord' ? round($L['big'] * 1.35) : $L['big'] }}" data-live-count="{{ $count }}">{{ $n($c[$count]['today']) }}</text>
            <text class="nm-sub nm-mid" x="{{ $hx }}" y="{{ $hy + $L['hub'] + $L['big'] * ($k === 'ord' ? 1.35 : 1) + 26 }}">{{ $unit }}</text>
            @if($k === 'ord')
                <text class="nm-last" x="{{ $L['last'][0] }}" y="{{ $L['last'][1] }}">Last: <tspan data-live-last-order>{{ $lastText }}</tspan></text>
            @endif
        </g>
    @endforeach

    {{-- Ang mga ilaw (iisang hanay, ginagamit muli): ginagawa ng script. --}}
    <g class="nm-lights" data-lights></g>
</svg>
