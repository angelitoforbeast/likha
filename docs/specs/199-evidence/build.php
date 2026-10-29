<?php

/*
 * Gumagawa ng docs/specs/199-evidence/live.html: ang totoong view ng /chatbot/live (resources/views/chatbot/live.blade.php)
 * na may pekeng datos na kahugis ng totoo (walang database), ang chatbot-live-rules.js na nakapaloob, at isang pekeng feed
 * (pinapalitan ang fetch) na nagbibigay ng mga event bawat poll: mensahe, sagot, AI, follow-up, at order na may halaga, walang
 * halaga, o sunod-sunod. Kaya ang mismong script ng page ang nagpapatakbo ng money animation. Bukas nang mag-isa (file://).
 *
 * Patakbuhin mula sa root ng repo: php docs/specs/199-evidence/build.php
 */

require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['session.driver' => 'array', 'app.url' => 'https://likhaaitech.com']);
app('session')->setId(str_repeat('a', 40));
app('session')->start();

$bots = fn ($f, $i, $b, $u) => ['forbeast' => $f, 'incepxionbot' => $i, 'botcake' => $b, 'unknown' => $u];
$counts = [
    'messages' => ['today' => 7912, 'recent' => 46], 'confirmed' => ['today' => 6412, 'recent' => 40], 'unconfirmed' => ['today' => 12, 'recent' => 3],
    'follow_ups' => ['today' => 12305, 'recent' => 61], 'ai' => ['today' => 3087, 'recent' => 22], 'orders' => ['today' => 37, 'recent' => 2],
    'bots' => ['in' => $bots(7704, 0, 208, 0), 'out' => $bots(6228, 0, 184, 0)], 'active' => 14,
    'reply_time' => ['state' => 'ok', 'seconds' => 7.5], 'last_order' => ['page' => 'Sample Page 03', 'at' => '14:26', 'bot' => 'forbeast'],
    'order_value' => ['today' => 41860, 'recent' => 2298],
    'sequences' => [['name' => 'Pa-remind sa COD', 'n' => 5120], ['name' => 'Last call promo', 'n' => 3904], ['name' => 'Bumalik po kayo', 'n' => 1877]],
    'linked' => ['forbeast' => true, 'incepxionbot' => false], 'as_of' => '14:26:23',
];
$feed = ['counts' => $counts, 'events' => [], 'more' => 0, 'cursor' => ['chat' => '1', 'seq' => '1', 'order' => '1', 'ok' => '2026-10-30 14:26:18|0', 'ai' => '2026-10-30 14:26:18|0'], 'now' => '14:26:23'];
$pages = ['Sample Page 03' => '1003', 'Sample Page 07' => '1007', 'Sample Page 11' => '1011', 'Sample Page 12' => '1012'];

$html = view('chatbot.live', ['feed' => $feed, 'pages' => $pages, 'page' => null, 'bot' => null])->render();
$rules = file_get_contents(__DIR__ . '/../../../public/js/chatbot-live-rules.js');
$html = preg_replace('#<script src="[^"]*chatbot-live-rules\.js"></script>#', '<script>' . str_replace('$', '\$', $rules) . '</script>', $html, 1, $n);
if ($n !== 1) throw new RuntimeException('rules script tag not found');
$html = preg_replace('#<meta name="csrf-token" content="[^"]*">#', '<meta name="csrf-token" content="evidence">', $html);

// Ang pekeng feed: tumatakbo bago ang script ng page; bawat poll ay may mga event, at ang counts ay sumusunod sa mga ito.
$sim = <<<'JS'
<script>
// EVIDENCE ONLY: pekeng /chatbot/live/feed. Bawat poll (5 s): ilang mensahe at sagot, AI, minsan follow-up; isang order bawat
// poll (salit-salit: may COD, walang COD pa, at isang sunod-sunod na 3 order), at ang COD ng naunang order na walang halaga ay
// dumarating sa susunod na poll (ang kabuuan lang ang umaakyat, walang pangalawang packet).
(() => {
  const pages = ['Sample Page 03', 'Sample Page 07', 'Sample Page 11', 'Sample Page 12'];
  let id = 100, n = 0, late = 0;
  const pad = v => String(v).padStart(2, '0');
  const stamp = () => { const d = new Date(); return '2026-10-30 ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); };
  const pick = a => a[Math.floor(Math.random() * a.length)];
  window.fetch = async () => {
    const c = JSON.parse(JSON.stringify(window.likhaLive.counts)), ev = [], at = stamp();
    n++;
    const add = (type, bot, extra = {}) => ev.push(Object.assign({type, at, page: pick(pages), id: ++id, bot}, extra));
    for (let i = 0; i < 3; i++) {
      const bot = Math.random() < .2 ? 'botcake' : 'forbeast';
      add('message', bot); c.messages.today++; c.bots.in[bot]++;
      if (Math.random() < .45) { add('ai', null); c.ai.today++; }
      add('confirmed', bot); c.confirmed.today++; c.bots.out[bot]++;
    }
    if (n % 2 === 0) { add('follow_up', 'forbeast'); c.follow_ups.today++; }
    if (late) { c.order_value.today += late; late = 0; }
    const order = (amount, bot = 'forbeast') => { add('order', bot, {amount}); c.orders.today++; if (amount !== null) c.order_value.today += amount; };
    const k = n % 4;
    if (k === 1) order(499 + Math.floor(Math.random() * 20) * 50);
    else if (k === 2) { order(null, 'botcake'); late = 1299; }
    else if (k === 3) { order(1149); order(null); order(2050); }
    c.last_order = {page: ev.filter(e => e.type === 'order').map(e => e.page).pop() || c.last_order.page, at: at.slice(11, 16), bot: 'forbeast'};
    c.orders.recent = Math.min(9, c.orders.recent + (k ? 1 : 0));
    const d = new Date();
    return {status: 200, ok: true, json: async () => ({counts: c, events: ev, more: 0, cursor: window.likhaLive.cursor, now: pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())})};
  };
})();
</script>
JS;
$html = str_replace('</head>', $sim . "\n</head>", $html);
// Unang poll agad (hindi na hinihintay ang 5 s) para makita ang packet sa screenshot.
$html = str_replace('live.timer = setTimeout(poll, boot.interval);', 'live.timer = setTimeout(poll, new URLSearchParams(location.search).has("now") ? 300 : boot.interval);', $html);

file_put_contents(__DIR__ . '/live.html', $html);
echo 'wrote ' . strlen($html) . " bytes\n";
