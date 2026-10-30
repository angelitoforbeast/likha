{{--
  Ang neural network ng Live (1500 × 848 sa canvas na 1920 × 1080): ang Customer inquiries at Follow-up sequences sa kaliwa
  papasok sa core, ang AI answers bilang sariling node sa itaas ng core, ang tatlong bot sa kanan, at ang Orders sa ibaba.
  Iginuguhit dito (server) ang lahat, pati ang mga cluster at alikabok na node (seeded: pare-pareho bawat load), kaya may laman
  kahit walang script; ang script ang nagpapalakad ng ilaw sa mga path at nagpipinta ng 5-minutong orasan sa paligid ng core.
  Mga variable: $c, $bots, $linked, $site, $n, $lastText, $seqs.
--}}
@php
    $seed = 5;
    $rnd = function () use (&$seed) { $seed = ($seed * 16807) % 2147483647; return $seed / 2147483647; };
    $f = fn ($v) => round($v, 1);
    // Ang mga link: ang input ay papunta sa core, ang bot at Orders ay mula sa core.
    $paths = [
        'inq' => ['M 274 215 C 400 235 450 310 562 348', 'inq'], 'seq' => ['M 274 560 C 400 548 450 440 566 380', 'seq'],
        'forbeast' => ['M 818 316 C 940 308 990 168 1112 168', 'out'], 'incepxionbot' => ['M 820 348 C 940 348 1010 348 1112 348', 'out'],
        'botcake' => ['M 818 380 C 940 388 990 530 1112 530', 'out'], 'ai' => ['M 690 137 L 690 220', 'ai'], 'ord' => ['M 690 480 C 690 580 560 640 560 722', 'ord'],
    ];
    // Ang alikabok sa buong panel, at ang mga cluster ng node (gitna, radius, ilan, kulay, pinakamaliit na radius).
    $dust = [];
    for ($i = 0; $i < 70; $i++) $dust[] = [30 + $rnd() * 1440, 70 + $rnd() * 700];
    $cluster = function (float $cx, float $cy, float $R, int $count, string $tone, float $rmin = 50) use ($rnd) {
        $pts = [[$cx, $cy]];
        for ($i = 0; $i < $count; $i++) { $a = $rnd() * 6.283; $r = $rmin + $rnd() * ($R - $rmin); $pts[] = [$cx + cos($a) * $r, $cy + sin($a) * $r * .9]; }
        $links = [];
        foreach ($pts as $i => $a) foreach (array_slice($pts, $i + 1) as $b) {
            $d = hypot($a[0] - $b[0], $a[1] - $b[1]);
            if (($i === 0 && $rmin < 100 && $d < $R * .85) || $d < 58) $links[] = [$a, $b];
        }

        return ['tone' => $tone, 'pts' => array_slice($pts, 1), 'links' => $links];
    };
    $clusters = [$cluster(230, 215, 92, 24, 'inq'), $cluster(230, 560, 80, 22, 'seq'), $cluster(1150, 168, 84, 18, 'out'), $cluster(1150, 530, 84, 18, 'out'),
        $cluster(1150, 348, 60, 10, 'dim'), $cluster(690, 348, 188, 44, 'inq', 150)];
    // Ang mga icon (path sa 30-unit na hub).
    $ic = ['chat' => 'M -13 -11 h 26 v 17 h -15 l -8 7 v -7 h -3 z', 'seq' => 'M -12 2 a 12 12 0 1 0 3.5 -8.5 M -10 -14 v 6 h 6 M 0 -6 v 7 l 5 3',
        'send' => 'M 14 -13 L -14 0 L -3 3 L 0 14 Z M -3 3 L 14 -13', 'plug' => 'M -7 -14 v 9 M 7 -14 v 9 M -12 -5 h 24 v 5 a 12 12 0 0 1 -24 0 z M 0 12 v 10',
        'plus' => 'M 0 -11 v 22 M -11 0 h 22'];
    $botY = ['forbeast' => 168, 'incepxionbot' => 348, 'botcake' => 530];
    $seqPos = [[318, 604], [318, 630], [318, 656]];
@endphp
<svg class="nm" viewBox="0 0 1500 848" data-map="wide" role="img" aria-label="Neural network: inputs into the {{ $site }} bot core, out to each bot">
    <defs><filter id="nm-glow" x="-200%" y="-200%" width="500%" height="500%"><feGaussianBlur stdDeviation="4" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>
    <g class="nm-dust" aria-hidden="true">
        @foreach($dust as $i => $a)@foreach(array_slice($dust, $i + 1) as $b)@if(hypot($a[0] - $b[0], $a[1] - $b[1]) < 120)<line x1="{{ $f($a[0]) }}" y1="{{ $f($a[1]) }}" x2="{{ $f($b[0]) }}" y2="{{ $f($b[1]) }}"/>@endif @endforeach @endforeach
        @foreach($dust as $a)<circle cx="{{ $f($a[0]) }}" cy="{{ $f($a[1]) }}" r="2.2"/>@endforeach
    </g>
    @foreach($clusters as $cl)
        <g class="nm-cl t-{{ $cl['tone'] }}" aria-hidden="true">
            @foreach($cl['links'] as [$a, $b])<line class="lk" x1="{{ $f($a[0]) }}" y1="{{ $f($a[1]) }}" x2="{{ $f($b[0]) }}" y2="{{ $f($b[1]) }}"/>@endforeach
            @foreach($cl['pts'] as $i => $p)@if($i % 4 === 0)<circle class="nr{{ $i % 8 === 0 ? ' tw' : '' }}" cx="{{ $f($p[0]) }}" cy="{{ $f($p[1]) }}" r="7" style="animation-delay:-{{ $i * .6 }}s"/>@endif<circle class="nd{{ $i % 3 === 0 ? ' tw' : '' }}" cx="{{ $f($p[0]) }}" cy="{{ $f($p[1]) }}" r="{{ $i % 4 === 0 ? 2.6 : 3.2 }}" style="animation-delay:-{{ $i * .45 }}s"/>@endforeach
        </g>
    @endforeach

    {{-- Ang mga link (ang data-path ang dinadaanan ng ilaw) at ang tahimik na daloy sa bawat isa (SMIL; tumitigil sa reduced motion). --}}
    <g class="nm-wires">
        @foreach($paths as $key => [$d, $tone])
            <path id="nm-w-{{ $key }}" class="wire t-{{ $tone }}" d="{{ $d }}" data-path="{{ $key }}" @isset($bots[$key]) data-reply-row="{{ $key }}" data-linked="{{ $linked[$key] ? 1 : 0 }}" @endisset/>
        @endforeach
    </g>
    <g class="nm-amb" data-live-amb aria-hidden="true">
        @foreach($paths as $key => [$d, $tone])
            @for($i = 0; $i < 4; $i++)
                <circle class="t-{{ $tone }}" r="3.4" @isset($bots[$key]) data-reply-row="{{ $key }}" data-linked="{{ $linked[$key] ? 1 : 0 }}" @endisset><animateMotion dur="{{ 3 + (strlen($key) % 3) * .5 }}s" repeatCount="indefinite" begin="-{{ $i * .8 }}s"><mpath href="#nm-w-{{ $key }}"/></animateMotion></circle>
            @endfor
        @endforeach
    </g>

    {{-- Ang core: dalawang umiikot na singsing, ang 5-minutong orasan (60 guhit, isa kada poll na 5 segundo) at ang bilang ng signal. --}}
    <g class="nm-core" data-hit="core" data-live-core>
        <circle cx="690" cy="348" r="196" class="c-faint"/>
        <g class="spin"><circle cx="690" cy="348" r="188" class="c-arc"/></g>
        <g class="spin2"><circle cx="690" cy="348" r="138" class="c-arc2"/></g>
        <g data-live-ticks aria-hidden="true">
            @for($i = 0; $i < 60; $i++)
                @php $a = deg2rad($i * 6); @endphp
                <line x1="{{ $f(690 + sin($a) * 150) }}" y1="{{ $f(348 - cos($a) * 150) }}" x2="{{ $f(690 + sin($a) * 159) }}" y2="{{ $f(348 - cos($a) * 159) }}"/>
            @endfor
        </g>
        <circle class="wave" cx="690" cy="348" r="132" data-live-wave/>
        <g class="breathe"><circle class="ring" cx="690" cy="348" r="132"/></g>
        <text class="nm-core-name" x="690" y="318">{{ strtoupper($site) }}<tspan> BOT</tspan></text>
        <text class="t c sub s21" x="690" y="350">Process · prepare · route</text>
        <text class="t c big s" x="690" y="408" data-live-count="signals">{{ $n($signals) }}</text>
        <text class="t c sub s21" x="690" y="436">signals · last 5 min</text>
    </g>

    {{-- Ang mga input: Customer inquiries at Follow-up sequences (ang top 3 ngayong araw). --}}
    <g class="hub t-inq" transform="translate(230 215)" data-hit="inq"><circle class="halo spin" r="56"/><circle class="bg" r="42"/><path class="ic" d="{{ $ic['chat'] }}" transform="scale(1.4)"/></g>
    <text class="t c cap t-inq" x="230" y="96">CUSTOMER INQUIRIES</text>
    <text class="t c big" x="230" y="362" data-live-count="messages">{{ $n($c['messages']['today']) }}</text>
    <text class="t c sub" x="230" y="394">received today</text>
    <g class="hub t-seq" transform="translate(230 560)" data-hit="seq"><circle class="halo spin" r="56"/><circle class="bg" r="42"/><path class="ic" d="{{ $ic['seq'] }}" transform="scale(1.4)"/></g>
    <text class="t c cap t-seq" x="230" y="440">FOLLOW-UP SEQUENCES</text>
    <text class="t c sub" x="230" y="468">Top today, by follow-ups sent</text>
    @foreach($seqPos as $i => [$x, $y])
        <text class="t seqn" x="{{ $x }}" y="{{ $y }}" data-live-seq="{{ $i }}">{{ isset($seqs[$i]) ? mb_strimwidth($seqs[$i]['name'], 0, 18, '…') . ' · ' . $n($seqs[$i]['n']) : '' }}</text>
    @endforeach

    {{-- Ang AI answers: sariling hakbang, sariling node at link papunta sa core. --}}
    <g class="hub t-ai" transform="translate(690 113)" data-hit="ai"><circle class="halo spin" r="38"/><circle class="bg" r="24"/><path class="ic" d="{{ $ic['plus'] }}" transform="scale(.8)"/></g>
    <text class="t cap t-ai" x="738" y="98">AI ANSWERS</text>
    <text class="t big s" x="738" y="152" data-live-count="ai">{{ $n($c['ai']['today']) }}</text>

    {{-- Ang tatlong bot: bawat isa ay sariling hub. Ang hindi naka-link ay DORMANT (putol-putol, hindi pula). --}}
    @foreach($bots as $bot => [$label, $name, $tone])
        @php $y = $botY[$bot]; $fallback = $bot === 'botcake'; @endphp
        <g class="nm-bot" data-bot-row="{{ $bot }}" data-linked="{{ $linked[$bot] ? 1 : 0 }}">
            <g class="hub t-out" transform="translate(1150 {{ $y }})" data-hit="{{ $bot }}">
                <circle class="halo spin" r="48"/><circle class="bg" r="34"/>
                <path class="ic on" d="{{ $ic['send'] }}" transform="scale(1.13)"/><path class="ic off" d="{{ $ic['plug'] }}" transform="scale(1.13)"/>
            </g>
            <text class="t bname" x="1204" y="{{ $y - 46 }}">{{ strtoupper($label) }} · {{ strtoupper($name) }}</text>
            <text class="t st on" x="1204" y="{{ $y - 16 }}">● {{ $fallback ? 'Fallback' : 'Connected' }}</text>
            <text class="t st off" x="1204" y="{{ $y - 16 }}">○ Not linked</text>
            <text class="t big s on" x="1204" y="{{ $y + 42 }}" data-live-count="out-{{ $bot }}">{{ $n($c['bots']['out'][$bot]) }}</text>
            <text class="t sub s22 on" x="1204" y="{{ $y + 70 }}">replies sent · <tspan data-live-count="in-{{ $bot }}">{{ $n($c['bots']['in'][$bot]) }}</tspan> in</text>
            <text class="t dorm off" x="1204" y="{{ $y + 38 }}">DORMANT</text>
            <text class="t sub s21 off" x="1204" y="{{ $y + 66 }}">not linked · <tspan data-live-count="out-{{ $bot }}">{{ $n($c['bots']['out'][$bot]) }}</tspan> sent · <tspan data-live-count="in-{{ $bot }}">{{ $n($c['bots']['in'][$bot]) }}</tspan> in</text>
        </g>
    @endforeach

    {{-- Ang Orders node, sa dulo ng gintong link mula sa core. --}}
    <g class="hub t-ord" transform="translate(560 743)" data-hit="ord" data-live-flash="orders"><circle class="halo spin" r="34"/><circle class="bg" r="20"/><path class="ic" d="{{ $ic['plus'] }}" transform="scale(.66)"/></g>

    {{-- Ang ibabang hanay: Follow-ups sent, Orders recorded at ang huling order, Order value today; ang hindi makilalang bot. --}}
    <line class="strip" x1="30" y1="676" x2="1480" y2="676"/>
    <circle cx="70" cy="752" r="16" class="dot t-seq"/>
    <text class="t sub s26" x="114" y="724">Follow-ups sent</text>
    <text class="t big s" x="114" y="790" data-live-count="follow_ups">{{ $n($c['follow_ups']['today']) }}</text>
    <text class="t sub s26" x="604" y="724">Orders recorded</text>
    <text class="t big s t-ordf" x="604" y="790" data-live-count="orders">{{ $n($c['orders']['today']) }}</text>
    <text class="t sub s21" x="604" y="826">Last: <tspan data-live-last-order>{{ $lastText }}</tspan></text>
    <text class="t sub s26" x="900" y="724">Order value today (COD)</text>
    <text class="t big s t-ordf" x="900" y="790"><tspan class="peso-sign">₱</tspan><tspan dx="6" data-live-count="peso">{{ $n($c['order_value']['today']) }}</tspan></text>
    <text class="t sub s20 end" x="1480" y="716" data-live-unknown="in" @if(!$c['bots']['in']['unknown']) hidden @endif>+<tspan data-live-count="in-unknown">{{ $n($c['bots']['in']['unknown']) }}</tspan> messages from before the bot was recorded</text>
    <text class="t sub s20 end" x="1480" y="744" data-live-unknown="out" @if(!$c['bots']['out']['unknown']) hidden @endif>+<tspan data-live-count="out-unknown">{{ $n($c['bots']['out']['unknown']) }}</tspan> replies with no bot recorded</text>
    <text class="t sub s21 end" x="1480" y="800">Inputs → {{ strtoupper($site) }} BOT → designated bot</text>

    <g data-lights aria-hidden="true"></g>
</svg>
