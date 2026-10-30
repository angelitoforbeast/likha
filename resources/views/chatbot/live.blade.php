@extends('layouts.chatbot-tv')

@section('title', 'Live')

@php
    $c = $feed['counts'];
    // Ang mga bot: [pangalan, paliwanag, estado kapag naka-link, gitna (x, y) ng hub sa 1920 × 1080, ang notes (itaas, taas), lalim sa 3D].
    // Ang lalim ay bumababa mula sa itaas pababa (Bot 1 60, Bot 2 30, Botcake 0): sa 3D ang mas mataas na layer ay umaangat sa
    // screen, kaya ang bot sa itaas ang pinakamataas at walang plate na tumatakip sa kapitbahay o sa KPI sa buong ugoy (±11°, rotateX 29–35°).
    $bots = [
        'forbeast' => ['Bot 1', 'Forbeast', 'Connected', 490, 445, 104, 372, 60],
        'incepxionbot' => ['Bot 2', 'Incepxionbot', 'Connected', 430, 620, 486, 158, 30],
        'botcake' => ['Botcake', 'fallback', 'Fallback', 490, 795, 660, 372, 0],
    ];
    $linked = $c['linked'] + ['botcake' => true];
    // Ang pangalan ng site (Likha o Incepxion), ayon sa APP_URL.
    $site = \App\Services\Chatbot\ForbeastSite::label(\App\Services\Chatbot\ForbeastSite::self());
    // Ang paliwanag sa ilalim ng average na reply time, ayon sa estado ng Forbeast (iisa sa server at sa script).
    $notes = [
        'ok' => 'Customer sent → reply sent',
        'none' => 'No Forbeast replies yet today',
        'not_linked' => 'Not linked to Forbeast',
        'not_on_forbeast' => 'This page is not on Forbeast',
        'error' => 'Forbeast did not answer; trying again within a minute',
    ];
    $rt = $c['reply_time'];
    $rtNote = $notes[$rt['state'] === 'ok' && $rt['seconds'] === null ? 'none' : $rt['state']] ?? $notes['error'];
    $last = $c['last_order'];
    $lastText = $last ? mb_strimwidth($last['page'], 0, 24, '…') . ' · ' . $last['at'] . ($last['bot'] ? ' · ' . $bots[$last['bot']][0] : '') : 'no order yet today';
    $n = fn ($v) => number_format((int) round((float) $v));
    $seqs = $c['sequences'] ?? [];
    // Ang "signals · last 5 min" ng core: ang kabuuan ng mga bilang sa huling 5 minuto.
    $signals = $c['messages']['recent'] + $c['confirmed']['recent'] + $c['follow_ups']['recent'] + $c['ai']['recent'] + $c['orders']['recent'];
    $up = fn (string $k) => $c[$k]['recent'] > 0 ? 'up' : '';
    // Ang notes ng bawat bot (ang huling ginawa bilang text, iisa sa script).
    $kinds = ['message' => 'Inquiry', 'reply' => 'Reply', 'follow_up' => 'Follow-up', 'order' => 'Order', 'ai' => 'AI answer'];
    $lastOf = fn (?array $l) => $l ? ($kinds[$l['type']] ?? 'Event') . ' · ' . $l['page'] . ' · ' . $l['at'] : '—';
    $avgOf = fn ($v) => $v === null ? '—' : number_format((float) $v, 1) . ' s';
    // Ang reactor: gitna, at ang x sa bilog (r 250) sa taas na y, kung saan dumidikit ang pipe ng bot.
    [$cx, $cy] = [930, 620];
    $ring = fn (float $y) => round($cx - sqrt(max(0, 250 ** 2 - ($cy - $y) ** 2)), 1);
    // Ang 60 guhit ng 5-minutong orasan (isa kada 5 s; ang bawat ikalima ay mas mahaba).
    $ticks = array_map(fn ($i) => [round($cx + cos($i / 30 * M_PI - M_PI / 2) * 244, 1), round($cy + sin($i / 30 * M_PI - M_PI / 2) * 244, 1),
        round($cx + cos($i / 30 * M_PI - M_PI / 2) * ($i % 5 ? 254 : 262), 1), round($cy + sin($i / 30 * M_PI - M_PI / 2) * ($i % 5 ? 254 : 262), 1), $i % 5 ? 2 : 3], range(0, 59));
@endphp

@section('content')
<div class="vp" data-live data-live-board>
<div class="stage" data-live-stage>
<div class="cam" data-live-cam><div class="world" data-live-world>

    {{-- Ang malayong singsing: ang mga umiikot na arko at ang tanda ng 5-minutong orasan. --}}
    <svg class="lyr z far" style="--z:-110" viewBox="0 0 1920 1080" aria-hidden="true">
        <ellipse cx="930" cy="620" rx="400" ry="352" class="r-dots"/>
        <g class="rot rot-a"><circle cx="930" cy="620" r="318" class="r-a"/></g>
        <g class="rot rot-b"><circle cx="930" cy="620" r="334" class="r-b"/></g>
        <circle cx="930" cy="620" r="300" class="r-thin"/>
        <path class="r-cardinal" d="M930 296v16M930 928v-16M606 620h16M1254 620h-16"/>
        <text x="930" y="288" class="r-cap">5-MIN CLOCK</text>
    </svg>
    {{-- Ang dial: 60 guhit (isa kada 5 s), ang loob na arko at ang scan band. --}}
    <svg class="lyr z" style="--z:-40" viewBox="0 0 1920 1080" aria-hidden="true">
        <defs>
            <clipPath id="lv-dial"><circle cx="930" cy="620" r="296"/></clipPath>
            <linearGradient id="lv-scan" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#6fd8ff" stop-opacity="0"/><stop offset=".85" stop-color="#6fd8ff" stop-opacity=".16"/><stop offset="1" stop-color="#bff0ff" stop-opacity=".5"/></linearGradient>
        </defs>
        <g class="rot rot-c"><circle cx="930" cy="620" r="276" class="r-c"/></g>
        <circle cx="930" cy="620" r="268" class="r-thin2"/>
        <g data-live-ticks>
            @foreach($ticks as [$x1, $y1, $x2, $y2, $w])
                <line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}" stroke-width="{{ $w }}"/>
            @endforeach
        </g>
        <circle cx="930" cy="620" r="238" class="r-face"/>
        <g clip-path="url(#lv-dial)"><rect class="scanb" x="630" y="230" width="600" height="90" fill="url(#lv-scan)"/></g>
    </svg>
    {{-- Ang gintong core: ang halaga ng order ngayong araw. --}}
    <svg class="lyr z" style="--z:35" viewBox="0 0 1920 1080" data-live-core>
        <defs>
            <radialGradient id="lv-disc" cx="50%" cy="35%" r="75%"><stop offset="0" stop-color="#24200c"/><stop offset=".6" stop-color="#0f0d06"/><stop offset="1" stop-color="#080704"/></radialGradient>
            <radialGradient id="lv-glow" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#ffc247" stop-opacity=".45"/><stop offset="1" stop-color="#ffc247" stop-opacity="0"/></radialGradient>
        </defs>
        <circle cx="930" cy="620" r="218" fill="url(#lv-disc)"/>
        <circle cx="930" cy="620" r="218" fill="url(#lv-glow)" opacity="0" data-live-glow/>
        <circle cx="930" cy="620" r="218" class="rim" data-live-rim/>
        <circle cx="930" cy="620" r="206" class="rim2"/>
        <circle cx="930" cy="620" r="218" class="shock" opacity="0" data-live-shock/>
        <g class="valg" data-live-value>
            <text x="930" y="545" class="v-cap">Order value today · COD</text>
            <text x="930" y="650" class="v-num" aria-label="Order value today">₱<tspan data-live-count="peso">{{ $n($c['order_value']['today']) }}</tspan></text>
            <text x="930" y="696" class="v-sub"><tspan data-live-count="orders">{{ $n($c['orders']['today']) }}</tspan> orders · <tspan class="v-up">+<tspan data-live-recent="orders">{{ $n($c['orders']['recent']) }}</tspan></tspan> in the last 5 min</text>
            <text x="930" y="730" class="v-last">Last: <tspan data-live-last-order>{{ $lastText }}</tspan></text>
        </g>
    </svg>

    {{-- Ang mga satellite: kabuuang inquiry, AI, follow-up at ang bot core. --}}
    <div class="grp g-tot" style="--z:60"><section class="holo br abs tot" data-live-card="inq"><h2 class="lab">INQUIRIES RECEIVED TODAY</h2><b class="num" data-live-count="messages">{{ $n($c['messages']['today']) }}</b></section></div>
    <div class="grp g-ai" style="--z:55">
        <svg class="lyr" viewBox="0 0 1920 1080" aria-hidden="true"><path class="pipe" pathLength="1" d="M1140 445 L1108 445" data-path="ai"/><path class="streak s-ai" pathLength="1" d="M1140 445 L1108 445"/></svg>
        <section class="holo br abs ai" data-live-card="ai"><h2 class="lab ink">AI ANSWERS</h2><b class="num" data-live-count="ai">{{ $n($c['ai']['today']) }}</b></section>
    </div>
    <div class="grp g-seq" style="--z:95">
        <svg class="lyr" viewBox="0 0 1920 1080" aria-hidden="true"><path class="pipe" pathLength="1" d="M1140 795 L1108 795" data-path="seq"/><path class="streak s-seq" pathLength="1" d="M1140 795 L1108 795"/></svg>
        <section class="holo br abs seq" data-live-card="seq">
            <div class="hd"><h2 class="lab">FOLLOW-UPS SENT</h2><b class="num" data-live-count="follow_ups">{{ $n($c['follow_ups']['today']) }}</b></div>
            @for($i = 0; $i < 3; $i++)
                <div class="row" data-live-seq="{{ $i }}" @if(!isset($seqs[$i])) hidden @endif><span>{{ isset($seqs[$i]) ? $seqs[$i]['name'] : '' }}</span><b>{{ isset($seqs[$i]) ? $n($seqs[$i]['n']) : '' }}</b></div>
            @endfor
            <p class="none" data-live-seq-none @if($seqs) hidden @endif>No follow-ups yet today</p>
        </section>
    </div>
    <div class="grp g-core" style="--z:30">
        <section class="holo br abs core" data-live-card="core"><div><h2 class="lab ink"><span class="nm-core-name">{{ strtoupper($site) }}</span> BOT</h2><small>signals · last 5 min</small></div><b class="num" data-live-count="signals">{{ $n($signals) }}</b></section>
        <p class="abs unk"><span data-live-unknown="in" @if(!$c['bots']['in']['unknown']) hidden @endif>+<span data-live-count="in-unknown">{{ $n($c['bots']['in']['unknown']) }}</span> messages from before the bot was recorded</span>
            <span data-live-unknown="out" @if(!$c['bots']['out']['unknown']) hidden @endif>+<span data-live-count="out-unknown">{{ $n($c['bots']['out']['unknown']) }}</span> replies with no bot recorded</span></p>
    </div>

    {{-- Ang telepono lang: ang gintong core bilang card (ang SVG ay nakatago roon). --}}
    <section class="mcore" aria-label="Order value today">
        <h2>ORDER VALUE TODAY · COD</h2><b>₱<span data-live-count="peso">{{ $n($c['order_value']['today']) }}</span></b>
        <p><span data-live-count="orders">{{ $n($c['orders']['today']) }}</span> orders · <span class="v-up">+<span data-live-recent="orders">{{ $n($c['orders']['recent']) }}</span></span> in the last 5 min</p>
        <p class="m-last">Last: <span data-live-last-order>{{ $lastText }}</span></p>
    </section>

    {{-- Ang tatlong bot: hub na may IN at OUT, ang pipe papunta at mula sa core, at ang NOTES sa angled na leader line. --}}
    @foreach($bots as $key => [$label, $name, $state, $hx, $hy, $nTop, $nH, $z])
        @php
            $on = $linked[$key] ? '1' : '0';
            [$yi, $yo, $hr, $ay] = [$hy - 14, $hy + 14, $hx + 220, $hy - 34];
            $note = $c['notes'][$key] ?? ['linked' => false, 'chatting' => 0, 'ai' => 0, 'avg' => null, 'orders' => 0, 'value' => 0, 'last' => null, 'customers' => []];
        @endphp
        <div class="grp g-bot g-{{ $key }}" style="--z:{{ $z }}" data-bot-row="{{ $key }}" data-linked="{{ $on }}">
            <svg class="lyr" viewBox="0 0 1920 1080" aria-hidden="true">
                <path class="pipe bp" pathLength="1" d="M{{ $hr }} {{ $yi }} L{{ $ring($yi) }} {{ $yi }}" data-path="{{ $key }}-in"/>
                <path class="pipe bp" pathLength="1" d="M{{ $ring($yo) }} {{ $yo }} L{{ $hr }} {{ $yo }}" data-path="{{ $key }}-out"/>
                <path class="streak s-{{ $key }}-in" pathLength="1" d="M{{ $hr }} {{ $yi }} L{{ $ring($yi) }} {{ $yi }}"/>
                <path class="streak s-{{ $key }}-out" pathLength="1" d="M{{ $ring($yo) }} {{ $yo }} L{{ $hr }} {{ $yo }}"/>
                <path class="lead" pathLength="1" d="M{{ $hx }} {{ $hy }} L{{ $hx - 34 }} {{ $ay }} L390 {{ $ay }}"/>
                <rect class="cap" x="{{ $hx - 3 }}" y="{{ $hy - 3 }}" width="6" height="6"/><rect class="cap" x="387" y="{{ $ay - 3 }}" width="6" height="6"/>
            </svg>
            <section class="hub holo br abs" style="left:{{ $hx }}px;top:{{ $hy - 79 }}px" data-live-card="{{ $key }}" data-hub="{{ $key }}">
                <h3 class="nm"><s data-beat></s>{{ strtoupper($label) }} · {{ strtoupper($name) }}</h3>
                <p class="st"><span class="on">{{ $state }}</span><span class="off">Not linked</span></p>
                <div class="on">
                    <div class="io"><em>IN</em><b class="num" data-live-count="in-{{ $key }}">{{ $n($c['bots']['in'][$key]) }}</b></div>
                    <div class="io"><em>OUT</em><b class="num" data-live-count="out-{{ $key }}">{{ $n($c['bots']['out'][$key]) }}</b></div>
                </div>
                <div class="off"><b class="dorm">DORMANT</b><small><span data-live-count="in-{{ $key }}">{{ $n($c['bots']['in'][$key]) }}</span> in · <span data-live-count="out-{{ $key }}">{{ $n($c['bots']['out'][$key]) }}</span> out</small></div>
            </section>
            <section class="note holo br abs" style="top:{{ $nTop }}px;height:{{ $nH }}px" data-note="{{ $key }}" aria-label="{{ $label }} notes">
                <div class="h"><span class="mono">{{ $label }} // notes</span><span class="mono"><span class="on">live</span><span class="off">idle</span></span></div>
                <div class="on">
                    <div class="g3">
                        <div class="c"><span class="mono">Chatting</span><b data-n="chatting">{{ $n($note['chatting']) }}</b></div>
                        <div class="c"><span class="mono">AI answered</span><b data-n="ai">{{ $n($note['ai']) }}</b></div>
                        <div class="c"><span class="mono">Avg reply</span><b data-n="avg">{{ $avgOf($note['avg']) }}</b></div>
                    </div>
                    <div class="g2">
                        <div class="c"><span class="mono">Orders today</span><b data-n="orders">{{ $n($note['orders']) }}</b></div>
                        <div class="c"><span class="mono">Order value</span><b class="gold" data-n="value">₱{{ $n($note['value']) }}</b></div>
                    </div>
                    <div class="l"><span class="mono">Last activity</span><b data-n="last">{{ $lastOf($note['last']) }}</b></div>
                    <div class="cu"><span class="mono">Latest customers</span>
                        <ol class="cl" data-cl>
                            @foreach($note['customers'] as $cu)
                                <li><b>{{ $cu['name'] }}</b><em class="t-{{ strtolower($cu['tag']) }}">{{ $cu['tag'] }}</em><time>{{ $cu['at'] }}</time></li>
                            @endforeach
                        </ol>
                        <p class="none" data-cl-none @if($note['customers']) hidden @endif>No customers yet today</p>
                    </div>
                </div>
                <div class="off"><div class="l"><span class="mono">Chatting · orders · AI</span><b>—</b></div><div class="l"><span class="mono">Latest customers</span><b class="sm">none while not linked</b></div></div>
            </section>
        </div>
    @endforeach

    {{-- Ang loot: mga barya at ang "+₱N" na lumulutang sa core. --}}
    <svg class="lyr z fx" style="--z:140" viewBox="0 0 1920 1080" aria-hidden="true">
        <defs><radialGradient id="lv-coin" cx="40%" cy="32%" r="75%"><stop offset="0" stop-color="#fff0b0"/><stop offset=".5" stop-color="#ffc247"/><stop offset="1" stop-color="#8a5f12"/></radialGradient></defs>
        <circle class="burst" cx="0" cy="0" r="0" opacity="0" data-live-burst/>
        <g data-live-coins></g>
    </svg>
    <div class="fl z" style="--z:150" data-live-plus aria-hidden="true"></div>

</div></div>

    <header class="top">
        <svg class="logo" viewBox="0 0 44 44" aria-hidden="true"><path d="M4 8h26l-4 7H0zM12 19h26l-4 7H8zM20 30h24l-4 7H16z"/></svg>
        <h1>{{ strtoupper($site) }} LIVE</h1>
        <span class="lv-status" data-live-status="live"><i data-beat aria-hidden="true"></i><span data-live-state>LIVE</span></span>
        <span class="sp"></span>
        <form method="GET" action="{{ route('chatbot.live') }}" class="lv-filter" aria-label="Filters">
            <select class="sel" name="page_id" aria-label="Page" data-live-page>
                <option value="">All pages</option>
                @foreach($pages as $name => $id)<option value="{{ $id }}" @selected((string) $page === (string) $id)>{{ $name }}</option>@endforeach
            </select>
            <select class="sel" name="bot" aria-label="Bot" data-live-bot>
                <option value="">All bots</option>
                @foreach($bots as $key => [$label, $name])<option value="{{ $key }}" @selected($bot === $key)>{{ $label }} · {{ ucfirst($name) }}</option>@endforeach
            </select>
            <noscript><button class="btn">Show</button></noscript>
        </form>
        <span class="clock"><small>Today · Manila · updated <time data-live-updated>{{ $feed['now'] }}</time></small><b data-live-clock>{{ $feed['now'] }}</b></span>
        <button class="btn" type="button" aria-pressed="false" data-live-3d>3D</button>
        <button class="btn g" type="button" data-live-fullscreen hidden>TV mode</button>
        <a href="{{ route('chatbot.speed') }}" class="exit" data-tv-exit>Exit</a>
    </header>

    <section class="kpis" aria-label="Today">
        <div class="kp k1"><h2 class="lab">Replies today</h2><b class="num" data-live-count="replies">{{ $n($c['confirmed']['today']) }}</b>
            <small class="{{ $up('confirmed') }}" data-live-up="replies"><b>+<span data-live-recent="replies">{{ $n($c['confirmed']['recent']) }}</span></b> in the last 5 min</small></div>
        <div class="kp k2"><h2 class="lab">Avg reply time <span class="tag">BOT 1</span></h2><b class="num"><span data-live-count="reply_time">{{ $rt['seconds'] === null ? '—' : number_format($rt['seconds'], 1) }}</span><u data-live-unit @if($rt['seconds'] === null) hidden @endif>s</u></b>
            <small class="wrap" data-live-note="reply_time">{{ $rtNote }}</small></div>
        <div class="kp k3 hot" data-live-kpi="orders"><h2 class="lab">Orders today</h2><b class="num" data-live-count="orders">{{ $n($c['orders']['today']) }}</b>
            <small class="{{ $up('orders') }}" data-live-up="orders"><b>+<span data-live-recent="orders">{{ $n($c['orders']['recent']) }}</span></b> in the last 5 min</small></div>
        <div class="kp k4"><h2 class="lab">Active chats</h2><b class="num" data-live-count="active">{{ $n($c['active']) }}</b><small>last 5 min</small></div>
    </section>

    <aside class="tape holo br" aria-labelledby="lv-h-log">
        <h2 id="lv-h-log"><i data-beat aria-hidden="true"></i>LIVE ACTIVITY</h2>
        <ol class="rows" data-live-log></ol>
        <p class="listen">Listening for new events</p>
    </aside>
    <div class="scan" aria-hidden="true"></div>

</div>
</div>

<script type="application/json" id="live-data">{!! json_encode(['feed' => route('chatbot.live.feed', [], false), 'page' => $page, 'bot' => $bot, 'cursor' => $feed['cursor'], 'counts' => $feed['counts'],
    'interval' => 5000, 'site' => $site, 'bots' => array_map(fn ($b) => $b[0], $bots), 'notes' => $notes, 'kinds' => $kinds,
    'hubs' => array_map(fn ($b) => [$b[3] + 210, $b[4]], $bots)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@push('styles')
<style>
    /* Ang Live bilang Jarvis HUD: isang 1920 × 1080 na stage na pinapalaki o pinapaliit sa screen. Dalawang kulay lang: ice blue
       para sa system at ginto para sa pera. Ang font ay ang nasa Windows ng TV (Bahnschrift, Cascadia Mono); walang ini-load. */
    @property --k { syntax: '<number>'; inherits: true; initial-value: 0; }
    :root { --ground: #03070d; --ink: #e8f6ff; --mut: #9bb9cc; --dim: #7690a2; --ice: #6fd8ff; --pipe: #2a8fb8; --gold: #ffc247; --gold2: #ffd27a; --goldInk: #1a1203;
        --line: rgba(111,216,255,.25); --line2: rgba(111,216,255,.45);
        --cond: "Bahnschrift Condensed", "Bahnschrift SemiCondensed", "Roboto Condensed", "Arial Narrow", sans-serif;
        --semi: "Bahnschrift SemiCondensed", "Bahnschrift", "Segoe UI", "Roboto", sans-serif;
        --mono: "Cascadia Mono", Consolas, ui-monospace, monospace;
        --ease: cubic-bezier(.16,1,.3,1); }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { background: var(--ground); color: var(--ink); font-family: var(--semi); font-variant-numeric: tabular-nums; -webkit-font-smoothing: antialiased; }
    body { overflow-x: hidden; }
    ::selection { background: var(--gold); color: var(--goldInk); }
    [hidden] { display: none !important; }
    ol { list-style: none; }
    .vp { position: relative; height: 100vh; overflow: hidden; background: var(--ground); }
    .stage { position: absolute; left: 0; top: 0; width: 1920px; height: 1080px; transform-origin: 0 0; color: var(--ink);
        background: radial-gradient(ellipse 900px 700px at 930px 620px, #0b2238 0%, #06121f 50%, #03070d 100%); }
    .abs { position: absolute; }
    .num { font-family: var(--cond); font-weight: 700; line-height: .86; }
    .mono { font: 400 19px var(--mono); letter-spacing: .08em; text-transform: uppercase; color: var(--mut); }
    .lab { font: 600 22px var(--semi); letter-spacing: .08em; text-transform: uppercase; color: var(--mut); }
    .lab.ink { color: var(--ink); font-weight: 700; }
    svg.lyr { position: absolute; left: 0; top: 0; width: 1920px; height: 1080px; pointer-events: none; overflow: visible; }

    /* 3D: ang camera ay may perspective; bawat layer ay may sariling lalim (--z), na lumalabas lang kapag 3D (--k = 1). */
    .cam { position: absolute; inset: 0; perspective: 1500px; perspective-origin: 930px 620px; }
    .world { position: absolute; inset: 0; transform-style: preserve-3d; transform-origin: 930px 620px; }
    .z, .grp { transform: translateZ(calc(var(--k, 0) * var(--z, 0) * 1px)); }
    .grp { position: absolute; inset: 0; pointer-events: none; }
    .grp section, .grp p { pointer-events: auto; }
    .scan { position: absolute; inset: 0; pointer-events: none; background: repeating-linear-gradient(0deg, rgba(111,216,255,.04) 0 1px, transparent 1px 4px); z-index: 5; }

    /* Mga sulok ng HUD (brackets) at ang holographic na plate. */
    .br::before { content: ""; position: absolute; inset: -2px; pointer-events: none; --c: var(--ice);
        background: linear-gradient(var(--c), var(--c)) 0 0/16px 2.5px no-repeat, linear-gradient(var(--c), var(--c)) 0 0/2.5px 16px no-repeat,
            linear-gradient(var(--c), var(--c)) 100% 0/16px 2.5px no-repeat, linear-gradient(var(--c), var(--c)) 100% 0/2.5px 16px no-repeat,
            linear-gradient(var(--c), var(--c)) 0 100%/16px 2.5px no-repeat, linear-gradient(var(--c), var(--c)) 0 100%/2.5px 16px no-repeat,
            linear-gradient(var(--c), var(--c)) 100% 100%/16px 2.5px no-repeat, linear-gradient(var(--c), var(--c)) 100% 100%/2.5px 16px no-repeat; }
    .holo { background: linear-gradient(180deg, rgba(16,46,70,.78), rgba(6,16,28,.82)); border: 1.5px solid rgba(111,216,255,.32); transition: border-color .5s; }
    .holo.hit { border-color: var(--ice); transition: none; }

    /* Ang header. */
    .top { position: absolute; left: 40px; top: 0; width: 1840px; height: 88px; display: flex; align-items: center; gap: 22px; z-index: 3; border-bottom: 1.5px solid var(--line); }
    .logo { width: 44px; height: 44px; flex: none; fill: var(--ice); }
    .top h1 { font: 700 56px/1 var(--cond); letter-spacing: .06em; white-space: nowrap; }
    .lv-status { display: inline-flex; align-items: center; gap: 12px; font: 700 26px/1 var(--semi); letter-spacing: .14em; color: var(--ice); white-space: nowrap; }
    .lv-status i, .tape h2 i { width: 12px; height: 12px; border-radius: 50%; background: var(--ice); flex: none; }
    .lv-status:not([data-live-status="live"]) { color: var(--gold); } .lv-status:not([data-live-status="live"]) i { background: var(--gold); }
    .beat { animation: beat 1.1s var(--ease); }
    @keyframes beat { 0% { opacity: 1; transform: scale(1.5); } 40% { opacity: .35; } 100% { opacity: 1; transform: none; } }
    .top .sp { flex: 1; }
    .lv-filter { display: flex; gap: 16px; min-width: 0; }
    .sel, .btn { height: 52px; background: rgba(10,28,44,.8); color: var(--ink); border: 1.5px solid var(--line2); border-radius: 2px; font: 600 25px var(--semi); padding: 0 16px; }
    .sel { max-width: 260px; min-width: 0; cursor: pointer; } .sel option { background: #061424; color: var(--ink); }
    .btn { color: var(--ice); letter-spacing: .12em; text-transform: uppercase; font-size: 23px; cursor: pointer; white-space: nowrap; }
    .btn[aria-pressed="true"] { background: var(--ice); color: #03212f; }
    .btn.g { color: var(--gold); border-color: rgba(255,194,71,.5); }
    .sel:hover, .btn:hover { border-color: var(--ice); }
    .sel:focus-visible, .btn:focus-visible, .exit:focus-visible { outline: 3px solid var(--gold); outline-offset: 2px; }
    .clock { text-align: right; line-height: 1; white-space: nowrap; }
    .clock small { display: block; font: 400 17px var(--mono); letter-spacing: .06em; color: var(--mut); text-transform: uppercase; margin-bottom: 4px; }
    .clock b { font: 700 46px var(--cond); letter-spacing: .04em; }
    .exit { color: var(--mut); font-size: 19px; text-decoration: underline; text-underline-offset: .2em; white-space: nowrap; }
    .exit:hover { color: var(--ink); }

    /* Ang apat na KPI. */
    .kpis { position: absolute; left: 0; top: 0; width: 0; height: 0; }
    .kp { position: absolute; top: 100px; width: 230px; }
    .kp.k1 { left: 440px; } .kp.k2 { left: 690px; width: 240px; } .kp.k3 { left: 950px; } .kp.k4 { left: 1190px; width: 220px; }
    .kp .num { display: block; font-size: 78px; margin: 8px 0 6px; white-space: nowrap; }
    .kp .num u { text-decoration: none; font-size: 40px; color: var(--mut); margin-left: 4px; }
    .kp small { display: block; font-size: 21px; color: var(--mut); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .kp small.wrap { white-space: normal; line-height: 1.1; }
    .kp small > b { color: var(--gold); font-weight: 700; }
    .kp.hot .num { color: var(--gold2); } .kp.hot .lab { color: #e5b95c; }
    .tag { display: inline-block; border: 1.5px solid var(--line2); font-size: 16px; padding: 0 5px; margin-left: 8px; letter-spacing: .08em; vertical-align: 2px; color: var(--ink); }

    /* Ang reactor. */
    .r-dots { fill: none; stroke: rgba(111,216,255,.16); stroke-width: 1.5; stroke-dasharray: 2 10; }
    .r-a { fill: none; stroke: var(--ice); stroke-opacity: .55; stroke-width: 3; stroke-dasharray: 190 90 70 140 30 90; }
    .r-b { fill: none; stroke: var(--ice); stroke-opacity: .35; stroke-width: 1.5; stroke-dasharray: 2 9; }
    .r-c { fill: none; stroke: var(--ice); stroke-opacity: .75; stroke-width: 3; stroke-dasharray: 120 40 40 40 200 40; }
    .r-thin { fill: none; stroke: var(--ice); stroke-opacity: .25; stroke-width: 1.5; } .r-thin2 { fill: none; stroke: var(--ice); stroke-opacity: .3; stroke-width: 1.5; }
    .r-face { fill: #04101a; stroke: var(--ice); stroke-opacity: .5; stroke-width: 1.5; }
    .r-cardinal { stroke: var(--ice); stroke-opacity: .7; stroke-width: 2.5; }
    .r-cap { font: 400 17px var(--mono); letter-spacing: 3px; fill: var(--ice); fill-opacity: .8; text-anchor: middle; }
    [data-live-ticks] line { stroke: var(--ice); opacity: .2; }
    .rot { transform-box: view-box; transform-origin: 930px 620px; }
    .rot-a { animation: spin 80s linear infinite; } .rot-b { animation: spin 164s linear infinite reverse; } .rot-c { animation: spin 40s linear infinite reverse; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .scanb { animation: scan 7s linear infinite; }
    @keyframes scan { to { transform: translateY(690px); } }
    .rim { fill: none; stroke: var(--gold); stroke-width: 2; opacity: .6; animation: rim 5s ease-in-out infinite; }
    @keyframes rim { 50% { opacity: .72; } }
    .rim2 { fill: none; stroke: var(--gold); stroke-opacity: .25; stroke-dasharray: 3 6; }
    .shock { fill: none; stroke: var(--gold); stroke-width: 2; }
    .valg { transform-box: view-box; transform-origin: 930px 620px; }
    svg text { font-family: var(--cond); font-weight: 700; font-variant-numeric: tabular-nums; text-anchor: middle; }
    .v-cap { font: 600 22px var(--semi); letter-spacing: 2.6px; fill: #e0b660; text-transform: uppercase; }
    .v-num { font-size: 120px; fill: #ffd36e; }
    .v-sub { font: 600 26px var(--semi); fill: #f0e3c4; } .v-up { fill: var(--gold); color: var(--gold); }
    .v-last { font: 500 21px var(--semi); fill: #b7a97f; }

    /* Ang mga satellite. */
    .tot { left: 740px; top: 248px; width: 380px; height: 58px; display: flex; align-items: center; justify-content: space-between; padding: 0 18px; gap: 12px; }
    .tot .lab { font-size: 19px; letter-spacing: .05em; white-space: nowrap; } .tot .num { font-size: 44px; }
    .ai { left: 1140px; top: 397px; width: 220px; height: 96px; padding: 12px 16px; } .ai .num { display: block; font-size: 54px; margin-top: 8px; }
    .seq { left: 1140px; top: 700px; width: 280px; height: 190px; padding: 10px 16px; overflow: hidden; }
    .seq .hd { display: flex; justify-content: space-between; align-items: flex-end; gap: 10px; margin-bottom: 6px; }
    .seq .hd .lab { font-size: 17px; white-space: nowrap; letter-spacing: .04em; } .seq .hd .num { font-size: 34px; }
    .seq .row { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; font-size: 21px; padding: 2px 0; border-top: 1px solid rgba(111,216,255,.2); margin-top: 3px; }
    .seq .row span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; } .seq .row b { font: 700 26px var(--cond); }
    .none { color: var(--mut); font-size: 19px; margin-top: 6px; }
    .core { left: 750px; top: 905px; width: 360px; height: 70px; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
    .core small { display: block; font-size: 18px; color: var(--mut); } .core .num { font-size: 52px; }
    .unk { left: 750px; top: 986px; width: 360px; font-size: 17px; color: var(--mut); line-height: 1.3; text-align: center; }
    .unk > span { display: block; }
    .pipe { fill: none; stroke: var(--pipe); stroke-width: 2.5; }
    .streak { fill: none; stroke: #d4f4ff; stroke-width: 5; stroke-linecap: round; stroke-dasharray: .07 3; stroke-dashoffset: .07; animation: streak 1.5s linear infinite; }
    @keyframes streak { 0% { stroke-dashoffset: .07; } 55%, 100% { stroke-dashoffset: -1; } }
    .s-ai { animation-duration: 2.9s; animation-delay: -.3s; } .s-seq { animation-duration: 2.3s; animation-delay: -.9s; }
    .s-forbeast-out, .s-incepxionbot-out { animation-duration: 1.9s; animation-delay: -.7s; }
    .s-botcake-in { animation-duration: 4.3s; animation-delay: -.3s; } .s-botcake-out { animation-duration: 5.2s; animation-delay: -1.7s; }

    /* Ang bot hub at ang notes nito. */
    .hub { width: 220px; height: 158px; padding: 8px 12px; }
    .hub .nm { display: flex; gap: 8px; align-items: center; font: 700 17px var(--semi); letter-spacing: .05em; white-space: nowrap; }
    .hub .nm s, .tape h2 i { text-decoration: none; }
    .hub .nm s { width: 9px; height: 9px; border-radius: 50%; background: var(--ice); flex: none; }
    .hub .st { color: var(--ice); font-weight: 600; font-size: 18px; margin-top: 2px; }
    .hub .io { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
    .hub .io em { font: 700 14px var(--mono); letter-spacing: .1em; color: var(--mut); border: 1.5px solid var(--line2); padding: 0 4px; line-height: 19px; font-style: normal; width: 42px; text-align: center; }
    .hub .io .num { font-size: 42px; line-height: .9; }
    .hub .dorm { display: block; font: 700 32px var(--cond); letter-spacing: .06em; color: var(--dim); margin-top: 16px; }
    .hub .off small { display: block; font-size: 19px; color: var(--dim); margin-top: 8px; }
    .lead { fill: none; stroke: var(--ice); stroke-width: 1.5; stroke-opacity: .8; } .cap { fill: var(--ice); }
    .note { left: 40px; width: 350px; padding: 10px 16px 8px; overflow: hidden; }
    .note .h { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; } .note .h .mono { color: var(--ice); font-size: 17px; }
    .note .g2 { display: grid; grid-template-columns: 1fr 1fr; column-gap: 12px; } .note .g3 { display: grid; grid-template-columns: 1fr 1fr 1fr; column-gap: 8px; }
    .note .c { padding: 3px 0; min-width: 0; } .note .c .mono { font-size: 15px; display: block; white-space: nowrap; } .note .g3 .mono { font-size: 14px; letter-spacing: .03em; }
    .note .c b { font: 700 32px var(--cond); display: block; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .note .c b.gold { color: var(--gold2); }
    .note .l { margin-top: 4px; min-width: 0; } .note .l b { font: 600 22px var(--semi); display: block; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .note .l b.sm { font-size: 20px; }
    .note .cu { margin-top: 6px; }
    .cl { margin-top: 4px; overflow: hidden; height: 116px; }
    .cl li { display: flex; align-items: center; gap: 8px; height: 29px; border-top: 1px solid rgba(111,216,255,.14); font-size: 23px; white-space: nowrap; }
    .cl li.new { animation: slide .8s var(--ease); }
    .cl b { font-weight: 600; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
    .cl em { font: 700 14px var(--mono); letter-spacing: .06em; font-style: normal; border: 1.5px solid var(--line2); color: var(--ice); padding: 0 5px; line-height: 18px; }
    .cl em.t-ordered { color: var(--gold); border-color: rgba(255,194,71,.6); }
    .cl time { font: 16px var(--mono); color: var(--mut); }
    .tk { animation: tk .5s ease-out; }
    @keyframes tk { 0% { color: #fff; transform: translateY(-3px); } 100% { transform: none; } }
    .off { display: none; }
    [data-linked="0"] .on { display: none; } [data-linked="0"] .off { display: block; } [data-linked="0"] .h .off { display: inline; }
    [data-linked="0"] .hub, [data-linked="0"] .note { background: transparent; border: 1.5px dashed rgba(111,216,255,.3); color: var(--dim); }
    [data-linked="0"] .br::before { --c: rgba(111,216,255,.35); }
    [data-linked="0"] .hub .nm s { background: transparent; border: 1.5px dashed var(--dim); }
    [data-linked="0"] .hub .st, [data-linked="0"] .note .h .mono { color: var(--dim); }
    [data-linked="0"] .bp { stroke-dasharray: 6 7; stroke-opacity: .5; }
    [data-linked="0"] .lead { stroke-dasharray: 5 5; stroke-opacity: .45; }
    [data-linked="0"] .streak { display: none; }

    /* Ang loot. */
    .burst { fill: none; stroke: var(--gold); stroke-width: 3; }
    .fl { position: absolute; left: 780px; top: 462px; width: 300px; text-align: center; font: 700 40px var(--cond); color: var(--gold); opacity: 0; pointer-events: none; letter-spacing: .03em; white-space: nowrap; }
    .fl.go { animation: fl 1.8s var(--ease); } .fl.still { opacity: 1; }
    @keyframes fl { 0% { opacity: 0; translate: 0 0; } 12% { opacity: 1; } 70% { opacity: 1; } 100% { opacity: 0; translate: 0 -26px; } }

    /* Ang LIVE ACTIVITY. */
    .tape { position: absolute; left: 1440px; top: 104px; width: 440px; height: 936px; overflow: hidden; }
    .tape h2 { height: 64px; display: flex; align-items: center; gap: 14px; padding: 0 22px; font: 700 28px var(--cond); letter-spacing: .14em; border-bottom: 1.5px solid rgba(111,216,255,.22); }
    .rows li { height: 108px; padding: 14px 22px 0; border-bottom: 1px solid rgba(111,216,255,.14); }
    .rows li:first-child { background: rgba(111,216,255,.07); }
    .rows li:nth-child(n+5) { opacity: .85; } .rows li:nth-child(n+7) { opacity: .72; }
    .rows .a { display: flex; align-items: center; gap: 14px; height: 32px; min-width: 0; }
    .rows time { font: 21px var(--mono); color: var(--mut); } .rows .ch { font: 700 19px var(--mono); letter-spacing: .1em; color: var(--ice); white-space: nowrap; }
    .rows .b { margin-left: auto; font-size: 28px; font-weight: 700; white-space: nowrap; }
    .rows .d { font-size: 26px; margin-top: 8px; color: #d3e8f5; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rows li.o { background: linear-gradient(90deg, rgba(255,194,71,.22), rgba(255,194,71,.06)); } .rows li.o .ch { color: var(--gold); } .rows li.o .d { color: #ffe2a3; }
    .rows li.new { animation: slide .8s var(--ease); }
    @keyframes slide { from { transform: translateX(24px); opacity: 0; } }
    .listen { display: none; padding: 18px 22px; font-size: 22px; color: var(--mut); }
    .rows:empty + .listen { display: block; }

    /* Boot (minsan, sa pagbukas): ang mga pipe ay iginuguhit, ang notes ay bumubukas mula itaas pababa. */
    .boot .pipe:not([data-linked="0"] .bp) { stroke-dasharray: 1; stroke-dashoffset: 1; animation: draw 1.4s var(--ease) forwards; }
    @keyframes draw { to { stroke-dashoffset: 0; } }
    .boot .note { animation: unfold .7s var(--ease) both; }
    .boot .g-forbeast .note { animation-delay: .5s; } .boot .g-incepxionbot .note { animation-delay: .9s; } .boot .g-botcake .note { animation-delay: 1.3s; }
    @keyframes unfold { from { clip-path: inset(0 0 100% 0); } to { clip-path: inset(0 0 0 0); } }

    /* Telepono (390 px): walang singsing, pipe o 3D; ang HUD ay mga card sa isang column: header, KPI 2 × 2, ang gintong core,
       isang card kada bot na may notes sa ilalim, AI, follow-ups, at ang activity. */
    .mcore { display: none; }
    @media (max-width: 900px) {
        .vp { height: auto; min-height: 100vh; overflow: visible; }
        .stage { position: static; width: auto; height: auto; transform: none !important; display: flex; flex-direction: column; gap: 10px; padding: 0 14px 30px; }
        .cam, .world { display: contents; }
        svg.lyr, .fl, .scan, .unk, [data-live-3d] { display: none !important; }
        .grp { position: static; display: contents; transform: none; }
        .abs, .top, .kpis, .kp, .tape, .hub, .note { position: relative !important; left: auto !important; top: auto !important; width: auto !important; height: auto !important; }
        .top { order: 1; flex-wrap: wrap; gap: 6px 12px; padding: 12px 0; } .top h1 { font-size: 30px; } .logo { width: 28px; height: 28px; } .lv-status { font-size: 17px; }
        .top .sp { display: none; } .lv-filter { flex-wrap: wrap; gap: 8px; width: 100%; order: 5; }
        .sel, .btn { height: 40px; font-size: 16px; padding: 0 10px; } .sel { flex: 1 1 140px; max-width: none; }
        .clock { order: 4; text-align: left; } .clock small { font-size: 12px; } .clock b { font-size: 22px; } .exit { font-size: 15px; order: 6; }
        .kpis { order: 2; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .kp { min-width: 0; } .kp .lab { font-size: 14px; } .kp .num { font-size: 46px; } .kp .num u { font-size: 22px; } .kp small { font-size: 14px; white-space: normal; } .tag { font-size: 11px; }
        .mcore { display: block; order: 3; text-align: center; padding: 16px; border: 1.5px solid rgba(255,194,71,.6); background: radial-gradient(circle at 50% 30%, #24200c, #0f0d06 60%, #080704); }
        .mcore h2 { font: 600 14px var(--semi); letter-spacing: .14em; color: #e0b660; } .mcore b { display: block; font: 700 64px/1 var(--cond); color: #ffd36e; margin: 6px 0; }
        .mcore p { font-size: 16px; color: #f0e3c4; } .mcore .v-up { font-weight: 700; } .mcore .m-last { color: #b7a97f; font-size: 14px; margin-top: 4px; }
        .g-forbeast > section { order: 10; } .g-incepxionbot > section { order: 12; } .g-botcake > section { order: 14; }
        .g-ai > section { order: 20; } .g-seq > section { order: 21; } .g-tot > section { order: 22; } .g-core > section { order: 23; } .tape { order: 30; }
        .hub { padding: 10px 14px; } .hub .on { display: flex; gap: 18px; } [data-linked="0"] .hub .on { display: none; }
        .note { margin-top: -10px; border-top: 0; padding: 10px 14px; } .note .c b { font-size: 26px; } .cl { height: auto; } .cl li { font-size: 17px; }
        .tot, .ai, .seq, .core { padding: 10px 14px; } .tot .lab { white-space: normal; font-size: 15px; } .seq .row { font-size: 16px; }
        .tape { padding-bottom: 8px; } .tape h2 { font-size: 20px; height: 48px; } .rows li { height: auto; padding: 10px 14px; } .rows time { font-size: 14px; }
        .rows .ch { font-size: 13px; } .rows .b { font-size: 18px; } .rows .d { font-size: 16px; margin-top: 4px; }
    }
    /* Reduced motion: walang barya, streak, ikot, scan, boot o pag-ugoy; ang mga bilang ay napapalitan agad at ang orasan ay gumagalaw pa rin (data iyon). */
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation: none !important; transition: none !important; }
        .streak { display: none; }
    }
</style>
@endpush

@push('scripts')
<script src="{{ asset('js/chatbot-live-rules.js') }}"></script>
<script>
// /chatbot/live: ang feed ay pino-poll kada 5 segundo (tumitigil kapag nakatago ang tab). Ang galaw na laging tumatakbo (ikot ng
// mga singsing, scan band, streak sa bawat buhay na link) ay CSS; ang script ay para sa data: ang mga bilang (umaakyat, hindi
// bumababa sa loob ng isang minuto: LiveRules.settle / land), ang loot ng order (LiveRules.schedule / loot: mga barya mula sa hub
// papunta sa core, ang kabuuan ay umaakyat sa bawat paglapag), ang notes ng bawat bot, ang 5-minutong orasan at ang 3D na camera.
// Buong araw ito sa TV: walang lumalaking DOM (12 barya na ginagamit muli, 8 linya ng activity, 4 na customer kada bot), at ang
// animation loop ay tumatakbo lang habang may gumagalaw. Ang text mula sa server (pangalan ng page o customer) ay textContent lang.
(() => {
    const R = window.LiveRules;
    const boot = JSON.parse(document.getElementById('live-data').textContent);
    const root = document.querySelector('[data-live]');
    const stage = root.querySelector('[data-live-stage]');
    const world = root.querySelector('[data-live-world]');
    const mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const live = window.likhaLive = {reduced: !!(mq && mq.matches), cursor: boot.cursor, counts: boot.counts, timer: null, inflight: false, fail: 0, st: {}};
    const LOG_LINES = 8, POOL = 12;
    const TYPE = {message: ['INCOMING', 'Inquiry received'], confirmed: ['SENT', 'Reply sent'], follow_up: ['SEQUENCE', 'Follow-up sent'], ai: ['AI', 'AI answered'], order: ['ORDER', 'Order recorded']};
    const all = s => root.querySelectorAll(s);
    const toggle = (e, on) => on ? e.removeAttribute('hidden') : e.setAttribute('hidden', '');
    const now = () => performance.now();
    const phone = () => innerWidth <= 900;
    const mk = (n, a, p) => { const e = document.createElementNS('http://www.w3.org/2000/svg', n); for (const k in a) e.setAttribute(k, a[k]); if (p) p.appendChild(e); return e; };
    const ease = t => t >= 1 ? 1 : t <= 0 ? 0 : 1 - Math.pow(2, -10 * t);
    const clamp = (x, a = 0, b = 1) => Math.min(b, Math.max(a, x));
    function again(e, cls) { e.classList.remove(cls); void e.getBoundingClientRect(); e.classList.add(cls); }

    // ── ang stage: 1920 × 1080 na pinalaki sa screen, at ang mabagal na paglipat laban sa burn-in (hanggang 4 px, isang hakbang kada minuto) ──
    const DRIFT = [[0, 0], [2, 1], [3, -2], [1, 3], [-1, 2], [-3, 3], [-2, -1], [2, 2], [-1, -3], [0, 1]];
    let drift = 0;
    function fit() {
        if (phone()) { stage.style.transform = ''; return; }
        const s = Math.min(innerWidth / 1920, innerHeight / 1080), [dx, dy] = DRIFT[drift % DRIFT.length];
        stage.style.transform = 'translate(' + ((innerWidth - 1920 * s) / 2 + dx) + 'px,' + ((innerHeight - 1080 * s) / 2 + dy) + 'px) scale(' + s + ')';
    }
    addEventListener('resize', fit);
    setInterval(() => { drift++; fit(); }, 60000);
    fit();
    if (!live.reduced) { stage.classList.add('boot'); setTimeout(() => stage.classList.remove('boot'), 2600); }

    // ── ang mga bilang: bawat isa ay may nakikita at server; umaakyat nang paunti-unti (iisang animation loop para sa lahat) ──
    const day = (k, f, hold = 0, dur = 900) => ({k, f, hold, floor: 60000, dur});
    const win = (k, f) => ({k, f, hold: 0, floor: 0, dur: 900});
    const NUMS = [
        day('replies', c => c.confirmed.today), day('orders', c => c.orders.today, 8000, 600),
        day('peso', c => c.order_value ? c.order_value.today : 0, 8000, 300), day('messages', c => c.messages.today),
        day('ai', c => c.ai.today), day('follow_ups', c => c.follow_ups.today),
        win('active', c => c.active), win('signals', c => R.signals(c)),
    ];
    for (const side of ['in', 'out']) for (const b of [...Object.keys(boot.bots), 'unknown']) NUMS.push(day(side + '-' + b, c => c.bots[side][b]));
    const spec = {};
    for (const n of NUMS) spec[n.k] = n;
    const shown = {}, rolls = {};
    let rollRaf = 0;
    function paint(k, v) {
        const text = R.num(v);
        for (const e of all('[data-live-count="' + k + '"]')) if (e.textContent !== text) e.textContent = text;
    }
    function rollTick(t) {
        for (const k of Object.keys(rolls)) {
            const r = rolls[k], q = Math.min(1, (t - r.t0) / r.dur);
            paint(k, R.roll(r.from, r.to, q));
            if (q >= 1) delete rolls[k];
        }
        rollRaf = Object.keys(rolls).length ? requestAnimationFrame(rollTick) : 0;
    }
    function show(k, v) {
        const from = shown[k] === undefined ? v : shown[k];
        shown[k] = v;
        if (from === v) return;
        if (live.reduced || document.hidden) { paint(k, v); return; }
        rolls[k] = {from: rolls[k] ? R.roll(rolls[k].from, rolls[k].to, Math.min(1, (now() - rolls[k].t0) / rolls[k].dur)) : from, to: v, t0: now(), dur: spec[k].dur};
        if (!rollRaf) rollRaf = requestAnimationFrame(rollTick);
    }
    function settleAll() {
        const t = Date.now();
        for (const n of NUMS) {
            const server = Number(n.f(live.counts)) || 0;
            live.st[n.k] = live.st[n.k] ? R.settle(live.st[n.k], server, t, n.hold, n.floor) : {shown: server, server, heldSince: null, lastUp: null};
            show(n.k, live.st[n.k].shown);
        }
    }
    function landOn(k, delta) {
        live.st[k] = R.land(live.st[k], delta, Date.now());
        show(k, live.st[k].shown);
    }
    function put(key, value, attr) {
        for (const e of all('[' + attr + '="' + key + '"]')) if (e.textContent !== value) e.textContent = value;
    }

    // ── ang notes ng bawat bot: ang mga numero, ang huling ginawa at ang 4 na pinakabagong customer ──
    const held = {};
    function noteText(n) {
        return {chatting: R.num(n.chatting), ai: R.num(n.ai), avg: n.avg === null || n.avg === undefined ? '—' : Number(n.avg).toFixed(1) + ' s', orders: R.num(n.orders),
            value: R.peso(n.value), last: n.last ? (boot.kinds[n.last.type] || 'Event') + ' · ' + n.last.page + ' · ' + n.last.at : '—'};
    }
    function setNote(bot, n) {
        const el = root.querySelector('[data-note="' + bot + '"]');
        if (!el || !n) return;
        const text = noteText(n);
        for (const k of Object.keys(text)) {
            const e = el.querySelector('[data-n="' + k + '"]');
            if (!e || e.textContent === text[k]) continue;
            e.textContent = text[k];
            if (!live.reduced) again(e, 'tk');
        }
        const list = el.querySelector('[data-cl]'), rows = n.customers || [];
        const sig = c => c ? c.name + '|' + c.tag + '|' + c.at : '';
        const before = list.firstElementChild ? list.firstElementChild.dataset.sig : '';
        if ([...list.children].map(li => li.dataset.sig).join('/') !== rows.map(sig).join('/')) {
            list.replaceChildren(...rows.map(c => {
                const li = document.createElement('li'), b = document.createElement('b'), em = document.createElement('em'), t = document.createElement('time');
                li.dataset.sig = sig(c); b.textContent = c.name; em.textContent = c.tag; em.className = 't-' + String(c.tag).toLowerCase(); t.textContent = c.at;
                li.append(b, em, t);
                return li;
            }));
            if (rows.length && before !== undefined && before !== sig(rows[0]) && !live.reduced) list.firstElementChild.classList.add('new');
        }
        toggle(el.querySelector('[data-cl-none]'), !rows.length);
    }
    for (const li of all('[data-cl] li')) li.dataset.sig = li.children[0].textContent + '|' + li.children[1].textContent + '|' + li.children[2].textContent;
    function setNotes(notes) {
        for (const bot of Object.keys(boot.bots)) {
            const n = notes && notes[bot];
            if (!n) continue;
            // Ang notes ng bot na may paparating na barya ay hinihintay ang paglapag (ang ORDERED at ang halaga ay sabay ng loot).
            if (held[bot]) { held[bot].note = n; continue; }
            setNote(bot, n);
        }
    }

    function setCounts(c) {
        live.counts = c;
        settleAll();
        for (const [k, src] of [['replies', 'confirmed'], ['orders', 'orders']]) {
            put(k, R.num(c[src].recent), 'data-live-recent');
            for (const e of all('[data-live-up="' + k + '"]')) e.classList.toggle('up', c[src].recent > 0);
        }
        const rt = c.reply_time || {state: 'error', seconds: null};
        put('reply_time', rt.seconds === null ? '—' : Number(rt.seconds).toFixed(1), 'data-live-count');
        for (const e of all('[data-live-unit]')) toggle(e, rt.seconds !== null);
        put('reply_time', boot.notes[rt.state === 'ok' && rt.seconds === null ? 'none' : rt.state] || boot.notes.error, 'data-live-note');
        for (const side of ['in', 'out']) for (const e of all('[data-live-unknown="' + side + '"]')) toggle(e, !!c.bots[side].unknown);
        for (const bot of Object.keys(boot.bots)) {
            const on = bot === 'botcake' || !!(c.linked && c.linked[bot]) ? '1' : '0';
            for (const e of all('[data-bot-row="' + bot + '"]')) e.setAttribute('data-linked', on);
        }
        const o = c.last_order, page = o ? (o.page.length > 24 ? o.page.slice(0, 23) + '…' : o.page) : '';
        put('', o ? page + ' · ' + o.at + (o.bot && boot.bots[o.bot] ? ' · ' + boot.bots[o.bot] : '') : 'no order yet today', 'data-live-last-order');
        const seqs = c.sequences || [];
        for (let i = 0; i < 3; i++) {
            const s = seqs[i];
            for (const e of all('[data-live-seq="' + i + '"]')) {
                toggle(e, !!s);
                if (s && e.children[0].textContent !== s.name) e.children[0].textContent = s.name;
                if (s && e.children[1].textContent !== R.num(s.n)) e.children[1].textContent = R.num(s.n);
            }
        }
        for (const e of all('[data-live-seq-none]')) toggle(e, !seqs.length);
        setNotes(c.notes);
    }

    // ── ang 5-minutong orasan: 60 guhit, isa kada 5 s; ang ulo ay puti, ang may event ay maliwanag at kumukupas, ang order ay ginto ──
    const ticks = [...all('[data-live-ticks] line')], bucket = new Array(60).fill(0), slotOf = new Array(60).fill(-1), gold = new Array(60).fill(-1);
    const head = () => Math.floor(Date.now() / 5000);
    function mark(order) { const h = head(), i = h % 60; if (slotOf[i] !== h) { slotOf[i] = h; bucket[i] = 0; } bucket[i]++; if (order) gold[i] = h; }
    function paintTicks() {
        const h = head();
        ticks.forEach((l, i) => {
            const age = ((h % 60) - i + 60) % 60, slot = h - age, n = slotOf[i] === slot ? bucket[i] : 0;
            let o = .2 + (n && age < 40 ? (1 - age / 40) * (.25 + .15 * Math.min(n, 4)) : 0), c = '';
            if (gold[i] === slot) { c = '#ffc247'; o = Math.max(o, .9); }
            if (age === 0) { c = '#ffffff'; o = 1; }
            l.style.stroke = c; l.setAttribute('opacity', o.toFixed(2));
        });
    }

    // ── ang loot: 12 barya na ginagamit muli, iisang animation loop habang may lumilipad ──
    const coinLayer = root.querySelector('[data-live-coins]'), burstEl = root.querySelector('[data-live-burst]'), shockEl = root.querySelector('[data-live-shock]');
    const rimEl = root.querySelector('[data-live-rim]'), glowEl = root.querySelector('[data-live-glow]'), valueEl = root.querySelector('[data-live-value]'), plus = root.querySelector('[data-live-plus]');
    const pool = [];
    for (let i = 0; i < POOL; i++) {
        const g = mk('g', {style: 'display:none'}, coinLayer), coin = mk('g', {}, g), plate = mk('g', {}, g);
        mk('circle', {r: 30, fill: 'url(#lv-coin)', stroke: '#ffe9a8', 'stroke-width': 2}, coin);
        mk('circle', {r: 22, fill: 'none', stroke: '#6a4a0c', 'stroke-width': 2, 'stroke-dasharray': '3 3'}, coin);
        mk('text', {y: 13, 'font-size': 36, fill: '#3a2603'}, coin).textContent = '₱';
        mk('rect', {x: -34, y: -19, width: 68, height: 38, rx: 3, fill: 'url(#lv-coin)', stroke: '#6a4a0c', 'stroke-width': 2}, plate);
        mk('text', {y: 10, 'font-size': 28, fill: '#2a1a02'}, plate).textContent = '+1';
        pool.push({g, coin, plate, busy: false});
    }
    const TARGET = [930, 600], bursts = [];
    let fxRaf = 0, glow = 0, glowAt = 0, shockAt = -1e9, bumpAt = -1e9, nudge = 0, nudgeAt = 0;
    function fx(t) {
        for (let k = bursts.length - 1; k >= 0; k--) {
            const b = bursts[k], u = t - b.t0;
            const q = clamp(u / 520);
            burstEl.setAttribute('cx', b.origin[0]); burstEl.setAttribute('cy', b.origin[1]);
            burstEl.setAttribute('r', (20 + 90 * ease(q)).toFixed(1)); burstEl.setAttribute('opacity', q >= 1 ? 0 : (.9 * (1 - q)).toFixed(3));
            for (const c of b.coins) {
                if (c.done) continue;
                const tt = u - c.i * 110;
                if (tt < 0) { c.p.g.style.display = 'none'; continue; }
                if (tt >= c.land) { c.done = true; c.p.g.style.display = 'none'; c.p.busy = false; b.onLand(c.i); continue; }
                let x, y, sc, op = 1;
                if (tt < 380) {
                    const v = tt / 380, e = 1 - Math.pow(1 - v, 3);
                    x = b.origin[0] + (c.bx - b.origin[0]) * e; y = b.origin[1] + (c.by - b.origin[1]) * e - 34 * Math.sin(Math.PI * v); sc = Math.min(1.15, .3 + 1.3 * v);
                } else {
                    const v = (tt - 380) / 900, e = Math.pow(v, 1.8), cx = (c.bx + TARGET[0]) / 2, cy = Math.min(c.by, TARGET[1]) - 150;
                    x = (1 - e) * (1 - e) * c.bx + 2 * (1 - e) * e * cx + e * e * TARGET[0]; y = (1 - e) * (1 - e) * c.by + 2 * (1 - e) * e * cy + e * e * TARGET[1];
                    sc = 1.12 - .38 * v; if (v > .9) op = (1 - v) / .1;
                }
                const sx = b.plate ? 1 : Math.max(.22, Math.abs(Math.cos(tt / 105 + c.i * 1.3)));
                c.p.g.style.display = '';
                c.p.g.setAttribute('transform', 'translate(' + x.toFixed(1) + ' ' + y.toFixed(1) + ') scale(' + (sx * sc).toFixed(3) + ' ' + sc.toFixed(3) + ')');
                c.p.g.style.opacity = op;
            }
            if (b.coins.every(c => c.done)) bursts.splice(k, 1);
        }
        // Ang core: kumikinang sa bawat paglapag, ang shock ring at ang pag-overshoot pagkatapos ng huling barya.
        const gl = glow * (1 - clamp((t - glowAt) / 260));
        rimEl.style.opacity = (.6 + .4 * gl).toFixed(3); glowEl.setAttribute('opacity', (gl * .6).toFixed(3));
        const ks = clamp((t - shockAt) / 1000);
        shockEl.setAttribute('r', (218 + 110 * ease(ks)).toFixed(1)); shockEl.setAttribute('opacity', ks >= 1 ? 0 : (.7 * (1 - ks)).toFixed(3)); shockEl.setAttribute('stroke-width', (3 - 2 * ks).toFixed(2));
        const bump = 1 + nudge * (1 - clamp((t - nudgeAt) / 180)) + .035 * Math.sin(Math.PI * clamp((t - bumpAt) / 520)) * (t - bumpAt < 520 ? 1 : 0);
        valueEl.style.transform = bump === 1 ? '' : 'scale(' + bump.toFixed(4) + ')';
        const busy = bursts.length || t - glowAt < 300 || t - shockAt < 1000 || t - bumpAt < 520;
        fxRaf = busy ? requestAnimationFrame(fx) : 0;
        if (!busy) { rimEl.style.opacity = ''; valueEl.style.transform = ''; }
    }
    function float(text) {
        clearTimeout(plus._lvT);
        plus.textContent = text;
        plus.classList.remove('go', 'still'); void plus.offsetWidth;
        plus.classList.add(live.reduced ? 'still' : 'go');
        plus._lvT = setTimeout(() => plus.classList.remove('still', 'go'), live.reduced ? 4000 : 1800);
    }
    // Ang pagdating ng order(s): ang mga barya (o ang "+1" na plate) mula sa hub ng bot papunta sa core; ang Orders ay +N sa unang
    // paglapag, ang halaga ay umaakyat ng bahagi ng bawat barya; ang notes ng bot ay sabay. Walang mapa (telepono, nakatago,
    // reduced motion) o walang bakanteng barya: ang dulo agad, walang nawawalang bilang.
    function cash(a) {
        const plan = R.loot(a.peso, {reduced: live.reduced || document.hidden || phone()});
        const bot = a.bot && boot.hubs[a.bot] ? a.bot : null;
        const finish = () => release(bot);
        const free = pool.filter(p => !p.busy);
        if (!plan.coins || free.length < plan.coins) {
            landOn('orders', a.orders); landOn('peso', plan.peso); mark(true); float(a.label); finish();
            return;
        }
        const t0 = now(), origin = bot ? boot.hubs[bot] : [930, 905], times = R.landings(plan.coins), N = plan.coins;
        const coins = free.slice(0, N).map((p, i) => {
            p.busy = true; p.coin.style.display = plan.plate ? 'none' : ''; p.plate.style.display = plan.plate ? '' : 'none';
            const ang = (-90 + (N > 1 ? (i - (N - 1) / 2) / ((N - 1) / 2) * 95 : 0)) * Math.PI / 180, rad = N > 1 ? 70 + (i % 3) * 24 : 50;
            return {p, i, land: times[i] - i * 110, bx: origin[0] + Math.cos(ang) * rad, by: origin[1] + Math.sin(ang) * rad * .85, done: false};
        });
        let landed = 0;
        bursts.push({t0, origin, coins, plate: plan.plate, onLand: i => {
            const t = now();
            if (landed++ === 0) { landOn('orders', a.orders); mark(true); float(a.label); finish(); }
            if (plan.peso) { landOn('peso', plan.parts[i]); glow = 1; glowAt = t; nudge = .012; nudgeAt = t; }
            if (landed === N && plan.peso) { shockAt = t; bumpAt = t; }
        }});
        if (!fxRaf) fxRaf = requestAnimationFrame(fx);
    }
    function hit(name) {
        for (const g of all('[data-live-card="' + name + '"]')) {
            g.classList.add('hit');
            clearTimeout(g._lvT);
            g._lvT = setTimeout(() => g.classList.remove('hit'), 500);
        }
    }
    function act(a) {
        if (a.type === 'order') { cash(a); return; }
        mark(false);
        if (a.type === 'ai') hit('ai');
        else if (a.type === 'follow_up') { hit('seq'); if (a.bot) hit(a.bot); }
        else if (a.bot) hit(a.bot);
        if (a.type === 'message') hit('inq');
    }
    // Ang plano ng poll. Ang notes ng bot na may loot sa plano ay hinihintay ang unang paglapag nito (ang ORDERED at ang halaga
    // ay sabay ng barya); hanggang 10 s lang, para hindi maiwan kailanman.
    function schedule(events) {
        const plan = R.schedule(events, {reduced: live.reduced || document.hidden || phone()});
        for (const a of plan.actions) {
            if (a.kind !== 'money' || !a.bot || !boot.hubs[a.bot]) continue;
            const h = held[a.bot] = held[a.bot] || {note: null, n: 0, timer: 0};
            h.n++;
            clearTimeout(h.timer);
            h.timer = setTimeout(() => { if (held[a.bot]) { held[a.bot].n = 1; release(a.bot); } }, 10000);
        }
        return plan;
    }
    function release(bot) {
        const h = bot && held[bot];
        if (!h || --h.n > 0) return;
        clearTimeout(h.timer);
        delete held[bot];
        if (h.note) setNote(bot, h.note);
    }
    function play(plan) {
        for (const a of plan.actions) a.at ? setTimeout(() => act(a), a.at) : act(a);
    }

    // ── ang LIVE ACTIVITY: 8 na linya, ang pinakabago sa itaas ──
    const logEl = root.querySelector('[data-live-log]');
    function log(events) {
        for (const e of events.slice(-LOG_LINES)) {
            const [chip, desc] = TYPE[e.type] || ['EVENT', 'Event'];
            const li = document.createElement('li');
            if (e.type === 'order') li.className = 'o';
            const a = document.createElement('div'); a.className = 'a';
            const t = document.createElement('time'); t.textContent = String(e.at).slice(11, 19);
            const ch = document.createElement('span'); ch.className = 'ch'; ch.textContent = chip + (e.type === 'order' && typeof e.amount === 'number' ? ' +' + R.peso(e.amount) : '');
            const b = document.createElement('span'); b.className = 'b'; b.textContent = e.bot && boot.bots[e.bot] ? boot.bots[e.bot] : boot.site;
            const d = document.createElement('div'); d.className = 'd'; d.textContent = desc + ' · ' + e.page;
            a.append(t, ch, b); li.append(a, d);
            logEl.prepend(li);
        }
        if (events.length && !live.reduced) for (const li of logEl.querySelectorAll('.new')) li.classList.remove('new');
        if (events.length && !live.reduced) logEl.firstElementChild.classList.add('new');
        while (logEl.children.length > LOG_LINES) logEl.lastElementChild.remove();
    }

    const statusEl = root.querySelector('[data-live-status]');
    function setStatus(state, label) {
        statusEl.dataset.liveStatus = state;
        statusEl.querySelector('[data-live-state]').textContent = label;
    }
    // Ang tuldok ng status ay pumipintig isang beses kada matagumpay na poll (hindi walang-tigil), kaya totoo ang "buhay".
    function beat() { if (!live.reduced) for (const e of all('[data-beat]')) again(e, 'beat'); }
    async function poll() {
        clearTimeout(live.timer);
        if (document.hidden || live.inflight) return;
        live.inflight = true;
        const q = new URLSearchParams();
        if (boot.page) q.set('page_id', boot.page);
        if (boot.bot) q.set('bot', boot.bot);
        for (const [k, v] of Object.entries(live.cursor || {})) q.set('cursor[' + k + ']', v);
        try {
            // Ang request na nabitin (hal. nawala ang Wi-Fi ng TV) ay pinuputol pagkalipas ng 15 s, kaya tuloy ang ulit.
            const res = await fetch(boot.feed + '?' + q, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store',
                signal: window.AbortSignal && AbortSignal.timeout ? AbortSignal.timeout(15000) : undefined});
            if ([401, 403, 419].includes(res.status)) { setStatus('off', 'SIGNED OUT: RELOAD'); return; }
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            live.cursor = data.cursor;
            live.fail = 0;
            const plan = schedule(data.events);
            setCounts(data.counts);
            for (const e of all('[data-live-updated]')) e.textContent = data.now;
            log(data.events);
            play(plan);
            setStatus('live', data.more > 0 ? 'LIVE · +' + R.num(data.more) + ' MORE' : 'LIVE');
            beat();
        } catch (e) {
            live.fail++;
            setStatus('error', 'RECONNECTING…');
        } finally {
            live.inflight = false;
        }
        if (!document.hidden && statusEl.dataset.liveStatus !== 'off') live.timer = setTimeout(poll, live.fail ? Math.min(30000, boot.interval * 2 ** live.fail) : boot.interval);
    }
    document.addEventListener('visibilitychange', () => {
        if (statusEl.dataset.liveStatus === 'off') return;
        if (document.hidden) { clearTimeout(live.timer); setStatus('hidden', 'PAUSED (TAB HIDDEN)'); } else { setStatus('live', 'LIVE'); poll(); }
    });
    for (const s of all('[data-live-page], [data-live-bot]')) s.addEventListener('change', e => e.target.form.submit());

    // ── 3D: CSS perspective; ang camera ay lumalapit at umiikot nang dahan-dahan (isang transform sa isang element kada frame,
    // habang 3D lang). Ang pinili ay naaalala ng browser na ito; 2D ang default. ──
    let store = null;
    try { store = window.localStorage; } catch (e) { store = null; }
    const b3 = root.querySelector('[data-live-3d]');
    let want = R.readView(store) === '3d' ? 1 : 0, k3 = 0, camRaf = 0;
    function cam(t) {
        k3 = live.reduced ? want : k3 + (want - k3) * .05;
        if (Math.abs(want - k3) < .002) k3 = want;
        const sway = live.reduced ? 0 : 1, rx = k3 * (32 + 3 * sway * Math.sin(t / 9000)), ry = k3 * k3 * k3 * 11 * sway * Math.sin(t / 11000 - 1.9);
        // Sa 3D ang mundo ay lumalayo nang 330 px at bumababa nang 70 px (buo na sa unang ikatlo ng paglipat, at mas baba pa nang
        // kaunti sa gitna nito), at ang ugoy ay lumalaki lang sa dulo ng paglipat: ang nakatagilid na notes ng Bot 1 ay hindi umaabot
        // sa KPI sa itaas, walang plate na tumatakip sa iba sa anumang punto ng paglipat o ng ugoy, at sa 3D ay buo ang lahat sa screen.
        const ty = 70 * Math.min(1, k3 * 3) + 30 * Math.sin(Math.PI * k3);
        world.style.transform = k3 > .001 ? 'translateY(' + ty.toFixed(1) + 'px) translateZ(' + (k3 * -330).toFixed(1) + 'px) rotateX(' + rx.toFixed(2) + 'deg) rotateY(' + ry.toFixed(2) + 'deg)' : '';
        world.style.setProperty('--k', k3.toFixed(3));
        // Habang 3D: ang pag-ikot ay tuloy (maliban sa reduced motion); sa 2D ay tumitigil ang loop pagkalapag.
        camRaf = (want && !live.reduced) || k3 !== want ? requestAnimationFrame(cam) : 0;
    }
    function view(mode, save) {
        want = mode === '3d' && !phone() ? 1 : 0;
        b3.setAttribute('aria-pressed', want ? 'true' : 'false');
        b3.textContent = want ? '2D' : '3D';
        if (save) R.saveView(store, want ? '3d' : '2d');
        if (!camRaf) camRaf = requestAnimationFrame(cam);
    }
    b3.addEventListener('click', () => view(want ? '2d' : '3d', true));
    view(want ? '3d' : '2d', false);

    function motion(reduced) { live.reduced = reduced; if (!camRaf) camRaf = requestAnimationFrame(cam); }
    if (mq && mq.addEventListener) mq.addEventListener('change', e => motion(e.matches));

    // TV mode: buong screen (kapag kaya ng browser).
    const fs = root.querySelector('[data-live-fullscreen]');
    if (fs && document.documentElement.requestFullscreen) {
        toggle(fs, true);
        fs.addEventListener('click', () => document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen().catch(() => {}));
        document.addEventListener('fullscreenchange', () => { fs.textContent = document.fullscreenElement ? 'Exit full screen' : 'TV mode'; });
    }
    // Ang orasan ng Manila, kada segundo; ang mga bilang na hinihintay ang barya ay sinusuri rin kada segundo.
    const clock = root.querySelector('[data-live-clock]');
    function second() {
        try { clock.textContent = new Date().toLocaleTimeString('en-GB', {timeZone: 'Asia/Manila', hour12: false}); } catch (e) { /* ang huling oras ng server ang naiiwan */ }
        paintTicks();
        if (['orders', 'peso'].some(k => live.st[k] && live.st[k].heldSince !== null)) settleAll();
    }
    live.play = events => play(schedule(events)); live.setCounts = setCounts; live.log = log; live.view = view;

    setCounts(boot.counts);
    second();
    setInterval(second, 1000);
    live.timer = setTimeout(poll, boot.interval);
})();
</script>
@endpush
