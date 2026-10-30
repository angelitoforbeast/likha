<?php

/*
 * Gumagawa ng docs/specs/200-evidence/live.html: ang totoong view ng /chatbot/live (resources/views/chatbot/live.blade.php)
 * na may pekeng datos na kahugis ng totoo (walang database), ang chatbot-live-rules.js na nakapaloob, at isang pekeng feed
 * (pinapalitan ang fetch) na nagbibigay ng mga event at notes bawat poll: mensahe, sagot, AI, follow-up, at order na may
 * halaga, walang halaga, o sunod-sunod. Kaya ang mismong script ng page ang nagpapatakbo ng loot, ng notes at ng 3D.
 * Bukas nang mag-isa (file://). Ang mga pangalan ng customer ay gawa-gawa lang.
 *
 * URL: ?now[=ms] = unang poll pagkalipas ng 0.3 s o ng ms (para sa screenshot); ?shot = frame bilang timer (headless); ?d3 = bukas sa 3D; ?noamt = ang unang order ay walang COD pa.
 * Patakbuhin mula sa root ng repo: php docs/specs/200-evidence/build.php
 */

require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['session.driver' => 'array', 'app.url' => 'https://likhaaitech.com']);
app('session')->setId(str_repeat('a', 40));
app('session')->start();

$bots = fn ($f, $i, $b, $u) => ['forbeast' => $f, 'incepxionbot' => $i, 'botcake' => $b, 'unknown' => $u];
$cust = fn (string $name, string $tag, string $at) => ['name' => $name, 'tag' => $tag, 'at' => $at];
$counts = [
    'messages' => ['today' => 12293, 'recent' => 61], 'confirmed' => ['today' => 17896, 'recent' => 222], 'unconfirmed' => ['today' => 12, 'recent' => 3],
    'follow_ups' => ['today' => 22699, 'recent' => 74], 'ai' => ['today' => 9421, 'recent' => 40], 'orders' => ['today' => 262, 'recent' => 3],
    'bots' => ['in' => $bots(11582, 0, 711, 0), 'out' => $bots(17893, 0, 3, 0)], 'active' => 71,
    'reply_time' => ['state' => 'ok', 'seconds' => 5.4], 'last_order' => ['page' => 'Sample Page 07', 'at' => '11:52', 'bot' => 'forbeast'],
    'order_value' => ['today' => 62456, 'recent' => 1797],
    'sequences' => [['name' => 'Preorder Sequence', 'n' => 14210], ['name' => 'Abandoned Cart', 'n' => 5871], ['name' => 'Restock Notice', 'n' => 2618]],
    'linked' => ['forbeast' => true, 'incepxionbot' => false],
    'notes' => [
        'forbeast' => ['linked' => true, 'chatting' => 68, 'ai' => 9418, 'avg' => 5.4, 'orders' => 260, 'value' => 61857, 'last' => ['type' => 'message', 'page' => 'Sample Page 12', 'at' => '11:52'],
            'customers' => [$cust('Liza Fernandez', 'ORDERED', '11:52'), $cust('Jose Dela Cruz', 'CHATTING', '11:52'), $cust('Jenny Ramos', 'AI', '11:51'), $cust('Carlo Mendoza', 'CHATTING', '11:51')]],
        'incepxionbot' => ['linked' => false, 'chatting' => 0, 'ai' => 0, 'avg' => null, 'orders' => 0, 'value' => 0, 'last' => null, 'customers' => []],
        'botcake' => ['linked' => true, 'chatting' => 3, 'ai' => 3, 'avg' => null, 'orders' => 2, 'value' => 599, 'last' => ['type' => 'message', 'page' => 'Sample Page 15', 'at' => '11:49'],
            'customers' => [$cust('Mark Bautista', 'CHATTING', '11:50'), $cust('Ana Reyes', 'AI', '11:49'), $cust('Ryan Aquino', 'CHATTING', '11:47'), $cust('Grace Villanueva', 'AI', '11:44')]],
    ],
    'as_of' => '11:52:20',
];
$feed = ['counts' => $counts, 'events' => [], 'more' => 0, 'cursor' => ['chat' => '1', 'seq' => '1', 'order' => '1', 'ok' => '2026-10-30 11:52:15|0', 'ai' => '2026-10-30 11:52:15|0'], 'now' => '11:52:20'];
$pages = ['Sample Page 03' => '1003', 'Sample Page 07' => '1007', 'Sample Page 12' => '1012', 'Sample Page 15' => '1015'];

$html = view('chatbot.live', ['feed' => $feed, 'pages' => $pages, 'page' => null, 'bot' => null])->render();
$rules = file_get_contents(__DIR__ . '/../../../public/js/chatbot-live-rules.js');
$html = preg_replace('#<script src="[^"]*chatbot-live-rules\.js"></script>#', '<script>' . str_replace('$', '\$', $rules) . '</script>', $html, 1, $n);
if ($n !== 1) throw new RuntimeException('rules script tag not found');
$html = preg_replace('#<meta name="csrf-token" content="[^"]*">#', '<meta name="csrf-token" content="evidence">', $html);

// Ang pekeng feed: tumatakbo bago ang script ng page; bawat poll ay may mga event, at ang counts at notes ay sumusunod sa mga ito.
$sim = <<<'JS'
<script>
// EVIDENCE ONLY: pekeng /chatbot/live/feed. Bawat poll (5 s): ilang mensahe at sagot, AI, minsan follow-up; isang order bawat
// poll (salit-salit: may COD, walang COD pa, at isang sunod-sunod na 3 order). Ang notes ng Bot 1 at Botcake ay sumusunod:
// ang customer na nag-message ay umaakyat sa itaas, ang may order ay ORDERED. Gawa-gawa ang mga pangalan.
(() => {
  const Q = new URLSearchParams(location.search);
  // ?shot: ang headless Chrome na may virtual time ay halos hindi nagpapatakbo ng requestAnimationFrame; para sa screenshot lang,
  // ang frame ay timer na 16 ms (ang parehong code ng page ang tumatakbo).
  if (Q.has('shot')) window.requestAnimationFrame = cb => setTimeout(() => cb(performance.now()), 16);
  const pages = ['Sample Page 03', 'Sample Page 07', 'Sample Page 12', 'Sample Page 15'];
  const names = ['Maria Santos', 'Paolo Garcia', 'Nina Castillo', 'Rey Navarro', 'Jose Dela Cruz', 'Carlo Mendoza', 'Jenny Ramos', 'Liza Fernandez', 'Mark Bautista', 'Ana Reyes'];
  let id = 100, n = 0;
  const pad = v => String(v).padStart(2, '0');
  const clock = () => { const d = new Date(); return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); };
  const pick = a => a[Math.floor(Math.random() * a.length)];
  const top = (note, c) => { note.customers = [c, ...note.customers.filter(x => x.name !== c.name)].slice(0, 4); };
  window.fetch = async () => {
    const c = JSON.parse(JSON.stringify(window.likhaLive.counts)), ev = [], t = clock(), at = '2026-10-30 ' + t, hm = t.slice(0, 5);
    n++;
    const add = (type, bot, extra = {}) => { const e = Object.assign({type, at, page: pick(pages), id: ++id, bot}, extra); ev.push(e); return e; };
    for (let i = 0; i < 3; i++) {
      const bot = Math.random() < .2 ? 'botcake' : 'forbeast', note = c.notes[bot], e = add('message', bot);
      c.messages.today++; c.bots.in[bot]++;
      const ai = Math.random() < .45;
      if (ai) { add('ai', null); c.ai.today++; note.ai++; }
      add('confirmed', bot); c.confirmed.today++; c.bots.out[bot]++;
      top(note, {name: pick(names), tag: ai ? 'AI' : 'CHATTING', at: hm});
      note.last = {type: 'message', page: e.page, at: hm};
    }
    if (n % 2 === 0) { add('follow_up', 'forbeast'); c.follow_ups.today++; }
    const order = (amount, bot = 'forbeast') => {
      const e = add('order', bot, {amount}), note = c.notes[bot];
      c.orders.today++; note.orders++;
      if (amount !== null) { c.order_value.today += amount; note.value += amount; }
      top(note, {name: pick(names), tag: 'ORDERED', at: hm});
      note.last = {type: 'order', page: e.page, at: hm};
      c.last_order = {page: e.page, at: hm, bot};
    };
    const k = n % 4;
    if (k === 1) order(Q.has('noamt') ? null : 599);
    else if (k === 2) order(null, 'botcake');
    else if (k === 3) { order(1149); order(null); order(2050); }
    c.orders.recent = Math.min(9, c.orders.recent + (k ? 1 : 0));
    return {status: 200, ok: true, json: async () => ({counts: c, events: ev, more: 0, cursor: window.likhaLive.cursor, now: t})};
  };
})();
</script>
JS;
$html = str_replace('</head>', $sim . "\n</head>", $html);
// Unang poll agad (?now) para makita ang loot sa screenshot; ?d3 ay bukas sa 3D.
$html = str_replace('live.timer = setTimeout(poll, boot.interval);', 'live.timer = setTimeout(poll, new URLSearchParams(location.search).has("now") ? (+new URLSearchParams(location.search).get("now") || 300) : boot.interval);'
    . ' if (new URLSearchParams(location.search).has("d3")) view("3d", false);', $html, $m);
if ($m !== 1) throw new RuntimeException('poll timer not found');

file_put_contents(__DIR__ . '/live.html', $html);
echo 'wrote ' . strlen($html) . " bytes\n";
