@props(['user'])

{{--
  Likha Chat sa bawat page (spec 300, MP-1 at MP-2). Isinasama ng layout para lang sa naka-login.
  Bawat 15 s (hinto kapag nakatago ang tab, agad kapag bumalik) binabasa ang /chat/unread?alerts=1:
   - ang pulang bilang sa Chat link ng sidebar (rail at drawer din) at "(3) " sa title ng tab;
   - tunog at browser notification para sa bagong message sa conversation na hindi bukas sa screen.
  Hindi humihingi ng pahintulot para sa notification dito: sa button lang ng chat page. Ang tunog (bukas o sarado) at
  ang huling na-alert na id ay nasa browser na ito, bawat user.
--}}
@php
  $chatUnreadCfg = ['user' => (int) $user->id, 'poll_ms' => (int) config('chat.unread_poll_ms', 15000), 'url' => '/chat/unread'];
@endphp
<script data-chat-unread>
(() => {
  const CFG = @json($chatUnreadCfg, JSON_UNESCAPED_SLASHES);
  const KEY_LAST = `likha.chat.alerted.${CFG.user}`;
  const KEY_SOUND = `likha.chat.sound.${CFG.user}`;
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } },
  };
  let timer = null; let busy = false; let stopped = false; let audio = null;

  const plain = () => document.title.replace(/^\(\d+\+?\)\s/, '');
  // The sidebar's Chat link (spec 295: data-nav-link is its url) and its copy in the Pinned group.
  const CHAT_URLS = ['/chat', 'chat'];
  function links() {
    return [...document.querySelectorAll('a[data-nav-link], .lk-pinned a.lk-link')]
      .filter((a) => CHAT_URLS.includes(a.dataset.navLink !== undefined ? a.dataset.navLink : a.getAttribute('href')));
  }
  // Ang pulang bilang sa Chat link (kapareho ng checklist badge) at ang pulang tuldok sa icon para sa rail.
  function set(n) {
    n = Math.max(0, Number(n) || 0);
    const label = n > 99 ? '99+' : String(n);
    links().forEach((a) => {
      let badge = a.querySelector('[data-badge="chat"]');
      let dot = a.querySelector('[data-chat-dot]');
      if (!n) { if (badge) badge.remove(); if (dot) dot.remove(); return; }
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'lk-badge is-red';
        badge.dataset.badge = 'chat';
        a.append(badge);
      }
      badge.textContent = label;
      badge.dataset.count = String(n);
      badge.title = `${n} unread message${n === 1 ? '' : 's'}`;
      const icon = a.querySelector('.lk-icon');
      if (!dot && icon) {
        dot = document.createElement('span');
        dot.className = 'lk-dot is-red';
        dot.setAttribute('data-chat-dot', '');
        icon.append(dot);
      }
    });
    document.title = (n ? `(${label}) ` : '') + plain();
    // Spec 308 (WP-4): the installed app's icon shows the count where the browser supports it; elsewhere nothing.
    if ('setAppBadge' in navigator) {
      try { (n ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(() => {}); } catch (e) { /* not allowed here */ }
    }
  }

  const soundOn = () => store.get(KEY_SOUND) !== '0';
  // Isang maikli at mahinang dalawang nota (walang audio file).
  function chime() {
    if (!soundOn()) return;
    try {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      audio = audio || new Ctx();
      // Chrome starts it suspended until the person has touched the page; resume() works after any gesture.
      if (audio.state === 'suspended') audio.resume().catch(() => {});
      const t = audio.currentTime;
      [[660, 0], [990, 0.11]].forEach(([hz, at]) => {
        const osc = audio.createOscillator(); const gain = audio.createGain();
        osc.type = 'sine'; osc.frequency.value = hz;
        gain.gain.setValueAtTime(0.0001, t + at);
        gain.gain.exponentialRampToValueAtTime(0.08, t + at + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + at + 0.18);
        osc.connect(gain).connect(audio.destination);
        osc.start(t + at); osc.stop(t + at + 0.2);
      });
    } catch (e) { /* blocked until the person interacts with the page */ }
  }
  function open(a) {
    window.focus();
    if (window.LikhaChat && window.LikhaChat.open) window.LikhaChat.open(a.conversation_id);
    else location.href = a.url;
  }
  function alertAbout(list) {
    // Hindi para sa conversation na bukas at nakikita ngayon sa chat page.
    const fresh = list.filter((a) => !(window.LikhaChat && window.LikhaChat.isOpen && window.LikhaChat.isOpen(a.conversation_id)));
    if (!fresh.length) return;
    chime();
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    const seen = new Set();
    for (const a of fresh) {
      if (seen.has(a.conversation_id)) continue;   // isa bawat conversation, ang pinakabago
      seen.add(a.conversation_id);
      try {
        const n = new Notification(a.group ? `${a.sender} in ${a.title}` : a.sender, { body: a.text, tag: `likha-chat-${a.conversation_id}` });
        n.onclick = () => { open(a); n.close(); };
      } catch (e) {
        // Android Chrome allows notifications only from a service worker (a later spec): the sound still plays,
        // and the chat page's Alerts box says so.
        notifWorks = false;
        return;
      }
    }
  }
  let notifWorks = true;

  function schedule(ms = CFG.poll_ms) { clearTimeout(timer); if (!document.hidden && !stopped) timer = setTimeout(poll, ms); }
  // quiet: right after the tab comes back, the count is refreshed at once but what arrived meanwhile is not chimed
  // (the person is looking at the page now; a burst of notifications would only repeat the badge).
  async function poll(quiet = false) {
    if (busy || stopped || document.hidden) return;
    busy = true;
    const last = store.get(KEY_LAST);
    const qs = 'alerts=1' + (last !== null && /^\d+$/.test(last) ? `&after=${last}` : '');
    try {
      const res = await fetch(`${CFG.url}?${qs}`, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
      if (res.status === 401 || res.status === 403 || res.status === 419) { stopped = true; return; }
      if (res.ok) {
        const data = await res.json();
        set(data.unread);
        if (!quiet && data.alerts && data.alerts.length) alertAbout(data.alerts);
        // Ibang tab na mas nauna? Ang mas mataas ang itinatago, para walang dobleng alert.
        const now = Number(store.get(KEY_LAST) || 0);
        if (!(now > data.last_id)) store.set(KEY_LAST, String(data.last_id));
      }
    } catch (e) { /* offline: try again on the next tick */ } finally {
      busy = false;
      schedule();
    }
  }
  // Spec 308: a tap on a push notification focused this tab (public/sw.js); open that chat here.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (e) => {
      const d = e.data || {};
      const c = Number(d.c);
      if (d.type !== 'likha-chat-open' || !Number.isInteger(c) || c < 1) return;
      open({ conversation_id: c, url: `/chat?c=${c}` });
    });
  }
  document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(timer); else poll(true); });

  window.likhaChatUnread = { set, poll, soundOn, setSound(on) { store.set(KEY_SOUND, on ? '1' : '0'); }, chime, notifWorks: () => notifWorks };
  poll();
})();
</script>
