@extends('layouts.chatbot-tv')

@section('title', 'Live')

@php
    $c = $feed['counts'];
    // Ang mga bot: [pangalan, paliwanag, kulay].
    $bots = [
        'forbeast' => ['Bot 1', 'Forbeast', 'b1'],
        'incepxionbot' => ['Bot 2', 'Incepxionbot', 'b2'],
        'botcake' => ['Botcake', 'fallback', 'bc'],
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
@endphp

@section('content')
<div class="vp" data-live data-live-board>
<div class="stage" data-live-stage>

    <header class="top">
        <svg class="logo" viewBox="0 0 48 48" aria-hidden="true"><path d="M6 20 24 4l4 4-18 16zM12 34 36 10l4 4-24 24zM22 40 44 20v8L26 44z"/></svg>
        <h1>{{ strtoupper($site) }} LIVE</h1>
        <span class="lv-status" data-live-status="live"><i aria-hidden="true"></i><span data-live-state>LIVE</span></span>
        <span class="sp">
            <form method="GET" action="{{ route('chatbot.live') }}" class="lv-filter" aria-label="Filters">
                <select class="sel" name="page_id" aria-label="Page" data-live-page>
                    <option value="">All pages</option>
                    @foreach($pages as $name => $id)<option value="{{ $id }}" @selected((string) $page === (string) $id)>{{ $name }}</option>@endforeach
                </select>
                <select class="sel" name="bot" aria-label="Bot" data-live-bot>
                    <option value="">All bots</option>
                    @foreach($bots as $key => [$label, $name])<option value="{{ $key }}" @selected($bot === $key)>{{ $label }} · {{ ucfirst($name) }}</option>@endforeach
                </select>
                <noscript><button class="sel">Show</button></noscript>
            </form>
            <span class="clock">Today · Manila · updated <time data-live-updated>{{ $feed['now'] }}</time><b data-live-clock>{{ $feed['now'] }}</b></span>
            <button class="tvb" type="button" data-live-fullscreen hidden>TV mode</button>
            <a href="{{ route('chatbot.speed') }}" class="exit" data-tv-exit>Exit TV mode</a>
        </span>
    </header>

    <section class="kpis" aria-label="Today">
        <div class="kpi k1"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v12H11l-5 4v-4H3z"/></svg>
            <div><h2>Replies today</h2><b class="v" data-live-count="replies">{{ $n($c['confirmed']['today']) }}</b><small class="{{ $up('confirmed') }}" data-live-up="replies"><b><i>▲ </i>+<span data-live-recent="replies">{{ $n($c['confirmed']['recent']) }}</span></b> in the last 5 min</small></div></div>
        <div class="kpi k2"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="13.5" r="7.5"/><path d="M12 9.5v4l2.5 2M9.5 3h5M12 3v3"/></svg>
            <div><h2>Avg reply time <span class="bt">Bot 1</span></h2><b class="v"><span data-live-count="reply_time">{{ $rt['seconds'] === null ? '—' : number_format($rt['seconds'], 1) }}</span><u data-live-unit @if($rt['seconds'] === null) hidden @endif>s</u></b><small class="wrap" data-live-note="reply_time">{{ $rtNote }}</small></div></div>
        <div class="kpi k3" data-live-kpi="orders"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 8h14l-1 12H6zM9 8V6a3 3 0 0 1 6 0v2"/></svg>
            <div><h2>Orders today</h2><b class="v" data-live-count="orders">{{ $n($c['orders']['today']) }}</b><small class="{{ $up('orders') }}" data-live-up="orders"><b><i>▲ </i>+<span data-live-recent="orders">{{ $n($c['orders']['recent']) }}</span></b> in the last 5 min</small></div>
            <span class="plus" data-live-plus aria-hidden="true"></span></div>
        <div class="kpi k4"><svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-4 3-6 6.5-6s6.5 2 6.5 6M16 4.5a3.5 3.5 0 0 1 0 7M18 14c2.5.7 3.5 2.5 3.5 6"/></svg>
            <div><h2>Active chats</h2><b class="v" data-live-count="active">{{ $n($c['active']) }}</b><small>Messaged in the last 5 min</small></div></div>
    </section>

    <section class="net" aria-label="Neural network">
        @include('chatbot.partials.live-map')
        <div class="bar"><h2>NEURAL NETWORK</h2><span>Live signals · today’s activity</span>
            <div class="leg" aria-label="Legend"><span class="t-inq"><i></i>Inquiries</span><span class="t-seq"><i></i>Sequences</span><span class="t-out"><i></i>Outbound</span><span class="t-ai"><i></i>AI</span><span class="t-ord"><i></i>Orders</span></div></div>
    </section>

    {{-- Telepono: walang canvas at wire; ang parehong bilang bilang mga card, sa ayos ng daloy. --}}
    <div class="mob" aria-label="Network (phone)">
        <div class="mc mcore t-out" data-live-card="core"><h3>{{ strtoupper($site) }} BOT</h3><small>Process · prepare · route</small><b data-live-count="signals">{{ $n($signals) }}</b><small>signals · last 5 min</small></div>
        <div class="mrow">
            <div class="mc t-inq" data-live-card="inq"><h3>CUSTOMER INQUIRIES</h3><b data-live-count="messages">{{ $n($c['messages']['today']) }}</b><small>received today</small></div>
            <div class="mc t-ai" data-live-card="ai"><h3>AI ANSWERS</h3><b data-live-count="ai">{{ $n($c['ai']['today']) }}</b><small>its own step</small></div>
        </div>
        @foreach($bots as $key => [$label, $name])
            <div class="mc t-out" data-bot-row="{{ $key }}" data-linked="{{ $linked[$key] ? 1 : 0 }}" data-live-card="{{ $key }}">
                <h3>{{ strtoupper($label) }} · {{ strtoupper($name) }}</h3><span class="chip on">● {{ $key === 'botcake' ? 'Fallback' : 'Connected' }}</span><span class="chip off">○ Not linked</span>
                <b class="on" data-live-count="out-{{ $key }}">{{ $n($c['bots']['out'][$key]) }}</b><b class="off">DORMANT</b>
                <small>replies sent · <span data-live-count="in-{{ $key }}">{{ $n($c['bots']['in'][$key]) }}</span> in<span class="off"> · no traffic while not linked</span></small>
            </div>
        @endforeach
        <div class="mrow">
            <div class="mc t-seq" data-live-card="seq"><h3>FOLLOW-UPS SENT</h3><b data-live-count="follow_ups">{{ $n($c['follow_ups']['today']) }}</b>
                <small data-live-seq="0">{{ isset($seqs[0]) ? mb_strimwidth($seqs[0]['name'], 0, 18, '…') . ' · ' . $n($seqs[0]['n']) : '' }}</small></div>
            <div class="mc t-ord" data-live-card="ord"><h3>ORDER VALUE</h3><b>₱ <span data-live-count="peso">{{ $n($c['order_value']['today']) }}</span></b><small>orders recorded: <span data-live-count="orders">{{ $n($c['orders']['today']) }}</span></small></div>
        </div>
    </div>

    <aside class="act" aria-labelledby="lv-h-log">
        <h2 id="lv-h-log">LIVE ACTIVITY <i aria-hidden="true"></i></h2>
        <ol class="tl" data-live-log></ol>
        <div class="listen"><i aria-hidden="true"></i>Listening for new events</div>
    </aside>

</div>
</div>

<script type="application/json" id="live-data">{!! json_encode(['feed' => route('chatbot.live.feed', [], false), 'page' => $page, 'bot' => $bot, 'cursor' => $feed['cursor'], 'counts' => $feed['counts'],
    'interval' => 5000, 'site' => $site, 'bots' => array_map(fn ($b) => $b[0], $bots), 'notes' => $notes], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@push('styles')
<style>
    /* Ang Live bilang neural network room: isang canvas na 1920 × 1080 na pinapalaki o pinapaliit sa screen (ang KPI, ang
       network at ang timeline ay iisang coordinate space), madilim na navy, kulay sa node, link at numero lang. Ang font ay
       Saira / IBM Plex Mono kapag naka-install sa TV, kung hindi ay ang system font (Bahnschrift, saka ang iba). */
    :root { --void: #040a14; --line: rgba(120,190,255,.16); --line2: rgba(120,190,255,.30); --ink: #f2f8ff; --mut: #8ea6bf;
        --inq: #35c8ff; --seq: #a566ff; --out: #2af0c0; --ai: #ff5fa8; --ord: #ffbf2f; --chip: #ffd77a; --bad: #ff6262;
        --num: "Saira Condensed", "Bahnschrift Condensed", "Bahnschrift", "Roboto Condensed", "Arial Narrow", sans-serif;
        --txt: "Saira", "Bahnschrift", "Segoe UI", "Roboto", system-ui, sans-serif;
        --mono: "IBM Plex Mono", ui-monospace, "Cascadia Mono", Consolas, monospace;
        --ease: cubic-bezier(.16,1,.3,1); }
    * { box-sizing: border-box; margin: 0; }
    html, body { background: var(--void); color: var(--ink); font-family: var(--txt); font-variant-numeric: tabular-nums; -webkit-font-smoothing: antialiased; }
    body { overflow-x: hidden; }
    [hidden] { display: none !important; }
    .vp { position: relative; height: 100vh; overflow: hidden; background: radial-gradient(1000px 700px at 40% 62%, rgba(30,110,190,.20), transparent 70%), var(--void); }
    .stage { position: absolute; left: 0; top: 0; width: 1920px; height: 1080px; transform-origin: 0 0; }
    .mob { display: none; }
    .t-inq { --c: var(--inq); } .t-seq { --c: var(--seq); } .t-out { --c: var(--out); } .t-ai { --c: var(--ai); } .t-ord { --c: var(--ord); } .t-dim { --c: #7f93ab; }

    /* Ang header. */
    .top { position: absolute; left: 0; top: 0; width: 1920px; height: 72px; display: flex; align-items: center; gap: 28px; padding: 0 48px; border-bottom: 1px solid var(--line); }
    .logo { width: 46px; height: 46px; flex: none; fill: var(--out); }
    .top h1 { font: 700 40px/1 var(--txt); letter-spacing: .14em; white-space: nowrap; }
    .lv-status { display: inline-flex; align-items: center; gap: 10px; color: var(--ink); font: 500 26px/1 var(--txt); letter-spacing: .08em; white-space: nowrap; }
    .lv-status i { width: 14px; height: 14px; border-radius: 50%; background: var(--out); animation: ping 2s infinite; }
    .lv-status[data-live-status="hidden"] { color: var(--ord); } .lv-status[data-live-status="hidden"] i { background: var(--ord); }
    .lv-status[data-live-status="error"], .lv-status[data-live-status="off"] { color: var(--bad); }
    .lv-status[data-live-status="error"] i, .lv-status[data-live-status="off"] i { background: var(--bad); }
    .lv-status:not([data-live-status="live"]) i { animation: none; }
    .top .sp { margin-left: auto; display: flex; align-items: center; gap: 24px; min-width: 0; }
    .lv-filter { display: flex; gap: 16px; min-width: 0; }
    .sel { font: 500 24px var(--txt); color: var(--ink); background: rgba(8,24,44,.8); border: 1px solid var(--line2); padding: 8px 16px; border-radius: 6px; max-width: 340px; min-width: 0; }
    .clock { border-left: 1px solid var(--line2); padding-left: 24px; font: 500 18px/1.15 var(--txt); color: var(--mut); white-space: nowrap; }
    .clock b { display: block; font: 600 32px/1.1 var(--txt); color: var(--ink); }
    .tvb { font: 500 24px var(--txt); color: var(--ink); background: rgba(8,24,44,.8); border: 1px solid var(--line2); padding: 10px 20px; border-radius: 6px; cursor: pointer; }
    .exit { color: var(--mut); font-size: 18px; text-decoration: underline; text-underline-offset: .2em; white-space: nowrap; }
    .exit:hover, .exit:focus-visible, .tvb:hover { color: var(--ink); }
    .sel:focus-visible, .tvb:focus-visible, .exit:focus-visible { outline: 3px solid var(--inq); outline-offset: 2px; }

    /* Ang apat na KPI. */
    .kpis { position: absolute; left: 0; top: 72px; width: 1920px; height: 160px; display: grid; grid-template-columns: repeat(4, 1fr); border-bottom: 1px solid var(--line); }
    .kpi { position: relative; display: flex; align-items: center; gap: 18px; padding: 0 26px 0 48px; border-right: 1px solid var(--line); min-width: 0; }
    .kpi:last-child { border-right: 0; }
    .kpi > div { min-width: 0; }
    .kpi svg.ic { flex: none; width: 50px; height: 50px; fill: none; stroke: var(--c); stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
    .kpi h2 { font: 500 25px/1 var(--txt); color: var(--mut); letter-spacing: .04em; white-space: nowrap; }
    .kpi .bt { display: inline-block; border: 1px solid var(--line2); border-radius: 4px; padding: 1px 8px; margin-left: 8px; color: var(--ink); font-size: 18px; vertical-align: 2px; }
    .kpi .v { display: block; font: 700 88px/.95 var(--num); color: var(--ink); margin: 4px 0 0; white-space: nowrap; }
    .kpi .v u { font: 600 46px var(--num); text-decoration: none; color: var(--mut); margin-left: 6px; }
    .kpi small { display: block; font: 400 22px/1.15 var(--txt); color: var(--mut); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .kpi small.wrap { white-space: normal; max-height: 2.3em; }
    .kpi small > b { color: var(--c); font-weight: 600; }
    .kpi small:not(.up) > b i { display: none; }
    .kpi.k1 { --c: var(--out); } .kpi.k2 { --c: var(--inq); } .kpi.k3 { --c: var(--ord); } .kpi.k4 { --c: var(--inq); }
    .kpi.cash { animation: kcash 1.4s var(--ease); }
    @keyframes kcash { 0%, 30% { background: radial-gradient(400px 160px at 30% 50%, rgba(255,191,47,.30), transparent 70%); } 100% { background: none; } }
    .kpi .plus { position: absolute; left: 330px; top: 12px; font: 700 40px var(--num); color: var(--chip); opacity: 0; white-space: nowrap; pointer-events: none; }
    .kpi .plus.go { animation: plus 1.8s var(--ease); }
    .kpi .plus.still { opacity: 1; }
    @keyframes plus { 0% { opacity: 0; transform: translateY(16px); } 18% { opacity: 1; } 100% { opacity: 0; transform: translateY(-30px); } }

    /* Ang network. */
    .net { position: absolute; left: 0; top: 232px; width: 1500px; height: 848px; }
    .nm { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; display: block; }
    .net .bar { position: absolute; left: 48px; top: 12px; right: 40px; display: flex; align-items: baseline; gap: 22px; }
    .net .bar h2 { font: 700 28px/1 var(--txt); letter-spacing: .3em; }
    .net .bar h2::before { content: ""; display: inline-block; width: 5px; height: 24px; background: var(--inq); margin-right: 16px; vertical-align: -3px; }
    .net .bar > span { font-size: 24px; color: var(--mut); }
    .leg { margin-left: auto; display: flex; gap: 26px; font-size: 24px; color: var(--ink); }
    .leg i { display: inline-block; width: 14px; height: 14px; border-radius: 50%; background: var(--c); margin-right: 9px; vertical-align: -1px; }
    .nm-dust line { stroke: #5b9bd5; stroke-opacity: .12; } .nm-dust circle { fill: #5b9bd5; fill-opacity: .35; }
    .nm-cl .lk { stroke: var(--c); stroke-opacity: .34; stroke-width: 1.2; } .nm-cl .nd { fill: var(--c); } .nm-cl .nr { fill: #061222; stroke: var(--c); stroke-width: 1.5; }
    .tw { animation: tw 4.5s ease-in-out infinite alternate; }
    @keyframes tw { from { opacity: .25; } to { opacity: 1; } }
    .wire { fill: none; stroke: var(--c); stroke-width: 2.6; stroke-opacity: .55; }
    .wire[data-linked="0"] { stroke-dasharray: 7 10; stroke-opacity: .45; stroke: var(--mut); }
    .nm-amb circle { fill: var(--c); opacity: .9; } .nm-amb circle[data-linked="0"] { opacity: 0; }
    .hub .bg { fill: #061424; stroke: var(--c); stroke-width: 3.2; transition: stroke .4s; }
    .hub .halo { fill: none; stroke: var(--c); stroke-opacity: .35; stroke-dasharray: 3 6; }
    .hub .ic { fill: none; stroke: var(--c); stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
    .hub.is-hit .bg { stroke: #fff; }
    .hub.is-hit.long .bg { stroke: var(--chip); }
    .nm-bot .off, .mc .off { display: none; }
    .nm-bot[data-linked="0"] .on, .mc[data-linked="0"] .on { display: none; }
    .nm-bot[data-linked="0"] .off { display: inline; } .mc[data-linked="0"] .off { display: inline; } .mc[data-linked="0"] b.off { display: block; }
    .nm-bot[data-linked="0"] .hub .bg { stroke-opacity: .5; stroke-dasharray: 7 8; stroke-width: 2.4; stroke: var(--mut); }
    .nm-bot[data-linked="0"] .hub .ic, .nm-bot[data-linked="0"] .hub .halo { stroke: var(--mut); }
    .nm-bot[data-linked="0"] .hub .halo { animation: none; stroke-opacity: .3; }
    .spin { animation: spin 80s linear infinite; transform-box: fill-box; transform-origin: center; }
    .spin2 { animation: spin 50s linear infinite reverse; transform-box: fill-box; transform-origin: center; }
    .breathe { animation: breathe 5s ease-in-out infinite; transform-box: fill-box; transform-origin: center; }
    @keyframes spin { to { transform: rotate(360deg); } }
    @keyframes breathe { 50% { transform: scale(1.015); } }
    @keyframes ping { 0% { box-shadow: 0 0 0 0 rgba(42,240,192,.6); } 100% { box-shadow: 0 0 0 14px rgba(42,240,192,0); } }
    .nm-core .c-faint { fill: none; stroke: var(--out); stroke-opacity: .12; }
    .nm-core .c-arc { fill: none; stroke: var(--out); stroke-opacity: .6; stroke-width: 3; stroke-dasharray: 300 80; }
    .nm-core .c-arc2 { fill: none; stroke: var(--seq); stroke-opacity: .55; stroke-width: 2; stroke-dasharray: 90 28 8 28; }
    .nm-core [data-live-ticks] line { stroke: var(--out); stroke-width: 2.4; stroke-opacity: .14; }
    .nm-core .ring { fill: #06182c; stroke: var(--out); stroke-width: 4; transition: stroke .3s; }
    .nm-core.is-hit .ring { stroke: #fff; }
    .wave { fill: none; stroke: var(--out); stroke-width: 3; opacity: 0; transform-box: fill-box; transform-origin: center; }
    .wave.go { animation: wave 1s var(--ease); }
    @keyframes wave { 0% { opacity: .8; transform: scale(1); } 100% { opacity: 0; transform: scale(1.4); } }
    .t { font-family: var(--txt); fill: var(--ink); } .t.c { text-anchor: middle; } .t.end { text-anchor: end; }
    .nm-core-name { font: 700 36px var(--txt); letter-spacing: .06em; fill: var(--ink); text-anchor: middle; }
    .cap { font: 700 26px var(--txt); letter-spacing: .14em; fill: var(--c); }
    .sub { font: 400 24px var(--txt); fill: var(--mut); }
    .s20 { font-size: 20px; } .s21 { font-size: 21px; } .s22 { font-size: 22px; } .s26 { font-size: 26px; }
    .big { font: 700 76px var(--num); fill: var(--ink); } .big.s { font-size: 64px; }
    .t-ordf { fill: var(--ord); } .peso-sign { font-size: 44px; }
    .bname { font: 600 22px var(--txt); letter-spacing: .04em; }
    .st { font: 500 23px var(--txt); fill: var(--out); } .st.off { fill: var(--mut); }
    .dorm { font: 700 44px var(--num); fill: #7f93ab; letter-spacing: .14em; }
    .seqn { font: 500 21px var(--txt); fill: #c9a8ff; paint-order: stroke; stroke: var(--void); stroke-width: 5; }
    .strip { stroke: var(--line); stroke-width: 1.5; } .dot { fill: var(--c); }
    .pk { font: 700 30px var(--num); fill: var(--chip); paint-order: stroke; stroke: var(--void); stroke-width: 6; text-anchor: middle; }
    .flick { animation: flick 1.1s var(--ease); }
    @keyframes flick { 0% { opacity: .5; } 100% { opacity: 1; } }

    /* Ang timeline. */
    .act { position: absolute; left: 1500px; top: 232px; width: 420px; height: 848px; border-left: 2px solid rgba(53,200,255,.45); padding: 12px 40px 0 28px; }
    .act h2 { font: 700 28px/1 var(--txt); letter-spacing: .3em; display: flex; align-items: center; gap: 16px; padding: 6px 0 16px; border-bottom: 1px solid var(--line); }
    .act h2 i { width: 14px; height: 14px; border-radius: 50%; background: var(--out); animation: ping 2s infinite; }
    .tl { list-style: none; padding: 0; position: relative; margin-top: 8px; }
    .tl:not(:empty)::before { content: ""; position: absolute; left: 92px; top: 12px; bottom: 0; width: 1px; background: var(--line2); }
    .tl li { position: relative; display: grid; grid-template-columns: 78px minmax(0, 1fr); gap: 0 24px; height: 84px; border-bottom: 1px solid rgba(120,190,255,.07); padding-top: 10px; animation: lin .8s var(--ease) both; }
    .tl time { font: 400 18px var(--mono); color: var(--mut); padding-top: 3px; }
    .tl li::before { content: ""; position: absolute; left: 86px; top: 18px; width: 13px; height: 13px; border-radius: 50%; background: var(--c); }
    .tl .tx { padding-left: 6px; min-width: 0; }
    .tl .l1 { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .tl .ch { flex: none; font: 600 16px/1 var(--txt); letter-spacing: .08em; color: var(--c); border: 1px solid var(--c); border-radius: 20px; padding: 3px 9px; }
    .tl .ti { font: 600 26px/1.15 var(--txt); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tl .de { display: block; font: 400 21px/1.25 var(--txt); color: var(--mut); margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tl li:nth-child(n+6) { opacity: .7; } .tl li:nth-child(n+8) { opacity: .45; }
    .listen { position: absolute; left: 28px; bottom: 22px; display: flex; align-items: center; gap: 14px; font-size: 24px; color: var(--mut); }
    .listen i { width: 13px; height: 13px; border-radius: 50%; background: var(--out); }
    .ty-message { --c: var(--inq); } .ty-follow_up { --c: var(--seq); } .ty-confirmed { --c: var(--out); } .ty-ai { --c: var(--ai); } .ty-order { --c: var(--ord); }
    @keyframes lin { from { opacity: 0; transform: translateX(14px); } }

    /* Telepono (390 px): walang canvas; isang column ng mga card, at ang timeline sa dulo. */
    @media (max-width: 900px) {
        .vp { height: auto; min-height: 100vh; overflow: visible; }
        .stage { position: static; width: auto; height: auto; transform: none !important; display: flex; flex-direction: column; padding-bottom: 30px; }
        .net { display: none; } .mob { display: grid; grid-template-columns: minmax(0, 1fr); }
        .top, .kpis, .act { position: relative; left: auto; top: auto; width: auto; height: auto; }
        .top { flex-wrap: wrap; gap: 6px 14px; padding: 12px 14px; } .top h1 { font-size: 26px; } .logo { width: 30px; height: 30px; } .lv-status { font-size: 18px; }
        .top .sp { margin-left: 0; flex-wrap: wrap; gap: 8px 12px; width: 100%; } .lv-filter { flex-wrap: wrap; gap: 8px; width: 100%; }
        .sel, .tvb { font-size: 16px; padding: 6px 10px; } .sel { flex: 1 1 140px; max-width: none; }
        .clock { border: 0; padding: 0; font-size: 14px; } .clock b { font-size: 20px; } .exit { font-size: 15px; }
        .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } .kpi { padding: 12px 14px; gap: 10px; border-bottom: 1px solid var(--line); } .kpi:nth-child(2) { border-right: 0; }
        .kpi small { white-space: normal; font-size: 14px; } .kpi svg.ic { display: none; } .kpi h2 { font-size: 15px; white-space: normal; } .kpi .bt { font-size: 12px; }
        .kpi .v { font-size: 52px; } .kpi .v u { font-size: 24px; } .kpi .plus { left: 12px; top: 4px; font-size: 26px; }
        .act { border-left: 0; border-top: 2px solid rgba(53,200,255,.45); padding: 12px 14px 56px; order: 6; }
        .act h2 { font-size: 18px; } .tl:not(:empty)::before { left: 84px; } .tl li { grid-template-columns: 70px minmax(0, 1fr); height: 74px; gap: 0 18px; } .tl li::before { left: 78px; }
        .tl .ti { font-size: 19px; } .tl time { font-size: 14px; } .tl .de { font-size: 15px; } .tl .ch { font-size: 12px; } .listen { left: 14px; bottom: 16px; font-size: 16px; }
        .mob { padding: 10px 14px; gap: 10px; order: 5; }
        .mc { border: 1px solid var(--line2); border-radius: 6px; padding: 10px 14px; background: linear-gradient(180deg, rgba(12,36,64,.7), rgba(6,18,34,.7)); position: relative; min-width: 0; transition: border-color .6s; }
        .mc h3 { font: 700 15px/1.1 var(--txt); letter-spacing: .12em; color: var(--c); padding-right: 96px; }
        .mrow .mc h3 { padding-right: 0; }
        .mc > b, .mc b { white-space: nowrap; display: block; font: 700 48px/1 var(--num); margin-top: 4px; }
        .mc small { display: block; font-size: 14px; color: var(--mut); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mc small span { display: inline; font: inherit; margin: 0; }
        .mc .chip { position: absolute; right: 12px; top: 10px; font-size: 14px; color: var(--c); }
        .mc[data-linked="0"] { opacity: .65; border-style: dashed; --c: var(--mut); } .mc[data-linked="0"] b.off { font-size: 30px; color: var(--mut); }
        .mc.flash { border-color: var(--c); }
        .mrow { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .mcore { text-align: center; padding: 16px; } .mcore h3 { font-size: 20px; padding-right: 0; } .mcore b { font-size: 56px; }
    }
    /* Reduced motion: walang boot, daloy, kislap, ikot, packet, shockwave o pag-akyat ng numero; ang kulay lang ng gilid
       ng tinamaan ang nagbabago, at ang chip ng order ay nakatayo nang 4 na segundo. */
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation: none !important; transition: none !important; }
        .nm-amb { display: none; }
    }
</style>
@endpush

@push('scripts')
<script src="{{ asset('js/chatbot-live-rules.js') }}"></script>
<script>
// /chatbot/live: ang feed ay pino-poll kada 5 segundo (tumitigil kapag nakatago ang tab). Ang bawat bagong event ay isang
// ilaw na dumadaan sa totoong link (LiveRules.schedule ang nagpapasya ng ruta, ng pagsasama ng mga order at ng chip); ang
// mga bilang ay umaakyat mula sa nakikita papunta sa bago (LiveRules.settle / land: ang order ay naghihintay sa packet at
// hindi bumababa sa loob ng isang minuto). Tumatakbo ito buong araw sa TV, kaya walang lumalaking DOM: 30 packet na ginagamit
// muli, iisang animation loop, 8 na linya ng timeline. Ang text mula sa server (pangalan ng page) ay textContent lang.
(() => {
    const R = window.LiveRules;
    const boot = JSON.parse(document.getElementById('live-data').textContent);
    const root = document.querySelector('[data-live]');
    const stage = root.querySelector('[data-live-stage]');
    const mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const live = window.likhaLive = {reduced: !!(mq && mq.matches), cursor: boot.cursor, counts: boot.counts, timer: null, inflight: false, fail: 0, active: 0, sampled: 0, st: {}};
    const LIGHTS = 30, LOG_LINES = 8, HOP = 1900;
    const TYPE = {message: ['INCOMING', 'Inquiry received'], confirmed: ['SENT', 'Reply sent'], follow_up: ['SEQUENCE', 'Follow-up sent'], ai: ['AI', 'AI answered'], order: ['ORDER', 'Order recorded']};
    const COLOR = {message: '--inq', ai: '--ai', confirmed: '--out', follow_up: '--seq', order: '--ord'};
    const all = s => root.querySelectorAll(s);
    const css = v => getComputedStyle(root).getPropertyValue(v).trim() || '#fff';
    const toggle = (e, on) => on ? e.removeAttribute('hidden') : e.setAttribute('hidden', '');
    const now = () => performance.now();

    // ── ang canvas: 1920 × 1080 na pinalaki sa screen, at ang mabagal na paglipat laban sa burn-in (hanggang 4 px, isang hakbang kada minuto) ──
    const DRIFT = [[0, 0], [2, 1], [4, 2], [3, 4], [1, 3], [-1, 4], [-3, 2], [-4, 0], [-2, -2], [1, -3]];
    let drift = 0;
    function fit() {
        if (innerWidth <= 900) { stage.style.transform = ''; return; }
        const s = Math.min(innerWidth / 1920, innerHeight / 1080), [dx, dy] = DRIFT[drift % DRIFT.length];
        stage.style.transform = 'translate(' + ((innerWidth - 1920 * s) / 2 + dx) + 'px,' + ((innerHeight - 1080 * s) / 2 + dy) + 'px) scale(' + s + ')';
    }
    addEventListener('resize', fit);
    setInterval(() => { drift++; fit(); }, 60000);
    fit();

    // ── ang mga bilang: bawat isa ay may nakikita at server; umaakyat nang paunti-unti (isang animation loop para sa lahat) ──
    const day = (k, f, hold = 0, dur = 900) => ({k, f, hold, floor: 60000, dur});
    const win = (k, f) => ({k, f, hold: 0, floor: 0, dur: 900});
    const NUMS = [
        day('replies', c => c.confirmed.today), day('orders', c => c.orders.today, 8000, 1200),
        day('peso', c => c.order_value ? c.order_value.today : 0, 8000, 1400), day('messages', c => c.messages.today),
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
        for (const e of all('[data-live-count="' + k + '"]')) { e.classList.remove('flick'); void e.getBoundingClientRect(); e.classList.add('flick'); }
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
    function landOrders(count, pesos) {
        const t = Date.now();
        live.st.orders = R.land(live.st.orders, count, t);
        live.st.peso = R.land(live.st.peso, pesos, t);
        show('orders', live.st.orders.shown);
        show('peso', live.st.peso.shown);
    }
    function put(key, value, attr) {
        for (const e of all('[' + attr + '="' + key + '"]')) if (e.textContent !== value) e.textContent = value;
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
            for (const e of all('[data-bot-row="' + bot + '"], [data-reply-row="' + bot + '"]')) e.setAttribute('data-linked', on);
        }
        const o = c.last_order, page = o ? (o.page.length > 24 ? o.page.slice(0, 23) + '…' : o.page) : '';
        put('', o ? page + ' · ' + o.at + (o.bot && boot.bots[o.bot] ? ' · ' + boot.bots[o.bot] : '') : 'no order yet today', 'data-live-last-order');
        const seqs = c.sequences || [];
        for (let i = 0; i < 3; i++) {
            const s = seqs[i], text = s ? (s.name.length > 18 ? s.name.slice(0, 17) + '…' : s.name) + ' · ' + R.num(s.n) : '';
            for (const e of all('[data-live-seq="' + i + '"]')) if (e.textContent !== text) e.textContent = text;
        }
    }

    // ── ang 5-minutong orasan sa core: 60 guhit, isa kada 5 s; ang ulo ay "ngayon", ang guhit na may event ay maliwanag at kumukupas ──
    const ticks = [...all('[data-live-ticks] line')], bucket = new Array(60).fill(0), slotOf = new Array(60).fill(-1);
    const head = () => Math.floor(Date.now() / 5000);
    function mark() { const h = head(), i = h % 60; if (slotOf[i] !== h) { slotOf[i] = h; bucket[i] = 0; } bucket[i]++; }
    function paintTicks() {
        const h = head();
        ticks.forEach((l, i) => {
            const age = ((h % 60) - i + 60) % 60, n = slotOf[i] === h - age ? bucket[i] : 0, a = i * 6 * Math.PI / 180, len = 9 + Math.min(n, 4) * 4 + (age === 0 ? 7 : 0);
            l.setAttribute('x2', (690 + Math.sin(a) * (150 + len)).toFixed(1)); l.setAttribute('y2', (348 - Math.cos(a) * (150 + len)).toFixed(1));
            l.style.stroke = age === 0 ? '#fff' : ''; l.style.strokeWidth = age === 0 ? 4 : '';
            l.style.strokeOpacity = age === 0 ? 1 : n ? Math.max(.25, .95 - age / 70) : '';
        });
    }

    // ── ang mga packet: 30 na ginagamit muli (ulo na may glow, buntot na 3 bilog, chip ng order), iisang animation loop ──
    const svg = root.querySelector('[data-map]');
    const NS = 'http://www.w3.org/2000/svg';
    const mk = (n, a, p) => { const e = document.createElementNS(NS, n); for (const k in a) e.setAttribute(k, a[k]); if (p) p.appendChild(e); return e; };
    const paths = {}, pool = [], moving = [];
    if (svg) {
        for (const p of svg.querySelectorAll('[data-path]')) paths[p.dataset.path] = {el: p, len: 0};
        const layer = svg.querySelector('[data-lights]');
        for (let i = 0; i < LIGHTS; i++) {
            const g = mk('g', {style: 'display:none'}, layer), tail = [1, 2, 3].map(j => mk('circle', {r: 6 - j * 1.4, opacity: .55 / j}, g));
            pool.push({g, tail, dot: mk('circle', {r: 7, filter: 'url(#nm-glow)'}, g), text: mk('text', {class: 'pk'}, g), busy: false});
        }
    }
    const len = p => { if (!p.len) { try { p.len = p.el.getTotalLength(); } catch (e) { p.len = 0; } } return p.len; };
    const visible = () => svg && svg.getBoundingClientRect().width > 0;
    let raf = 0, lastWave = 0;
    function frame(t) {
        for (let i = moving.length - 1; i >= 0; i--) {
            const m = moving[i], q = Math.min(1, (t - m.t0) / m.dur);
            [m.l.dot, ...m.l.tail].forEach((c, j) => { const p = m.at(Math.max(0, q - j * .022) * m.total); c.setAttribute('cx', p.x); c.setAttribute('cy', p.y); });
            if (m.label) { const p = m.at(q * m.total); m.l.text.setAttribute('x', p.x); m.l.text.setAttribute('y', p.y - 24); }
            if (m.mid && q >= .47) { m.mid(); m.mid = null; }
            if (q >= 1) { m.l.g.style.display = 'none'; m.l.busy = false; moving.splice(i, 1); live.active--; m.done(); }
        }
        raf = moving.length ? requestAnimationFrame(frame) : 0;
    }
    // Isang packet sa ruta ([path, pasulong], ...). Walang mapang nakikita, walang bakanteng packet o nakatago ang tab: ang dulo agad.
    function travel(route, color, dur, done, label, mid) {
        const segs = route.map(([k, fwd]) => paths[k] && {p: paths[k], fwd}).filter(Boolean);
        if (live.reduced || document.hidden || !visible() || !segs.length || segs.some(s => !len(s.p))) { if (mid) mid(); done(); return; }
        const l = pool.find(x => !x.busy);
        if (!l) { live.sampled++; if (mid) mid(); done(); return; }
        const total = segs.reduce((a, s) => a + s.p.len, 0);
        const at = d => { let k = d; for (const s of segs) { if (k <= s.p.len) return s.p.el.getPointAtLength(s.fwd ? k : s.p.len - k); k -= s.p.len; } const s = segs[segs.length - 1]; return s.p.el.getPointAtLength(s.fwd ? s.p.len : 0); };
        l.busy = true;
        const c = css(color);
        l.dot.setAttribute('fill', c); l.dot.setAttribute('r', label ? 10 : 7);
        l.tail.forEach((e, j) => { e.setAttribute('fill', c); e.setAttribute('r', (label ? 8 : 6) - (j + 1) * 1.4); });
        l.text.textContent = label || '';
        l.g.style.display = '';
        live.active++;
        moving.push({l, at, total, dur, done, label, mid, t0: now()});
        if (!raf) raf = requestAnimationFrame(frame);
    }
    function hit(target, ms = 600, cls = '') {
        for (const g of all('[data-hit="' + target + '"], [data-live-card="' + target + '"]')) {
            g.classList.add(g.dataset.liveCard ? 'flash' : 'is-hit');
            if (cls) g.classList.add(cls);
            clearTimeout(g._lvT);
            g._lvT = setTimeout(() => g.classList.remove('is-hit', 'flash', 'long'), ms);
        }
    }
    function wave() {
        const w = root.querySelector('[data-live-wave]'), t = now();
        if (!w || live.reduced || t - lastWave < 400) return;
        lastWave = t;
        w.classList.remove('go'); void w.getBoundingClientRect(); w.classList.add('go');
    }
    // Ang pagdating ng order: ang Orders, Orders recorded at Order value ay umaakyat, ang KPI ay kumikinang na ginto at ang chip ay lumulutang.
    function cash(a) {
        landOrders(a.orders, a.peso);
        hit('ord', 1400, 'long');
        const k = root.querySelector('[data-live-kpi="orders"]'), plus = root.querySelector('[data-live-plus]');
        if (k && !live.reduced) { k.classList.remove('cash'); void k.offsetWidth; k.classList.add('cash'); }
        if (!plus) return;
        clearTimeout(plus._lvT);
        plus.textContent = a.label;
        plus.classList.remove('go', 'still'); void plus.offsetWidth;
        plus.classList.add(live.reduced ? 'still' : 'go');
        plus._lvT = setTimeout(() => plus.classList.remove('still', 'go'), live.reduced ? 4000 : 1800);
    }
    function act(a) {
        const bot = a.bot;
        mark();
        if (a.type === 'order') {
            if (a.kind === 'instant') { cash(a); return; }
            travel(a.route, COLOR.order, 3200, () => cash(a), a.label, () => { hit('core'); wave(); });
            return;
        }
        const land = {
            message: () => { hit('core'); hit('inq'); wave(); },
            ai: () => { hit('ai'); hit('core'); wave(); },
            confirmed: () => { if (bot) hit(bot); },
            follow_up: () => { hit('seq'); if (bot) hit(bot); },
        }[a.type] || (() => hit('core'));
        if (a.kind === 'hit') { land(); return; }
        travel(a.route, COLOR[a.type], a.type === 'follow_up' ? 3000 : a.type === 'ai' ? 1000 : HOP, land);
    }
    function play(events) {
        const plan = R.schedule(events, {reduced: live.reduced || !visible()});
        live.sampled += plan.sampled;
        for (const a of plan.actions) a.at ? setTimeout(() => act(a), a.at) : act(a);
    }

    // ── ang timeline: 8 na linya, ang pinakabago sa itaas ──
    const logEl = root.querySelector('[data-live-log]');
    function log(events) {
        for (const e of events.slice(-LOG_LINES)) {
            const [chip, desc] = TYPE[e.type] || ['EVENT', 'Event'];
            const li = document.createElement('li');
            li.className = 'ty-' + (TYPE[e.type] ? e.type : 'message');
            const t = document.createElement('time'); t.textContent = String(e.at).slice(11, 19);
            const tx = document.createElement('div'); tx.className = 'tx';
            const l1 = document.createElement('div'); l1.className = 'l1';
            const ch = document.createElement('span'); ch.className = 'ch'; ch.textContent = chip;
            const ti = document.createElement('span'); ti.className = 'ti'; ti.textContent = e.bot && boot.bots[e.bot] ? boot.bots[e.bot] : boot.site;
            const de = document.createElement('span'); de.className = 'de';
            de.textContent = desc + ' · ' + e.page + (e.type === 'order' && typeof e.amount === 'number' ? ' · ' + R.peso(e.amount) : '');
            l1.append(ch, ti); tx.append(l1, de); li.append(t, tx);
            logEl.prepend(li);
        }
        while (logEl.children.length > LOG_LINES) logEl.lastElementChild.remove();
    }

    const statusEl = root.querySelector('[data-live-status]');
    function setStatus(state, label) {
        statusEl.dataset.liveStatus = state;
        statusEl.querySelector('[data-live-state]').textContent = label;
    }
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
            setCounts(data.counts);
            for (const e of all('[data-live-updated]')) e.textContent = data.now;
            log(data.events);
            play(data.events);
            setStatus('live', data.more > 0 ? 'LIVE · +' + R.num(data.more) + ' MORE' : 'LIVE');
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
    function motion(reduced) {
        live.reduced = reduced;
        if (svg && svg.pauseAnimations) reduced ? svg.pauseAnimations() : svg.unpauseAnimations();
    }
    if (mq && mq.addEventListener) mq.addEventListener('change', e => motion(e.matches));
    motion(live.reduced);

    // TV mode: buong screen (kapag kaya ng browser).
    const fs = root.querySelector('[data-live-fullscreen]');
    if (fs && document.documentElement.requestFullscreen) {
        toggle(fs, true);
        fs.addEventListener('click', () => document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen().catch(() => {}));
        document.addEventListener('fullscreenchange', () => { fs.textContent = document.fullscreenElement ? 'Exit full screen' : 'TV mode'; });
    }
    // Ang orasan ng Manila, kada segundo; ang mga bilang na hinihintay ang packet ay sinusuri rin kada segundo.
    const clock = root.querySelector('[data-live-clock]');
    function second() {
        try { clock.textContent = new Date().toLocaleTimeString('en-GB', {timeZone: 'Asia/Manila', hour12: false}); } catch (e) { /* ang huling oras ng server ang naiiwan */ }
        paintTicks();
        if (['orders', 'peso'].some(k => live.st[k] && live.st[k].heldSince !== null)) settleAll();
    }
    live.play = play; live.setCounts = setCounts; live.log = log;

    setCounts(boot.counts);
    second();
    setInterval(second, 1000);
    live.timer = setTimeout(poll, boot.interval);
})();
</script>
@endpush
