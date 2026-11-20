<x-layout>
  <x-slot name="heading">Chat</x-slot>
  <x-slot name="title">Chat</x-slot>

{{--
  /chat — Likha Chat (spec 296). Brief: docs/design/296-likha-chat/brief.md.
  Ang page ay isang fixed na panel sa ilalim ng nav (hindi ginagalaw ang layout, spec 295): listahan at thread
  magkatabi sa desktop, isang column sa phone. Lahat ng text mula sa user ay textContent; ang message body ay HTML na
  escaped na sa server (ChatText). Poll bawat 3 s, hinto kapag nakatago ang tab.
--}}
<style>
  #lc {
    --lc-bg: #f9fafb; --lc-panel: #ffffff; --lc-line: #e5e7eb; --lc-line-strong: #d1d5db;
    --lc-text: #111827; --lc-muted: #4b5563; --lc-faint: #5b6270; --lc-hover: #f3f4f6; --lc-selected: #eef2ff;
    --lc-accent: #2563eb; --lc-accent-hover: #1d4ed8; --lc-accent-soft: #dbeafe; --lc-on-accent: #ffffff;
    --lc-other: #f3f4f6; --lc-danger: #b91c1c; --lc-ai-bg: #f5f3ff; --lc-ai-text: #5b21b6; --lc-ai-edge: #ddd6fe;
    --lc-field-edge: #868e9a;
    --lc-top: 64px;
    position: fixed; top: var(--lc-top); left: 0; right: 0; bottom: 0; z-index: 40;
    display: flex; background: var(--lc-bg); color: var(--lc-text);
    font: 15px/1.45 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-variant-numeric: tabular-nums;
  }
  html:has(#lc), html:has(#lc) body { overflow: hidden; }
  /* Sa tabi ng sidebar (spec 295) sa ≥ 1024 px, o ng rail kapag naka-collapse; sa phone drawer ang sidebar. */
  @media (min-width: 1024px) {
    #lc { left: var(--lk-w, 0px); }
    html[data-nav-rail] #lc { left: var(--lk-rail, 0px); }
  }
  #lc *, #lc *::before, #lc *::after { box-sizing: border-box; }
  #lc ::selection { background: rgba(37, 99, 235, .2); }
  :where(#lc) :where(button, input, textarea) { font: inherit; color: inherit; }
  :where(#lc) button { cursor: pointer; }
  :where(#lc) button:disabled { cursor: default; opacity: .5; }
  #lc :focus-visible { outline: 2px solid var(--lc-accent); outline-offset: 2px; }
  #lc [hidden] { display: none !important; }
  .lc-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

  /* ── side: list ── */
  .lc-side { width: 340px; flex: none; display: flex; flex-direction: column; background: var(--lc-panel); border-right: 1px solid var(--lc-line); min-height: 0; }
  .lc-side-head { padding: 14px 16px 10px; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
  .lc-side-head .lc-h { font-size: 20px; font-weight: 700; margin: 0; letter-spacing: -.01em; }
  .lc-btn { display: inline-flex; align-items: center; gap: 7px; border: 1px solid var(--lc-line-strong); background: var(--lc-panel); border-radius: 9px; padding: 7px 12px; font-size: 14px; font-weight: 600; color: var(--lc-text); }
  .lc-btn:hover { background: var(--lc-hover); }
  .lc-btn.primary { background: var(--lc-accent); border-color: var(--lc-accent); color: var(--lc-on-accent); }
  .lc-btn.primary:hover { background: var(--lc-accent-hover); }
  .lc-btn.danger { color: var(--lc-danger); }
  .lc-icon-btn { width: 38px; height: 38px; display: inline-grid; place-items: center; border: 0; background: transparent; border-radius: 9px; color: var(--lc-muted); flex: none; }
  .lc-icon-btn:hover { background: var(--lc-hover); color: var(--lc-text); }
  .lc-seg { margin: 0 16px 10px; display: grid; grid-template-columns: 1fr 1fr; background: var(--lc-hover); border-radius: 9px; padding: 3px; }
  .lc-seg button { border: 0; background: transparent; border-radius: 7px; padding: 5px 8px; font-size: 13px; font-weight: 600; color: var(--lc-muted); }
  .lc-seg button[aria-pressed="true"] { background: var(--lc-panel); color: var(--lc-text); box-shadow: inset 0 0 0 1px var(--lc-field-edge), 0 1px 2px rgba(17, 24, 39, .08); }
  .lc-field { width: 100%; border: 1px solid var(--lc-field-edge); border-radius: 9px; padding: 8px 11px; background: var(--lc-panel); font-size: 14px; }
  .lc-field::placeholder { color: var(--lc-faint); }
  .lc-filter { padding: 0 16px 8px; }
  .lc-list { list-style: none; margin: 0; padding: 0 8px 8px; overflow-y: auto; flex: 1; min-height: 0; }
  .lc-row { width: 100%; display: flex; align-items: center; gap: 12px; padding: 10px 10px; border: 0; background: transparent; border-radius: 12px; text-align: left; min-height: 64px; }
  .lc-row:hover { background: var(--lc-bg); }
  .lc-row[aria-current="true"] { background: var(--lc-hover); }
  .lc-row-main { flex: 1; min-width: 0; }
  .lc-row-top { display: flex; align-items: baseline; gap: 8px; }
  .lc-row-name { flex: 1; min-width: 0; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .lc-row-time { flex: none; font-size: 12px; color: var(--lc-faint); }
  .lc-row-bottom { display: flex; align-items: center; gap: 8px; margin-top: 1px; }
  .lc-row-last { flex: 1; min-width: 0; font-size: 13.5px; color: var(--lc-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .lc-row.unread .lc-row-name { font-weight: 750; }
  .lc-row.unread .lc-row-last { color: var(--lc-text); font-weight: 600; }
  .lc-row.left .lc-row-last { font-style: italic; }
  .lc-pill { flex: none; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--lc-accent); color: var(--lc-on-accent); font-size: 12px; font-weight: 700; display: inline-grid; place-items: center; }
  .lc-empty { padding: 40px 24px; text-align: center; color: var(--lc-muted); }
  .lc-empty h2 { font-size: 17px; color: var(--lc-text); margin: 0 0 6px; font-weight: 700; }
  .lc-empty p { margin: 0 0 16px; font-size: 14px; }
  #lc-list-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding-bottom: 80px; }
  .lc-empty-icon { font-size: 28px; color: var(--lc-line-strong); margin-bottom: 12px; }

  /* ── avatars ── */
  .lc-av { flex: none; width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; font-size: 14px; font-weight: 700; letter-spacing: .02em; user-select: none; }
  .lc-av.sm { width: 28px; height: 28px; font-size: 11px; }
  .lc-av.xs { width: 18px; height: 18px; font-size: 8.5px; border: 1.5px solid var(--lc-panel); }
  .lc-av.group { border-radius: 11px; background: #e5e7eb; color: #374151; }
  .lc-av.t0 { background: #dbeafe; color: #1e3a8a; } .lc-av.t1 { background: #dcfce7; color: #14532d; }
  .lc-av.t2 { background: #fef3c7; color: #78350f; } .lc-av.t3 { background: #fce7f3; color: #831843; }
  .lc-av.t4 { background: #e0e7ff; color: #312e81; } .lc-av.t5 { background: #ccfbf1; color: #134e4a; }
  .lc-av.tn { background: #e5e7eb; color: #374151; }
  .lc-av.ai { background: var(--lc-ai-bg); color: var(--lc-ai-text); box-shadow: inset 0 0 0 1px var(--lc-ai-edge); }
  .lc-ai { display: inline-block; margin-left: 5px; padding: 0 5px; border-radius: 5px; background: var(--lc-ai-bg); color: var(--lc-ai-text); font-size: 11px; font-weight: 800; letter-spacing: .05em; line-height: 16px; vertical-align: 1px; box-shadow: inset 0 0 0 1px var(--lc-ai-edge); }

  /* ── main: thread ── */
  .lc-main { flex: 1; min-width: 0; display: flex; position: relative; }
  .lc-thread { flex: 1; min-width: 0; display: flex; flex-direction: column; background: var(--lc-panel); }
  .lc-thread-head { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-bottom: 1px solid var(--lc-line); min-height: 64px; }
  .lc-thread-title { flex: 1; min-width: 0; }
  .lc-thread-title h2 { font-size: 16px; font-weight: 700; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .lc-thread-sub { font-size: 12.5px; color: var(--lc-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .lc-lock { display: flex; align-items: center; gap: 6px; padding: 6px 16px; font-size: 12px; color: var(--lc-muted); background: var(--lc-bg); border-bottom: 1px solid var(--lc-line); }
  .lc-reconnect { padding: 4px 16px; font-size: 12.5px; background: #fef3c7; color: #78350f; }
  .lc-scroll { flex: 1; min-height: 0; overflow-y: auto; padding: 12px 16px 8px; overscroll-behavior: contain; position: relative; }
  .lc-msgs { list-style: none; margin: 0; padding: 0; }
  .lc-day { text-align: center; font-size: 12px; font-weight: 600; color: var(--lc-faint); margin: 18px 0 10px; }
  .lc-sys { text-align: center; font-size: 12.5px; color: var(--lc-muted); margin: 10px 24px; }
  .lc-msg { display: flex; align-items: flex-end; gap: 8px; margin-top: 12px; position: relative; }
  .lc-msg.cont { margin-top: 2px; }
  .lc-msg.me { flex-direction: row-reverse; }
  .lc-msg .lc-av-slot { width: 28px; flex: none; }
  .lc-msg.me .lc-av-slot { display: none; }
  .lc-col { max-width: min(75%, 560px); display: flex; flex-direction: column; align-items: flex-start; min-width: 0; }
  .lc-msg.me .lc-col { align-items: flex-end; }
  .lc-name { font-size: 12px; font-weight: 600; color: var(--lc-muted); margin: 0 0 3px 12px; }
  .lc-bubble { position: relative; padding: 8px 12px; border-radius: 18px; background: var(--lc-other); color: var(--lc-text); overflow-wrap: anywhere; word-break: break-word; max-width: 100%; }
  .lc-msg.me .lc-bubble { background: var(--lc-accent); color: var(--lc-on-accent); }
  .lc-msg.me .lc-bubble a { color: var(--lc-on-accent); }
  .lc-msg.me .lc-bubble :focus-visible { outline-color: var(--lc-on-accent); outline-offset: 1px; }
  .lc-bubble a { color: var(--lc-accent-hover); text-decoration: underline; text-underline-offset: 2px; }
  .lc-bubble.removed { background: transparent; border: 1px solid var(--lc-line-strong); color: var(--lc-faint); font-style: italic; }
  .lc-msg.me .lc-bubble.removed { background: transparent; color: var(--lc-faint); }
  .lc-msg.pending .lc-meta { font-style: italic; }
  .lc-quote { display: block; width: 100%; text-align: left; margin: 0 0 6px; padding: 4px 8px; border: 0; border-radius: 8px; background: rgba(17, 24, 39, .06); font-size: 13px; color: inherit; box-shadow: inset 2px 0 0 rgba(17, 24, 39, .25); }
  .lc-msg.me .lc-quote { background: rgba(15, 23, 42, .22); box-shadow: inset 2px 0 0 rgba(255, 255, 255, .7); }
  .lc-quote b { display: block; font-size: 12px; }
  .lc-quote span { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .lc-imgs { display: grid; gap: 4px; margin: 2px 0; }
  .lc-imgs.n1 { grid-template-columns: minmax(0, 280px); }
  .lc-imgs.multi { grid-template-columns: repeat(2, minmax(0, 140px)); }
  .lc-imgs button { padding: 0; border: 0; background: var(--lc-hover); border-radius: 12px; overflow: hidden; display: block; }
  .lc-imgs img { display: block; width: 100%; height: auto; max-height: 320px; object-fit: cover; }
  .lc-imgs.n1 img { aspect-ratio: 4 / 3; }
  .lc-imgs.multi img { aspect-ratio: 1; height: 100%; }
  .lc-file { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 12px; background: var(--lc-hover); color: var(--lc-text) !important; text-decoration: none !important; margin: 2px 0; max-width: 280px; }
  .lc-file i { color: var(--lc-muted); font-size: 18px; }
  .lc-file span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 14px; font-weight: 600; }
  .lc-file small { display: block; font-weight: 400; color: var(--lc-faint); font-size: 12px; }
  .lc-meta { font-size: 11.5px; color: var(--lc-faint); margin: 3px 12px 0; display: none; }
  .lc-msg:hover .lc-meta, .lc-msg:focus-within .lc-meta, .lc-msg.show .lc-meta, .lc-msg.pending .lc-meta, .lc-msg.failed .lc-meta { display: block; }
  .lc-msg.failed .lc-meta { color: var(--lc-danger); font-size: 12.5px; font-weight: 600; }
  .lc-msg.failed .lc-bubble, .lc-msg.me.failed .lc-bubble { background: var(--lc-panel); color: var(--lc-text); box-shadow: inset 0 0 0 1.5px var(--lc-danger); }
  .lc-msg.failed .lc-bubble a { color: var(--lc-accent-hover); }
  .lc-retry { min-height: 32px; padding: 4px 12px !important; font-size: 13px !important; margin-left: 6px; }
  .lc-tag-reading { font-size: 11px; font-weight: 700; color: var(--lc-muted); background: var(--lc-hover); border-radius: 5px; padding: 0 6px; margin-left: 6px; flex: none; }
  .lc-act { align-self: center; opacity: 0; width: 30px; height: 30px; }
  .lc-msg:hover .lc-act, .lc-msg:focus-within .lc-act, .lc-msg.show .lc-act { opacity: 1; }
  .lc-menu { position: absolute; z-index: 5; bottom: calc(100% - 6px); background: var(--lc-panel); border: 1px solid var(--lc-line); border-radius: 10px; box-shadow: 0 8px 24px rgba(17, 24, 39, .14); padding: 4px; min-width: 140px; }
  .lc-msg.me .lc-menu { right: 40px; } .lc-msg:not(.me) .lc-menu { left: 40px; }
  .lc-menu button { display: flex; width: 100%; gap: 8px; align-items: center; border: 0; background: transparent; padding: 7px 10px; border-radius: 7px; font-size: 14px; text-align: left; }
  .lc-menu button:hover { background: var(--lc-hover); }
  .lc-seen { display: flex; justify-content: flex-end; align-items: center; gap: 3px; font-size: 11.5px; color: var(--lc-faint); margin: 4px 0 0; }
  .lc-seen .lc-av { margin-left: -4px; }
  .lc-new-pill { position: sticky; bottom: 8px; margin: 0 auto; display: block; border: 0; background: var(--lc-accent); color: var(--lc-on-accent); font-size: 13px; font-weight: 600; padding: 6px 14px; border-radius: 16px; box-shadow: 0 4px 12px rgba(37, 99, 235, .3); }
  .lc-earlier { display: block; margin: 0 auto 8px; }
  .lc-thread-empty { text-align: center; color: var(--lc-muted); padding: 48px 16px; font-size: 14px; }
  @media (prefers-reduced-motion: no-preference) {
    .lc-msg.arrive { animation: lc-in 160ms cubic-bezier(.16, 1, .3, 1); }
    @keyframes lc-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
  }

  /* ── composer ── */
  .lc-compose { border-top: 1px solid var(--lc-line); padding: 8px 12px calc(8px + env(safe-area-inset-bottom)); background: var(--lc-panel); }
  .lc-replybar { display: flex; align-items: center; gap: 8px; padding: 4px 4px 8px 8px; font-size: 13px; color: var(--lc-muted); }
  .lc-replybar div { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .lc-chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 4px 8px; }
  .lc-chip { display: inline-flex; align-items: center; gap: 6px; max-width: 220px; background: var(--lc-hover); border-radius: 8px; padding: 3px 4px 3px 9px; font-size: 13px; }
  .lc-chip span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .lc-chip button { border: 0; background: transparent; width: 24px; height: 24px; border-radius: 6px; color: var(--lc-muted); }
  .lc-row-compose { display: flex; align-items: flex-end; gap: 6px; }
  .lc-input { flex: 1; min-width: 0; resize: none; border: 1px solid var(--lc-field-edge); border-radius: 20px; padding: 9px 14px; max-height: 150px; line-height: 1.4; background: var(--lc-bg); }
  .lc-input::placeholder { color: var(--lc-faint); }
  .lc-input:focus-visible { outline-offset: 0; border-radius: 20px; }
  .lc-send { width: 40px; height: 40px; border-radius: 50%; border: 0; background: var(--lc-accent); color: var(--lc-on-accent); display: grid; place-items: center; flex: none; }
  .lc-send:hover { background: var(--lc-accent-hover); }
  .lc-hint { font-size: 12px; color: var(--lc-faint); padding: 4px 6px 0; display: flex; justify-content: space-between; gap: 8px; }
  .lc-hint .err { color: var(--lc-danger); }
  .lc-bar { display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; padding: 6px; font-size: 14px; color: var(--lc-muted); text-align: center; }

  /* ── panels (new chat, group info) ── */
  .lc-panel { position: absolute; inset: 0; background: var(--lc-panel); display: flex; flex-direction: column; z-index: 3; min-height: 0; }
  .lc-panel-head { display: flex; align-items: center; gap: 8px; padding: 10px 10px 10px 8px; border-bottom: 1px solid var(--lc-line); min-height: 64px; }
  .lc-panel-head h2 { font-size: 16px; font-weight: 700; margin: 0; flex: 1; }
  .lc-panel-body { padding: 12px 16px; overflow-y: auto; flex: 1; min-height: 0; }
  .lc-label { display: block; font-size: 13px; font-weight: 600; margin: 0 0 6px; color: var(--lc-muted); }
  .lc-person { width: 100%; display: flex; align-items: center; gap: 12px; padding: 8px; border: 0; background: transparent; border-radius: 10px; text-align: left; }
  .lc-person:hover { background: var(--lc-hover); }
  .lc-person-name { font-weight: 600; }
  .lc-person-role { font-size: 12.5px; color: var(--lc-muted); }
  .lc-person .lc-check { margin-left: auto; width: 22px; height: 22px; border-radius: 6px; border: 1.5px solid var(--lc-line-strong); display: grid; place-items: center; color: transparent; font-size: 12px; flex: none; }
  .lc-person[aria-pressed="true"] .lc-check { background: var(--lc-accent); border-color: var(--lc-accent); color: #fff; }
  .lc-tag { font-size: 11px; font-weight: 700; color: var(--lc-muted); background: var(--lc-hover); border-radius: 5px; padding: 1px 6px; margin-left: 6px; }
  .lc-section { margin: 0 0 20px; }
  .lc-section h3 { font-size: 13px; font-weight: 700; color: var(--lc-muted); margin: 0 0 8px; }
  .lc-inline { display: flex; gap: 8px; }
  .lc-msg-err { color: var(--lc-danger); font-size: 13px; margin: 6px 0 0; }

  .lc-placeholder { flex: 1; display: grid; place-items: center; text-align: center; color: var(--lc-muted); padding: 24px; }
  .lc-placeholder > div { max-width: 360px; }

  /* lightbox */
  .lc-lightbox { position: fixed; inset: 0; z-index: 60; background: rgba(17, 24, 39, .92); display: grid; place-items: center; padding: 48px 12px 12px; }
  .lc-lightbox[hidden] { display: none; }
  .lc-lightbox img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 6px; }
  .lc-lightbox .lc-icon-btn { position: absolute; top: 10px; right: 10px; color: #fff; }
  .lc-lightbox .lc-icon-btn:hover { background: rgba(255, 255, 255, .12); color: #fff; }
  .lc-lightbox a { position: absolute; top: 16px; left: 16px; color: #fff; font-size: 14px; }

  /* ── spec 300: Messenger habits ── */
  /* Runs: the inner corners of a run from one sender are tight, the avatar sits on the last bubble. */
  .lc-msg:not(.me).run-first .lc-bubble { border-bottom-left-radius: 5px; }
  .lc-msg:not(.me).run-mid .lc-bubble { border-top-left-radius: 5px; border-bottom-left-radius: 5px; }
  .lc-msg:not(.me).run-last .lc-bubble { border-top-left-radius: 5px; }
  .lc-msg.me.run-first .lc-bubble { border-bottom-right-radius: 5px; }
  .lc-msg.me.run-mid .lc-bubble { border-top-right-radius: 5px; border-bottom-right-radius: 5px; }
  .lc-msg.me.run-last .lc-bubble { border-top-right-radius: 5px; }
  #lc { --lc-online: #16a34a; --lc-rec: #dc2626; --lc-mine-edge: #2563eb; --lc-knob: #ffffff; }
  .lc-av-wrap { position: relative; display: inline-flex; flex: none; }
  .lc-dot { position: absolute; right: 0; bottom: 0; width: 10px; height: 10px; border-radius: 50%; background: var(--lc-online); box-shadow: 0 0 0 2px var(--lc-panel); }
  .lc-av-wrap.sm .lc-dot { width: 8px; height: 8px; }
  /* Long-press on phones opens the reaction bar, not the phone's text selection or link callout. */
  @media (pointer: coarse) { .lc-msg .lc-bubble, .lc-msg .lc-voice { -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; } }
  .lc-row-muted { flex: none; color: var(--lc-faint); font-size: 12px; }
  .lc-pill.muted { background: var(--lc-line); color: var(--lc-text); }
  .lc-hl { font-weight: 700; color: var(--lc-text); background: transparent; }
  .lc-results h3 { font-size: 12px; font-weight: 700; color: var(--lc-muted); margin: 10px 10px 4px; text-transform: none; }
  .lc-row-last.lc-hit-text { white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }

  /* Message tools: react + more, beside the bubble on hover / focus (desktop), by long-press on phones. */
  .lc-tools { align-self: center; display: flex; gap: 2px; opacity: 0; }
  .lc-msg:hover .lc-tools, .lc-msg:focus-within .lc-tools, .lc-msg.show .lc-tools { opacity: 1; }
  .lc-tools .lc-icon-btn { width: 30px; height: 30px; }
  .lc-rbar { position: absolute; z-index: 6; bottom: calc(100% - 4px); display: flex; gap: 2px; padding: 4px; background: var(--lc-panel); border: 1px solid var(--lc-line); border-radius: 24px; box-shadow: 0 6px 20px rgba(17, 24, 39, .14); }
  .lc-msg.me .lc-rbar { right: 36px; } .lc-msg:not(.me) .lc-rbar { left: 36px; }
  .lc-rbar button { width: 40px; height: 40px; border: 0; background: transparent; border-radius: 50%; font-size: 22px; line-height: 1; display: grid; place-items: center; }
  .lc-rbar button:hover { background: var(--lc-hover); }
  /* Your reaction: a filled disc and a dot under it (not a ring, which is the focus style). */
  .lc-rbar button { position: relative; }
  .lc-rbar button[aria-pressed="true"] { background: var(--lc-accent-soft); }
  .lc-rbar button[aria-pressed="true"]::after { content: ""; position: absolute; bottom: 1px; left: 50%; width: 4px; height: 4px; margin-left: -2px; border-radius: 50%; background: var(--lc-accent); }
  @media (prefers-reduced-motion: no-preference) {
    .lc-rbar button { transition: transform 120ms cubic-bezier(.16, 1, .3, 1); }
    .lc-rbar button:hover { transform: scale(1.18); }
  }
  .lc-reacts { position: relative; z-index: 1; margin: -8px 8px 0; display: inline-flex; align-items: center; gap: 2px; padding: 2px 8px 2px 6px; min-height: 26px; border: 1px solid var(--lc-line); background: var(--lc-panel); border-radius: 11px; box-shadow: 0 1px 3px rgba(17, 24, 39, .1); font-size: 13px; color: var(--lc-muted); }
  .lc-msg.has-reacts + .lc-msg.cont, .lc-msg.has-reacts + .lc-seen-at { margin-top: 6px; }
  .lc-reacts .n { margin-left: 3px; font-size: 12px; font-weight: 600; font-variant-numeric: tabular-nums; }
  .lc-reacts.mine { border-color: var(--lc-mine-edge); }
  .lc-who { position: absolute; z-index: 6; top: 100%; margin-top: 4px; background: var(--lc-panel); border: 1px solid var(--lc-line); border-radius: 10px; box-shadow: 0 8px 24px rgba(17, 24, 39, .14); padding: 6px; min-width: 200px; max-width: 280px; max-height: 260px; overflow-y: auto; }
  .lc-msg.me .lc-who { right: 0; } .lc-msg:not(.me) .lc-who { left: 36px; }
  .lc-who h4 { margin: 2px 6px 6px; font-size: 12px; font-weight: 700; color: var(--lc-muted); }
  .lc-who li { display: flex; align-items: center; gap: 8px; padding: 4px 6px; border-radius: 7px; font-size: 14px; }
  .lc-who ul { list-style: none; margin: 0; padding: 0; }
  .lc-who .e { margin-left: auto; font-size: 17px; }
  .lc-who button { border: 0; background: transparent; padding: 0; text-align: left; width: 100%; display: flex; align-items: center; gap: 8px; color: inherit; }
  .lc-who small { display: block; font-size: 11.5px; color: var(--lc-faint); }
  .lc-edited { font-size: 11.5px; color: var(--lc-faint); margin: 3px 12px 0; }
  .lc-edited button { border: 0; background: transparent; padding: 6px 4px; margin: -6px -4px; color: var(--lc-accent-hover); font-size: 12px; text-decoration: underline; text-underline-offset: 2px; }
  .lc-history { margin: 6px 0 0; padding: 8px 10px; border-radius: 10px; background: var(--lc-bg); border: 1px solid var(--lc-line); font-size: 13px; color: var(--lc-muted); max-width: 100%; }
  .lc-history ol { margin: 0; padding: 0; list-style: none; }
  .lc-history li + li { margin-top: 6px; }
  .lc-history time { display: block; font-size: 11.5px; color: var(--lc-faint); }
  .lc-history span { color: var(--lc-text); overflow-wrap: anywhere; }
  .lc-msg.hit .lc-bubble { box-shadow: 0 0 0 3px var(--lc-accent-soft), 0 0 0 4px var(--lc-accent); }
  @media (prefers-reduced-motion: no-preference) { .lc-msg.hit .lc-bubble { transition: box-shadow 1.2s ease-out; } }

  /* Seen heads: under the last message each person saw. */
  .lc-seen-at { display: flex; justify-content: flex-end; gap: 2px; margin: 3px 0 0; min-height: 16px; }
  .lc-seen-at .lc-av { margin: 0; }

  /* Typing */
  .lc-typing { display: flex; align-items: center; gap: 8px; min-height: 22px; padding: 2px 16px 4px 52px; font-size: 12.5px; color: var(--lc-muted); }
  .lc-dots { display: inline-flex; gap: 3px; padding: 7px 9px; background: var(--lc-other); border-radius: 12px; }
  .lc-dots i { width: 5px; height: 5px; border-radius: 50%; background: #6b7280; }
  @media (prefers-reduced-motion: no-preference) {
    .lc-dots i { animation: lc-blink 1.2s infinite ease-in-out; }
    .lc-dots i:nth-child(2) { animation-delay: .15s; } .lc-dots i:nth-child(3) { animation-delay: .3s; }
    @keyframes lc-blink { 0%, 60%, 100% { opacity: .35; transform: none; } 30% { opacity: 1; transform: translateY(-2px); } }
  }

  /* Voice message */
  .lc-voice { display: flex; align-items: center; gap: 10px; padding: 6px 12px 6px 6px; border-radius: 18px; background: var(--lc-other); min-width: 200px; max-width: 280px; }
  .lc-msg.me .lc-voice { background: var(--lc-accent); color: var(--lc-on-accent); }
  .lc-voice button { width: 36px; height: 36px; border-radius: 50%; border: 1px solid var(--lc-field-edge); display: grid; place-items: center; flex: none; background: var(--lc-knob); color: var(--lc-accent); }
  .lc-msg.me .lc-voice button { border-color: var(--lc-knob); }
  .lc-voice .track { flex: 1; height: 4px; border-radius: 2px; background: var(--lc-field-edge); overflow: hidden; }
  .lc-voice .track span { background: var(--lc-text); }
  .lc-msg.me .lc-voice .track { background: #1e3a8a; }
  .lc-msg.me .lc-voice .track span { background: var(--lc-on-accent); }
  .lc-voice .track span { display: block; height: 100%; width: 0; background: currentColor; border-radius: 2px; }
  .lc-voice time { font-size: 12.5px; font-variant-numeric: tabular-nums; flex: none; }
  .lc-msg.me .lc-voice :focus-visible { outline-color: var(--lc-on-accent); }

  /* Composer: edit bar, mic, recording */
  .lc-rec { display: flex; align-items: center; gap: 10px; padding: 6px 4px 8px 8px; font-size: 13.5px; color: var(--lc-muted); }
  .lc-rec .red { width: 10px; height: 10px; border-radius: 50%; background: var(--lc-rec); flex: none; }
  @media (prefers-reduced-motion: no-preference) { .lc-rec .red { animation: lc-pulse 1s infinite ease-in-out; } @keyframes lc-pulse { 50% { opacity: .35; } } }
  .lc-rec time { font-weight: 700; color: var(--lc-accent); font-variant-numeric: tabular-nums; }
  .lc-rec .hint { margin-left: auto; font-size: 12.5px; }
  #lc-mic[aria-pressed="true"] { background: var(--lc-accent-soft); color: var(--lc-accent-hover); }
  #lc-mic { touch-action: none; user-select: none; -webkit-user-select: none; -webkit-touch-callout: none; }

  /* Alerts popover (list header) and mute menu (thread header) */
  .lc-pop { position: absolute; z-index: 8; top: calc(100% + 4px); right: 12px; width: 280px; background: var(--lc-panel); border: 1px solid var(--lc-line); border-radius: 12px; box-shadow: 0 10px 30px rgba(17, 24, 39, .16); padding: 12px; font-size: 14px; }
  .lc-pop h3 { margin: 0 0 8px; font-size: 14px; font-weight: 700; }
  .lc-pop p { margin: 0 0 10px; color: var(--lc-muted); font-size: 13px; }
  .lc-switch { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 6px 0 0; border-top: 1px solid var(--lc-line); margin-top: 10px; }
  .lc-switch button { width: 44px; height: 26px; border-radius: 13px; border: 1px solid var(--lc-field-edge); background: var(--lc-line-strong); position: relative; flex: none; }
  .lc-switch button[aria-checked="true"] { border-color: var(--lc-accent); }
  .lc-switch button::after { content: ""; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: var(--lc-knob); box-shadow: 0 1px 2px rgba(17, 24, 39, .25); }
  .lc-switch button[aria-checked="true"] { background: var(--lc-accent); }
  .lc-switch button[aria-checked="true"]::after { left: 21px; }
  @media (prefers-reduced-motion: no-preference) { .lc-switch button::after { transition: left 140ms cubic-bezier(.16, 1, .3, 1); } }
  /* Spec 308: this device's alerts (web push) */
  .lc-pop .lc-push-on { display: flex; align-items: center; gap: 7px; margin: 0 0 4px; color: var(--lc-text); font-size: 14px; font-weight: 600; }
  .lc-push-on i { color: var(--lc-online); }
  .lc-pop #lc-push-ios { line-height: 1.45; }
  .lc-push-btn { width: 100%; justify-content: center; }
  .lc-push-btn[aria-disabled="true"] { opacity: .6; cursor: progress; }
  .lc-pop .lc-push-err { margin: 8px 0 0; color: var(--lc-danger); }
  .lc-mute-menu { right: 8px; width: 260px; padding: 4px; }
  .lc-mute-menu button { display: flex; width: 100%; gap: 10px; align-items: center; border: 0; background: transparent; padding: 9px 10px; border-radius: 8px; font-size: 14px; text-align: left; }
  .lc-mute-menu button:hover { background: var(--lc-hover); }
  .lc-head-tools { display: flex; gap: 2px; position: relative; }
  .lc-side-tools { display: flex; align-items: center; gap: 4px; position: relative; }

  /* phone: one column */
  @media (max-width: 1023px) {
    .lc-send, #lc-mic, #lc-attach, .lc-head-tools .lc-icon-btn, #lc-alerts-btn, .lc-replybar .lc-icon-btn { width: 44px; height: 44px; }
    .lc-rbar button { width: 44px; height: 44px; }
    .lc-reacts { min-height: 30px; }
    .lc-mute-menu button { min-height: 44px; }
    /* Spec 308: the alert buttons and the Sound switch get a 44 px touch area on phones. */
    .lc-push-btn { min-height: 44px; }
    .lc-switch { min-height: 44px; }
    .lc-switch button::before { content: ""; position: absolute; inset: -9px -4px; }
    .lc-tools { opacity: 1; }
    .lc-msg:not(.show) .lc-tools:not(:focus-within) { opacity: 0; pointer-events: none; }
    .lc-tools .lc-icon-btn { width: 40px; height: 40px; }
    .lc-side { width: 100%; border-right: 0; }
    #lc[data-view="list"] .lc-main { display: none; }
    #lc[data-view="thread"] .lc-side { display: none; }
    .lc-col { max-width: 80%; }
    .lc-act { opacity: 1; width: 40px; height: 40px; }
    /* Hidden until the message is tapped, but still reachable with the keyboard. */
    .lc-msg:not(.show) .lc-act:not(:focus-visible):not([aria-expanded="true"]) { opacity: 0; pointer-events: none; }
    /* 16 px: iOS Safari zooms into any smaller field on focus. */
    .lc-input, .lc-field { font-size: 16px; }
  }
  @media (min-width: 1024px) { #lc { font-size: 14px; } .lc-back { display: none; } }
</style>

<div id="lc" data-view="{{ $boot['open'] ? 'thread' : 'list' }}">
  {{-- ── list ── --}}
  <section class="lc-side" aria-label="Conversations" style="position: relative">
    <div class="lc-side-head">
      <h2 class="lc-h">Chats</h2>
      <div class="lc-side-tools">
        <button type="button" class="lc-icon-btn" id="lc-alerts-btn" aria-label="Alerts and sound" title="Alerts and sound" aria-haspopup="dialog" aria-expanded="false" aria-controls="lc-alerts"><i class="fa-solid fa-sliders" aria-hidden="true"></i></button>
        <button type="button" class="lc-btn primary" id="lc-new-btn"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> New chat</button>
        {{-- Alerts: asks the browser for notifications only on this button, never on load (spec 300, MP-2).
             Spec 308: with the server's push key the button turns on alerts for this device even when Likha is closed. --}}
        <div class="lc-pop" id="lc-alerts" role="dialog" aria-label="Alerts" hidden>
          <h3>Alerts</h3>
          @if (!empty($boot['push_key']))
            {{-- One live region: the state, its line and the iPhone steps are announced together. --}}
            <div aria-live="polite">
              <p class="lc-push-on" id="lc-push-status" hidden><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Alerts on for this device</p>
              <p id="lc-notif-text">Get alerts on this device for new messages, even when Likha is closed.</p>
              <p id="lc-push-ios" hidden>To get alerts on iPhone: Share <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> <span aria-hidden="true">→</span><span class="lc-sr">, then</span> Add to Home Screen, then open Likha from the Home Screen.</p>
            </div>
            <button type="button" class="lc-btn primary lc-push-btn" id="lc-notif-allow">Turn on alerts</button>
            <button type="button" class="lc-btn lc-push-btn" id="lc-push-off" aria-describedby="lc-push-status" hidden>Turn off</button>
            <p class="lc-push-err" id="lc-push-err" role="alert" hidden></p>
          @else
            <p id="lc-notif-text">Get a notification when a message arrives in a chat that is not open.</p>
            <button type="button" class="lc-btn primary" id="lc-notif-allow">Allow notifications</button>
          @endif
          <div class="lc-switch">
            <span id="lc-sound-label">Sound for new messages</span>
            <button type="button" id="lc-sound" role="switch" aria-checked="true" aria-labelledby="lc-sound-label"></button>
          </div>
        </div>
      </div>
    </div>
    @if ($boot['me']['ceo'])
      <div class="lc-seg" role="group" aria-label="Which conversations">
        <button type="button" data-filter="mine" aria-pressed="true">Mine</button>
        <button type="button" data-filter="all" aria-pressed="false">All conversations</button>
      </div>
    @endif
    <div class="lc-filter">
      <label class="lc-sr" for="lc-filter">Search chats and messages</label>
      <input id="lc-filter" class="lc-field" type="search" placeholder="Search chats and messages" autocomplete="off">
    </div>
    <ul class="lc-list" id="lc-list" aria-label="Chats"></ul>
    <div class="lc-list lc-results" id="lc-results" hidden></div>
    <div class="lc-sr" id="lc-search-status" role="status" aria-live="polite"></div>
    <div class="lc-empty" id="lc-list-empty" hidden>
      <div class="lc-empty-icon"><i class="fa-regular fa-comments" aria-hidden="true"></i></div>
      <h2>No chats yet</h2>
      <p>Start a direct chat or a group with the team.</p>
      <button type="button" class="lc-btn primary" data-open-new><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> New chat</button>
    </div>
    <div class="lc-empty" id="lc-list-loading">Loading chats…</div>

    {{-- New chat panel: replaces the list --}}
    <div class="lc-panel" id="lc-new" hidden role="region" aria-label="New chat">
      <div class="lc-panel-head">
        <button type="button" class="lc-icon-btn" data-close-new aria-label="Back to chats"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button>
        <h2 id="lc-new-title">New chat</h2>
      </div>
      <div class="lc-panel-body">
        @if ($boot['me']['may_create_group'])
          <div class="lc-seg" role="group" aria-label="Chat type" style="margin: 0 0 12px">
            <button type="button" data-mode="direct" aria-pressed="true">Direct</button>
            <button type="button" data-mode="group" aria-pressed="false">New group</button>
          </div>
          <div id="lc-group-fields" hidden class="lc-section">
            <label class="lc-label" for="lc-group-name">Group name</label>
            <input id="lc-group-name" class="lc-field" maxlength="80" placeholder="e.g. Packing team" autocomplete="off">
          </div>
        @endif
        <label class="lc-label" for="lc-people-q">People</label>
        <input id="lc-people-q" class="lc-field" type="search" placeholder="Search people by name" autocomplete="off">
        <div id="lc-people" style="margin-top: 8px"></div>
        <p class="lc-msg-err" id="lc-new-err" hidden></p>
      </div>
      <div class="lc-compose" id="lc-group-create" hidden>
        <button type="button" class="lc-btn primary" style="width: 100%; justify-content: center" id="lc-create-group">Create group</button>
      </div>
    </div>
  </section>

  {{-- ── thread ── --}}
  <section class="lc-main" aria-label="Conversation">
    <div class="lc-placeholder" id="lc-placeholder">
      <div>
        <div class="lc-empty-icon"><i class="fa-regular fa-comment-dots" aria-hidden="true"></i></div>
        <p style="margin: 0 0 8px; font-weight: 600; color: var(--lc-text)" id="lc-placeholder-text">Pick a chat on the left, or start a new one.</p>
        {{-- Spec 304: ang lock line ay para sa owner (CEO) lang; hindi ito ipinapadala sa staff. --}}
        @if ($boot['me']['ceo'])
          <p style="margin: 0; font-size: 13px"><i class="fa-solid fa-lock" aria-hidden="true"></i> The owner can read every conversation in Likha Chat.</p>
        @endif
      </div>
    </div>

    <div class="lc-thread" id="lc-thread" hidden>
      <header class="lc-thread-head">
        <button type="button" class="lc-icon-btn lc-back" id="lc-back" aria-label="Back to chats"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button>
        <span id="lc-thread-av"></span>
        <div class="lc-thread-title">
          <h2 id="lc-thread-title"></h2>
          <div class="lc-thread-sub" id="lc-thread-sub"></div>
        </div>
        <div class="lc-head-tools">
          <button type="button" class="lc-icon-btn" id="lc-mute-btn" aria-label="Mute" aria-haspopup="menu" aria-expanded="false" hidden><i class="fa-regular fa-bell" aria-hidden="true"></i></button>
          <button type="button" class="lc-icon-btn" id="lc-info-btn" aria-label="Group info" hidden><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button>
          <div class="lc-pop lc-mute-menu" id="lc-mute-menu" role="menu" aria-label="Mute" hidden></div>
        </div>
      </header>
      @if ($boot['me']['ceo'])
        <div class="lc-lock"><i class="fa-solid fa-lock" aria-hidden="true"></i> The owner can read every conversation in Likha Chat</div>
      @endif
      <div class="lc-reconnect" id="lc-reconnect" role="status" hidden>Reconnecting…</div>
      <div class="lc-scroll" id="lc-scroll">
        <button type="button" class="lc-btn lc-earlier" id="lc-earlier" hidden>Load earlier messages</button>
        <ol class="lc-msgs" id="lc-msgs" aria-label="Messages"></ol>
        <div class="lc-sr" id="lc-announce" role="status" aria-live="polite"></div>
        <div class="lc-thread-empty" id="lc-thread-empty" hidden></div>
        <button type="button" class="lc-new-pill" id="lc-new-pill" hidden>New messages <i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button>
      </div>
      <div class="lc-typing" id="lc-typing" aria-live="polite"></div>

      <footer class="lc-compose" id="lc-compose">
        <div class="lc-replybar" id="lc-replybar" hidden>
          <i class="fa-solid fa-reply" aria-hidden="true"></i>
          <div id="lc-replytext"></div>
          <button type="button" class="lc-icon-btn" id="lc-reply-cancel" aria-label="Cancel reply"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="lc-replybar" id="lc-editbar" hidden>
          <i class="fa-solid fa-pen" aria-hidden="true"></i>
          <div>Editing message</div>
          <button type="button" class="lc-icon-btn" id="lc-edit-cancel" aria-label="Cancel editing"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="lc-rec" id="lc-rec" hidden>
          <span class="red" aria-hidden="true"></span>
          <span aria-hidden="true">Recording <time id="lc-rec-time">0:00</time> / 2:00</span>
          <span class="hint" id="lc-rec-hint">Release to send</span>
        </div>
        <div class="lc-chips" id="lc-chips" hidden></div>
        <form class="lc-row-compose" id="lc-form" autocomplete="off">
          <input type="file" id="lc-file" multiple accept="{{ $boot['accept'] }}" hidden>
          <button type="button" class="lc-icon-btn" id="lc-attach" aria-label="Add photos or files"><i class="fa-solid fa-paperclip" aria-hidden="true"></i></button>
          {{-- Hold to record, release to send (Enter or Space: start, then again to send). Hidden where the browser cannot record. --}}
          <button type="button" class="lc-icon-btn" id="lc-mic" aria-label="Hold to record a voice message" aria-pressed="false" hidden><i class="fa-solid fa-microphone" aria-hidden="true"></i></button>
          <label class="lc-sr" for="lc-input">Message</label>
          <textarea id="lc-input" class="lc-input" rows="1" placeholder="Message" maxlength="{{ $boot['max_body'] }}"></textarea>
          <button type="submit" class="lc-send" id="lc-send" aria-label="Send"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
        </form>
        <div class="lc-hint"><span id="lc-err" class="err" role="alert"></span><span id="lc-count"></span></div>
      </footer>
      <div class="lc-compose lc-bar" id="lc-readonly" hidden></div>
    </div>

    {{-- Group info: members, rename, add, remove, leave --}}
    <div class="lc-panel" id="lc-info" hidden role="region" aria-label="Group info">
      <div class="lc-panel-head">
        <button type="button" class="lc-icon-btn" id="lc-info-close" aria-label="Back to the conversation"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button>
        <h2>Group info</h2>
      </div>
      <div class="lc-panel-body" id="lc-info-body"></div>
    </div>
  </section>
</div>

<div class="lc-lightbox" id="lc-lightbox" hidden role="dialog" aria-modal="true" aria-label="Photo">
  <a id="lc-lightbox-open" href="#" target="_blank" rel="noopener">Open original</a>
  <button type="button" class="lc-icon-btn" id="lc-lightbox-close" aria-label="Close photo"><i class="fa-solid fa-xmark fa-lg" aria-hidden="true"></i></button>
  <div id="lc-lightbox-frame" style="display: contents"></div>
</div>

<template id="lc-ai-mark"><span class="lc-ai" data-ai-mark title="AI member">AI</span></template>

<script>
(() => {
  const BOOT = @json($boot);
  const $ = (id) => document.getElementById(id);
  const root = $('lc');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const S = {
    rows: new Map(), all: false, open: null, conv: null, msgs: [], hasMore: false, reads: [],
    since: null, reply: null, files: [], timer: null, fails: 0, inflight: false, mode: 'direct', picked: new Map(),
    // Spec 300.
    edit: null, typing: [], typingAt: 0, hit: null, history: new Set(), players: new Map(), searchSeq: 0, searching: false,
  };
  const coarse = window.matchMedia('(pointer: coarse)').matches;
  const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const EMOJI_NAMES = { '👍': 'thumbs up', '❤️': 'heart', '😆': 'laugh', '😮': 'wow', '😢': 'sad', '😡': 'angry' };

  // ── small helpers ──
  function h(tag, attrs = {}, ...kids) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v === true ? '' : v);
    }
    for (const kid of kids.flat()) if (kid !== null && kid !== undefined && kid !== false) el.append(kid);
    return el;
  }
  const icon = (cls) => h('i', { class: cls, 'aria-hidden': 'true' });
  async function api(method, url, body) {
    const opt = { method, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf }, credentials: 'same-origin' };
    if (body instanceof FormData) opt.body = body;
    else if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    let res;
    try { res = await fetch(url, opt); } catch (e) {
      const err = new Error('No connection. Check your internet and try again.'); err.status = 0; throw err;
    }
    let data = null;
    try { data = await res.json(); } catch (e) { /* no body */ }
    if (!res.ok) {
      const first = data && data.errors ? Object.values(data.errors)[0][0] : null;
      const err = new Error(first || (data && data.message) || (res.status === 403 ? 'You cannot do that here.' : 'Something went wrong. Try again.'));
      err.status = res.status;
      throw err;
    }
    return data;
  }
  function avatar(a, size = '') {
    if (!a) return h('span', { class: 'lc-av ' + size });
    const cls = ['lc-av', size];
    if (a.ai) cls.push('ai'); else if (a.group) cls.push('group'); else if (a.tone !== null && a.tone !== undefined) cls.push('t' + a.tone); else cls.push('tn');
    return h('span', { class: cls.join(' '), 'aria-hidden': 'true', text: a.initials || '?' });
  }
  const aiMark = () => $('lc-ai-mark').content.firstElementChild.cloneNode(true);
  // Active: a green dot within 5 minutes, "Active 12m ago" within the hour (spec 300, MP-5).
  function minutesAgo(at) { return at ? Math.max(0, Math.floor((Date.now() - new Date(at)) / 60e3)) : null; }
  function activeText(at) {
    const m = minutesAgo(at);
    if (m === null || m >= 60) return null;
    return m < 5 ? 'Active now' : `Active ${m}m ago`;
  }
  function avatarDot(a, at, sz = '') {
    const el = avatar(a, sz);
    const m = minutesAgo(at);
    if (m === null || m >= 5) return el;
    return h('span', { class: 'lc-av-wrap ' + sz }, el, h('span', { class: 'lc-dot', title: 'Active now', 'aria-hidden': 'true' }));
  }
  function initialsOf(name) {
    const w = String(name || '?').trim().split(/[\s._-]+/).filter(Boolean);
    return ((w[0] || '?')[0] + (w.length > 1 ? w[w.length - 1][0] : '')).toUpperCase();
  }
  const personAv = (p, sz = 'sm') => avatar({ initials: initialsOf(p.name), tone: p.id % 6 }, sz);
  function dur(s) { s = Math.max(0, Math.round(s || 0)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; }
  function marked(text, q) {
    const out = []; const lower = text.toLowerCase(); const ql = q.toLowerCase();
    let i = 0; let j;
    while (ql && (j = lower.indexOf(ql, i)) !== -1) { out.push(text.slice(i, j), h('mark', { class: 'lc-hl', text: text.slice(j, j + ql.length) })); i = j + ql.length; }
    out.push(text.slice(i));
    return out;
  }
  const setUnread = (n) => { if (window.likhaChatUnread) window.likhaChatUnread.set(n); };
  const pad = (n) => String(n).padStart(2, '0');
  function clock(d) { let hr = d.getHours(); const ap = hr >= 12 ? 'PM' : 'AM'; hr = hr % 12 || 12; return `${hr}:${pad(d.getMinutes())} ${ap}`; }
  function dayKey(d) { return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`; }
  function dayLabel(d) {
    const now = new Date(); const y = new Date(now); y.setDate(now.getDate() - 1);
    if (dayKey(d) === dayKey(now)) return 'Today';
    if (dayKey(d) === dayKey(y)) return 'Yesterday';
    const opts = { weekday: 'short', month: 'short', day: 'numeric' };
    if (d.getFullYear() !== now.getFullYear()) opts.year = 'numeric';
    return d.toLocaleDateString('en-US', opts);
  }
  function listTime(iso) {
    if (!iso) return '';
    const d = new Date(iso); const now = new Date();
    if (dayKey(d) === dayKey(now)) return clock(d);
    if ((now - d) < 6 * 864e5) return d.toLocaleDateString('en-US', { weekday: 'short' });
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }
  function fullTime(iso) { const d = new Date(iso); return d.toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); }
  function size(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
  const isPhone = () => window.matchMedia('(max-width: 1023px)').matches;

  // Ang taas ng top bar (at ng storage banner, kung meron) para sa fixed na panel. Hindi `nav`: sa layout ng spec 295,
  // ang unang <nav> ay ang listahan sa sidebar, na abot hanggang ibaba ng screen.
  function fitTop() {
    const nav = document.querySelector('.lk-topbar') || document.querySelector('nav');
    const banner = document.querySelector('[data-storage-banner]');
    let top = nav ? nav.getBoundingClientRect().bottom : 64;
    if (banner) top = Math.max(top, banner.getBoundingClientRect().bottom);
    root.style.setProperty('--lc-top', Math.max(0, Math.round(top)) + 'px');
  }
  fitTop(); window.addEventListener('resize', fitTop); window.addEventListener('load', fitTop);

  // ── list ──
  function setRows(rows, replace) {
    if (replace) S.rows.clear();
    for (const r of rows) S.rows.set(r.id, r);
    renderList();
  }
  function renderList() {
    const q = $('lc-filter').value.trim().toLowerCase();
    const rows = [...S.rows.values()]
      .filter((r) => !q || (r.title || '').toLowerCase().includes(q))
      .sort((a, b) => (b.at || '').localeCompare(a.at || '') || b.id - a.id);
    const list = $('lc-list');
    const focusedRow = document.activeElement && document.activeElement.closest && document.activeElement.closest('.lc-row');
    const keepRow = focusedRow ? focusedRow.dataset.id : null;
    list.replaceChildren(...rows.map(rowEl));
    if (keepRow) { const again = list.querySelector(`.lc-row[data-id="${keepRow}"]`); if (again) again.focus({ preventScroll: true }); }
    $('lc-list-loading').hidden = true;
    $('lc-list-empty').hidden = S.rows.size > 0;
    list.hidden = S.rows.size === 0 || S.searching;
    $('lc-filter').parentElement.hidden = S.rows.size === 0;
    $('lc-placeholder-text').textContent = S.rows.size === 0 ? 'Start your first chat with New chat.' : 'Pick a chat on the left, or start a new one.';
    if (S.rows.size > 0 && rows.length === 0) list.append(h('li', { class: 'lc-empty', text: 'No chats match.' }));
    // The tab title and the sidebar badge belong to the unread poll of every page (components/chat-unread).
    setUnread([...S.rows.values()].reduce((n, r) => n + (r.muted || r.left ? 0 : (r.unread || 0)), 0));
  }
  function rowEl(r) {
    const last = r.last;
    // In a direct chat the other person's name is already the title; only "You:" is added there.
    const showSender = last && last.sender && (r.kind === 'group' || last.sender === 'You' || !r.member);
    const lastText = last ? (showSender ? `${last.sender}: ${last.text}` : last.text) : (r.kind === 'group' ? 'Group created' : 'No messages yet');
    return h('li', {},
      h('button', {
        type: 'button', class: 'lc-row' + (r.unread ? ' unread' : '') + (r.left ? ' left' : ''), 'data-id': r.id,
        'aria-current': S.open === r.id ? 'true' : 'false', onclick: () => openConv(r.id),
      },
        avatarDot(r.avatar, r.active_at),
        h('span', { class: 'lc-row-main' },
          h('span', { class: 'lc-row-top' }, h('span', { class: 'lc-row-name', text: r.title }),
            r.muted ? h('span', { class: 'lc-row-muted', title: 'Muted' }, icon('fa-solid fa-bell-slash'), h('span', { class: 'lc-sr', text: ' (muted)' })) : null,
            activeText(r.active_at) ? h('span', { class: 'lc-sr', text: ` (${activeText(r.active_at).toLowerCase()})` }) : null,
            !r.member ? h('span', { class: 'lc-tag-reading', text: 'Reading' }) : null,
            h('span', { class: 'lc-row-time', text: listTime(r.at) })),
          h('span', { class: 'lc-row-bottom' },
            h('span', { class: 'lc-row-last', text: lastText }),
            r.unread ? h('span', { class: 'lc-pill' + (r.muted ? ' muted' : '') }, h('span', { 'aria-hidden': 'true', text: r.unread > 99 ? '99+' : String(r.unread) }), h('span', { class: 'lc-sr', text: ` ${r.unread} unread` })) : null,
          ),
        ),
      ));
  }
  async function loadList() {
    const data = await api('GET', `/chat/conversations${S.all ? '?all=1' : ''}`);
    S.since = S.since || data.now;
    setRows(data.conversations, true);
  }

  // ── search: names here at once; from 2 characters also the server (names and message text, MP-6) ──
  let searchTimer = null;
  function showResults(on) { S.searching = on; $('lc-results').hidden = !on; $('lc-list').hidden = on || S.rows.size === 0; }
  $('lc-filter').addEventListener('input', () => {
    const q = $('lc-filter').value.trim();
    clearTimeout(searchTimer);
    if (q.length < 2) { S.searchSeq++; showResults(false); renderList(); return; }
    searchTimer = setTimeout(() => runSearch(q), 250);
  });
  async function runSearch(q) {
    const seq = ++S.searchSeq;
    const data = await api('GET', '/chat/search?q=' + encodeURIComponent(q)).catch(() => null);
    if (seq !== S.searchSeq || $('lc-filter').value.trim() !== q) return;
    if (!data) {
      $('lc-results').replaceChildren(h('p', { class: 'lc-empty', role: 'alert', text: 'Search failed. Check your connection and try again.' }));
      showResults(true); return;
    }
    $('lc-search-status').textContent = data.chats.length || data.messages.length
      ? `${data.chats.length} chat${data.chats.length === 1 ? '' : 's'}, ${data.messages.length} message${data.messages.length === 1 ? '' : 's'} found`
      : `Nothing found for ${q}`;
    const parts = [];
    if (data.chats.length) parts.push(h('h3', { text: 'Chats' }), h('ul', { class: 'lc-msgs' }, data.chats.map((r) => rowEl(S.rows.get(r.id) || r))));
    if (data.messages.length) {
      parts.push(h('h3', { text: 'Messages' }), h('ul', { class: 'lc-msgs' }, data.messages.map((m) => {
        const row = S.rows.get(m.conversation_id);
        return h('li', {}, h('button', { type: 'button', class: 'lc-row', onclick: () => openConv(m.conversation_id, { at: m.id }) },
          avatar(row ? row.avatar : { initials: initialsOf(m.title), tone: null }),
          h('span', { class: 'lc-row-main' },
            h('span', { class: 'lc-row-top' }, h('span', { class: 'lc-row-name', text: m.title }), h('span', { class: 'lc-row-time', text: listTime(m.at) })),
            // In a direct chat the title is already the other person; only "You:" is added there (as the list).
            h('span', { class: 'lc-row-bottom' }, h('span', { class: 'lc-row-last lc-hit-text' },
              (row && row.kind === 'group') || m.sender === 'You' || !row ? `${m.sender}: ` : null, ...marked(m.text, q))))));
      })));
    }
    if (!parts.length) parts.push(h('p', { class: 'lc-empty', text: `Nothing found for “${q}”.` }));
    $('lc-results').replaceChildren(...parts);
    showResults(true);
  }
  root.querySelectorAll('[data-filter]').forEach((b) => b.addEventListener('click', async () => {
    S.all = b.dataset.filter === 'all';
    root.querySelectorAll('[data-filter]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    await loadList().catch(() => {});
  }));

  // ── thread ──
  async function openConv(id, opts = {}) {
    closeNew(); closeInfo(); closePops();
    // A recording belongs to the chat it started in; players of the last chat stop and are let go.
    if (R.rec || R.starting) stopRec(true);
    releasePlayers();
    S.open = id; S.conv = null;
    root.dataset.view = 'thread';
    if (!opts.keepUrl) {
      const st = history.state || {};
      if (isPhone() && !st.c) history.pushState({ c: id, pushed: true }, '', `/chat?c=${id}`);
      else history.replaceState({ c: id, pushed: !!st.pushed }, '', `/chat?c=${id}`);
    }
    renderList();
    let data;
    try { data = await api('GET', `/chat/${id}`); } catch (e) {
      S.open = null; root.dataset.view = 'list'; history.replaceState(null, '', '/chat');
      $('lc-thread').hidden = true; $('lc-placeholder').hidden = false;
      if (e.status === 403 || e.status === 404) { renderList(); return; }
      throw e;
    }
    if (S.open !== id) return;
    S.conv = data.conversation; S.msgs = data.messages; S.hasMore = data.has_more; S.reads = data.reads;
    if (S.edit) cancelEdit();
    S.reply = null; S.files = []; S.hit = null; S.history.clear();
    renderTyping([]);
    const row = S.rows.get(id);
    if (row) { row.unread = 0; renderList(); }
    $('lc-placeholder').hidden = true; $('lc-thread').hidden = false;
    renderHead(); renderComposer(); renderMessages(true);
    if (opts.at) await reveal(id, opts.at);
    else if (!isPhone() && S.conv.me.can_post) $('lc-input').focus({ preventScroll: true });
  }
  // A search result: load earlier pages until the message is here, then scroll to it and mark it for a moment.
  async function reveal(id, mid) {
    for (let pages = 0; !byId(mid) && S.hasMore && pages < 20 && S.open === id; pages++) await loadEarlier();
    if (S.open !== id) return;
    if (!byId(mid)) { $('lc-err').textContent = 'That message is too far back to open here. Scroll up to find it.'; return; }
    S.hit = mid; renderMessages(false); jumpTo(mid);
    setTimeout(() => { if (S.hit === mid) { S.hit = null; const li = $('lc-msgs').querySelector(`li[data-id="${mid}"]`); if (li) li.classList.remove('hit'); } }, 1800);
  }
  function closeThread(opts = {}) {
    if (!opts.keepUrl && history.state && history.state.pushed) { history.back(); return; }   // popstate closes it
    if (R.rec || R.starting) stopRec(true);
    releasePlayers();
    S.open = null; S.conv = null; root.dataset.view = 'list';
    if (!opts.keepUrl) history.replaceState(null, '', '/chat');
    $('lc-thread').hidden = true; $('lc-placeholder').hidden = false; closeInfo();
    renderList();
  }
  $('lc-back').addEventListener('click', () => closeThread());
  window.addEventListener('popstate', (e) => {
    const st = e.state;
    if (st && st.c) openConv(st.c, { keepUrl: true }).catch(() => {}); else closeThread({ keepUrl: true });
  });

  function renderHead() {
    const c = S.conv;
    const other = c.kind === 'group' ? null : c.members.find((m) => m.id !== BOOT.me.id);
    $('lc-thread-av').replaceChildren(other && c.members.length === 2 ? avatarDot(c.avatar, other.active_at) : avatar(c.avatar));
    $('lc-thread-title').textContent = c.title;
    let sub = '';
    if (c.me.left) sub = 'You left this group';
    else if (c.kind === 'group') {
      sub = `${c.members.length} member${c.members.length === 1 ? '' : 's'}`;
      if (c.members.some((m) => m.id !== BOOT.me.id && activeText(m.active_at) === 'Active now')) sub += ' · Active now';
    }
    else sub = c.subtitle || (other && activeText(other.active_at)) || (other && other.role ? other.role : 'Direct chat');
    if (c.me.muted) sub += c.me.muted_until ? ` · Muted until ${clock(new Date(c.me.muted_until))}` : ' · Muted';
    $('lc-thread-sub').textContent = sub;
    $('lc-info-btn').hidden = c.kind !== 'group' || c.me.left;
    const mb = $('lc-mute-btn');
    mb.hidden = !c.me.can_post;
    mb.setAttribute('aria-label', c.me.muted ? 'Muted. Change' : 'Mute this chat');
    mb.replaceChildren(icon(c.me.muted ? 'fa-solid fa-bell-slash' : 'fa-regular fa-bell'));
  }

  // ── popovers: alerts (list header) and mute (thread header) ──
  function closePops(refocus) {
    for (const [pop, btn] of [['lc-alerts', 'lc-alerts-btn'], ['lc-mute-menu', 'lc-mute-btn']]) {
      const was = !$(pop).hidden;
      $(pop).hidden = true; $(btn).setAttribute('aria-expanded', 'false');
      if (was && refocus) $(btn).focus();
    }
  }
  document.addEventListener('click', (e) => { if (!e.target.closest('.lc-pop, #lc-alerts-btn, #lc-mute-btn')) closePops(); });
  $('lc-mute-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    const menuBox = $('lc-mute-menu'); const opening = menuBox.hidden;
    closePops();
    if (!opening || !S.conv) return;
    const items = (S.conv.me.muted
      ? [['Unmute', 'off', 'fa-regular fa-bell']]
      : [['Mute for 8 hours', '8h', 'fa-regular fa-bell-slash'], ['Mute until I turn it back on', 'on', 'fa-solid fa-bell-slash']])
      .map(([label, v, ic]) => h('button', { type: 'button', role: 'menuitem', onclick: () => setMute(v) }, icon(ic), label));
    menuBox.replaceChildren(...items);
    menuBox.hidden = false; $('lc-mute-btn').setAttribute('aria-expanded', 'true'); items[0].focus();
  });
  $('lc-mute-menu').addEventListener('keydown', (e) => {
    const items = [...$('lc-mute-menu').querySelectorAll('button')]; const i = items.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
    else if (e.key === 'Tab') closePops();
  });
  async function setMute(v) {
    closePops(true);
    try {
      const d = await api('POST', `/chat/${S.open}/mute`, { mute: v });
      S.conv.me.muted = v !== 'off';
      S.conv.me.muted_until = v === '8h' ? d.muted_until : null;
      const r = S.rows.get(S.open); if (r) r.muted = S.conv.me.muted;
      renderHead(); renderList();
    } catch (e) { $('lc-err').textContent = e.message; }
  }

  // ── Spec 308: alerts on this device, even when Likha is closed (web push). Without the server's key: 300's box. ──
  const PUSH_KEY = BOOT.push_key || null;
  const KEY_PUSH = `likha.chat.push.${BOOT.me.id}`;
  const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const installed = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  const pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;
  const P = { state: 'off', busy: false, error: '' }; // off | on | ios | blocked | unsupported
  const pushFlag = {
    get() { try { return localStorage.getItem(KEY_PUSH) === '1'; } catch (e) { return false; } },
    set(on) { try { on ? localStorage.setItem(KEY_PUSH, '1') : localStorage.removeItem(KEY_PUSH); } catch (e) { /* private mode */ } },
  };
  function keyBytes(b64) {
    const s = (b64 + '='.repeat((4 - (b64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(s), (ch) => ch.charCodeAt(0));
  }
  async function shortHash(text) {
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
    return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('').slice(0, 16);
  }
  const pushReg = () => navigator.serviceWorker.register('/sw.js', { scope: '/' });
  async function currentSub() {
    const reg = await navigator.serviceWorker.getRegistration('/');
    return reg ? reg.pushManager.getSubscription() : null;
  }
  // Works out this device's state; never asks the browser for permission.
  async function pushRefresh() {
    if (isIos && !installed && !pushSupported) { P.state = 'ios'; return; }
    if (!pushSupported) { P.state = 'unsupported'; return; }
    if (Notification.permission === 'denied') { P.state = 'blocked'; return; }
    try {
      let sub = await currentSub();
      // This device had alerts on but the browser dropped or rotated the subscription: subscribe again quietly
      // (permission is already granted, so the browser asks nothing).
      if (!sub && pushFlag.get() && Notification.permission === 'granted') sub = await subscribe();
      if (!sub) { P.state = 'off'; return; }
      const { devices } = await api('GET', '/chat/push');
      const mine = await shortHash(sub.endpoint);
      if (!devices.some((d) => d.key === mine)) {
        if (!pushFlag.get()) { P.state = 'off'; return; }
        await api('POST', '/chat/push', sub.toJSON());
      }
      pushFlag.set(true); P.state = 'on';
    } catch (e) { P.state = pushFlag.get() ? 'on' : 'off'; } // offline: keep what this device last knew
  }
  async function subscribe() {
    const reg = await pushReg();
    await navigator.serviceWorker.ready;
    const opts = { userVisibleOnly: true, applicationServerKey: keyBytes(PUSH_KEY) };
    try { return await reg.pushManager.subscribe(opts); } catch (e) {
      // A subscription made with an older server key: drop it and subscribe with the current one.
      const old = await reg.pushManager.getSubscription();
      if (!old) throw e;
      await old.unsubscribe();
      return reg.pushManager.subscribe(opts);
    }
  }
  async function turnOn() {
    P.busy = true; P.error = ''; renderAlerts();
    try {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') { P.state = perm === 'denied' ? 'blocked' : 'off'; if (perm !== 'denied') P.error = 'Alerts were not turned on. Tap Turn on alerts, then Allow.'; return; }
      const sub = await subscribe();
      await api('POST', '/chat/push', sub.toJSON());
      pushFlag.set(true); P.state = 'on';
    } catch (e) {
      P.error = navigator.onLine === false ? 'No connection. Check your internet and try again.' : 'Could not turn on alerts. Try again.';
    } finally { P.busy = false; renderAlerts(); focusPush(); }
  }
  async function turnOff() {
    P.busy = true; P.error = ''; renderAlerts();
    try {
      const sub = await currentSub();
      if (sub) { await api('DELETE', '/chat/push', { endpoint: sub.endpoint }); await sub.unsubscribe(); }
      pushFlag.set(false); P.state = 'off';
    } catch (e) {
      P.error = navigator.onLine === false ? 'No connection. Check your internet and try again.' : 'Could not turn off alerts. Try again.';
    } finally { P.busy = false; renderAlerts(); focusPush(); }
  }
  function focusPush() {
    const on = $('lc-push-off'); const off = $('lc-notif-allow');
    (!on.hidden ? on : (!off.hidden ? off : $('lc-sound'))).focus();
  }
  const PUSH_TEXT = {
    off: 'Get alerts on this device for new messages, even when Likha is closed.',
    on: 'New messages show here even when Likha is closed. Muted chats stay quiet.',
    blocked: 'Alerts are blocked for Likha in this browser. Tap the icon left of the address, open Permissions or Notifications, choose Allow, then open this again.',
    unsupported: 'This browser cannot get alerts when Likha is closed. Likha plays the sound while it is open.',
  };
  function renderPush() {
    const s = P.state;
    $('lc-push-status').hidden = s !== 'on';
    $('lc-push-ios').hidden = s !== 'ios';
    $('lc-notif-text').hidden = s === 'ios';
    $('lc-notif-text').textContent = PUSH_TEXT[s] || '';
    const allow = $('lc-notif-allow');
    allow.hidden = s !== 'off';
    allow.setAttribute('aria-disabled', String(P.busy)); // not disabled: focus stays on the button
    allow.textContent = P.busy && s === 'off' ? 'Turning on…' : 'Turn on alerts';
    const off = $('lc-push-off');
    off.hidden = s !== 'on';
    off.setAttribute('aria-disabled', String(P.busy));
    off.textContent = P.busy && s === 'on' ? 'Turning off…' : 'Turn off';
    $('lc-push-err').hidden = !P.error;
    $('lc-push-err').textContent = P.error;
  }
  const notifSupported = 'Notification' in window;
  function renderAlerts() {
    const allow = $('lc-notif-allow'); const text = $('lc-notif-text');
    if (PUSH_KEY) renderPush();
    else if (!notifSupported) { allow.hidden = true; text.textContent = 'This browser cannot show notifications. Likha plays the sound instead.'; }
    else if (Notification.permission === 'granted' && window.likhaChatUnread && !window.likhaChatUnread.notifWorks()) { allow.hidden = true; text.textContent = 'This browser shows Likha alerts as a sound only. Keep Likha open on screen to hear them.'; }
    else if (Notification.permission === 'granted') { allow.hidden = true; text.textContent = 'Notifications are on. They come while Likha is open on screen in a tab, for chats that are not open.'; }
    else if (Notification.permission === 'denied') { allow.hidden = true; text.textContent = 'Notifications are blocked. Allow them for Likha in this browser’s site settings.'; }
    else { allow.hidden = false; text.textContent = 'Get a notification when a message arrives in a chat that is not open, while Likha is open on screen in a tab.'; }
    $('lc-sound').setAttribute('aria-checked', String(window.likhaChatUnread ? window.likhaChatUnread.soundOn() : true));
  }
  $('lc-alerts-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    const opening = $('lc-alerts').hidden;
    closePops();
    if (!opening) return;
    renderAlerts(); $('lc-alerts').hidden = false; $('lc-alerts-btn').setAttribute('aria-expanded', 'true');
    if (PUSH_KEY) {
      focusPush();
      // The state can change on refresh (e.g. off → on): keep focus on the control that is shown now.
      if (!P.busy) pushRefresh().then(() => {
        if ($('lc-alerts').hidden || P.busy) return;
        const here = $('lc-alerts').contains(document.activeElement) || document.activeElement === document.body;
        renderAlerts(); if (here) focusPush();
      });
      return;
    }
    ($('lc-notif-allow').hidden ? $('lc-sound') : $('lc-notif-allow')).focus();
  });
  // Asks the browser once, only on this click (never on load).
  $('lc-notif-allow').addEventListener('click', async () => {
    if (PUSH_KEY) { if (!P.busy) turnOn(); return; }
    try { await Notification.requestPermission(); } catch (e) { /* old Safari: callback form only */ }
    renderAlerts(); $('lc-sound').focus();
  });
  if (PUSH_KEY) {
    $('lc-push-off').addEventListener('click', () => { if (!P.busy) turnOff(); });
    // This device's state at load, without asking anything (also saves a rotated subscription again).
    pushRefresh().then(() => { if (!$('lc-alerts').hidden) renderAlerts(); });
  }
  $('lc-sound').addEventListener('click', () => {
    const on = $('lc-sound').getAttribute('aria-checked') !== 'true';
    if (window.likhaChatUnread) { window.likhaChatUnread.setSound(on); if (on) window.likhaChatUnread.chime(); }
    renderAlerts();
  });
  function renderComposer() {
    const me = S.conv.me; const bar = $('lc-readonly');
    $('lc-compose').hidden = !me.can_post;
    bar.hidden = me.can_post;
    if (me.can_post) { renderReply(); renderChips(); return; }
    if (me.left) bar.replaceChildren(h('span', { text: 'You left this group' }));
    else if (me.can_join) bar.replaceChildren(
      h('span', { text: 'You are reading as the owner. Join to post.' }),
      h('button', { type: 'button', class: 'lc-btn primary', onclick: join }, icon('fa-solid fa-right-to-bracket'), 'Join'));
    else bar.replaceChildren(h('span', { text: 'You are reading as the owner. Only the two people in a direct chat can post here.' }));
  }

  const byId = (id) => S.msgs.find((m) => m.id === id);
  function senderKey(m) { return m.sender.kind === 'user' ? 'u' + m.sender.id : m.sender.kind === 'ai' ? 'a' + m.sender.key : 'sys'; }
  function nearBottom() { const s = $('lc-scroll'); return s.scrollHeight - s.scrollTop - s.clientHeight < 80; }
  function toBottom(smooth) {
    const s = $('lc-scroll');
    s.scrollTo({ top: s.scrollHeight, behavior: smooth && !reduceMotion() ? 'smooth' : 'auto' });
    $('lc-new-pill').hidden = true;
  }
  // Seen heads (spec 300): each other member under the last message they read.
  function seenMap() {
    const map = new Map();
    const shown = S.msgs.filter((m) => m.id > 0 && m.sender.kind !== 'system');
    for (const r of S.reads) {
      if (!r.last_read_message_id) continue;
      let at = null;
      for (const m of shown) { if (m.id <= r.last_read_message_id) at = m; else break; }
      if (at) { if (!map.has(at.id)) map.set(at.id, []); map.get(at.id).push(r); }
    }
    return map;
  }

  function renderMessages(stick, arrivedIds = new Set()) {
    const list = $('lc-msgs');
    const entries = [];
    let prev = null; let prevDay = null;
    for (const m of S.msgs) {
      const d = new Date(m.at);
      if (dayKey(d) !== prevDay) { entries.push({ day: dayLabel(d) }); prevDay = dayKey(d); prev = null; }
      if (m.sender.kind === 'system') { entries.push({ sys: m }); prev = null; continue; }
      const cont = !!prev && senderKey(prev) === senderKey(m) && (d - new Date(prev.at)) < 5 * 60e3;
      entries.push({ m, cont });
      prev = m;
    }
    const seen = seenMap();
    const out = [];
    entries.forEach((e, i) => {
      if (e.day) { out.push(h('li', { class: 'lc-day', text: e.day })); return; }
      if (e.sys) { out.push(h('li', { class: 'lc-sys', 'data-id': e.sys.id, text: e.sys.text || '' })); return; }
      const next = entries[i + 1];
      const nextCont = !!(next && next.m && next.cont);
      const pos = e.cont ? (nextCont ? 'run-mid' : 'run-last') : (nextCont ? 'run-first' : '');
      out.push(msgEl(e.m, e.cont, nextCont, pos, arrivedIds.has(e.m.id)));
      const who = seen.get(e.m.id);
      if (who) {
        out.push(h('li', { class: 'lc-seen-at' },
          h('span', { class: 'lc-sr', text: `Seen by ${who.map((r) => r.name).join(', ')}` }),
          ...who.slice(0, 8).map((r) => { const a = avatar(r, 'xs'); a.setAttribute('title', `Seen by ${r.name}`); return a; }),
          ...(who.length > 8 ? [h('span', { class: 'lc-row-time', text: `+${who.length - 8}` })] : [])));
      }
    });
    const active = document.activeElement;
    const keepLi = active && active.closest && active.closest('#lc-msgs > li');
    // Every control in a message has a stable data-k, so focus survives the redraw of a poll (popovers included).
    const keep = keepLi && active.dataset && active.dataset.k ? { id: keepLi.dataset.id, k: active.dataset.k } : null;
    // An open menu, reaction bar or "who reacted" survives the redraw of a poll.
    const openPop = list.querySelector('.lc-menu, .lc-rbar, .lc-who');
    const pop = openPop ? { id: openPop.closest('li').dataset.id, kind: openPop.classList.contains('lc-menu') ? 'menu' : openPop.classList.contains('lc-rbar') ? 'rbar' : 'who' } : null;
    list.replaceChildren(...out);
    if (pop) {
      const li = list.querySelector(`li[data-id="${pop.id}"]`); const mm = byId(Number(pop.id));
      if (li && mm && !mm.removed) { li.classList.add('show'); (pop.kind === 'menu' ? menu : pop.kind === 'rbar' ? reactBar : whoReacted)(li, mm, false); }
    }
    if (keep) refocus(keep.id, keep.k);
    $('lc-earlier').hidden = !S.hasMore;
    const empty = $('lc-thread-empty');
    empty.hidden = S.msgs.length > 0;
    if (!S.msgs.length) empty.textContent = S.conv.kind === 'group' ? 'No messages yet. Say hello to the group.' : `No messages yet. Say hello to ${S.conv.title}.`;
    if (stick) toBottom(stick === 'smooth');
  }
  function refocus(id, ...keys) {
    const li = $('lc-msgs').querySelector(`li[data-id="${id}"]`);
    if (!li) return;
    for (const k of keys) {
      const el = li.querySelector(`[data-k="${CSS.escape(k)}"]`);
      if (el) { li.classList.add('show'); el.focus({ preventScroll: true }); return; }
    }
  }
  const canAct = (m) => !m.pending && !m.failed && !m.removed && S.conv.me.can_post;
  function msgEl(m, cont, nextCont, pos, arrived) {
    const me = m.sender.me;
    const cls = ['lc-msg']; if (me) cls.push('me'); if (cont) cls.push('cont'); if (arrived) cls.push('arrive');
    if (pos) cls.push(pos);
    if (m.pending) cls.push('pending'); if (m.failed) cls.push('failed');
    if (m.reactions && m.reactions.length && !m.removed) cls.push('has-reacts');
    if (S.hit === m.id) cls.push('hit');
    const showName = !me && !cont && (S.conv.kind === 'group' || m.sender.kind === 'ai');
    const col = h('div', { class: 'lc-col' });
    col.append(h('span', { class: 'lc-sr', text: `${me ? 'You' : (m.sender.name || '')}${m.sender.kind === 'ai' ? ' (AI)' : ''}, ${fullTime(m.at)}: ` }));
    if (showName) col.append(h('div', { class: 'lc-name' }, m.sender.name, m.sender.kind === 'ai' ? [' ', aiMark()] : null));

    const bubble = h('div', { class: 'lc-bubble' + (m.removed ? ' removed' : '') });
    if (m.removed) bubble.textContent = 'Message removed';
    else {
      if (m.reply) {
        bubble.append(h('button', { type: 'button', class: 'lc-quote', 'data-k': 'quote', onclick: (e) => { e.stopPropagation(); jumpTo(m.reply.id); } },
          h('b', { text: m.reply.name }), h('span', { text: m.reply.removed ? 'Message removed' : (m.reply.text || '') })));
      }
      if (m.html) { const t = h('div'); t.innerHTML = m.html; bubble.append(t); }   // escaped by the server (ChatText)
    }
    const imgs = m.removed ? [] : m.files.filter((f) => f.kind === 'image');
    const voices = m.removed ? [] : m.files.filter((f) => f.kind === 'audio');
    const docs = m.removed ? [] : m.files.filter((f) => f.kind !== 'image' && f.kind !== 'audio');
    if (!m.removed && (m.html || m.reply || (!imgs.length && !docs.length && !voices.length))) col.append(bubble);
    else if (m.removed) col.append(bubble);
    for (const f of voices) col.append(voiceEl(f));
    if (imgs.length) col.append(h('div', { class: 'lc-imgs ' + (imgs.length === 1 ? 'n1' : 'multi') },
      imgs.map((f, i) => h('button', { type: 'button', 'data-k': `photo:${i}`, 'aria-label': `Open photo ${f.name}`, onclick: (e) => { e.stopPropagation(); lightbox(f); } },
        h('img', { src: f.url, alt: f.name, loading: 'lazy' })))));
    docs.forEach((f, i) => col.append(h('a', { class: 'lc-file', 'data-k': `file:${i}`, href: f.url, download: f.name },
      icon('fa-regular fa-file-lines'), h('span', {}, f.name, h('small', { text: size(f.size) })))));
    const li = h('li', { class: cls.join(' '), 'data-id': m.id });
    // Reactions: up to three emoji and the total; tap for who reacted.
    if (!m.removed && m.reactions && m.reactions.length) {
      const total = m.reactions.reduce((n, r) => n + r.count, 0);
      const mine = m.reactions.some((r) => r.mine);
      col.append(h('button', {
        type: 'button', class: 'lc-reacts' + (mine ? ' mine' : ''), 'data-k': 'reacts', 'aria-haspopup': 'dialog',
        'aria-label': `Reactions: ${m.reactions.map((r) => `${EMOJI_NAMES[r.emoji] || r.emoji} ${r.count}`).join(', ')}. Show who reacted`,
        onclick: (e) => { e.stopPropagation(); whoReacted(li, m); },
      }, ...m.reactions.slice(0, 3).map((r) => h('span', { 'aria-hidden': 'true', text: r.emoji })), total > 1 ? h('span', { class: 'n', 'aria-hidden': 'true', text: String(total) }) : null));
    }
    // "Edited" always shows; the owner can open the earlier versions.
    if (m.edited && !m.removed) {
      const open = S.history.has(m.id);
      col.append(h('div', { class: 'lc-edited' }, m.edited_at ? `Edited ${clock(new Date(m.edited_at))}` : 'Edited',
        m.history && m.history.length ? [' · ', h('button', { type: 'button', 'data-k': 'history', 'aria-expanded': String(open), onclick: (e) => { e.stopPropagation(); open ? S.history.delete(m.id) : S.history.add(m.id); renderMessages(false); } }, open ? 'Hide history' : 'History')] : null));
      if (open && m.history) {
        col.append(h('div', { class: 'lc-history' }, h('ol', {}, m.history.map((x) => h('li', {}, h('time', { text: x.at ? fullTime(x.at) : '' }), h('span', { text: x.text }))))));
      }
    }
    const meta = m.failed ? h('div', { class: 'lc-meta', role: 'alert' }, icon('fa-solid fa-circle-exclamation'), ` Not sent. ${m.error || ''} `, h('button', { type: 'button', class: 'lc-btn lc-retry', 'data-k': 'retry', onclick: () => retry(m) }, 'Retry'))
      : h('div', { class: 'lc-meta', text: m.pending ? 'Sending…' : fullTime(m.at) });
    col.append(meta);

    li.append(h('div', { class: 'lc-av-slot' }, !me && !nextCont ? avatar({ ...m.sender, ai: m.sender.kind === 'ai' }, 'sm') : null), col);
    if (canAct(m)) {
      li.append(h('div', { class: 'lc-tools' },
        h('button', { type: 'button', class: 'lc-icon-btn lc-react', 'data-k': 'react', 'aria-label': 'React', 'aria-expanded': 'false', onclick: (e) => { e.stopPropagation(); reactBar(li, m); } }, icon('fa-regular fa-face-smile')),
        h('button', { type: 'button', class: 'lc-icon-btn lc-act', 'data-k': 'act', 'aria-label': `Actions for ${me ? 'your message' : 'the message from ' + m.sender.name}`, 'aria-haspopup': 'menu', 'aria-expanded': 'false', onclick: (e) => { e.stopPropagation(); menu(li, m); } }, icon('fa-solid fa-ellipsis'))));
    }
    li.addEventListener('click', (e) => {
      if (li._long) { li._long = false; return; }   // the click that ends a long-press
      if (e.target.closest('a,button,audio')) return;
      li.classList.toggle('show');
    });
    if (canAct(m)) {
      // Long-press on a phone (and right-click): the reaction bar; the ⋯ beside it has Reply, Edit and Remove.
      // Android fires contextmenu on a long-press; on a desktop the native right-click menu (Copy…) stays.
      li.addEventListener('contextmenu', (e) => { if (!coarse || e.target.closest('a')) return; e.preventDefault(); li.classList.add('show'); reactBar(li, m, false); });
      let press = null;
      li.addEventListener('pointerdown', (e) => {
        if (e.pointerType !== 'touch' || e.target.closest('a,button')) return;
        const x = e.clientX; const y = e.clientY;
        press = { x, y, t: setTimeout(() => {
          li._long = true; setTimeout(() => { li._long = false; }, 600);   // only the click that ends this press is skipped
          li.classList.add('show'); reactBar(li, m, false); if (navigator.vibrate) navigator.vibrate(10);
        }, 450) };
      });
      const cancel = () => { if (press) { clearTimeout(press.t); press = null; } };
      li.addEventListener('pointermove', (e) => { if (press && Math.hypot(e.clientX - press.x, e.clientY - press.y) > 10) cancel(); });
      li.addEventListener('pointerup', cancel); li.addEventListener('pointercancel', cancel);
    }
    return li;
  }
  // Opening another chat stops the players of this one and lets them go (and the local copies of my recordings).
  function releasePlayers() {
    for (const [url, el] of S.players) { el._audio.pause(); el._audio.removeAttribute('src'); if (url.startsWith('blob:')) URL.revokeObjectURL(url); }
    S.players.clear();
  }
  // One player per voice message, kept across redraws so playback does not stop when a poll redraws the list.
  function voiceEl(f) {
    if (S.players.has(f.url)) return S.players.get(f.url);
    const audio = new Audio(); audio.preload = 'none'; audio.src = f.url;
    const btn = h('button', { type: 'button', 'data-k': 'voice', 'aria-label': `Play voice message, ${dur(f.duration)}` }, icon('fa-solid fa-play'));
    const fill = h('span'); const time = h('time', { text: dur(f.duration) });
    const length = () => (audio.duration && isFinite(audio.duration) ? audio.duration : f.duration);
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (!audio.paused) { audio.pause(); return; }
      for (const p of S.players.values()) if (p._audio !== audio) p._audio.pause();
      audio.play().catch(() => { $('lc-err').textContent = 'This voice message cannot play in this browser.'; });
    });
    audio.addEventListener('play', () => { btn.replaceChildren(icon('fa-solid fa-pause')); btn.setAttribute('aria-label', 'Pause voice message'); });
    audio.addEventListener('pause', () => { btn.replaceChildren(icon('fa-solid fa-play')); btn.setAttribute('aria-label', `Play voice message, ${dur(f.duration)}`); });
    audio.addEventListener('timeupdate', () => { fill.style.width = Math.min(100, (audio.currentTime / length()) * 100) + '%'; time.textContent = dur(length() - audio.currentTime); });
    audio.addEventListener('ended', () => { fill.style.width = '0'; time.textContent = dur(f.duration); });
    const el = h('div', { class: 'lc-voice' }, btn, h('span', { class: 'track', 'aria-hidden': 'true' }, fill), time);
    el._audio = audio;
    S.players.set(f.url, el);
    return el;
  }
  function closeMenus(refocus) {
    root.querySelectorAll('.lc-menu, .lc-rbar, .lc-who').forEach((x) => {
      const li = x.closest('li');
      const btn = li && li.querySelector(x.classList.contains('lc-menu') ? '.lc-act' : x.classList.contains('lc-rbar') ? '.lc-react' : '.lc-reacts');
      if (btn) { if (btn.hasAttribute('aria-expanded')) btn.setAttribute('aria-expanded', 'false'); if (refocus) btn.focus(); }
      x.remove();
    });
  }
  const myReaction = (m) => (m.reactions || []).find((r) => r.mine)?.emoji || null;
  function reactBar(li, m, focusFirst = true) {
    const had = li.querySelector('.lc-rbar'); closeMenus(); if (had && focusFirst) return;
    const mine = myReaction(m);
    const items = BOOT.reactions.map((e) => h('button', {
      type: 'button', 'data-k': `rbar:${e}`, 'aria-label': `${mine === e ? 'Remove' : 'React with'} ${EMOJI_NAMES[e] || e}`, 'aria-pressed': String(mine === e), text: e,
      onclick: (ev) => { ev.stopPropagation(); react(m, e); },
    }));
    const bar = h('div', { class: 'lc-rbar', role: 'toolbar', 'aria-label': 'Reactions' }, items);
    bar.addEventListener('keydown', (e) => {
      const i = items.indexOf(document.activeElement);
      if (e.key === 'ArrowRight') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenus(true); }
      else if (e.key === 'Tab') closeMenus();
    });
    const btn = li.querySelector('.lc-react'); if (btn) btn.setAttribute('aria-expanded', 'true');
    li.append(bar); if (focusFirst) items[0].focus();
  }
  function whoReacted(li, m, focusFirst = true) {
    const had = li.querySelector('.lc-who'); closeMenus(); if (had && focusFirst) return;
    const rows = m.reactions.flatMap((r) => r.people.map((p) => ({ p, e: r.emoji })))
      .sort((a, b) => (b.p.id === BOOT.me.id) - (a.p.id === BOOT.me.id));
    const box = h('div', { class: 'lc-who', role: 'dialog', 'aria-label': 'Who reacted', tabindex: '-1' }, h('h4', { text: 'Who reacted' }),
      h('ul', {}, rows.map(({ p, e }) => h('li', {}, p.id === BOOT.me.id && S.conv.me.can_post
        ? h('button', { type: 'button', 'data-k': 'who', onclick: (ev) => { ev.stopPropagation(); react(m, e); } }, personAv(p, 'sm'), h('span', {}, 'You', h('small', { text: 'Remove' })), h('span', { class: 'e' }, h('span', { 'aria-hidden': 'true', text: e }), h('span', { class: 'lc-sr', text: ` ${EMOJI_NAMES[e] || ''}` })))
        : [personAv(p, 'sm'), h('span', { text: p.name }), h('span', { class: 'e' }, h('span', { 'aria-hidden': 'true', text: e }), h('span', { class: 'lc-sr', text: ` ${EMOJI_NAMES[e] || ''}` }))]))));
    box.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenus(true); } });
    li.append(box);
    // Near the composer it would be cut off: open it above the message instead.
    const room = $('lc-scroll').getBoundingClientRect().bottom - li.getBoundingClientRect().bottom;
    if (room < box.offsetHeight + 8) { box.style.top = 'auto'; box.style.bottom = '100%'; box.style.marginTop = '0'; box.style.marginBottom = '4px'; }
    if (focusFirst) (box.querySelector('button') || box).focus({ preventScroll: true });
  }
  const replaceMsg = (nm) => { const i = S.msgs.findIndex((x) => x.id === nm.id); if (i >= 0) S.msgs[i] = nm; };
  async function react(m, e) {
    closeMenus();
    try {
      const d = await api('POST', `/chat/${S.open}/messages/${m.id}/reactions`, { emoji: e });
      replaceMsg(d.message); renderMessages(false);
      refocus(m.id, 'reacts', 'react');   // keyboard users stay on the message they reacted to
    } catch (err) { $('lc-err').textContent = err.message; }
  }
  const editable = (m) => m.sender.me && m.edit_until && new Date(m.edit_until) > new Date() && !m.files.some((f) => f.kind === 'audio');
  function menu(li, m, focusFirst = true) {
    const had = li.querySelector('.lc-menu'); closeMenus(); if (had && focusFirst) return;
    const items = [h('button', { type: 'button', role: 'menuitem', 'data-k': 'menu:reply', onclick: () => { closeMenus(); startReply(m); } }, icon('fa-solid fa-reply'), 'Reply')];
    if (editable(m)) items.push(h('button', { type: 'button', role: 'menuitem', 'data-k': 'menu:edit', onclick: () => { closeMenus(); startEdit(m); } }, icon('fa-solid fa-pen'), 'Edit'));
    if (m.sender.me) items.push(h('button', { type: 'button', role: 'menuitem', 'data-k': 'menu:remove', class: 'danger', style: 'color:var(--lc-danger)', onclick: () => { closeMenus(); removeMsg(m); } }, icon('fa-regular fa-trash-can'), 'Remove'));
    const box = h('div', { class: 'lc-menu', role: 'menu' }, items);
    box.addEventListener('keydown', (e) => {
      const i = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenus(true); }
      else if (e.key === 'Tab') closeMenus();
    });
    const btn = li.querySelector('.lc-act'); if (btn) btn.setAttribute('aria-expanded', 'true');
    li.append(box); if (focusFirst) items[0].focus();
  }
  document.addEventListener('click', (e) => { if (!e.target.closest('.lc-menu, .lc-rbar, .lc-who')) closeMenus(); });
  function jumpTo(id) {
    const el = $('lc-msgs').querySelector(`[data-id="${id}"]`);
    if (el) { el.scrollIntoView({ block: 'center', behavior: reduceMotion() ? 'auto' : 'smooth' }); el.classList.add('show'); }
  }
  $('lc-new-pill').addEventListener('click', () => toBottom(true));
  $('lc-scroll').addEventListener('scroll', () => { if (nearBottom()) $('lc-new-pill').hidden = true; });
  async function loadEarlier() {
    const first = S.msgs.find((m) => m.id > 0); if (!first) return;
    const s = $('lc-scroll'); const before = s.scrollHeight; const conv = S.open;
    const data = await api('GET', `/chat/${conv}?before=${first.id}`).catch(() => null);
    if (!data || S.open !== conv) return;
    S.msgs = [...data.messages, ...S.msgs]; S.hasMore = data.has_more;
    renderMessages(false); s.scrollTop = s.scrollHeight - before;
  }
  $('lc-earlier').addEventListener('click', loadEarlier);

  // ── typing (MP-5): "Rey is typing…" from the poll ──
  // Changed only when the words change, so a screen reader hears "Rey is typing…" once, not every poll.
  function renderTyping(list) {
    S.typing = list;
    const box = $('lc-typing');
    const first = (n) => String(n || 'Someone').split(' ')[0];
    const text = !list.length ? '' : list.length === 1 ? `${first(list[0].name)} is typing…`
      : list.length === 2 ? `${first(list[0].name)} and ${first(list[1].name)} are typing…` : 'Several people are typing…';
    if (box.dataset.text === text) return;
    const stick = S.conv && nearBottom();
    box.dataset.text = text;
    box.replaceChildren(...(text ? [h('span', { class: 'lc-dots', 'aria-hidden': 'true' }, h('i'), h('i'), h('i')), h('span', { text })] : []));
    if (stick) toBottom();
  }

  // ── composer ──
  const input = $('lc-input');
  function grow() { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight + 2, 150) + 'px'; }
  input.addEventListener('input', () => {
    grow();
    const n = input.value.length;
    $('lc-count').textContent = n > BOOT.max_body - 400 ? `${n} / ${BOOT.max_body}` : '';
    // "is typing…" for the others: at most one small ping every 3 s while there is text (spec 300, MP-5).
    if (S.conv && S.conv.me.can_post && !S.edit && input.value.trim() && Date.now() - S.typingAt > 3000) {
      S.typingAt = Date.now();
      api('POST', `/chat/${S.open}/typing`).catch(() => {});
    }
  });
  // Desktop: Enter sends, Shift+Enter is a new line. Phone: Enter is a new line; the big Send button sends.
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && !coarse) { e.preventDefault(); send(); }
  });
  $('lc-form').addEventListener('submit', (e) => { e.preventDefault(); send(); });
  $('lc-attach').addEventListener('click', () => $('lc-file').click());
  $('lc-file').addEventListener('change', (e) => {
    const err = [];
    for (const f of e.target.files) {
      if (S.files.length >= BOOT.max_files) { err.push(`At most ${BOOT.max_files} files per message.`); break; }
      if (f.size > BOOT.max_file_kb * 1024) { err.push(`${f.name} is over 10 MB.`); continue; }
      S.files.push(f);
    }
    e.target.value = '';
    $('lc-err').textContent = err.join(' ');
    renderChips();
  });
  function renderChips() {
    const box = $('lc-chips');
    box.hidden = S.files.length === 0;
    box.replaceChildren(...S.files.map((f, i) => h('span', { class: 'lc-chip' },
      icon(f.type.startsWith('image/') ? 'fa-regular fa-image' : 'fa-regular fa-file'), h('span', { text: `${f.name} · ${size(f.size)}` }),
      h('button', { type: 'button', 'aria-label': `Remove ${f.name}`, onclick: () => { S.files.splice(i, 1); renderChips(); } }, icon('fa-solid fa-xmark')))));
  }
  function startReply(m) { S.reply = m; renderReply(); input.focus(); }
  function renderReply() {
    const bar = $('lc-replybar');
    bar.hidden = !S.reply;
    if (S.reply) $('lc-replytext').textContent = `Replying to ${S.reply.sender.me ? 'your message' : S.reply.sender.name}: ${S.reply.text || 'a file'}`;
  }
  $('lc-reply-cancel').addEventListener('click', () => { S.reply = null; renderReply(); });

  // ── edit your own text within 15 minutes (MP-4) ──
  function startEdit(m) {
    S.reply = null; renderReply();
    S.edit = m; $('lc-editbar').hidden = false;
    input.setAttribute('aria-describedby', 'lc-editbar'); $('lc-send').setAttribute('aria-label', 'Save edit');
    input.value = m.text || ''; grow(); input.focus();
    input.setSelectionRange(input.value.length, input.value.length);
  }
  function cancelEdit() {
    input.removeAttribute('aria-describedby'); $('lc-send').setAttribute('aria-label', 'Send');
    S.edit = null; $('lc-editbar').hidden = true; input.value = ''; grow(); $('lc-err').textContent = ''; }
  $('lc-edit-cancel').addEventListener('click', () => { cancelEdit(); input.focus(); });
  async function saveEdit() {
    const m = S.edit; const body = input.value.trim();
    if (!body) { $('lc-err').textContent = 'Write a message, or cancel editing.'; return; }
    if (body.length > BOOT.max_body) { $('lc-err').textContent = `A message can have at most ${BOOT.max_body} characters.`; return; }
    if (body === (m.text || '').trim()) { cancelEdit(); return; }
    try { const d = await api('PATCH', `/chat/${S.open}/messages/${m.id}`, { body }); replaceMsg(d.message); cancelEdit(); renderMessages(false); }
    catch (e) { $('lc-err').textContent = e.message; }
  }

  let tempId = 0;
  async function send() {
    if (S.edit) { await saveEdit(); return; }
    const body = input.value.trim();
    if (!S.conv || (!body && !S.files.length)) return;
    if (body.length > BOOT.max_body) { $('lc-err').textContent = `A message can have at most ${BOOT.max_body} characters.`; return; }
    const pending = {
      id: --tempId, pending: true, removed: false, at: new Date().toISOString(), files: [], text: body, html: null,
      sender: { kind: 'user', id: BOOT.me.id, me: true, name: BOOT.me.name },
      reply: S.reply ? { id: S.reply.id, name: S.reply.sender.me ? 'You' : S.reply.sender.name, text: S.reply.text, removed: false } : null,
      _form: { body, reply: S.reply ? S.reply.id : null, files: [...S.files] },
    };
    pending.html = body.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c])).replace(/\n/g, '<br>');
    input.value = ''; grow(); $('lc-count').textContent = ''; $('lc-err').textContent = '';
    S.reply = null; S.files = []; renderReply(); renderChips();
    S.msgs.push(pending); renderMessages(true);
    await deliver(pending);
  }
  async function deliver(p) {
    const fd = new FormData();
    const conv = p._form.conv || S.open;   // a voice message goes to the chat it was recorded in
    let url = `/chat/${conv}/messages`;
    if (p._form.voice) {
      fd.append('voice', p._form.voice, `voice.${p._form.ext}`); fd.append('duration', p._form.duration);
      url = `/chat/${conv}/voice`;
    } else {
      if (p._form.body) fd.append('body', p._form.body);
      if (p._form.reply) fd.append('reply_to_id', p._form.reply);
      p._form.files.forEach((f) => fd.append('files[]', f));
    }
    try {
      const data = await api('POST', url, fd);
      // The local copy of a recording is no longer needed once the server has it.
      for (const f of p.files || []) if (f.url && f.url.startsWith('blob:')) { const el = S.players.get(f.url); if (el) el._audio.pause(); S.players.delete(f.url); URL.revokeObjectURL(f.url); }
      if (S.open !== conv) return;
      const i = S.msgs.indexOf(p);
      if (byId(data.message.id)) S.msgs.splice(i, 1); else S.msgs[i] = data.message;
      renderMessages(nearBottom());
    } catch (e) {
      p.pending = false; p.failed = true; p.error = e.message;
      if (S.open === conv) renderMessages(nearBottom());
    }
  }
  function retry(m) { m.failed = false; m.pending = true; renderMessages(nearBottom()); deliver(m); }

  // ── voice (MP-7): hold the mic to record up to 2 minutes, release to send; hidden where the browser cannot record ──
  const mic = $('lc-mic');
  const canRecord = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
  mic.hidden = !canRecord;
  const R = { rec: null, stream: null, chunks: [], start: 0, timer: null, cancel: false, starting: false, early: false, away: false, keys: false };
  function recType() {
    for (const t of ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus']) {
      if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t)) return t;
    }
    return '';
  }
  const recHint = () => (R.away ? 'Release to cancel' : R.keys ? 'Enter to send · Esc to cancel' : isPhone() ? 'Release to send · slide away to cancel' : 'Release to send · Esc to cancel');
  function stopStream() { if (R.stream) R.stream.getTracks().forEach((t) => t.stop()); R.stream = null; }
  async function startRec() {
    if (R.rec || R.starting || !S.conv || !S.conv.me.can_post) return;
    R.starting = true; R.cancel = false; R.early = false; R.away = false; $('lc-err').textContent = '';
    try { R.stream = await navigator.mediaDevices.getUserMedia({ audio: true }); }
    catch (e) { R.starting = false; $('lc-err').textContent = 'Allow the microphone in the browser to record.'; return; }
    R.starting = false;
    if (R.early) { stopStream(); return; }   // let go while the browser was still asking
    const type = recType();
    try { R.rec = new MediaRecorder(R.stream, type ? { mimeType: type } : undefined); } catch (e) { stopStream(); $('lc-err').textContent = 'This browser cannot record voice messages.'; return; }
    R.chunks = [];
    R.rec.addEventListener('dataavailable', (e) => { if (e.data && e.data.size) R.chunks.push(e.data); });
    R.rec.addEventListener('stop', finishRec);
    R.rec.start(250); R.start = Date.now(); R.conv = S.open;
    const stick = nearBottom();
    mic.setAttribute('aria-pressed', 'true'); $('lc-rec').hidden = false; $('lc-rec-hint').textContent = recHint();
    if (stick) toBottom();
    // Said once (the timer itself is not read out every second).
    $('lc-announce').textContent = R.keys ? 'Recording. Press Enter to send, Escape to cancel.' : 'Recording. Release to send.';
    tick(); R.timer = setInterval(tick, 250);
  }
  function tick() {
    const s = Math.floor((Date.now() - R.start) / 1000);
    $('lc-rec-time').textContent = dur(s);
    if (s === BOOT.voice_max_seconds - 10 && !R.warned) { R.warned = true; $('lc-announce').textContent = '10 seconds left.'; }
    if (s >= BOOT.voice_max_seconds) stopRec(false);   // at 2:00 it sends by itself
  }
  function stopRec(cancel) {
    if (!R.rec) { if (R.starting) R.early = true; return; }
    R.cancel = cancel;
    if (R.rec.state !== 'inactive') R.rec.stop();
  }
  function finishRec() {
    clearInterval(R.timer); stopStream();
    const ms = Date.now() - R.start;
    const type = (R.rec && R.rec.mimeType) || 'audio/webm';
    const conv = R.conv;
    R.rec = null; R.keys = false; R.warned = false;
    $('lc-announce').textContent = R.cancel || ms < 1000 ? 'Recording cancelled.' : '';
    mic.setAttribute('aria-pressed', 'false'); $('lc-rec').hidden = true;
    if (R.cancel || ms < 1000) return;   // under a second: a slip, not a message
    const blob = new Blob(R.chunks, { type });
    if (blob.size > BOOT.voice_max_kb * 1024) { $('lc-err').textContent = 'Voice message is too large.'; return; }
    const ext = type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm';
    const secs = Math.max(1, Math.min(BOOT.voice_max_seconds, Math.round(ms / 1000)));
    const pending = {
      id: --tempId, pending: true, removed: false, at: new Date().toISOString(), text: '', html: '', reply: null, reactions: [],
      files: [{ kind: 'audio', url: URL.createObjectURL(blob), duration: secs, name: 'Voice message' }],
      sender: { kind: 'user', id: BOOT.me.id, me: true, name: BOOT.me.name },
      _form: { voice: blob, ext, duration: secs, conv },
    };
    if (S.open === conv) { S.msgs.push(pending); renderMessages(true); }
    deliver(pending);
  }
  // A held mic that loses the pointer, the window or the tab is a cancelled recording (it never sends by itself).
  mic.addEventListener('lostpointercapture', () => { if (!R.keys && (R.rec || R.starting)) stopRec(R.away); });
  window.addEventListener('blur', () => { if (R.rec || R.starting) stopRec(true); });
  document.addEventListener('visibilitychange', () => { if (document.hidden && (R.rec || R.starting)) stopRec(true); });
  mic.addEventListener('pointerdown', (e) => {
    if (e.button !== 0) return;
    e.preventDefault();
    try { mic.setPointerCapture(e.pointerId); } catch (err) { /* old browsers */ }
    R.x = e.clientX; R.y = e.clientY; R.keys = false;
    startRec();
  });
  mic.addEventListener('pointermove', (e) => {
    if (!R.rec) return;
    const away = Math.hypot(e.clientX - R.x, e.clientY - R.y) > 80;
    if (away !== R.away) { R.away = away; $('lc-rec-hint').textContent = recHint(); }
  });
  mic.addEventListener('pointerup', () => { if (!R.keys) stopRec(R.away); });
  mic.addEventListener('pointercancel', () => { if (!R.keys) stopRec(true); });
  mic.addEventListener('contextmenu', (e) => e.preventDefault());
  // Keyboard: Enter or Space starts, and again sends.
  mic.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    if (e.repeat) return;
    if (R.rec) stopRec(false); else { R.keys = true; startRec(); }
  });
  async function removeMsg(m) {
    if (!confirm('Remove this message for everyone?')) return;
    try { await api('DELETE', `/chat/${S.open}/messages/${m.id}`); m.removed = true; m.html = null; m.text = null; m.files = []; renderMessages(false); }
    catch (e) { $('lc-err').textContent = e.message; }
  }
  async function join() {
    try { await api('POST', `/chat/${S.open}/join`); await openConv(S.open, { keepUrl: true }); }
    catch (e) { $('lc-readonly').append(h('span', { class: 'lc-msg-err', role: 'alert', text: e.message })); }
  }

  // ── polling: bawat 3 s, hinto kapag nakatago ang tab ──
  function schedule(ms = BOOT.poll_ms) { clearTimeout(S.timer); if (!document.hidden) S.timer = setTimeout(poll, ms); }
  async function poll() {
    if (S.inflight || document.hidden) return;
    S.inflight = true;
    const conv = S.open;
    const after = S.msgs.reduce((n, m) => (m.id > n ? m.id : n), 0);
    const qs = new URLSearchParams();
    if (S.since) qs.set('since', S.since);
    if (S.all) qs.set('all', '1');
    if (conv && S.conv) { qs.set('c', conv); qs.set('after', after); }
    try {
      const data = await api('GET', '/chat/poll?' + qs);
      S.fails = 0; $('lc-reconnect').hidden = true;
      S.since = data.now;
      if (data.conversations.length) setRows(data.conversations.map((r) => (r.id === S.open ? { ...r, unread: 0 } : r)));
      if (data.thread && S.open === conv && S.conv && S.conv.id === conv) {
        const stick = nearBottom();
        const fresh = data.thread.messages.filter((m) => !byId(m.id));
        for (const id of data.thread.removed) { const m = byId(id); if (m) { m.removed = true; m.html = null; m.text = null; m.files = []; m.reactions = []; } }
        // Spec 300: reactions and edits on messages already on screen.
        const changed = (data.thread.changed || []).filter((m) => byId(m.id));
        changed.forEach(replaceMsg);
        S.msgs.push(...fresh);
        const heard = fresh.filter((m) => !m.sender.me && m.sender.kind !== 'system');
        if (heard.length) $('lc-announce').textContent = heard.map((m) => `${m.sender.name}: ${m.text || (m.files.some((f) => f.kind === 'audio') ? 'voice message' : 'sent a file')}`).join('. ');
        // Whoever just sent a message is no longer "typing".
        const spoke = new Set(fresh.map((m) => m.sender.id));
        renderTyping((data.thread.typing || []).filter((t) => !spoke.has(t.id)));
        const readsChanged = JSON.stringify(S.reads) !== JSON.stringify(data.thread.reads);
        S.reads = data.thread.reads;
        if (fresh.length || data.thread.removed.length || changed.length || readsChanged) {
          renderMessages(stick && fresh.length ? 'smooth' : stick, new Set(fresh.map((m) => m.id)));
          if (!stick && fresh.some((m) => !m.sender.me)) $('lc-new-pill').hidden = false;
        }
      }
      setUnread(data.unread);
    } catch (e) {
      S.fails++;
      if (S.fails >= 3) $('lc-reconnect').hidden = false;
      if (e.status === 401 || e.status === 419) { $('lc-reconnect').textContent = 'Signed out. Reload the page to sign in again.'; $('lc-reconnect').hidden = false; S.inflight = false; return; }
    }
    S.inflight = false;
    schedule(S.fails ? Math.min(30000, BOOT.poll_ms * 2 ** S.fails) : BOOT.poll_ms);
  }
  document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(S.timer); else poll(); });

  // ── new chat ──
  let peopleTimer = null;
  function openNew() {
    closeInfo(); $('lc-new').hidden = false; S.picked.clear(); setMode('direct');
    $('lc-people-q').value = ''; $('lc-new-err').hidden = true; searchPeople(); $('lc-people-q').focus();
  }
  function closeNew(refocus) { const was = !$('lc-new').hidden; $('lc-new').hidden = true; if (was && refocus) $('lc-new-btn').focus(); }
  $('lc-new-btn').addEventListener('click', openNew);
  root.querySelectorAll('[data-open-new]').forEach((b) => b.addEventListener('click', openNew));
  root.querySelectorAll('[data-close-new]').forEach((b) => b.addEventListener('click', () => closeNew(true)));
  function setMode(mode) {
    S.mode = mode;
    root.querySelectorAll('[data-mode]').forEach((x) => x.setAttribute('aria-pressed', String(x.dataset.mode === mode)));
    if ($('lc-group-fields')) $('lc-group-fields').hidden = mode !== 'group';
    $('lc-group-create').hidden = mode !== 'group';
    $('lc-new-title').textContent = mode === 'group' ? 'New group' : 'New chat';
    renderPeople(S.lastPeople || []);
  }
  root.querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => setMode(b.dataset.mode)));
  $('lc-people-q').addEventListener('input', () => { clearTimeout(peopleTimer); peopleTimer = setTimeout(searchPeople, 200); });
  async function searchPeople() {
    const q = $('lc-people-q').value.trim();
    const data = await api('GET', '/chat/people?q=' + encodeURIComponent(q)).catch(() => ({ people: [] }));
    if ($('lc-people-q').value.trim() !== q) return;
    S.lastPeople = data.people; renderPeople(data.people);
  }
  function renderPeople(people) {
    const box = $('lc-people');
    if (!people.length) { box.replaceChildren(h('p', { class: 'lc-empty', style: 'padding:16px 0', text: 'No people found.' })); return; }
    box.replaceChildren(...people.map((p) => {
      const group = S.mode === 'group';
      const btn = h('button', { type: 'button', class: 'lc-person', 'aria-pressed': group ? String(S.picked.has(p.id)) : null,
        onclick: () => (group ? togglePick(p, btn) : startDirect(p)) },
        avatar(p), h('span', {}, h('div', { class: 'lc-person-name', text: p.name }), p.role ? h('div', { class: 'lc-person-role', text: p.role }) : null),
        group ? h('span', { class: 'lc-check' }, icon('fa-solid fa-check')) : null);
      return btn;
    }));
  }
  function togglePick(p, btn) {
    S.picked.has(p.id) ? S.picked.delete(p.id) : S.picked.set(p.id, p);
    btn.setAttribute('aria-pressed', String(S.picked.has(p.id)));
    $('lc-create-group').textContent = S.picked.size ? `Create group with ${S.picked.size} ${S.picked.size === 1 ? 'person' : 'people'}` : 'Create group';
  }
  async function startDirect(p) {
    try { const data = await api('POST', '/chat/direct', { user_id: p.id }); await loadList(); openConv(data.id); }
    catch (e) { const err = $('lc-new-err'); err.textContent = e.message; err.hidden = false; }
  }
  $('lc-create-group').addEventListener('click', async () => {
    const err = $('lc-new-err'); err.hidden = true;
    const title = ($('lc-group-name') || {}).value?.trim() || '';
    if (!title) { err.textContent = 'Give the group a name.'; err.hidden = false; $('lc-group-name').focus(); return; }
    if (!S.picked.size) { err.textContent = 'Pick at least one person.'; err.hidden = false; return; }
    try { const data = await api('POST', '/chat/groups', { title, user_ids: [...S.picked.keys()] }); await loadList(); openConv(data.id); }
    catch (e) { err.textContent = e.message; err.hidden = false; }
  });

  // ── group info ──
  function closeInfo(refocus) {
    const was = !$('lc-info').hidden;
    $('lc-info').hidden = true; $('lc-thread').inert = false;
    if (was && refocus) $('lc-info-btn').focus();
  }
  $('lc-info-close').addEventListener('click', () => closeInfo(true));
  $('lc-info-btn').addEventListener('click', renderInfo);
  function renderInfo() {
    const c = S.conv; const me = c.me; const body = $('lc-info-body');
    const parts = [];
    if (me.can_manage) {
      const name = h('input', { class: 'lc-field', value: c.title, maxlength: '80', id: 'lc-rename', 'aria-label': 'Group name' });
      parts.push(h('div', { class: 'lc-section' }, h('label', { class: 'lc-label', for: 'lc-rename', text: 'Group name' }),
        h('div', { class: 'lc-inline' }, name, h('button', { type: 'button', class: 'lc-btn', onclick: () => act('PATCH', `/chat/${c.id}`, { title: name.value.trim() }) }, 'Rename'))));
    }
    parts.push(h('div', { class: 'lc-section' }, h('h3', { text: `Members (${c.members.length})` }),
      ...c.members.map((p) => h('div', { class: 'lc-person', style: 'cursor:default' }, avatar(p),
        h('span', { style: 'flex:1;min-width:0' }, h('div', { class: 'lc-person-name' }, p.name, p.admin ? h('span', { class: 'lc-tag', text: 'Admin' }) : null, p.id === BOOT.me.id ? h('span', { class: 'lc-tag', text: 'You' }) : null),
          p.role ? h('div', { class: 'lc-person-role', text: p.role }) : null),
        me.can_manage && p.id !== BOOT.me.id ? h('button', { type: 'button', class: 'lc-btn danger', onclick: () => { if (confirm(`Remove ${p.name} from this group?`)) act('DELETE', `/chat/${c.id}/members/${p.id}`); } }, 'Remove') : null))));
    if (me.can_manage) {
      const q = h('input', { class: 'lc-field', type: 'search', placeholder: 'Search people to add', 'aria-label': 'Search people to add' });
      const res = h('div');
      let t = null;
      q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => {
        const data = await api('GET', '/chat/people?q=' + encodeURIComponent(q.value.trim())).catch(() => ({ people: [] }));
        const inGroup = new Set(c.members.map((m) => m.id));
        res.replaceChildren(...data.people.filter((p) => !inGroup.has(p.id)).slice(0, 8).map((p) => h('button', { type: 'button', class: 'lc-person', onclick: () => act('POST', `/chat/${c.id}/members`, { user_ids: [p.id] }) },
          avatar(p), h('span', { style: 'flex:1' }, h('div', { class: 'lc-person-name', text: p.name })), h('span', { class: 'lc-tag', text: 'Add' }))));
      }, 200); });
      parts.push(h('div', { class: 'lc-section' }, h('h3', { text: 'Add people' }), q, res));
    }
    if (me.can_leave) parts.push(h('button', { type: 'button', class: 'lc-btn danger', onclick: () => { if (confirm('Leave this group? You will not get new messages.')) act('POST', `/chat/${c.id}/leave`); } }, icon('fa-solid fa-arrow-right-from-bracket'), 'Leave group'));
    parts.push(h('p', { class: 'lc-msg-err', id: 'lc-info-err', hidden: true }));
    body.replaceChildren(...parts);
    $('lc-info').hidden = false; $('lc-thread').inert = true; $('lc-info-close').focus();
  }
  async function act(method, url, payload) {
    try { await api(method, url, payload); await openConv(S.open, { keepUrl: true }); poll(); }
    catch (e) { const err = $('lc-info-err'); if (err) { err.textContent = e.message; err.hidden = false; } }
  }

  // ── lightbox ──
  let lastFocus = null;
  function lightbox(f) {
    lastFocus = document.activeElement;
    $('lc-lightbox-frame').replaceChildren(h('img', { src: f.url, alt: f.name })); $('lc-lightbox-open').href = f.url;
    $('lc-lightbox').hidden = false; $('lc-lightbox-close').focus();
  }
  function closeLightbox() { $('lc-lightbox').hidden = true; $('lc-lightbox-frame').replaceChildren(); if (lastFocus && lastFocus.isConnected) lastFocus.focus(); }
  $('lc-lightbox-close').addEventListener('click', closeLightbox);
  $('lc-lightbox').addEventListener('click', (e) => { if (e.target.id === 'lc-lightbox') closeLightbox(); });
  $('lc-lightbox').addEventListener('keydown', (e) => {
    if (e.key !== 'Tab') return;
    const a = $('lc-lightbox-open'); const b = $('lc-lightbox-close');
    e.preventDefault(); (document.activeElement === b ? a : b).focus();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (R.rec || R.starting) stopRec(true);
    else if (!$('lc-lightbox').hidden) closeLightbox();
    else if (!$('lc-alerts').hidden || !$('lc-mute-menu').hidden) closePops(true);
    else if (root.querySelector('.lc-menu, .lc-rbar, .lc-who')) closeMenus(true);
    else if (S.edit) cancelEdit();
    else if (!$('lc-info').hidden) closeInfo(true);
    else if (!$('lc-new').hidden) closeNew(true);
    else if (S.reply) { S.reply = null; renderReply(); }
  });

  // For the alerts of components/chat-unread: open a chat from a notification; no alert for the chat open on screen.
  window.LikhaChat = {
    open: (id) => openConv(Number(id)).catch(() => {}),
    isOpen: (id) => !document.hidden && S.open === Number(id) && !!S.conv && (!isPhone() || root.dataset.view === 'thread'),
  };

  // ── start ──
  (async () => {
    try { await loadList(); } catch (e) { $('lc-list-loading').textContent = 'Chats could not load. Reload the page.'; }
    if (BOOT.open) { history.replaceState({ c: BOOT.open }, '', location.href); openConv(BOOT.open, { keepUrl: true }).catch(() => {}); }
    schedule();
  })();
})();
</script>
</x-layout>
