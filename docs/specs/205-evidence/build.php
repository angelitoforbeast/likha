<?php

/*
 * Gumagawa ng docs/specs/205-evidence/live.html: ang totoong view ng /chatbot/live (resources/views/chatbot/live.blade.php)
 * na may pekeng datos na kahugis ng totoo (walang database), ang chatbot-live-rules.js na nakapaloob, at isang pekeng feed
 * (pinapalitan ang fetch) na nagbibigay ng mga event, counts at radar bawat poll: mga bagong customer (blip na may pangalan),
 * mensahe, sagot, AI, follow-up, at order na may halaga, walang halaga, o sunod-sunod. Kaya ang mismong script ng page ang
 * nagpapatakbo ng radar, loot at 3D. Bukas nang mag-isa (file://). Gawa-gawa ang mga pangalan.
 *
 * URL: ?now[=ms] = unang poll pagkalipas ng 0.3 s o ng ms; ?shot = frame bilang timer (headless); ?d3 = bukas sa 3D;
 * ?card=<panel> = naka-focus ang panel at bukas ang card nito;
 * ?orbit=<ms> = idinadagdag sa oras ng frame (ang dulo ng ugoy ng 3D); ?hit = sinusukat ang overlap ng mga panel at kung nasa
 * screen ang lahat (sa data-hit ng <html>); ?wc = binibilang ang salita at numero na nakikita (sa data-wc ng <html>);
 * ?save3d = may naka-save na 3D sa browser bago bumukas ang page; ?tv = TV mode pagbukas (gaya ng pindot sa TV, walang
 * fullscreen); ?lbl[=ms] = ang taas sa screen ng bawat label ng panel (ang malayong gilid, sa data-lbl ng <html>), at ang
 * ipinapakita (K, sa data-view).
 * Patakbuhin mula sa root ng repo: php docs/specs/205-evidence/build.php
 */

require __DIR__ . '/../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['session.driver' => 'array', 'app.url' => 'https://likhaaitech.com']);
app('session')->setId(str_repeat('a', 40));
app('session')->start();

$bots = fn ($f, $i, $b, $u) => ['forbeast' => $f, 'incepxionbot' => $i, 'botcake' => $b, 'unknown' => $u];
$cust = fn (string $name, string $tag, string $at) => ['name' => $name, 'tag' => $tag, 'at' => $at];
// Ang radar sa simula: 68 customer ng Bot 1 at 3 ng Botcake sa huling 5 minuto (luma na lahat: walang blip sa unang laman).
$dot = fn (int $i, int $ago, string $s) => ['k' => substr(md5('c' . $i), 0, 12), 'ago' => $ago, 's' => $s, 'new' => false, 'name' => null];
$b1 = [];
for ($i = 0; $i < 68; $i++) $b1[] = $dot($i, (int) (4 + $i * 4.3), $i % 23 === 5 ? 'order' : ($i % 5 === 2 ? 'ai' : 'chat'));
$bc = [$dot(900, 40, 'ai'), $dot(901, 130, 'order'), $dot(902, 220, 'chat')];
$counts = [
    'messages' => ['today' => 12293, 'recent' => 61], 'confirmed' => ['today' => 17896, 'recent' => 222], 'unconfirmed' => ['today' => 12, 'recent' => 3],
    'follow_ups' => ['today' => 22699, 'recent' => 74], 'ai' => ['today' => 9421, 'recent' => 40], 'orders' => ['today' => 270, 'recent' => 3],
    'ai_minute' => [3, 2, 4, 1, 2, 1], 'order_split' => ['other' => 8, 'no_amount' => 31],
    'bots' => ['in' => $bots(11582, 0, 711, 0), 'out' => $bots(17893, 0, 3, 0)], 'active' => 71,
    'reply_time' => ['state' => 'ok', 'seconds' => 5.4], 'last_order' => ['page' => 'Sample Page 07', 'at' => '11:52', 'bot' => 'forbeast'],
    'order_value' => ['today' => 62456, 'recent' => 1797],
    'sequences' => [['name' => 'Preorder Sequence', 'n' => 14210], ['name' => 'Abandoned Cart', 'n' => 5871], ['name' => 'Restock Notice', 'n' => 2618]],
    'linked' => ['forbeast' => true, 'incepxionbot' => false],
    'notes' => [
        'forbeast' => ['linked' => true, 'chatting' => 68, 'ai' => 9418, 'avg' => 5.4, 'orders' => 260, 'value' => 61857, 'last' => ['type' => 'message', 'page' => 'Sample Page 12', 'at' => '11:52'],
            'customers' => [$cust('Liza F.', 'ORDERED', '11:52'), $cust('Jose C.', 'CHATTING', '11:52'), $cust('Jenny R.', 'AI', '11:51'), $cust('Carlo M.', 'CHATTING', '11:51')]],
        'incepxionbot' => ['linked' => false, 'chatting' => 0, 'ai' => 0, 'avg' => null, 'orders' => 0, 'value' => 0, 'last' => null, 'customers' => []],
        'botcake' => ['linked' => true, 'chatting' => 3, 'ai' => 3, 'avg' => null, 'orders' => 2, 'value' => 599, 'last' => ['type' => 'message', 'page' => 'Sample Page 15', 'at' => '11:49'],
            'customers' => [$cust('Mark B.', 'CHATTING', '11:50'), $cust('Ana R.', 'AI', '11:49'), $cust('Ryan A.', 'CHATTING', '11:47')]],
    ],
    'radar' => ['forbeast' => $b1, 'incepxionbot' => [], 'botcake' => $bc],
    'as_of' => '11:52:20',
];
$feed = ['counts' => $counts, 'events' => [], 'more' => 0, 'cursor' => ['chat' => '1', 'seq' => '1', 'order' => '1', 'ok' => '2026-10-30 11:52:15|0', 'ai' => '2026-10-30 11:52:15|0'], 'now' => '11:52:20'];
$pages = ['Sample Page 03' => '1003', 'Sample Page 07' => '1007', 'Sample Page 12' => '1012', 'Sample Page 15' => '1015'];

$html = view('chatbot.live', ['feed' => $feed, 'pages' => $pages, 'page' => null, 'bot' => null])->render();
$rules = file_get_contents(__DIR__ . '/../../../public/js/chatbot-live-rules.js');
$html = preg_replace('#<script src="[^"]*chatbot-live-rules\.js\?v=[0-9a-f]+"></script>#', '<script>' . str_replace('$', '\$', $rules) . '</script>', $html, 1, $n);
if ($n !== 1) throw new RuntimeException('rules script tag not found');
$html = preg_replace('#<meta name="csrf-token" content="[^"]*">#', '<meta name="csrf-token" content="evidence">', $html);

$sim = <<<'JS'
<script>
// EVIDENCE ONLY: pekeng /chatbot/live/feed. Bawat poll (5 s): ilang mensahe at sagot, AI, minsan follow-up, isa o dalawang bagong
// customer (blip na may pangalan); isang order bawat poll (salit-salit: may COD, walang COD pa, at isang sunod-sunod na 3 order).
// Ang radar ay sumusunod: ang customer na nag-message ay bumabalik sa gitna, ang may order ay ginto. Gawa-gawa ang mga pangalan.
(() => {
  const Q = new URLSearchParams(location.search);
  window.addEventListener('error', e => { document.documentElement.dataset.err = (document.documentElement.dataset.err || '') + e.message + ' @' + e.lineno + '; '; });
  if (Q.has('shot')) { const off = +Q.get('orbit') || 0; window.requestAnimationFrame = cb => setTimeout(() => cb(performance.now() + off), 16); }
  const pages = ['Sample Page 03', 'Sample Page 07', 'Sample Page 12', 'Sample Page 15'];
  const names = ['Maria S.', 'Paolo G.', 'Nina C.', 'Rey N.', 'Grace P.', 'Carlo M.', 'Jenny R.', 'Liza F.', 'Mark B.', 'Ana R.'];
  let id = 100, n = 0, cid = 2000;
  const pad = v => String(v).padStart(2, '0');
  const clock = () => { const d = new Date(); return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); };
  const pick = a => a[Math.floor(Math.random() * a.length)];
  // Ang mga customer ng sim: k => {lastAt (ms), s, new, name}.
  const crowd = {forbeast: new Map(), botcake: new Map()}, t0 = Date.now();
  window.addEventListener('DOMContentLoaded', () => {
    for (const bot of ['forbeast', 'botcake']) for (const d of window.likhaLive.counts.radar[bot]) crowd[bot].set(d.k, {lastAt: t0 - d.ago * 1000, s: d.s, new: false, name: null});
  });
  const radarOf = bot => [...crowd[bot].entries()].map(([k, d]) => ({k, ago: Math.round((Date.now() - d.lastAt) / 1000), s: d.s, new: d.new, name: d.new ? d.name : null}))
    .filter(d => d.ago <= 300).sort((a, b) => a.ago - b.ago);
  window.fetch = async () => {
    const c = JSON.parse(JSON.stringify(window.likhaLive.counts)), ev = [], t = clock(), at = '2026-10-30 ' + t, hm = t.slice(0, 5);
    n++;
    const add = (type, bot, extra = {}) => { const e = Object.assign({type, at, page: pick(pages), id: ++id, bot}, extra); ev.push(e); return e; };
    let fresh = null;
    for (let i = 0; i < (n % 2 ? 2 : 1); i++) { fresh = 'n' + (cid++); crowd.forbeast.set(fresh, {lastAt: Date.now(), s: 'chat', new: true, name: names[(cid + i) % names.length]}); add('message', 'forbeast'); c.messages.today++; c.bots.in.forbeast++; }
    for (let i = 0; i < 3; i++) {
      const bot = Math.random() < .15 ? 'botcake' : 'forbeast', e = add('message', bot), list = [...crowd[bot].keys()], k = pick(list), d = crowd[bot].get(k);
      d.lastAt = Date.now(); c.messages.today++; c.bots.in[bot]++;
      if (Math.random() < .45) { add('ai', null); c.ai.today++; c.notes[bot].ai++; if (d.s === 'chat') d.s = 'ai'; }
      add('confirmed', bot); c.confirmed.today++; c.bots.out[bot]++;
      c.notes[bot].last = {type: 'message', page: e.page, at: hm};
    }
    if (n % 2 === 0) { add('follow_up', 'forbeast'); c.follow_ups.today++; c.sequences[0].n++; }
    const order = (amount, bot = 'forbeast') => {
      const e = add('order', bot, {amount}), note = c.notes[bot];
      c.orders.today++; note.orders++;
      if (amount !== null) { c.order_value.today += amount; note.value += amount; } else c.order_split.no_amount++;
      const k = bot === 'forbeast' && fresh ? fresh : pick([...crowd[bot].keys()]);
      crowd[bot].get(k).s = 'order'; crowd[bot].get(k).lastAt = Date.now();
      note.last = {type: 'order', page: e.page, at: hm};
      c.last_order = {page: e.page, at: hm, bot};
    };
    const q = n % 4;
    if (q === 1) order(Q.has('noamt') ? null : 599);
    else if (q === 2) order(null, 'botcake');
    else if (q === 3) { order(1149); order(null); order(2050); }
    c.orders.recent = Math.min(9, c.orders.recent + (q ? 1 : 0));
    c.radar = {forbeast: radarOf('forbeast'), incepxionbot: [], botcake: radarOf('botcake')};
    c.notes.forbeast.chatting = c.radar.forbeast.length; c.notes.botcake.chatting = c.radar.botcake.length; c.active = c.radar.forbeast.length + c.radar.botcake.length;
    for (const bot of ['forbeast', 'botcake']) for (const d of crowd[bot].values()) d.new = d.new && Date.now() - d.lastAt < 300000;
    c.as_of = t;
    return {status: 200, ok: true, json: async () => ({counts: c, events: ev, more: 0, cursor: window.likhaLive.cursor, now: t})};
  };
  // ?save3d: ang browser ay may naka-save na 3D bago bumukas ang page. ?tv: TV mode pagbukas (ang setTv ng page, walang fullscreen).
  if (Q.has('save3d')) try { localStorage.setItem('likha-live-view', '3d'); } catch (e) { document.documentElement.dataset.err = 'no localStorage'; }
  if (Q.has('tv')) window.addEventListener('DOMContentLoaded', () => window.likhaLive.tv(true));
  // ?lbl: ang taas sa screen (px ng 1920 × 1080) ng bawat label ng panel, sa malayong gilid nito (ang mas maliit sa kaliwa at kanan,
  // dahil sa 3D ay nakatagilid ang panel); line-height 1, kaya ang taas ng kahon ay ang laki ng font.
  if (Q.has('lbl')) setTimeout(() => {
    const sr = document.querySelector('[data-live-stage]').getBoundingClientRect(), s = sr.width / 1920, out = [];
    for (const el of document.querySelectorAll('[data-pn] .lb, .orb .core .lb, .orb .nm-core-name')) {
      if (!el.getBoundingClientRect().height) continue;
      if (getComputedStyle(el).position === 'static') el.style.position = 'relative';
      const hs = ['left', 'right'].map(side => { const p = document.createElement('i'); p.style.cssText = 'position:absolute;top:0;width:0;height:100%;' + side + ':0';
        el.appendChild(p); const h = p.getBoundingClientRect().height / s; p.remove(); return h; });
      out.push([(el.closest('[data-pn]') ? el.closest('[data-pn]').dataset.pn : 'core') + ':' + el.textContent.trim(), Math.round(Math.min(...hs) * 10) / 10]);
    }
    const min = Math.min(...out.map(x => x[1]));
    document.documentElement.dataset.lbl = JSON.stringify({min, labels: out.map(x => x.join('=')).join(' ')});
  }, +Q.get('lbl') || 4000);
  if (Q.has('lbl') || Q.has('tv')) setTimeout(() => {
    let saved = null; try { saved = localStorage.getItem('likha-live-view'); } catch (e) { saved = 'none'; }
    document.documentElement.dataset.view = JSON.stringify({saved, tv: document.querySelector('[data-live]').classList.contains('tv'), K: Math.round(window.likhaLive.shown() * 1000) / 1000});
  }, +Q.get('lbl') || 3000);
  // ?card=<panel>: naka-focus ang panel (gaya ng Tab), kaya nakikita ang card nito.
  if (Q.has('card')) setTimeout(() => { const el = Q.get('card') === 'core' ? document.querySelector('.orbhit') : document.querySelector('[data-pn="' + Q.get('card') + '"]'); el.focus(); }, 2600);
  // ?hit: bawat 6 px ng 1920 × 1080, ilang panel ang nasa puntong iyon (overlap = 2 o higit), at kung nasa screen ang bawat panel.
  if (Q.has('hit')) setTimeout(() => {
    const pn = [...document.querySelectorAll('[data-pn]')];
    let pts = 0, over = 0; const pairs = new Set();
    for (let y = 0; y < innerHeight; y += 6) for (let x = 0; x < innerWidth; x += 6) {
      const hit = new Set(document.elementsFromPoint(x, y).map(e => e.closest('[data-pn]')).filter(Boolean));
      pts++; if (hit.size > 1) { over++; pairs.add([...hit].map(e => e.dataset.pn).sort().join('+')); }
    }
    // Sa 1920 × 1080 ng stage (hindi ng window): ang panel ay dapat buo sa pagitan ng header (76 px) at ng 1046 px (ang dating
    // ticker; wala na ito, pero ang hangganan ay pinanatili para pareho ang sukat sa S-205.7).
    const sr = document.querySelector('[data-live-stage]').getBoundingClientRect(), s = sr.width / 1920;
    const box = pn.map(e => { const r = e.getBoundingClientRect(); return [e.dataset.pn, [(r.left - sr.left) / s, (r.top - sr.top) / s, (r.right - sr.left) / s, (r.bottom - sr.top) / s].map(Math.round)]; });
    const off = box.filter(([, b]) => b[0] < 0 || b[1] < 76 || b[2] > 1920 || b[3] > 1046).map(([k, b]) => k + ':' + b.join(','));
    document.documentElement.dataset.hit = JSON.stringify({orbit: Math.round((window.likhaLive.orbit || 0) * 100) / 100, points: pts, overlapping: over, pairs: [...pairs], outside: off, boxes: box.map(([k, b]) => k + ':' + b.join(','))});
  }, +Q.get('hit') || 2500);
  // ?wc: ang nakikitang text (DOM na hindi nakatago, laman ng viewport) at ang pangalan sa canvas (fillText), bilang ng salita at numero.
  if (Q.has('wc')) {
    const drawn = [];
    const orig = CanvasRenderingContext2D.prototype.fillText;
    CanvasRenderingContext2D.prototype.fillText = function (s, ...a) { if (this.globalAlpha > .05) drawn.push(String(s)); return orig.call(this, s, ...a); };
    setTimeout(() => {
      drawn.length = 0;
      requestAnimationFrame(() => setTimeout(() => {
        const vis = [];
        const walk = n => {
          for (const ch of n.childNodes) {
            if (ch.nodeType === 3) { const p = ch.parentElement, r = p.getBoundingClientRect(), cs = getComputedStyle(p);
              if (ch.textContent.trim() && r.width && r.bottom > 0 && r.top < innerHeight && cs.visibility !== 'hidden' && +cs.opacity > .05 && !p.closest('[hidden],script,style,noscript,.fp:not(.on),option')) vis.push(ch.textContent.trim()); }
            else if (ch.nodeType === 1) walk(ch);
          }
        };
        walk(document.body);
        const toks = [...vis, ...drawn].join(' ').split(/\s+/).filter(Boolean);
        const nums = toks.filter(x => /\d/.test(x));
        document.documentElement.dataset.wc = JSON.stringify({tokens: toks.length, numbers: nums.length, words: toks.length - nums.length, text: toks.join(' ')});
      }, 40));
    }, +Q.get('wc') || 2500);
  }
})();
</script>
JS;
$html = str_replace('</head>', $sim . "\n</head>", $html);
$html = str_replace('live.timer = setTimeout(poll, boot.interval);', 'live.timer = setTimeout(poll, new URLSearchParams(location.search).has("now") ? (+new URLSearchParams(location.search).get("now") || 300) : boot.interval);'
    . ' if (new URLSearchParams(location.search).has("d3")) view("3d", false);', $html, $m);
if ($m !== 1) throw new RuntimeException('poll timer not found');

file_put_contents(__DIR__ . '/live.html', $html);
echo 'wrote ' . strlen($html) . " bytes\n";
