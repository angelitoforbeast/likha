@extends('layouts.chatbot-tv')

@section('title', 'Live')

@php
    $c = $feed['counts'];
    // Ang pangalan ng site (Likha o Incepxion), ayon sa APP_URL.
    $site = \App\Services\Chatbot\ForbeastSite::label(\App\Services\Chatbot\ForbeastSite::self());
    $linked = $c['linked'] + ['botcake' => true];
    // Ang mga bot: [maikling pangalan, buong pangalan, estado kapag naka-link].
    $bots = ['forbeast' => ['Bot 1', 'Forbeast', 'Connected'], 'incepxionbot' => ['Bot 2', 'Incepxionbot', 'Connected'], 'botcake' => ['Botcake', 'Fallback', 'Fallback']];
    // Ang paliwanag ng average na reply time, ayon sa estado ng Forbeast (iisa sa server at sa script).
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
    // Ang isang numero ng globo: ang kabuuan ng mga bilang sa huling 5 minuto (signals).
    $signals = $c['messages']['recent'] + $c['confirmed']['recent'] + $c['follow_ups']['recent'] + $c['ai']['recent'] + $c['orders']['recent'];
    $up = fn (string $k) => $c[$k]['recent'] > 0 ? 'up' : '';
    $kinds = ['message' => 'Inquiry', 'reply' => 'Reply', 'follow_up' => 'Follow-up', 'order' => 'Order', 'ai' => 'AI answer'];
    $lastOf = fn (?array $l) => $l ? ($kinds[$l['type']] ?? 'Event') . ' · ' . $l['page'] . ' · ' . $l['at'] : '—';
    $avgOf = fn ($v) => $v === null ? '—' : number_format((float) $v, 1) . ' s';
    $stateOf = fn (string $b) => $linked[$b] ? $bots[$b][2] : 'Not linked';
    // Ang mga panel sa 1920 × 1080: gitna (x, y), laki, ang hiwa ng trapezoid at ang gilid na nakaharap palabas (L, R, T, B).
    $geo = [
        'forbeast' => [306, 395, 540, 410, 44, 'L'], 'botcake' => [1614, 395, 540, 410, 44, 'R'], 'incepxionbot' => [262, 842, 360, 250, 34, 'L'],
        'ai' => [772, 190, 340, 200, 36, 'T'], 'seq' => [1148, 190, 340, 200, 36, 'T'],
        'replies' => [590, 945, 250, 190, 30, 'B'], 'vault' => [960, 945, 420, 190, 34, 'B'], 'avg' => [1330, 945, 250, 190, 30, 'B'], 'chats' => [1658, 842, 360, 250, 34, 'R'],
    ];
    $attrs = fn (string $id, string $label) => 'data-geo="' . implode(',', array_slice($geo[$id], 0, 5)) . '" data-or="' . $geo[$id][5] . '" tabindex="0" role="group" aria-label="' . e($label)
        . '" aria-describedby="lv-card-' . $id . '" style="width:' . $geo[$id][2] . 'px;height:' . $geo[$id][3] . 'px;margin:' . (-$geo[$id][3] / 2) . 'px 0 0 ' . (-$geo[$id][2] / 2) . 'px"';
    $v = fn (string $file) => asset($file) . '?v=' . substr(md5_file(public_path($file)), 0, 10);
@endphp

@section('content')
<div class="vp" data-live data-live-board>
<div class="stage" data-live-stage>
    <canvas class="bg" width="1920" height="1080" data-live-bg aria-hidden="true"></canvas>

<div class="cam"><div class="world" data-live-world>
    {{-- Ang mga spoke: isa kada panel, mula sa globo; ang script ang naglalagay ng pangalawang ribbon at ng mga packet. --}}
    @foreach(array_keys($geo) as $id)<i class="rb {{ $id === 'vault' ? 'gd' : (isset($bots[$id]) && !$linked[$id] ? 'dm' : 'v') }}" data-spoke="{{ $id }}" aria-hidden="true"></i>@endforeach

    {{-- Ang tatlong bot: radar (bawat customer ng huling 5 minuto ay tuldok) at ang IN / OUT; ang hindi naka-link ay dim na wireframe. --}}
    @foreach(['forbeast', 'botcake', 'incepxionbot'] as $b)
    <div class="it pn bot {{ $geo[$b][2] > 400 ? 'big' : 'small' }} {{ $geo[$b][5] === 'R' ? 'mir' : '' }}" data-pn="{{ $b }}" data-bot-row="{{ $b }}" data-linked="{{ $linked[$b] ? '1' : '0' }}" {!! $attrs($b, $bots[$b][0]) !!}>
        <canvas></canvas>
        <span class="lb nm">{{ strtoupper($bots[$b][0]) }}</span>
        <span class="lb l-in">IN</span><b class="n n-in" data-live-count="in-{{ $b }}">{{ $n($c['bots']['in'][$b]) }}</b>
        <span class="lb l-out">OUT</span><b class="n n-out" data-live-count="out-{{ $b }}">{{ $n($c['bots']['out'][$b]) }}</b>
    </div><!--/pn-->
    @endforeach

    <div class="it pn ai" data-pn="ai" {!! $attrs('ai', 'AI answers') !!}>
        <canvas></canvas><span class="lb">AI</span><b class="n" data-live-count="ai">{{ $n($c['ai']['today']) }}</b>
    </div><!--/pn-->
    <div class="it pn seq" data-pn="seq" {!! $attrs('seq', 'Follow-ups') !!}>
        <canvas></canvas><span class="lb">FOLLOW-UPS</span><b class="n" data-live-count="follow_ups">{{ $n($c['follow_ups']['today']) }}</b>
    </div><!--/pn-->
    <div class="it pn gauge" data-pn="replies" {!! $attrs('replies', 'Replies') !!}>
        <canvas></canvas><span class="lb">REPLIES</span><b class="n" data-live-count="replies">{{ $n($c['confirmed']['today']) }}</b>
    </div><!--/pn-->
    {{-- Ang vault: ang pera ay may sariling lugar (ang order value ngayong araw, COD), sa ilalim ng globo. --}}
    <div class="it pn vault" data-pn="vault" data-live-kpi="orders" {!! $attrs('vault', 'Order value today') !!}>
        <canvas></canvas>
        <b class="n total" data-vault-total>₱<span data-live-count="peso">{{ $n($c['order_value']['today'] ?? 0) }}</span></b>
        <span class="orders"><b class="n" data-live-count="orders">{{ $n($c['orders']['today']) }}</b><span class="lb">ORDERS</span></span>
        <div class="plus" data-live-plus aria-hidden="true"></div>
    </div><!--/pn-->
    <div class="it pn gauge" data-pn="avg" {!! $attrs('avg', 'Avg reply') !!}>
        <canvas></canvas><span class="lb">AVG REPLY</span><b class="n"><span data-live-count="reply_time">{{ $rt['seconds'] === null ? '—' : number_format($rt['seconds'], 1) }}</span><u data-live-unit @if($rt['seconds'] === null) hidden @endif>s</u></b>
    </div><!--/pn-->
    <div class="it pn ring" data-pn="chats" {!! $attrs('chats', 'Chats') !!}>
        <canvas></canvas><span class="lb">CHATS</span><b class="n" data-live-count="active">{{ $n($c['active']) }}</b>
    </div><!--/pn-->

    {{-- Ang globo: ang bot core ng site, ang isang numero nito ay ang signals sa huling 5 minuto. --}}
    <div class="it orb" data-live-core style="width:700px;height:700px;margin:-350px 0 0 -350px">
        <canvas></canvas>
        <b class="n sig" data-live-count="signals">{{ $n($signals) }}</b>
        <span class="core"><b class="nm-core-name">{{ strtoupper($site) }}</b> <span class="lb">BOT</span></span>
        <div class="orbhit" tabindex="0" role="group" aria-label="{{ $site }} bot" aria-describedby="lv-card-core"></div>
    </div>
    <div class="coins" data-live-coins aria-hidden="true"></div>
</div></div>

    <canvas class="tk" width="1840" height="28" data-live-ticker aria-hidden="true"></canvas>

    <header class="hd">
        <svg class="logo" viewBox="0 0 46 36" aria-hidden="true"><path d="M2 4h34M2 16h42M2 28h26"/></svg>
        <h1>{{ strtoupper($site) }} LIVE</h1>
        <span class="lv-status" data-live-status="live"><i class="dot" data-beat role="img" aria-label="Live"></i><span data-live-state>LIVE</span></span>
        <span class="sp"></span>
        <button class="btn ctl" type="button" data-live-filter aria-label="Filters" aria-expanded="false" aria-controls="lv-filters"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18l-7 8v6l-4-2v-4z"/></svg></button>
        <span class="clk" tabindex="0" aria-label="Time" aria-describedby="lv-card-clock"><b data-live-clock>{{ $feed['now'] }}</b></span>
        <button class="btn ctl" type="button" aria-pressed="false" aria-label="3D view" data-live-3d>3D</button>
        <button class="btn g ctl" type="button" aria-label="TV mode" data-live-fullscreen><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="12" rx="1.5"/><path d="M9 21h6"/></svg>TV</button>
        <a href="{{ route('chatbot.speed') }}" class="btn exit ctl" aria-label="Exit" data-tv-exit><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></a>
    </header>
    <form method="GET" action="{{ route('chatbot.live') }}" class="fp" id="lv-filters" aria-label="Filters">
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
    <noscript><style>.fp { display: flex; }</style></noscript>

    {{-- Ang mga card: bawat numerong inalis sa screen, isang hover / tap / focus ang layo. Nakatago hanggang doon (ang TV ay hindi nagho-hover). --}}
    <div class="card" id="lv-card-core" role="tooltip" hidden><h3>{{ $site }} bot</h3><dl>
        <div><dt>Signals · last 5 min</dt><dd data-live-count="signals">{{ $n($signals) }}</dd></div>
        <div><dt>Inquiries received today</dt><dd data-live-count="messages">{{ $n($c['messages']['today']) }}</dd></div>
        <div><dt>Inquiries, last 5 min</dt><dd>+<span data-live-recent="messages">{{ $n($c['messages']['recent']) }}</span></dd></div>
        <div><dt>Active chats</dt><dd data-live-count="active">{{ $n($c['active']) }}</dd></div>
        <div data-live-unknown="in" @if(!$c['bots']['in']['unknown']) hidden @endif><dt>In, bot not known</dt><dd data-live-count="in-unknown">{{ $n($c['bots']['in']['unknown']) }}</dd></div>
        <div data-live-unknown="out" @if(!$c['bots']['out']['unknown']) hidden @endif><dt>Out, bot not known</dt><dd data-live-count="out-unknown">{{ $n($c['bots']['out']['unknown']) }}</dd></div>
    </dl></div>
    <div class="card gold" id="lv-card-vault" role="tooltip" hidden><h3>Order value today · COD</h3><dl>
        <div><dt>Order value</dt><dd>₱<span data-live-count="peso">{{ $n($c['order_value']['today'] ?? 0) }}</span></dd></div>
        <div><dt>Orders today</dt><dd data-live-count="orders">{{ $n($c['orders']['today']) }}</dd></div>
        <div><dt>Orders, last 5 min</dt><dd class="{{ $up('orders') }}" data-live-up="orders">+<span data-live-recent="orders">{{ $n($c['orders']['recent']) }}</span></dd></div>
        <div><dt>Value, last 5 min</dt><dd>+₱<span data-live-recent="peso">{{ $n($c['order_value']['recent'] ?? 0) }}</span></dd></div>
        <div><dt>Last order</dt><dd data-live-last-order>{{ $lastText }}</dd></div>
        <div><dt>One disc</dt><dd>₱5,000</dd></div>
    </dl></div>
    <div class="card" id="lv-card-replies" role="tooltip" hidden><h3>Replies today</h3><dl>
        <div><dt>Confirmed by Meta</dt><dd data-live-count="replies">{{ $n($c['confirmed']['today']) }}</dd></div>
        <div><dt>Last 5 min</dt><dd class="{{ $up('confirmed') }}" data-live-up="replies">+<span data-live-recent="replies">{{ $n($c['confirmed']['recent']) }}</span></dd></div>
        <div><dt>Gauge</dt><dd>last 5 min vs today's pace</dd></div>
    </dl></div>
    <div class="card" id="lv-card-ai" role="tooltip" hidden><h3>AI answers today</h3><dl>
        <div><dt>Answered</dt><dd data-live-count="ai">{{ $n($c['ai']['today']) }}</dd></div>
        <div><dt>Last 5 min</dt><dd>+<span data-live-recent="ai">{{ $n($c['ai']['recent']) }}</span></dd></div>
        <div><dt>Lit cells</dt><dd>answers, last minute</dd></div>
    </dl></div>
    <div class="card" id="lv-card-seq" role="tooltip" hidden><h3>Follow-ups sent today</h3><dl>
        <div><dt>Sent</dt><dd data-live-count="follow_ups">{{ $n($c['follow_ups']['today']) }}</dd></div>
        <div><dt>Last 5 min</dt><dd>+<span data-live-recent="follow_ups">{{ $n($c['follow_ups']['recent']) }}</span></dd></div>
        @for($i = 0; $i < 3; $i++)<div class="sq" data-live-seq="{{ $i }}" @if(!isset($seqs[$i])) hidden @endif><span>{{ $seqs[$i]['name'] ?? '' }}</span><b>{{ isset($seqs[$i]) ? $n($seqs[$i]['n']) : '' }}</b></div>@endfor
        <div data-live-seq-none @if($seqs) hidden @endif><dt>Sequences</dt><dd>none yet today</dd></div>
    </dl></div>
    <div class="card" id="lv-card-avg" role="tooltip" hidden><h3>Avg reply time</h3><dl>
        <div><dt>Average</dt><dd><span data-live-count="reply_time">{{ $rt['seconds'] === null ? '—' : number_format($rt['seconds'], 1) }}</span><u data-live-unit @if($rt['seconds'] === null) hidden @endif> s</u></dd></div>
        <div><dt>Measures</dt><dd data-live-note="reply_time">{{ $rtNote }}</dd></div>
        <div><dt>Bot</dt><dd>Bot 1 only</dd></div>
    </dl></div>
    <div class="card" id="lv-card-chats" role="tooltip" hidden><h3>Active chats</h3><dl>
        <div><dt>Customers, last 5 min</dt><dd data-live-count="active">{{ $n($c['active']) }}</dd></div>
        @foreach($bots as $b => [$label])<div><dt>{{ $label }}</dt><dd data-chat="{{ $b }}">{{ $n($c['notes'][$b]['chatting'] ?? 0) }}</dd></div>@endforeach
    </dl></div>
    @foreach(['forbeast', 'botcake', 'incepxionbot'] as $b)
    @php $note = $c['notes'][$b] ?? ['chatting' => 0, 'ai' => 0, 'avg' => null, 'orders' => 0, 'value' => 0, 'last' => null]; @endphp
    <div class="card" id="lv-card-{{ $b }}" role="tooltip" hidden><h3>{{ $bots[$b][0] }} · {{ $bots[$b][1] }}</h3><dl data-note="{{ $b }}">
        <div><dt>Status</dt><dd data-n="state">{{ $stateOf($b) }}</dd></div>
        <div><dt>In</dt><dd data-live-count="in-{{ $b }}">{{ $n($c['bots']['in'][$b]) }}</dd></div>
        <div><dt>Out</dt><dd data-live-count="out-{{ $b }}">{{ $n($c['bots']['out'][$b]) }}</dd></div>
        <div><dt>Chatting now</dt><dd data-n="chatting">{{ $n($note['chatting']) }}</dd></div>
        <div><dt>AI answered</dt><dd data-n="ai">{{ $n($note['ai']) }}</dd></div>
        <div><dt>Avg reply</dt><dd data-n="avg">{{ $avgOf($note['avg']) }}</dd></div>
        <div><dt>Orders today</dt><dd data-n="orders">{{ $n($note['orders']) }}</dd></div>
        <div><dt>Order value</dt><dd data-n="value">₱{{ $n($note['value']) }}</dd></div>
        <div><dt>Last activity</dt><dd data-n="last">{{ $lastOf($note['last']) }}</dd></div>
    </dl></div>
    @endforeach
    <div class="card" id="lv-card-clock" role="tooltip" hidden><h3>Today · Manila</h3><dl>
        <div><dt>Updated</dt><dd data-live-updated>{{ $feed['now'] }}</dd></div>
    </dl></div>
</div>
</div>

<script type="application/json" id="live-data">{!! json_encode(['feed' => route('chatbot.live.feed', [], false), 'page' => $page, 'bot' => $bot, 'cursor' => $feed['cursor'], 'counts' => $feed['counts'],
    'now' => $feed['now'], 'interval' => 5000, 'site' => $site, 'bots' => array_map(fn ($b) => $b[0], $bots), 'states' => array_map(fn ($b) => $b[2], $bots), 'notes' => $notes, 'kinds' => $kinds],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@push('styles')
<style>
    /* Ang Live bilang hologram wall (comp E): graphics ang page, ang mga numero ang text. Isang 1920 × 1080 na stage na pinapalaki o
       pinapaliit sa screen. Yelo / cyan para sa system, ginto para sa pera lang, berde para sa customer. Walang font na ini-load:
       ang condensed na font ng TV kung mayroon (Bahnschrift sa Windows, Roboto Condensed sa Android / Chrome OS, Arial Narrow o
       Liberation Sans Narrow sa iba), kung wala ay ang sans-serif ng system. Walang blur ng anumang uri. */
    :root { --ground: #02060c; --ink: #e6f7ff; --mut: #8fb4c8; --ice: #6fd8ff; --cy: #35c8ff; --gold: #ffc247; --gr: #5dff9c;
        --cond: "Bahnschrift Condensed", "Bahnschrift SemiCondensed", "Roboto Condensed", "Arial Narrow", "Liberation Sans Narrow", "Nimbus Sans Narrow", sans-serif;
        --semi: "Bahnschrift SemiCondensed", "Bahnschrift", "Segoe UI", "Roboto", "Liberation Sans", Arial, sans-serif; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { background: var(--ground); color: var(--ink); font-family: var(--semi); font-variant-numeric: tabular-nums; -webkit-font-smoothing: antialiased; }
    body { overflow-x: hidden; }
    ::selection { background: var(--gold); color: #1a1203; }
    [hidden] { display: none !important; }
    .vp { position: relative; height: 100vh; overflow: hidden; background: var(--ground); }
    .stage { position: absolute; left: 0; top: 0; width: 1920px; height: 1080px; transform-origin: 0 0; overflow: hidden; background: var(--ground); }
    .tv { cursor: none; }
    .bg { position: absolute; left: 0; top: 0; width: 1920px; height: 1080px; pointer-events: none; }
    .tk { position: absolute; left: 40px; top: 1048px; width: 1840px; height: 28px; pointer-events: none; }
    .cam { position: absolute; inset: 0; perspective: 1500px; perspective-origin: 960px 585px; }
    .world { position: absolute; inset: 0; transform-style: preserve-3d; transform-origin: 960px 585px; }
    .it { position: absolute; left: 960px; top: 585px; }
    .pn { outline: none; }
    .pn canvas, .orb canvas { position: absolute; left: 0; top: 0; display: block; width: 100%; height: 100%; }
    .orb canvas { pointer-events: none; }
    .pn:focus-visible, .orbhit:focus-visible, .clk:focus-visible { outline: 3px solid var(--gold); outline-offset: 3px; }
    .orbhit { position: absolute; left: 190px; top: 190px; width: 320px; height: 320px; border-radius: 50%; outline: none; }
    .rb { position: absolute; left: 960px; top: 585px; height: 3px; transform-origin: 0 50%; pointer-events: none; }
    .rb.b { opacity: 0; }
    .rb.v { background: linear-gradient(90deg, rgba(111,216,255,.04), rgba(111,216,255,.30) 55%, rgba(111,216,255,.5)); }
    .rb.gd { background: linear-gradient(90deg, rgba(255,194,71,.05), rgba(255,194,71,.4) 60%, rgba(255,194,71,.65)); }
    /* Ang spoke ng bot na hindi naka-link ay dashed (hindi pula: hindi ito sira, hindi lang naka-link). */
    .rb.dm { background: repeating-linear-gradient(90deg, rgba(111,216,255,.2) 0 8px, transparent 8px 18px); }
    .pk { position: absolute; left: 0; top: -3px; width: 70px; height: 9px; border-radius: 5px; opacity: 0; margin-left: -35px; }
    .coins { position: absolute; left: 0; top: 0; transform-style: preserve-3d; }
    .coin { position: absolute; left: 960px; top: 585px; width: 54px; height: 54px; margin: -27px 0 0 -27px; border-radius: 50%; display: none;
        background: radial-gradient(circle at 35% 30%, #fff3c2, #ffc247 46%, #bd7a0c); border: 3px solid #ffe08f; font: 700 27px/48px var(--cond);
        color: #6e4200; text-align: center; pointer-events: none; }
    .coin::after { content: ''; position: absolute; inset: 6px; border: 1.5px dashed rgba(110,66,0,.55); border-radius: 50%; }
    .coin.plate { width: 70px; border-radius: 8px; margin-left: -35px; }
    .coin.plate::after { border-radius: 4px; }

    /* Ang text sa mga panel: ang numero (condensed) at isa o dalawang salitang label. */
    .n { position: absolute; font-family: var(--cond); font-weight: 700; line-height: 1; white-space: nowrap; color: var(--ink); }
    .lb { position: absolute; font: 700 20px/1 var(--semi); letter-spacing: 4px; color: var(--mut); white-space: nowrap; }
    .bot .nm { font-size: 24px; color: #bfeaff; top: 30px; }
    .bot.big .nm { left: 60px; } .bot.big.mir .nm { left: auto; right: 54px; }
    .bot.big .n { font-size: 62px; left: 342px; } .bot.big.mir .n { left: 38px; }
    .bot.big .l-in { left: 362px; top: 108px; } .bot.big .l-out { left: 362px; top: 228px; }
    .bot.big.mir .l-in { left: 56px; } .bot.big.mir .l-out { left: 58px; }
    .bot.big .n-in { top: 130px; } .bot.big .n-out { top: 250px; color: #7fe0ff; }
    .bot.small .nm { left: 236px; top: 36px; }
    .bot.small .n { font-size: 40px; left: 236px; } .bot.small .l-in { left: 256px; top: 84px; font-size: 18px; } .bot.small .l-out { left: 256px; top: 152px; font-size: 18px; }
    .bot.small .n-in { top: 104px; } .bot.small .n-out { top: 172px; color: #7fe0ff; }
    .bot[data-linked="0"] .n, .bot[data-linked="0"] .l-in, .bot[data-linked="0"] .l-out { display: none; }
    .bot[data-linked="0"] .nm { color: #6f93a8; }
    .ai .lb, .seq .lb { left: 32px; top: 22px; font-size: 22px; }
    .ai .n { left: 230px; top: 86px; font-size: 50px; } .seq .n { left: 194px; top: 96px; font-size: 50px; }
    .gauge .lb { left: 124px; top: 54px; } .gauge .n { left: 124px; top: 94px; font-size: 46px; }
    .gauge u { text-decoration: none; font-size: 26px; color: var(--mut); margin-left: 4px; }
    .ring .lb { left: 58px; top: 42px; font-size: 22px; } .ring .n { left: 214px; top: 98px; font-size: 72px; transform: translateX(-50%); }
    .vault .total { left: 172px; top: 56px; font-size: 68px; color: var(--gold); letter-spacing: 1px; transform-origin: 0 60%; }
    .vault .orders { position: absolute; left: 172px; top: 128px; display: flex; align-items: baseline; gap: 10px; }
    .vault .orders .n, .vault .orders .lb { position: static; } .vault .orders .n { font-size: 36px; color: #ffe2a3; } .vault .orders .lb { font-size: 19px; color: #c9a24f; }
    .plus { position: absolute; left: 290px; top: 14px; font: 700 34px/1 var(--cond); color: var(--gold); opacity: 0; pointer-events: none; }
    .plus.go { animation: lift 1.8s ease-out forwards; } .plus.still { opacity: 1; }
    @keyframes lift { 0% { opacity: 0; transform: translateY(14px); } 20% { opacity: 1; } 80% { opacity: 1; } 100% { opacity: 0; transform: translateY(-26px); } }
    .orb .sig { left: 350px; top: 282px; font-size: 128px; color: #052b45; letter-spacing: 1px; transform: translateX(-50%); }
    .orb .core { position: absolute; left: 350px; top: 414px; transform: translateX(-50%); display: flex; gap: 8px; font: 700 20px/1 var(--semi); letter-spacing: 5px; color: #1d587d; white-space: nowrap; }
    .orb .core .lb { position: static; color: #1d587d; letter-spacing: 5px; }

    .hd { position: absolute; left: 0; top: 0; width: 1920px; height: 76px; display: flex; align-items: center; padding: 0 40px; gap: 20px; z-index: 5; }
    .hd h1 { font: 700 40px/1 var(--cond); letter-spacing: .09em; }
    .logo { width: 46px; height: 36px; fill: none; stroke: var(--ice); stroke-width: 6; stroke-linecap: round; }
    .lv-status { display: inline-flex; align-items: center; gap: 10px; font: 700 20px var(--cond); letter-spacing: .12em; color: var(--gold); }
    .dot { width: 12px; height: 12px; border-radius: 50%; background: var(--ice); display: inline-block; }
    /* Ang tuldok ang "LIVE": pumipintig isang beses kada matagumpay na poll; ang salita ay lumalabas lang kapag may problema. */
    .dot.beat { animation: beat .9s ease-out; }
    @keyframes beat { 0% { transform: scale(1); opacity: 1; } 40% { transform: scale(1.6); opacity: .5; } 100% { transform: scale(1); opacity: 1; } }
    [data-live-status="live"] [data-live-state] { display: none; }
    [data-live-status="error"] .dot, [data-live-status="off"] .dot, [data-live-status="hidden"] .dot { background: var(--gold); }
    .hd .sp { flex: 1; }
    .clk { font: 700 36px/1 var(--cond); letter-spacing: .05em; margin: 0 6px; outline: none; }
    .btn { height: 44px; min-width: 50px; padding: 0 14px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: rgba(40,150,200,.1);
        border: 1.5px solid rgba(111,216,255,.5); color: var(--ice); font: 700 21px var(--cond); letter-spacing: .14em; cursor: pointer; border-radius: 6px; text-decoration: none; }
    .btn:hover { background: rgba(111,216,255,.2); }
    .btn:focus-visible, .sel:focus-visible { outline: 3px solid var(--gold); outline-offset: 2px; }
    .btn[aria-pressed="true"], .btn[aria-expanded="true"] { background: var(--ice); color: #03212f; }
    .btn.g { color: var(--gold); border-color: rgba(255,194,71,.55); }
    .btn svg { width: 22px; height: 22px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .tv .ctl { opacity: 0; pointer-events: none; transition: opacity .6s; }
    .fp { position: absolute; right: 300px; top: 72px; z-index: 6; display: none; gap: 10px; padding: 12px; background: #06182a; border: 1.5px solid rgba(111,216,255,.45); border-radius: 8px; }
    .fp.on { display: flex; }
    .sel { height: 40px; max-width: 320px; background: #04101c; color: var(--ink); border: 1.5px solid rgba(111,216,255,.5); font: 600 20px var(--semi); padding: 0 10px; border-radius: 6px; }
    .card { position: absolute; z-index: 8; min-width: 300px; max-width: 460px; padding: 14px 18px; background: #06182a; border: 1.5px solid rgba(111,216,255,.55); border-radius: 10px; pointer-events: none; }
    .card.gold { border-color: rgba(255,194,71,.6); }
    .card h3 { font: 700 22px/1.45 var(--semi); color: var(--ice); letter-spacing: .14em; text-transform: uppercase; margin-bottom: 4px; }
    .card.gold h3 { color: var(--gold); }
    .card dl > div { display: flex; justify-content: space-between; gap: 28px; font: 600 22px/1.45 var(--semi); color: var(--mut); }
    .card dd, .card .sq b { color: var(--ink); font-weight: 700; text-align: right; }
    .card .up { color: var(--gr); }
    .card u { text-decoration: none; }

    /* Telepono: isang column (vault, globo, mga bot, ang iba), walang spoke, walang 3D; bawat panel ay pinaliit sa lapad (zoom). */
    @media (max-width: 900px) {
        .vp { height: auto; overflow: visible; }
        .stage { position: relative; width: 100%; height: auto; transform: none !important; overflow: visible; padding-bottom: 24px;
            background: radial-gradient(ellipse 120% 60% at 50% 30%, #0c2c47, #02060c 70%); }
        .bg, .rb, .coins { display: none; }
        .hd { position: relative; width: 100%; height: auto; flex-wrap: wrap; padding: 12px 16px; gap: 10px; }
        .hd h1 { font-size: 30px; } .clk { font-size: 28px; }
        .fp { position: relative; right: auto; top: auto; margin: 0 16px; flex-wrap: wrap; }
        .fp .sel { max-width: 100%; }
        .cam { position: relative; perspective: none; }
        .world { position: relative; display: flex; flex-direction: column; align-items: center; gap: 12px; transform: none !important; padding: 0 16px; }
        .it { position: relative; left: auto; top: auto; margin: 0 !important; transform: none !important; flex: none; }
        .it[data-pn="vault"] { order: 1; } .orb { order: 2; } .it[data-pn="forbeast"] { order: 3; } .it[data-pn="botcake"] { order: 4; } .it[data-pn="incepxionbot"] { order: 5; }
        .it[data-pn="ai"] { order: 6; } .it[data-pn="seq"] { order: 7; } .it[data-pn="replies"] { order: 8; } .it[data-pn="avg"] { order: 9; } .it[data-pn="chats"] { order: 10; }
        .tk { position: relative; left: auto; top: auto; display: block; width: calc(100% - 32px); margin: 12px 16px 0; }
        .card { max-width: calc(100vw - 32px); min-width: 0; }
        body { overflow-x: hidden; }
    }
    @media (prefers-reduced-motion: reduce) {
        .dot.beat, .plus.go { animation: none; }
    }
</style>
@endpush

@push('scripts')
<script src="{{ $v('js/chatbot-live-rules.js') }}"></script>
<script>
// /chatbot/live bilang hologram wall: ang feed ay pino-poll kada 5 segundo (tumitigil kapag nakatago ang tab). Ang mga graphic ay
// canvas na iginuguhit nang 30 beses kada segundo (walang blur: ang liwanag ay mga sprite na idinadagdag); ang mga numero ay DOM
// text sa bawat panel (umaakyat, hindi bumababa sa loob ng isang minuto: LiveRules.settle / land). Ang radar ng bawat bot ay ang
// mga customer ng huling 5 minuto (LiveRules.track / dotPos); ang order ay mga barya mula sa tuldok ng customer, dumadaan sa globo,
// papunta sa vault (LiveRules.schedule / loot / landings). Buong araw ito sa TV: walang lumalaking DOM (12 barya at 2 packet kada
// spoke na ginagamit muli, hanggang 90 icon sa ticker). Ang text mula sa server (pangalan ng page o customer) ay textContent o
// fillText lang, hindi kailanman HTML.
(() => {
    const R = window.LiveRules;
    const boot = JSON.parse(document.getElementById('live-data').textContent);
    const root = document.querySelector('[data-live]');
    const stage = root.querySelector('[data-live-stage]');
    const world = root.querySelector('[data-live-world]');
    const mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const live = window.likhaLive = {reduced: !!(mq && mq.matches), cursor: boot.cursor, counts: boot.counts, timer: null, inflight: false, fail: 0, st: {}};
    const BOTS = Object.keys(boot.bots);
    const all = s => root.querySelectorAll(s);
    const toggle = (e, on) => on ? e.removeAttribute('hidden') : e.setAttribute('hidden', '');
    const now = () => performance.now();
    const phone = () => innerWidth <= 900;
    const calm = () => live.reduced || document.hidden || phone();
    const TAU = Math.PI * 2, lerp = (a, b, k) => a + (b - a) * k, cl = (v, a = 0, b = 1) => Math.min(b, Math.max(a, v));
    const eio = x => x < .5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2;
    const H = (i, s) => { let x = Math.imul(i + 1, 374761393) ^ Math.imul(s + 7, 668265263); x = Math.imul(x ^ x >>> 13, 1274126177); return ((x ^ x >>> 16) >>> 0) / 4294967296; };
    const FS = getComputedStyle(document.documentElement).getPropertyValue('--semi');
    function again(e, cls) { e.classList.remove(cls); void e.getBoundingClientRect(); e.classList.add(cls); }

    // ── ang liwanag: radial na sprite na idinadagdag (globalCompositeOperation lighter), hindi blur ──
    function sprite(rgb) {
        const c = document.createElement('canvas'); c.width = c.height = 64;
        const g = c.getContext('2d'), gr = g.createRadialGradient(32, 32, 0, 32, 32, 32);
        gr.addColorStop(0, 'rgba(' + rgb + ',1)'); gr.addColorStop(.2, 'rgba(' + rgb + ',.8)'); gr.addColorStop(.5, 'rgba(' + rgb + ',.2)'); gr.addColorStop(1, 'rgba(' + rgb + ',0)');
        g.fillStyle = gr; g.fillRect(0, 0, 64, 64); return c;
    }
    const SG = sprite('93,255,156'), SW = sprite('235,255,245'), SY = sprite('255,194,71'), SCY = sprite('111,216,255'), SA = sprite('255,154,46');
    const glow = (c, s, x, y, r, a) => { c.globalAlpha = a; c.drawImage(s, x - r, y - r, 2 * r, 2 * r); c.globalAlpha = 1; };

    // ── ang mga panel: ang hugis ay mula sa server (data-geo), ang dulo ng spoke ay ang gilid na nakaharap sa globo ──
    const O = {x: 960, y: 585}, S15 = 1.45, CR = 795, TH = 548;
    const PN = [...all('[data-pn]')].map(el => {
        const [cx, cy, w, h, ins] = el.dataset.geo.split(',').map(Number);
        return {id: el.dataset.pn, el, cx, cy, w, h, ins, or: el.dataset.or, cv: el.querySelector('canvas'), c: null, M: {X: 0, Y: 0, Z: 0, rot: 0, sc: 1}};
    });
    const PM = {};
    for (const p of PN) {
        PM[p.id] = p;
        p.dx = p.cx - O.x; p.dy = p.cy - O.y; p.th = p.dx / TH;
        const m = Math.hypot(p.dx, p.dy), ux = -p.dx / m, uy = -p.dy / m, hw = p.w / 2, hh = p.h / 2;
        if (p.or === 'L') { p.ax = hw - 3; p.ay = cl(uy * hw / Math.abs(ux), -(hh - p.ins - 8), hh - p.ins - 8); }
        else if (p.or === 'R') { p.ax = -hw + 3; p.ay = cl(uy * hw / Math.abs(ux), -(hh - p.ins - 8), hh - p.ins - 8); }
        else if (p.or === 'T') { p.ay = hh - 3; p.ax = cl(ux * hh / Math.abs(uy), -(hw - p.ins - 8), hw - p.ins - 8); }
        else { p.ay = -hh + 3; p.ax = cl(ux * hh / Math.abs(uy), -(hw - p.ins - 8), hw - p.ins - 8); }
    }
    const orb = root.querySelector('[data-live-core]'), orbCv = orb.querySelector('canvas'), bgCv = root.querySelector('[data-live-bg]'), tkCv = root.querySelector('[data-live-ticker]');
    // Ang radar sa loob ng panel ng bot: gitna at radius.
    const geo = p => p.w > 400 ? {cx: p.or === 'R' ? p.w - 176 : 176, cy: 222, R: 142} : {cx: 130, cy: 132, R: 88};
    const isOn = b => !!PM[b] && PM[b].el.dataset.linked === '1';

    // ── ang mga spoke: dalawang magkakrus na ribbon (ang isa ay nakatagilid sa 2D), ang una ang may packet ──
    const SP = {};
    for (const p of PN) {
        const r0 = world.querySelector('[data-spoke="' + p.id + '"]'), r1 = r0.cloneNode(false);
        r1.removeAttribute('data-spoke'); r1.classList.add('b'); r0.after(r1);
        const pk = [];
        if (p.id !== 'vault') for (let i = 0; i < 2; i++) { const k = document.createElement('i'); k.className = 'pk'; r0.appendChild(k); pk.push({el: k, t0: -1e9, dir: 1, on: false}); }
        SP[p.id] = {p, L: 0, rb: [r0, r1], pk, next: 0};
    }

    // ── 2D ↔ 3D: ang mga panel ay nakalatag sa isang cylinder sa paligid ng globo; isang factor K (0 hanggang 1) ang paghalo ──
    let store = null;
    try { store = window.localStorage; } catch (e) { store = null; }
    let want = R.readView(store) === '3d' ? 1 : 0, K = want, dirty = true;
    function toWorld(p, lx, ly) { const M = p.M, c = Math.cos(M.rot), s = Math.sin(M.rot); return [M.X + M.sc * lx * c, M.Y + M.sc * ly, M.Z - M.sc * lx * s]; }
    function layout(t) {
        const sway = live.reduced ? 0 : 1, orbit = 7 * sway * Math.sin(t / 9500 + 4), tilt = 4 + 2 * sway * Math.sin(t / 7000);
        world.style.transform = K > .001 ? 'translateY(' + (2 * K).toFixed(1) + 'px) translateZ(' + (-240 * K).toFixed(1) + 'px) rotateX(' + (tilt * K).toFixed(2) + 'deg) rotateY(' + (orbit * K).toFixed(2) + 'deg)' : '';
        live.orbit = orbit * K;
        for (const p of PN) {
            const X = lerp(p.dx, CR * Math.sin(p.th), K), Y = lerp(p.dy, p.dy * S15, K), Z = lerp(0, -CR * Math.cos(p.th), K), rot = -p.th * K, sc = lerp(1, S15, K);
            p.M = {X, Y, Z, rot, sc};
            p.el.style.transform = 'translate3d(' + X.toFixed(2) + 'px,' + Y.toFixed(2) + 'px,' + Z.toFixed(2) + 'px) rotateY(' + rot.toFixed(4) + 'rad) scale(' + sc.toFixed(4) + ')';
        }
        for (const id in SP) {
            const s = SP[id], P = toWorld(s.p, s.p.ax, s.p.ay), len = Math.hypot(...P), u = P.map(v => v / len);
            const P0 = u.map(v => v * 236), E = P.map((v, i) => v - u[i] * 5), d = E.map((v, i) => v - P0[i]), L = Math.hypot(...d), a = d.map(v => v / L);
            const w = Math.abs(a[2]) < .8 ? [0, 0, 1] : [0, 1, 0];
            let v1 = [a[1] * w[2] - a[2] * w[1], a[2] * w[0] - a[0] * w[2], a[0] * w[1] - a[1] * w[0]];
            const m1 = Math.hypot(...v1); v1 = v1.map(v => v / m1);
            const n1 = [a[1] * v1[2] - a[2] * v1[1], a[2] * v1[0] - a[0] * v1[2], a[0] * v1[1] - a[1] * v1[0]];
            if (Math.abs(L - s.L) > .5) { s.L = L; s.rb[0].style.width = s.rb[1].style.width = L + 'px'; }
            s.rb[0].style.transform = 'matrix3d(' + [...a, 0, ...v1, 0, ...n1, 0, ...P0, 1].join(',') + ')';
            s.rb[1].style.transform = 'matrix3d(' + [...a, 0, ...n1, 0, ...v1.map(v => -v), 0, ...P0, 1].join(',') + ')';
            s.rb[1].style.opacity = cl(K * 1.6);
        }
    }

    // ── ang mga bilang: bawat isa ay may nakikita at server; umaakyat nang paunti-unti (iisang animation loop) ──
    const day = (k, f, hold = 0, dur = 900) => ({k, f, hold, floor: 60000, dur});
    const win = (k, f) => ({k, f, hold: 0, floor: 0, dur: 900});
    const NUMS = [
        day('replies', c => c.confirmed.today), day('orders', c => c.orders.today, 8000, 600),
        day('peso', c => c.order_value ? c.order_value.today : 0, 8000, 300), day('messages', c => c.messages.today),
        day('ai', c => c.ai.today), day('follow_ups', c => c.follow_ups.today),
        win('active', c => c.active), win('signals', c => R.signals(c)),
    ];
    for (const side of ['in', 'out']) for (const b of [...BOTS, 'unknown']) NUMS.push(day(side + '-' + b, c => c.bots[side][b]));
    const spec = {};
    for (const x of NUMS) spec[x.k] = x;
    const shown = {}, rolls = {};
    function paint(k, v) {
        const text = R.num(v);
        for (const e of all('[data-live-count="' + k + '"]')) if (e.textContent !== text) e.textContent = text;
    }
    // Ang kasalukuyang nakikitang halaga (habang umaakyat), para sa canvas.
    function cur(k) { const r = rolls[k]; return r ? R.roll(r.from, r.to, Math.min(1, (now() - r.t0) / r.dur)) : (shown[k] || 0); }
    function rollTick(t) {
        for (const k of Object.keys(rolls)) {
            const r = rolls[k], q = Math.min(1, (t - r.t0) / r.dur);
            paint(k, R.roll(r.from, r.to, q));
            if (q >= 1) delete rolls[k];
        }
    }
    function show(k, v) {
        const from = shown[k] === undefined ? v : shown[k];
        shown[k] = v;
        if (from === v) return;
        if (live.reduced || document.hidden) { paint(k, v); return; }
        rolls[k] = {from: rolls[k] ? cur(k) : from, to: v, t0: now(), dur: spec[k].dur};
        kick();
    }
    function settleAll() {
        const t = Date.now();
        for (const x of NUMS) {
            const server = Number(x.f(live.counts)) || 0;
            live.st[x.k] = live.st[x.k] ? R.settle(live.st[x.k], server, t, x.hold, x.floor) : {shown: server, server, heldSince: null, lastUp: null};
            show(x.k, live.st[x.k].shown);
        }
    }
    function landOn(k, delta) {
        live.st[k] = R.land(live.st[k], delta, Date.now());
        show(k, live.st[k].shown);
    }
    function put(key, value, attr) {
        for (const e of all('[' + attr + '="' + key + '"]')) if (e.textContent !== value) e.textContent = value;
    }

    // ── ang notes ng bawat bot (nasa card): ang bot na may paparating na barya ay hinihintay ang unang paglapag ──
    const held = {};
    function noteText(x) {
        return {chatting: R.num(x.chatting), ai: R.num(x.ai), avg: x.avg === null || x.avg === undefined ? '—' : Number(x.avg).toFixed(1) + ' s', orders: R.num(x.orders),
            value: R.peso(x.value), last: x.last ? (boot.kinds[x.last.type] || 'Event') + ' · ' + x.last.page + ' · ' + x.last.at : '—'};
    }
    function setNote(bot, x) {
        const el = root.querySelector('[data-note="' + bot + '"]');
        if (!el || !x) return;
        const text = noteText(x);
        for (const k of Object.keys(text)) { const e = el.querySelector('[data-n="' + k + '"]'); if (e && e.textContent !== text[k]) e.textContent = text[k]; }
        put(bot, R.num(x.chatting), 'data-chat');
    }
    function setNotes(notes) {
        for (const bot of BOTS) {
            const x = notes && notes[bot];
            if (!x) continue;
            if (held[bot]) { held[bot].note = x; continue; }
            setNote(bot, x);
        }
    }

    // ── ang radar: bawat customer ng huling 5 minuto ay tuldok; ang bago ay blip (ping at pangalan, 3.5 s) ──
    const radar = {};
    const hms = s => { const m = /^(\d{2}):(\d{2}):(\d{2})$/.exec(String(s || '')); return m ? +m[1] * 3600 + +m[2] * 60 + +m[3] : null; };
    function setRadar(c, nowText) {
        // Ang tanda ng tuldok ay sa oras ng pagbilang (as_of); ang lag hanggang sa sagot ng feed ay idinadagdag.
        const a = hms(c.as_of), b = hms(nowText), lag = a === null || b === null ? 0 : cl(((b - a) + 86400) % 86400, 0, 60);
        const t = Date.now(), tn = now();
        for (const bot of BOTS) {
            const list = (c.radar && c.radar[bot]) || [], r = radar[bot] || (radar[bot] = {dots: new Map(), track: null});
            const tr = R.track(r.track, list, {max: 8}), born = {};
            r.track = tr.state;
            for (const x of tr.blips) born[x.k] = x;
            const next = new Map();
            for (const d of list) {
                const old = r.dots.get(d.k);
                next.set(d.k, {k: d.k, at: t - (Number(d.ago) + lag) * 1000, s: d.s, born: born[d.k] ? tn : (old ? old.born : -1e9),
                    name: born[d.k] ? born[d.k].name : (old ? old.name : null), ring: old ? old.ring : -1e9});
            }
            r.dots = next;
        }
    }

    function setCounts(c, nowText) {
        live.counts = c;
        settleAll();
        for (const [k, src] of [['replies', 'confirmed'], ['orders', 'orders'], ['messages', 'messages'], ['ai', 'ai'], ['follow_ups', 'follow_ups']]) put(k, R.num(c[src].recent), 'data-live-recent');
        put('peso', R.num(c.order_value ? c.order_value.recent : 0), 'data-live-recent');
        for (const [k, src] of [['replies', 'confirmed'], ['orders', 'orders']]) for (const e of all('[data-live-up="' + k + '"]')) e.classList.toggle('up', c[src].recent > 0);
        const rt = c.reply_time || {state: 'error', seconds: null};
        put('reply_time', rt.seconds === null ? '—' : Number(rt.seconds).toFixed(1), 'data-live-count');
        for (const e of all('[data-live-unit]')) toggle(e, rt.seconds !== null);
        put('reply_time', boot.notes[rt.state === 'ok' && rt.seconds === null ? 'none' : rt.state] || boot.notes.error, 'data-live-note');
        for (const side of ['in', 'out']) for (const e of all('[data-live-unknown="' + side + '"]')) toggle(e, !!c.bots[side].unknown);
        for (const bot of BOTS) {
            const on = bot === 'botcake' || !!(c.linked && c.linked[bot]);
            for (const e of all('[data-bot-row="' + bot + '"]')) e.setAttribute('data-linked', on ? '1' : '0');
            SP[bot].rb[0].className = SP[bot].rb[0].className.replace(/\b(v|dm)\b/, on ? 'v' : 'dm');
            const st = root.querySelector('[data-note="' + bot + '"] [data-n="state"]');
            if (st) st.textContent = on ? boot.states[bot] : 'Not linked';
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
        setRadar(c, nowText);
    }

    // ── ang mga packet sa spoke (isa kada event, mula sa pool) at ang ticker ng icon ──
    function fire(id, dir, col) {
        const s = SP[id];
        if (!s || !s.pk.length || calm()) return;
        const k = s.pk[s.next++ % s.pk.length];
        k.t0 = now(); k.dir = dir;
        k.el.style.background = 'linear-gradient(' + (dir > 0 ? 90 : 270) + 'deg,transparent,' + col + ')';
        kick();
    }
    function packets(t) {
        for (const id in SP) for (const k of SP[id].pk) {
            const p = (t - k.t0) / 1400, on = p >= 0 && p < 1;
            if (!on) { if (k.on) { k.el.style.opacity = 0; k.on = false; } continue; }
            k.on = true;
            k.el.style.opacity = Math.sqrt(Math.sin(Math.PI * p)).toFixed(2);
            k.el.style.transform = 'translateX(' + ((k.dir > 0 ? p : 1 - p) * SP[id].L).toFixed(1) + 'px)';
        }
    }
    const ticker = [], hexLit = new Array(30).fill(-1e9);
    let hexN = 0;
    function tick(kind) { if (!kind) return; ticker.push({kind, t: now()}); if (ticker.length > 90) ticker.shift(); }

    // ── ang loot: mga barya mula sa tuldok ng customer (o sa gitna ng radar), dumadaan sa globo, papunta sa vault ──
    const GAP = 150, POP = 300, FLY = 1100;
    const coinLayer = root.querySelector('[data-live-coins]'), plus = root.querySelector('[data-live-plus]'), total = root.querySelector('[data-vault-total]');
    const coins = [];
    for (let i = 0; i < 12; i++) { const d = document.createElement('div'); d.className = 'coin'; coinLayer.appendChild(d); coins.push({el: d, busy: false}); }
    const bursts = [];
    let warmAt = -1e9, shockAt = -1e9, nudgeAt = -1e9, pass = 0;
    function float(text) {
        clearTimeout(plus._lvT);
        plus.textContent = text;
        plus.classList.remove('go', 'still'); void plus.offsetWidth;
        plus.classList.add(live.reduced ? 'still' : 'go');
        plus._lvT = setTimeout(() => plus.classList.remove('still', 'go'), live.reduced ? 4000 : 1800);
    }
    function vaultTop() { const v = PM.vault; return [88 - v.w / 2, 168 - R.discs(cur('peso')) * 11 - v.h / 2]; }
    // Ang pinanggagalingan: ang pinakabatang gintong tuldok ng bot; kung wala, ang gitna ng radar; walang bot = ang globo.
    function origin(bot) {
        const p = bot && PM[bot];
        if (!p) return {p: null, lx: 0, ly: 0};
        const g = geo(p), r = radar[bot], k = r ? R.goldDot([...r.dots.values()].map(d => ({k: d.k, s: d.s, ago: (Date.now() - d.at) / 1000}))) : null;
        let x = g.cx, y = g.cy;
        if (k) { const d = r.dots.get(k), q = R.dotPos(k, (Date.now() - d.at) / 1000); x += g.R * q.r * Math.cos(q.ang); y += g.R * q.r * Math.sin(q.ang); d.ring = now(); }
        return {p, lx: x - p.w / 2, ly: y - p.h / 2};
    }
    function cash(a) {
        const plan = R.loot(a.peso, {reduced: calm(), orders: a.orders});
        const bot = a.bot && PM[a.bot] ? a.bot : null, finish = () => release(bot);
        const free = coins.filter(x => !x.busy);
        if (!plan.coins || free.length < plan.coins) {
            landOn('orders', a.orders); landOn('peso', plan.peso); float(plan.label); finish();
            return;
        }
        const t0 = now(), times = R.landings(plan.coins, {gap: GAP, burst: POP, fly: FLY}), src = origin(bot);
        const list = free.slice(0, plan.coins).map((x, i) => {
            x.busy = true; x.el.textContent = plan.plate ? plan.label : '₱'; x.el.classList.toggle('plate', plan.plate);
            return {x, i, land: times[i], done: false, fx: (H(i, 3) - .5) * 70, fy: -30 - H(i, 4) * 40};
        });
        let landed = 0;
        bursts.push({t0, src, list, onLand: i => {
            const t = now();
            if (landed++ === 0) { landOn('orders', a.orders); float(plan.label); finish(); }
            if (plan.peso) { landOn('peso', plan.parts[i]); warmAt = t; nudgeAt = t; }
            if (landed === plan.coins && plan.peso) shockAt = t;
        }});
        kick();
    }
    function stepCoins(t) {
        pass = 0;
        for (let b = bursts.length - 1; b >= 0; b--) {
            const B = bursts[b], u = t - B.t0;
            for (const k of B.list) {
                if (k.done) continue;
                const tt = u - k.i * GAP;
                if (tt < 0) { k.x.el.style.display = 'none'; continue; }
                if (u >= k.land) { k.done = true; k.x.el.style.display = 'none'; k.x.busy = false; B.onLand(k.i); continue; }
                const A0 = B.src.p ? toWorld(B.src.p, B.src.lx, B.src.ly) : [0, 0, 40], [vx, vy] = vaultTop(), V = toWorld(PM.vault, vx, vy);
                let pos, sc;
                if (tt < POP) {
                    const e = 1 - Math.pow(1 - tt / POP, 3);
                    pos = [A0[0] + k.fx * e, A0[1] + k.fy * e, A0[2] + 20 * e]; sc = .3 + .85 * e;
                } else {
                    const A = [A0[0] + k.fx, A0[1] + k.fy, A0[2] + 20], p = (tt - POP) / FLY, q = eio(p);
                    // Ang kurba ay dumadaan sa gitna ng globo sa kalahati (ang control point ay ang salamin ng gitna ng A at V).
                    const C = [-(A[0] + V[0]) / 2, -(A[1] + V[1]) / 2, 60 - (A[2] + V[2]) / 2];
                    pos = [0, 1, 2].map(j => (1 - q) * (1 - q) * A[j] + 2 * (1 - q) * q * C[j] + q * q * V[j]);
                    sc = .75 + .3 * Math.sin(Math.PI * p);
                    pass = Math.max(pass, Math.exp(-Math.pow((p - .5) / .09, 2)));
                }
                const fl = Math.abs(Math.cos(tt / 1100 * TAU * 2.5)) * .85 + .15;
                k.x.el.style.display = 'block';
                k.x.el.style.transform = 'translate3d(' + pos[0].toFixed(1) + 'px,' + pos[1].toFixed(1) + 'px,' + pos[2].toFixed(1) + 'px) rotateY(' + (-(live.orbit || 0)).toFixed(2) + 'deg) scale(' + sc.toFixed(3) + ') scaleX(' + fl.toFixed(3) + ')';
            }
            if (B.list.every(k => k.done)) bursts.splice(b, 1);
        }
        const nd = t - nudgeAt, sd = t - shockAt;
        const pop = 1 + (nd < 220 ? .012 * (1 - nd / 220) : 0) + (sd < 520 ? .035 * Math.sin(Math.PI * sd / 520) : 0);
        total.style.transform = pop === 1 ? '' : 'scale(' + pop.toFixed(4) + ')';
        return bursts.length || nd < 520 || sd < 1000;
    }

    function hit(name) { const p = PM[name]; if (p) p.hitAt = now(); }
    function act(a) {
        tick(R.icon(a.event));
        if (a.type === 'order') { cash(a); return; }
        const bot = a.bot && PM[a.bot] ? a.bot : null;
        if (a.type === 'message') { if (bot) fire(bot, -1, '#e9fbff'); fire('chats', -1, '#5dff9c'); }
        else if (a.type === 'confirmed') { if (bot) fire(bot, 1, '#35c8ff'); fire('replies', 1, '#35c8ff'); }
        else if (a.type === 'ai') { fire('ai', 1, '#6fd8ff'); hexLit[(hexN++ * 11) % 30] = now(); }
        else if (a.type === 'follow_up') { fire('seq', 1, '#6fd8ff'); if (bot) fire(bot, 1, '#35c8ff'); }
        if (bot) hit(bot);
    }
    // Ang plano ng poll: ang notes ng bot na may loot ay hinihintay ang unang paglapag (hanggang 10 s, para hindi maiwan kailanman).
    function schedule(events) {
        const plan = R.schedule(events, {reduced: calm()});
        for (const a of plan.actions) {
            if (a.kind !== 'money' || !a.bot || !PM[a.bot]) continue;
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
    function play(plan) { for (const a of plan.actions) a.at ? setTimeout(() => act(a), a.at) : act(a); }

    // ── ang pagguhit (canvas) ──
    function rpath(c, pts, r) {
        const n = pts.length; c.beginPath();
        for (let i = 0; i < n; i++) {
            const a = pts[(i + n - 1) % n], b = pts[i], d = pts[(i + 1) % n], m0 = [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2], m2 = [(b[0] + d[0]) / 2, (b[1] + d[1]) / 2];
            if (!i) c.moveTo(m0[0], m0[1]);
            c.arcTo(b[0], b[1], m2[0], m2[1], r);
        }
        c.closePath();
    }
    function shape(p, sh) {
        const {w, h, ins: i} = p;
        let q = p.or === 'L' ? [[0, 0], [w, i], [w, h - i], [0, h]] : p.or === 'R' ? [[0, i], [w, 0], [w, h], [0, h - i]] : p.or === 'T' ? [[0, 0], [w, 0], [w - i, h], [i, h]] : [[i, 0], [w - i, 0], [w, h], [0, h]];
        if (sh) { const k = (w - 2 * sh) / w, j = (h - 2 * sh) / h; q = q.map(a => [sh + a[0] * k, sh + a[1] * j]); }
        return q;
    }
    function frame(c, p, t, dim) {
        const {w, h} = p, G = p.id === 'vault', hitK = cl(1 - (now() - (p.hitAt || -1e9)) / 500);
        c.clearRect(0, 0, w, h); c.globalAlpha = dim ? .6 : 1;
        rpath(c, shape(p, 2), 24);
        const g = c.createLinearGradient(0, 0, w, h);
        g.addColorStop(0, G ? 'rgba(255,190,70,.20)' : 'rgba(60,196,245,' + (.20 + .12 * hitK) + ')'); g.addColorStop(1, G ? 'rgba(190,110,20,.10)' : 'rgba(20,110,170,.09)');
        c.fillStyle = g; c.fill();
        c.lineWidth = 2; c.strokeStyle = G ? 'rgba(255,194,71,.7)' : 'rgba(111,216,255,.62)'; c.stroke();
        rpath(c, shape(p, 12), 16); c.lineWidth = 1; c.strokeStyle = G ? 'rgba(255,194,71,.2)' : 'rgba(111,216,255,.16)'; c.stroke();
        if (!dim) {
            // Isang maliwanag na guhit na gumagapang sa gilid.
            rpath(c, shape(p, 2), 24); const per = 2 * (w + h); c.setLineDash([90, per]); c.lineDashOffset = -((t * .09 + p.dx * .7) % per + per) % per;
            c.lineWidth = 3; c.strokeStyle = 'rgba(200,245,255,.9)'; c.stroke(); c.setLineDash([]);
        }
        c.globalAlpha = 1;
    }
    function radarBase(c, cx, cy, R0, dim) {
        const g = c.createRadialGradient(cx, cy, 0, cx, cy, R0);
        g.addColorStop(0, 'rgba(40,150,200,' + (dim ? .06 : .2) + ')'); g.addColorStop(1, 'rgba(10,70,110,' + (dim ? .12 : .4) + ')');
        c.fillStyle = g; c.beginPath(); c.arc(cx, cy, R0, 0, TAU); c.fill();
        if (dim) c.setLineDash([5, 8]);
        c.strokeStyle = 'rgba(111,216,255,' + (dim ? .2 : .26) + ')'; c.lineWidth = 1.2;
        for (const f of [.25, .5, .75]) { c.beginPath(); c.arc(cx, cy, R0 * f, 0, TAU); c.stroke(); }
        c.beginPath(); c.moveTo(cx - R0, cy); c.lineTo(cx + R0, cy); c.moveTo(cx, cy - R0); c.lineTo(cx, cy + R0); c.stroke(); c.setLineDash([]);
        c.strokeStyle = 'rgba(111,216,255,' + (dim ? .3 : .72) + ')'; c.lineWidth = 2; c.beginPath(); c.arc(cx, cy, R0, 0, TAU); c.stroke();
        c.lineWidth = 1.5;
        for (let i = 0; i < 72; i++) {
            const a = i / 72 * TAU, l = i % 6 ? 5 : 11;
            c.beginPath(); c.moveTo(cx + (R0 + 4) * Math.cos(a), cy + (R0 + 4) * Math.sin(a)); c.lineTo(cx + (R0 + 4 + l) * Math.cos(a), cy + (R0 + 4 + l) * Math.sin(a));
            c.strokeStyle = 'rgba(111,216,255,' + (dim ? .18 : i % 6 ? .3 : .6) + ')'; c.stroke();
        }
    }
    function radarLive(c, g, t, bot) {
        const {cx, cy, R: R0} = g;
        radarBase(c, cx, cy, R0, false);
        const sw = (t / 4200) % 1 * TAU, cg = c.createConicGradient(sw - 1.25, cx, cy);
        cg.addColorStop(0, 'rgba(111,216,255,0)'); cg.addColorStop(.199, 'rgba(150,232,255,.42)'); cg.addColorStop(.2, 'rgba(150,232,255,0)');
        c.fillStyle = cg; c.beginPath(); c.arc(cx, cy, R0, 0, TAU); c.fill();
        c.strokeStyle = 'rgba(225,250,255,.85)'; c.lineWidth = 2; c.beginPath(); c.moveTo(cx, cy); c.lineTo(cx + R0 * Math.cos(sw), cy + R0 * Math.sin(sw)); c.stroke();
        const r = radar[bot], tags = [], tn = now(), td = Date.now(), small = R0 < 100;
        if (!r) return;
        c.globalCompositeOperation = 'lighter';
        for (const d of r.dots.values()) {
            const age = (td - d.at) / 1000;
            if (age > 300) continue;
            const q = R.dotPos(d.k, age), x = cx + R0 * q.r * Math.cos(q.ang), y = cy + R0 * q.r * Math.sin(q.ang), gold = d.s === 'order';
            const dl = ((sw - q.ang) % TAU + TAU) % TAU, b = tn - d.born, rg = tn - d.ring;
            let al = .3 + .7 * Math.exp(-dl / 1.5), sp = SG, rad = small ? 9 : 12;
            if (gold) { sp = SY; rad = small ? 16 : 22; al = 1; }
            if (rg < 1800) { c.strokeStyle = 'rgba(255,194,71,' + (1 - rg / 1800) + ')'; c.lineWidth = 2.5; c.beginPath(); c.arc(x, y, 8 + rg / 1800 * 58, 0, TAU); c.stroke(); }
            if (b < 2600) { sp = gold ? SY : SW; rad = 14 + 3 * Math.sin(b / 90); al = 1; c.strokeStyle = 'rgba(190,255,215,' + (1 - b / 2600) + ')'; c.lineWidth = 2; c.beginPath(); c.arc(x, y, 6 + b / 2600 * 32, 0, TAU); c.stroke(); }
            glow(c, sp, x, y, rad, al);
            c.globalAlpha = al; c.fillStyle = gold ? '#fff0bd' : '#effff5'; c.beginPath(); c.arc(x, y, gold ? 4.4 : 2.9, 0, TAU); c.fill(); c.globalAlpha = 1;
            if (d.s === 'ai') { c.strokeStyle = 'rgba(111,216,255,.75)'; c.lineWidth = 1.3; c.beginPath(); c.arc(x, y, 6.5, 0, TAU); c.stroke(); }
            if (d.name && b < 3500) tags.push({x, y, name: d.name, b});
        }
        c.globalCompositeOperation = 'source-over';
        // Ang pangalan ng bagong customer: unang pangalan at inisyal, 3.5 s lang (fillText: text, hindi HTML).
        for (const d of tags) {
            c.globalAlpha = d.b < 2600 ? 1 : 1 - (d.b - 2600) / 900;
            c.font = '600 18px ' + FS;
            const tw = c.measureText(d.name).width, right = d.x < cx, bx = right ? d.x + 16 : d.x - 16 - tw - 14;
            c.fillStyle = 'rgba(3,28,44,.92)'; rpath(c, [[bx, d.y - 14], [bx + tw + 14, d.y - 14], [bx + tw + 14, d.y + 12], [bx, d.y + 12]], 6); c.fill();
            c.strokeStyle = 'rgba(93,255,156,.7)'; c.lineWidth = 1.2; c.stroke();
            c.fillStyle = '#d8ffe8'; c.textAlign = 'left'; c.fillText(d.name, bx + 7, d.y + 5);
            c.globalAlpha = 1;
        }
    }
    function led(c, x, y, t, col) { c.fillStyle = col; c.globalAlpha = .55 + .45 * Math.sin(t / 380); c.beginPath(); c.arc(x, y, 6, 0, TAU); c.fill(); c.globalAlpha = 1; }
    function tri(c, x, y, dir, col) { c.fillStyle = col; c.beginPath(); c.moveTo(x, y - 7); c.lineTo(x + dir * 13, y); c.lineTo(x, y + 7); c.closePath(); c.fill(); }
    function power(c, x, y, r) {
        c.strokeStyle = 'rgba(111,216,255,.4)'; c.lineWidth = 5; c.lineCap = 'round';
        c.beginPath(); c.arc(x, y, r, -Math.PI * .35, Math.PI * 1.35, false); c.stroke();
        c.beginPath(); c.moveTo(x, y - r - 8); c.lineTo(x, y - 8); c.stroke(); c.lineCap = 'butt';
    }
    function dBot(p, t) {
        const c = p.c, on = isOn(p.id), g = geo(p), big = p.w > 400, m = p.or === 'R';
        frame(c, p, t, !on);
        if (!on) {
            // Dormant: dim na wireframe, isang dashed na guhit na umiikot nang mabagal, at ang power icon. Hindi pula.
            c.globalAlpha = .7; radarBase(c, g.cx, g.cy, g.R, true);
            const a = (t / 9000) % 1 * TAU;
            c.strokeStyle = 'rgba(111,216,255,.16)'; c.lineWidth = 1.5; c.setLineDash([4, 7]); c.beginPath(); c.moveTo(g.cx, g.cy); c.lineTo(g.cx + g.R * Math.cos(a), g.cy + g.R * Math.sin(a)); c.stroke(); c.setLineDash([]);
            c.globalAlpha = 1;
            power(c, big ? (m ? 130 : p.w - 130) : 290, big ? p.h / 2 + 10 : 160, big ? 40 : 26);
            return;
        }
        radarLive(c, g, t, p.id);
        if (big) {
            led(c, m ? p.w - 34 : 44, 40, t, '#5dff9c');
            tri(c, m ? 48 : 342, 118, m ? -1 : 1, '#e9fbff'); tri(c, m ? 38 : 352, 238, m ? 1 : -1, '#35c8ff');
        } else {
            led(c, 222, 46, t, '#5dff9c');
            tri(c, 236, 94, 1, '#e9fbff'); tri(c, 246, 162, -1, '#35c8ff');
        }
    }
    function hex(c, x, y, r) { c.beginPath(); for (let i = 0; i < 6; i++) { const a = Math.PI / 6 + i * Math.PI / 3; c.lineTo(x + r * Math.cos(a), y + r * Math.sin(a)); } c.closePath(); }
    function dAI(p, t) {
        const c = p.c, tn = now(); frame(c, p, t, false);
        for (let r = 0; r < 5; r++) for (let k = 0; k < 6; k++) {
            const cell = r * 6 + k, x = 46 + k * 27 + (r % 2) * 13.5, y = 72 + r * 23.5, age = tn - hexLit[cell], lit = age < 60000;
            hex(c, x, y, 14.2);
            const b = lit ? cl(1 - age / 60000, .18, 1) : 0;
            c.fillStyle = lit ? 'rgba(111,216,255,' + (.14 + .62 * b) + ')' : 'rgba(111,216,255,.04)'; c.fill();
            c.strokeStyle = 'rgba(111,216,255,' + (lit ? .45 : .2) + ')'; c.lineWidth = 1.2; c.stroke();
            if (age < 1500) { c.fillStyle = 'rgba(255,255,255,' + (1 - age / 1500) + ')'; hex(c, x, y, 14.2); c.fill(); }
        }
    }
    function dFU(p, t) {
        const c = p.c, seqs = live.counts.sequences || [], top = seqs.length ? Math.max(1, seqs[0].n) : 1; frame(c, p, t, false);
        [[70, '#6fd8ff'], [53, '#bdeeff'], [36, '#35c8ff']].forEach(([R0, col], i) => {
            c.lineWidth = 9; c.lineCap = 'round'; c.strokeStyle = 'rgba(111,216,255,.12)'; c.beginPath(); c.arc(90, 126, R0, 0, TAU); c.stroke();
            const f = seqs[i] ? cl(seqs[i].n / top) : 0;
            if (f > 0) {
                const a0 = -Math.PI / 2, a1 = a0 + f * TAU * .96;
                c.strokeStyle = col; c.beginPath(); c.arc(90, 126, R0, a0, a1); c.stroke();
                const ad = a0 + ((t / (2600 + i * 900)) % 1) * f * TAU * .96;
                c.globalCompositeOperation = 'lighter'; glow(c, SW, 90 + R0 * Math.cos(ad), 126 + R0 * Math.sin(ad), 13, 1); c.globalCompositeOperation = 'source-over';
            }
            c.lineCap = 'butt';
        });
    }
    function arc(c, cx, cy, R0, a0, a1, w, col) { c.lineWidth = w; c.lineCap = 'round'; c.strokeStyle = col; c.beginPath(); c.arc(cx, cy, R0, a0, a1); c.stroke(); c.lineCap = 'butt'; }
    // Ang gauge ng replies: ang huling 5 minuto laban sa karaniwang 5 minuto ngayong araw (kalahati = karaniwan, puno = doble o higit pa).
    function pace(c) {
        const d = new Date(), s = d.getHours() * 3600 + d.getMinutes() * 60 + d.getSeconds(), today = Number(c.confirmed.today) || 0;
        if (!today || s < 300) return 0;
        return cl((Number(c.confirmed.recent) || 0) / (today * 300 / s) / 2);
    }
    function dRep(p, t) {
        const c = p.c, cx = 66, cy = 116, R0 = 44, a0 = Math.PI * .8333, span = Math.PI * 1.3333, f = pace(live.counts); frame(c, p, t, false);
        arc(c, cx, cy, R0, a0, a0 + span, 12, 'rgba(111,216,255,.14)');
        if (f > 0) {
            const g = c.createLinearGradient(cx - R0, cy, cx + R0, cy); g.addColorStop(0, '#35c8ff'); g.addColorStop(1, '#d9f6ff');
            arc(c, cx, cy, R0, a0, a0 + span * f, 12, g);
        }
        const ae = a0 + span * f;
        c.globalCompositeOperation = 'lighter'; glow(c, SW, cx + R0 * Math.cos(ae), cy + R0 * Math.sin(ae), 14 + 3 * Math.sin(t / 300), 1); c.globalCompositeOperation = 'source-over';
    }
    function dAvg(p, t) {
        const c = p.c, cx = 66, cy = 132, R0 = 46, rt = live.counts.reply_time, v = rt && rt.seconds !== null ? Number(rt.seconds) : null; frame(c, p, t, false);
        [[0, .5, '#35c8ff'], [.5, .8, '#bdeeff'], [.8, 1, '#ff9a2e']].forEach(([a, b, col]) => arc(c, cx, cy, R0, Math.PI + a * Math.PI, Math.PI + b * Math.PI - .02, 10, col + '66'));
        if (v === null) return;
        const na = Math.PI + cl(v / 15) * Math.PI;
        arc(c, cx, cy, R0, Math.PI, na, 10, '#35c8ff');
        c.strokeStyle = '#fff'; c.lineWidth = 3; c.lineCap = 'round'; c.beginPath(); c.moveTo(cx, cy); c.lineTo(cx + (R0 - 14) * Math.cos(na), cy + (R0 - 14) * Math.sin(na)); c.stroke(); c.lineCap = 'butt';
        c.fillStyle = '#fff'; c.beginPath(); c.arc(cx, cy, 6, 0, TAU); c.fill();
    }
    function dAct(p, t) {
        const c = p.c, cx = 214, cy = 134, R0 = 86, n = Math.min(100, Math.round(cur('active'))); frame(c, p, t, false);
        c.globalCompositeOperation = 'lighter';
        for (let i = 0; i < 100; i++) {
            const a = -Math.PI / 2 + i / 100 * TAU, x = cx + R0 * Math.cos(a), y = cy + R0 * Math.sin(a);
            if (i < n) { const b = .6 + .4 * Math.sin(t / 520 + i * .8); glow(c, SG, x, y, 9, b); c.globalAlpha = b; c.fillStyle = '#eafff2'; c.beginPath(); c.arc(x, y, 2.4, 0, TAU); c.fill(); c.globalAlpha = 1; }
            else { c.fillStyle = 'rgba(111,216,255,.22)'; c.beginPath(); c.arc(x, y, 2, 0, TAU); c.fill(); }
        }
        const hd = -Math.PI / 2 + (t / 6000 % 1) * TAU; glow(c, SW, cx + R0 * Math.cos(hd), cy + R0 * Math.sin(hd), 12, .9);
        c.globalCompositeOperation = 'source-over';
    }
    // Ang vault: isang disc kada ₱5,000 (LiveRules.discs), ang itaas ay kumikinang sa bawat paglapag; shock ellipse sa huli.
    function dVault(p, t) {
        const c = p.c, bx = 88, by = 168, dh = 11, n = R.discs(cur('peso')), tn = now(), warm = Math.exp(-Math.max(0, tn - warmAt) / 480), sk = (tn - shockAt) / 1000;
        frame(c, p, t, false);
        if (!n) { c.strokeStyle = 'rgba(255,194,71,.45)'; c.setLineDash([4, 5]); c.lineWidth = 1.5; c.beginPath(); c.ellipse(bx, by, 50, 13, 0, 0, TAU); c.stroke(); c.setLineDash([]); }
        for (let i = 0; i < n; i++) {
            const y = by - i * dh, g = c.createLinearGradient(bx - 50, 0, bx + 50, 0), top = i === n - 1;
            g.addColorStop(0, '#9c600a'); g.addColorStop(.38, '#ffd36e'); g.addColorStop(1, '#a9680e');
            c.fillStyle = g; c.beginPath(); c.moveTo(bx - 50, y - dh); c.lineTo(bx - 50, y); c.ellipse(bx, y, 50, 13, 0, Math.PI, 0, true); c.lineTo(bx + 50, y - dh); c.closePath(); c.fill();
            c.strokeStyle = 'rgba(90,50,0,.55)'; c.lineWidth = 1.2; c.stroke();
            c.fillStyle = top ? 'rgb(255,' + Math.round(236 + 19 * warm) + ',' + Math.round(170 + 60 * warm) + ')' : '#f3c658';
            c.beginPath(); c.ellipse(bx, y - dh, 50, 13, 0, 0, TAU); c.fill(); c.stroke();
            if (top) { c.strokeStyle = 'rgba(110,66,0,.5)'; c.setLineDash([4, 5]); c.beginPath(); c.ellipse(bx, y - dh, 36, 9, 0, 0, TAU); c.stroke(); c.setLineDash([]); }
        }
        const ty = by - n * dh;
        c.globalCompositeOperation = 'lighter'; glow(c, SY, bx, ty, 60 + 30 * warm, .35 + .55 * warm); c.globalCompositeOperation = 'source-over';
        if (sk >= 0 && sk < 1) { const e = 1 - Math.pow(1 - sk, 3); c.strokeStyle = 'rgba(255,226,140,' + (1 - sk) + ')'; c.lineWidth = 4 * (1 - sk) + 1; c.beginPath(); c.ellipse(bx, ty, 50 + e * 90, 13 + e * 30, 0, 0, TAU); c.stroke(); }
    }
    // Ang globo: limang singsing na umiikot sa 3D (likod na kalahati ay dim sa likod ng globo), mga satellite, berdeng kislap.
    const RINGS = [
        {R: 205, col: '255,168,56', w: 3, a0: 70, ad: 8, ap: 6000, b0: -20, bs: 4, dash: [44, 14], ds: 60, sat: .9},
        {R: 182, col: '120,224,255', w: 2.6, a0: 60, ad: 6, ap: 7000, b0: 55, bs: -5, dash: [], ds: 0, sat: -.7},
        {R: 230, col: '255,190,90', w: 1.7, a0: 78, ad: 4, ap: 9000, b0: 100, bs: 3, dash: [3, 9], ds: -30},
        {R: 166, col: '160,235,255', w: 1.9, a0: 80, ad: 5, ap: 5000, b0: -70, bs: -6, dash: [20, 10], ds: 40},
        {R: 216, col: '120,224,255', w: 1.2, a0: 50, ad: 6, ap: 8000, b0: 20, bs: 7, dash: [1, 6], ds: 20}];
    function ringPts(r, t) {
        const al = (r.a0 + r.ad * Math.sin(t / r.ap * TAU)) * Math.PI / 180, be = (r.b0 + r.bs * t / 1000) * Math.PI / 180, ca = Math.cos(al), sa = Math.sin(al), cb = Math.cos(be), sb = Math.sin(be);
        const f = a => { const x = r.R * Math.cos(a), y0 = r.R * Math.sin(a), y = y0 * ca; return [350 + x * cb - y * sb, 350 + x * sb + y * cb, y0 * sa]; };
        return {f, pts: Array.from({length: 120}, (_, j) => f(j / 120 * TAU)), t};
    }
    function ringPass(c, r, P, front) {
        c.setLineDash(r.dash); c.lineDashOffset = -(r.ds * (P.t / 1000)); c.lineWidth = front ? r.w : r.w * .7; c.strokeStyle = 'rgba(' + r.col + ',' + (front ? .92 : .32) + ')';
        let on = false; c.beginPath();
        for (let j = 0; j <= P.pts.length; j++) { const q = P.pts[j % P.pts.length], ok = front ? q[2] >= 0 : q[2] < 0; if (ok) { if (!on) { c.moveTo(q[0], q[1]); on = true; } else c.lineTo(q[0], q[1]); } else on = false; }
        c.stroke(); c.setLineDash([]);
    }
    function dOrb(c, t) {
        const cx = 350, cy = 350, Rg = 160;
        c.clearRect(0, 0, 700, 700);
        let g = c.createRadialGradient(cx, cy, Rg * .7, cx, cy, 340); g.addColorStop(0, 'rgba(150,222,255,.55)'); g.addColorStop(.4, 'rgba(60,160,225,.2)'); g.addColorStop(1, 'rgba(30,110,180,0)');
        c.fillStyle = g; c.fillRect(0, 0, 700, 700);
        const RP = RINGS.map(r => ringPts(r, t));
        c.globalCompositeOperation = 'lighter'; RINGS.forEach((r, i) => ringPass(c, r, RP[i], false)); c.globalCompositeOperation = 'source-over';
        g = c.createRadialGradient(cx - 38, cy - 46, 6, cx, cy, Rg + 8);
        g.addColorStop(0, '#ffffff'); g.addColorStop(.28, '#e9f9ff'); g.addColorStop(.62, '#a6def8'); g.addColorStop(.9, '#3da9de'); g.addColorStop(1, '#1d74b4');
        c.fillStyle = g; c.beginPath(); c.arc(cx, cy, Rg, 0, TAU); c.fill();
        c.save(); c.beginPath(); c.arc(cx, cy, Rg, 0, TAU); c.clip(); c.strokeStyle = 'rgba(10,80,130,.2)'; c.lineWidth = 1.3;
        for (let i = 0; i < 6; i++) { const a = t * .00018 * TAU + i * Math.PI / 6; c.beginPath(); c.ellipse(cx, cy, Rg * Math.abs(Math.cos(a)), Rg, 0, 0, TAU); c.stroke(); }
        for (const s of [-.7, -.35, 0, .35, .7]) { const rx = Rg * Math.sqrt(1 - s * s); c.beginPath(); c.ellipse(cx, cy + s * Rg, rx, rx * .16, 0, 0, TAU); c.stroke(); }
        const pu = .08 + .07 * Math.sin(t / 700) + .55 * pass;
        g = c.createRadialGradient(cx, cy, 0, cx, cy, Rg); g.addColorStop(0, 'rgba(255,255,255,' + pu + ')'); g.addColorStop(1, 'rgba(180,235,255,' + pu * .3 + ')'); c.fillStyle = g; c.fillRect(0, 0, 700, 700);
        c.restore();
        for (let i = 0; i < 16; i++) {
            const ph = t * .00012 * TAU * (.6 + H(i, 3)) + H(i, 4) * TAU, lat = (H(i, 5) - .5) * 2.2, x = cx + Rg * .97 * Math.cos(lat) * Math.sin(ph), zf = Math.cos(ph);
            if (zf > 0) { const y = cy + Rg * .97 * Math.sin(lat); c.fillStyle = 'rgba(20,170,90,' + (.5 + .4 * zf) + ')'; c.beginPath(); c.arc(x, y, 3, 0, TAU); c.fill(); glow(c, SG, x, y, 11, .55); }
        }
        c.globalCompositeOperation = 'lighter'; RINGS.forEach((r, i) => ringPass(c, r, RP[i], true));
        RINGS.forEach((r, i) => { if (!r.sat) return; const q = RP[i].f(t / 1000 * r.sat); glow(c, i ? SCY : SA, q[0], q[1], q[2] >= 0 ? 18 : 11, q[2] >= 0 ? 1 : .5); });
        c.globalCompositeOperation = 'source-over';
    }
    function dBg() {
        const c = bgCv.getContext('2d'); c.clearRect(0, 0, 1920, 1080);
        const g = c.createRadialGradient(960, 585, 50, 960, 585, 1100); g.addColorStop(0, '#0c2c47'); g.addColorStop(.35, '#071a2e'); g.addColorStop(1, '#02060c');
        c.fillStyle = g; c.fillRect(0, 0, 1920, 1080);
        c.strokeStyle = 'rgba(111,216,255,.06)'; c.lineWidth = 1.5;
        for (let i = 0; i < 48; i++) { const a = i / 48 * TAU; c.beginPath(); c.moveTo(960 + 250 * Math.cos(a), 585 + 250 * Math.sin(a)); c.lineTo(960 + 1500 * Math.cos(a), 585 + 1500 * Math.sin(a)); c.stroke(); }
        c.setLineDash([2, 9]); for (const R0 of [330, 470, 650, 860]) { c.strokeStyle = 'rgba(111,216,255,.1)'; c.beginPath(); c.arc(960, 585, R0, 0, TAU); c.stroke(); } c.setLineDash([]);
        c.fillStyle = 'rgba(111,216,255,.06)'; for (let i = 0; i < 60; i++) { c.beginPath(); c.arc(H(i, 91) * 1920, H(i, 92) * 1080, 1.5, 0, TAU); c.fill(); }
    }
    // Ang ticker: isang hanay ng icon (pababang arrow = pumasok, pataas = sagot, hexagon = AI, arko = follow-up, barya = order), 26 px/s pakaliwa.
    function dTk(t) {
        const c = tkCv.getContext('2d'), W = tkW, v = .026;
        c.clearRect(0, 0, W, 28);
        c.lineWidth = 2.2; c.lineCap = 'round'; c.lineJoin = 'round';
        while (ticker.length && W - 10 - (t - ticker[0].t) * v < 10) ticker.shift();
        for (const e of ticker) {
            const x = W - 10 - (t - e.t) * v, k = e.kind;
            c.globalAlpha = cl(Math.min(x / 160, (W - x) / 40)) * (k === 'order' ? 1 : .8);
            c.strokeStyle = '#6fd8ff';
            if (k === 'in') { c.beginPath(); c.moveTo(x, 6); c.lineTo(x, 20); c.moveTo(x - 6, 14); c.lineTo(x, 21); c.lineTo(x + 6, 14); c.stroke(); }
            else if (k === 'sent') { c.strokeStyle = '#35c8ff'; c.beginPath(); c.moveTo(x, 22); c.lineTo(x, 8); c.moveTo(x - 6, 14); c.lineTo(x, 7); c.lineTo(x + 6, 14); c.stroke(); }
            else if (k === 'ai') { hex(c, x, 14, 9); c.stroke(); }
            else if (k === 'seq') { c.beginPath(); c.arc(x, 14, 8, -Math.PI * .8, Math.PI * .3); c.stroke(); }
            else { c.fillStyle = '#ffc247'; c.strokeStyle = '#ffe08f'; c.beginPath(); c.arc(x, 14, 11, 0, TAU); c.fill(); c.stroke(); c.fillStyle = '#6e4200'; c.beginPath(); c.arc(x, 14, 3.2, 0, TAU); c.fill(); }
        }
        c.globalAlpha = 1;
    }
    const DRAW = {forbeast: dBot, botcake: dBot, incepxionbot: dBot, ai: dAI, seq: dFU, replies: dRep, avg: dAvg, chats: dAct, vault: dVault};
    function draw(t) {
        for (const p of PN) if (p.c) DRAW[p.id](p, t);
        dOrb(orbCv.c, t); dTk(now());
    }

    // ── ang stage: 1920 × 1080 na pinalaki sa screen, at ang mabagal na paglipat laban sa burn-in (hanggang 3 px, isang hakbang kada minuto) ──
    const DRIFT = [[0, 0], [2, 1], [3, -2], [1, 3], [-1, 2], [-3, 3], [-2, -1], [2, 2], [-1, -3], [0, 1]];
    let drift = 0, SC = 1, RS = 1, tkW = 1840;
    function alloc() {
        const z = el => phone() ? Math.min(1, (innerWidth - 32) / el) : 1;
        for (const p of PN) {
            const zz = z(p.w); p.el.style.zoom = phone() ? zz : '';
            const rs = phone() ? Math.max(1, (devicePixelRatio || 1) * zz) : RS;
            p.cv.width = Math.round(p.w * rs); p.cv.height = Math.round(p.h * rs); p.c = p.cv.getContext('2d'); p.c.setTransform(rs, 0, 0, rs, 0, 0);
        }
        orb.style.zoom = phone() ? z(700) : '';
        const ors = phone() ? Math.max(1, (devicePixelRatio || 1) * z(700)) : RS;
        orbCv.width = orbCv.height = Math.round(700 * ors); orbCv.c = orbCv.getContext('2d'); orbCv.c.setTransform(ors, 0, 0, ors, 0, 0);
        tkW = phone() ? Math.max(100, innerWidth - 32) : 1840;
        tkCv.width = Math.round(tkW * RS); tkCv.height = Math.round(28 * RS); tkCv.getContext('2d').setTransform(RS, 0, 0, RS, 0, 0);
    }
    function fit() {
        if (phone()) { stage.style.transform = ''; RS = 1; SC = 1; alloc(); dirty = true; draw(0); return; }
        SC = Math.min(innerWidth / 1920, innerHeight / 1080);
        const [dx, dy] = DRIFT[drift % DRIFT.length];
        stage.style.transform = 'translate(' + ((innerWidth - 1920 * SC) / 2 + dx) + 'px,' + ((innerHeight - 1080 * SC) / 2 + dy) + 'px) scale(' + SC + ')';
        const rs = Math.min(2, Math.max(1, (devicePixelRatio || 1) * SC));
        if (rs !== RS || !PN[0].c || PN[0].el.style.zoom) { RS = rs; alloc(); dBg(); }
        dirty = true;
    }
    let lastW = innerWidth;
    addEventListener('resize', () => { const was = lastW <= 900; lastW = innerWidth; if (was !== phone()) RS = 0; fit(); kick(); });
    setInterval(() => { drift++; if (!phone()) fit(); }, 60000);

    // ── ang animation loop: 30 frame kada segundo ang canvas; ang camera, packet at barya ay bawat frame ──
    let raf = 0, lastDraw = -1e9;
    function loop(t) {
        raf = 0;
        rollTick(now());
        if (!phone()) {
            const k0 = K;
            K = live.reduced ? want : K + (want - K) * .06;
            if (Math.abs(want - K) < .002) K = want;
            if (dirty || K !== k0 || (K > 0 && !live.reduced)) { layout(t); dirty = false; }
            packets(t);
        }
        stepCoins(now());
        if (t - lastDraw >= 33 || live.reduced) { draw(live.reduced ? 0 : t); lastDraw = t; }
        if (!live.reduced || Object.keys(rolls).length || bursts.length) raf = requestAnimationFrame(loop);
    }
    function kick() { if (!raf) raf = requestAnimationFrame(loop); }

    // ── ang mga card: hover, tap o focus; isa lang ang bukas ──
    let cardOn = null;
    function hideCard() { if (cardOn) { cardOn.setAttribute('hidden', ''); cardOn = null; } }
    function showCard(id, el) {
        const card = document.getElementById('lv-card-' + id);
        if (!card) return;
        if (cardOn && cardOn !== card) hideCard();
        card.removeAttribute('hidden'); cardOn = card;
        const sr = stage.getBoundingClientRect(), r = el.getBoundingClientRect(), s = phone() ? 1 : SC, w = card.offsetWidth, h = card.offsetHeight;
        let x, y;
        if (phone()) { x = 16; y = (r.bottom - sr.top) + 8; }
        else {
            x = ((r.left + r.right) / 2 < sr.left + sr.width / 2 ? r.right + 12 : r.left - w * s - 12);
            x = (x - sr.left) / s; y = (r.top - sr.top) / s;
            x = cl(x, 20, 1900 - w); y = cl(y, 84, 1040 - h);
        }
        card.style.left = x + 'px'; card.style.top = y + 'px';
    }
    const hooks = [...PN.map(p => [p.id, p.el]), ['core', orb.querySelector('.orbhit')], ['clock', root.querySelector('.clk')]];
    for (const [id, el] of hooks) {
        el.addEventListener('pointerenter', () => showCard(id, el));
        el.addEventListener('pointerleave', hideCard);
        el.addEventListener('focus', () => showCard(id, el));
        el.addEventListener('blur', hideCard);
        el.addEventListener('click', () => cardOn && cardOn.id === 'lv-card-' + id && document.activeElement !== el ? hideCard() : showCard(id, el));
    }

    const statusEl = root.querySelector('[data-live-status]');
    function setStatus(state, label) { statusEl.dataset.liveStatus = state; statusEl.querySelector('[data-live-state]').textContent = label; }
    // Ang tuldok ng status ay pumipintig isang beses kada matagumpay na poll (hindi walang-tigil), kaya totoo ang "buhay".
    function beat() { if (!live.reduced) for (const e of all('[data-beat]')) again(e, 'beat'); fire('avg', -1, '#e9fbff'); }
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
            setCounts(data.counts, data.now);
            for (const e of all('[data-live-updated]')) e.textContent = data.now;
            play(plan);
            setStatus('live', data.more > 0 ? 'LIVE · +' + R.num(data.more) : 'LIVE');
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
        if (document.hidden) { clearTimeout(live.timer); setStatus('hidden', 'PAUSED'); } else { setStatus('live', 'LIVE'); poll(); kick(); }
    });

    // ── ang header: filter sa likod ng funnel, 3D (naaalala ng browser na ito; 2D ang default), TV mode ──
    const fb = root.querySelector('[data-live-filter]'), fp = root.querySelector('#lv-filters');
    fb.addEventListener('click', () => { const o = fp.classList.toggle('on'); fb.setAttribute('aria-expanded', o ? 'true' : 'false'); });
    for (const s of all('[data-live-page], [data-live-bot]')) s.addEventListener('change', e => e.target.form.submit());
    const b3 = root.querySelector('[data-live-3d]');
    function view(mode, save) {
        want = mode === '3d' ? 1 : 0;
        b3.setAttribute('aria-pressed', want ? 'true' : 'false');
        b3.textContent = want ? '2D' : '3D';
        if (save) R.saveView(store, want ? '3d' : '2d');
        dirty = true; kick();
    }
    b3.addEventListener('click', () => view(want ? '2d' : '3d', true));
    const tv = root.querySelector('[data-live-fullscreen]');
    tv.addEventListener('click', () => {
        root.classList.add('tv'); hideCard();
        if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(() => {});
    });
    document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement) root.classList.remove('tv'); });
    addEventListener('keydown', e => { if (e.key === 'Escape') { root.classList.remove('tv'); hideCard(); if (fp.classList.contains('on')) fb.click(); } });
    function motion(reduced) { live.reduced = reduced; dirty = true; kick(); }
    if (mq && mq.addEventListener) mq.addEventListener('change', e => motion(e.matches));

    // Ang orasan ng Manila, kada segundo; ang mga bilang na hinihintay ang barya ay sinusuri rin kada segundo; sa reduced motion
    // ay dito iginuguhit ang frame (ang radar ay data pa rin: ang tuldok ay lumalayo sa gitna bawat segundo).
    const clock = root.querySelector('[data-live-clock]');
    function second() {
        try { clock.textContent = new Date().toLocaleTimeString('en-GB', {timeZone: 'Asia/Manila', hour12: false}); } catch (e) { /* ang huling oras ng server ang naiiwan */ }
        if (['orders', 'peso'].some(k => live.st[k] && live.st[k].heldSince !== null)) settleAll();
        if (live.reduced || phone()) kick();
    }
    live.play = events => play(schedule(events)); live.setCounts = setCounts; live.view = view; live.panels = PN;

    fit();
    view(want ? '3d' : '2d', false);
    setCounts(boot.counts, boot.now);
    second();
    setInterval(second, 1000);
    kick();
    live.timer = setTimeout(poll, boot.interval);
})();
</script>
@endpush
