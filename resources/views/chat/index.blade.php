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
    --lc-text: #111827; --lc-muted: #4b5563; --lc-faint: #6b7280; --lc-hover: #f3f4f6; --lc-selected: #eef2ff;
    --lc-accent: #2563eb; --lc-accent-hover: #1d4ed8; --lc-accent-soft: #dbeafe; --lc-on-accent: #ffffff;
    --lc-other: #f3f4f6; --lc-danger: #b91c1c; --lc-ai-bg: #fffbeb; --lc-ai-text: #92400e;
    --lc-top: 64px;
    position: fixed; top: var(--lc-top); left: 0; right: 0; bottom: 0; z-index: 40;
    display: flex; background: var(--lc-bg); color: var(--lc-text);
    font: 15px/1.45 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-variant-numeric: tabular-nums;
  }
  html:has(#lc), html:has(#lc) body { overflow: hidden; }
  #lc *, #lc *::before, #lc *::after { box-sizing: border-box; }
  #lc ::selection { background: rgba(37, 99, 235, .2); }
  :where(#lc) :where(button, input, textarea) { font: inherit; color: inherit; }
  :where(#lc) button { cursor: pointer; }
  :where(#lc) button:disabled { cursor: default; opacity: .5; }
  #lc :focus-visible { outline: 2px solid var(--lc-accent); outline-offset: 2px; border-radius: 6px; }
  #lc [hidden] { display: none !important; }
  .lc-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

  /* ── side: list ── */
  .lc-side { width: 340px; flex: none; display: flex; flex-direction: column; background: var(--lc-panel); border-right: 1px solid var(--lc-line); min-height: 0; }
  .lc-side-head { padding: 14px 16px 10px; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
  .lc-side-head h1 { font-size: 20px; font-weight: 700; margin: 0; letter-spacing: -.01em; }
  .lc-btn { display: inline-flex; align-items: center; gap: 7px; border: 1px solid var(--lc-line-strong); background: var(--lc-panel); border-radius: 9px; padding: 7px 12px; font-size: 14px; font-weight: 600; color: var(--lc-text); }
  .lc-btn:hover { background: var(--lc-hover); }
  .lc-btn.primary { background: var(--lc-accent); border-color: var(--lc-accent); color: var(--lc-on-accent); }
  .lc-btn.primary:hover { background: var(--lc-accent-hover); }
  .lc-btn.danger { color: var(--lc-danger); }
  .lc-icon-btn { width: 38px; height: 38px; display: inline-grid; place-items: center; border: 0; background: transparent; border-radius: 9px; color: var(--lc-muted); flex: none; }
  .lc-icon-btn:hover { background: var(--lc-hover); color: var(--lc-text); }
  .lc-seg { margin: 0 16px 10px; display: grid; grid-template-columns: 1fr 1fr; background: var(--lc-hover); border-radius: 9px; padding: 3px; }
  .lc-seg button { border: 0; background: transparent; border-radius: 7px; padding: 5px 8px; font-size: 13px; font-weight: 600; color: var(--lc-muted); }
  .lc-seg button[aria-pressed="true"] { background: var(--lc-panel); color: var(--lc-text); box-shadow: 0 1px 2px rgba(17, 24, 39, .08); }
  .lc-field { width: 100%; border: 1px solid var(--lc-line-strong); border-radius: 9px; padding: 8px 11px; background: var(--lc-panel); font-size: 14px; }
  .lc-field::placeholder { color: var(--lc-faint); }
  .lc-filter { padding: 0 16px 8px; }
  .lc-list { list-style: none; margin: 0; padding: 0 8px 8px; overflow-y: auto; flex: 1; min-height: 0; }
  .lc-row { width: 100%; display: flex; align-items: center; gap: 12px; padding: 10px 10px; border: 0; background: transparent; border-radius: 12px; text-align: left; min-height: 64px; }
  .lc-row:hover { background: var(--lc-hover); }
  .lc-row[aria-current="true"] { background: var(--lc-selected); }
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
  .lc-av.ai { background: var(--lc-ai-bg); color: var(--lc-ai-text); box-shadow: inset 0 0 0 1px #fde68a; }
  .lc-ai { display: inline-block; margin-left: 5px; padding: 0 5px; border-radius: 5px; background: var(--lc-ai-bg); color: var(--lc-ai-text); font-size: 10px; font-weight: 800; letter-spacing: .06em; line-height: 16px; vertical-align: 1px; box-shadow: inset 0 0 0 1px #fde68a; }

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
  .lc-bubble a { color: var(--lc-accent-hover); text-decoration: underline; text-underline-offset: 2px; }
  .lc-bubble.removed { background: transparent; border: 1px solid var(--lc-line-strong); color: var(--lc-faint); font-style: italic; }
  .lc-msg.me .lc-bubble.removed { background: transparent; color: var(--lc-faint); }
  .lc-msg.pending .lc-bubble { opacity: .6; }
  .lc-quote { display: block; width: 100%; text-align: left; margin: 0 0 6px; padding: 4px 8px; border: 0; border-radius: 8px; background: rgba(17, 24, 39, .06); font-size: 13px; color: inherit; box-shadow: inset 2px 0 0 rgba(17, 24, 39, .25); }
  .lc-msg.me .lc-quote { background: rgba(255, 255, 255, .16); box-shadow: inset 2px 0 0 rgba(255, 255, 255, .6); }
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
  .lc-msg.failed .lc-meta { color: var(--lc-danger); }
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
  .lc-input { flex: 1; min-width: 0; resize: none; border: 1px solid var(--lc-line-strong); border-radius: 20px; padding: 9px 14px; max-height: 150px; line-height: 1.4; background: var(--lc-bg); }
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

  /* phone: one column */
  @media (max-width: 1023px) {
    .lc-side { width: 100%; border-right: 0; }
    #lc[data-view="list"] .lc-main { display: none; }
    #lc[data-view="thread"] .lc-side { display: none; }
    .lc-back { display: inline-grid !important; }
    .lc-col { max-width: 80%; }
    .lc-act { opacity: 1; width: 28px; }
    .lc-msg:not(.show) .lc-act { display: none; }
  }
  .lc-back { display: none !important; }
  @media (min-width: 1024px) { #lc { font-size: 14px; } .lc-side-panel { width: 340px; } }
</style>

<div id="lc" data-view="{{ $boot['open'] ? 'thread' : 'list' }}">
  {{-- ── list ── --}}
  <section class="lc-side" aria-label="Conversations" style="position: relative">
    <div class="lc-side-head">
      <h1>Chats</h1>
      <button type="button" class="lc-btn primary" id="lc-new-btn"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> New chat</button>
    </div>
    @if ($boot['me']['ceo'])
      <div class="lc-seg" role="group" aria-label="Which conversations">
        <button type="button" data-filter="mine" aria-pressed="true">Mine</button>
        <button type="button" data-filter="all" aria-pressed="false">All conversations</button>
      </div>
    @endif
    <div class="lc-filter">
      <label class="lc-sr" for="lc-filter">Search chats</label>
      <input id="lc-filter" class="lc-field" type="search" placeholder="Search chats" autocomplete="off">
    </div>
    <ul class="lc-list" id="lc-list" aria-label="Chats"></ul>
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
        <p style="margin: 0 0 8px; font-weight: 600; color: var(--lc-text)">Pick a chat on the left, or start a new one.</p>
        <p style="margin: 0; font-size: 13px"><i class="fa-solid fa-lock" aria-hidden="true"></i> The owner can read every conversation in Likha Chat.</p>
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
        <button type="button" class="lc-icon-btn" id="lc-info-btn" aria-label="Group info" hidden><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button>
      </header>
      <div class="lc-lock"><i class="fa-solid fa-lock" aria-hidden="true"></i> The owner can read every conversation in Likha Chat</div>
      <div class="lc-reconnect" id="lc-reconnect" role="status" hidden>Reconnecting…</div>
      <div class="lc-scroll" id="lc-scroll">
        <button type="button" class="lc-btn lc-earlier" id="lc-earlier" hidden>Load earlier messages</button>
        <ol class="lc-msgs" id="lc-msgs" aria-live="polite" aria-relevant="additions"></ol>
        <div class="lc-thread-empty" id="lc-thread-empty" hidden></div>
        <div class="lc-seen" id="lc-seen" hidden></div>
        <button type="button" class="lc-new-pill" id="lc-new-pill" hidden>New messages <i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button>
      </div>

      <footer class="lc-compose" id="lc-compose">
        <div class="lc-replybar" id="lc-replybar" hidden>
          <i class="fa-solid fa-reply" aria-hidden="true"></i>
          <div id="lc-replytext"></div>
          <button type="button" class="lc-icon-btn" id="lc-reply-cancel" aria-label="Cancel reply"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="lc-chips" id="lc-chips" hidden></div>
        <form class="lc-row-compose" id="lc-form" autocomplete="off">
          <input type="file" id="lc-file" multiple accept="{{ $boot['accept'] }}" hidden>
          <button type="button" class="lc-icon-btn" id="lc-attach" aria-label="Add photos or files"><i class="fa-solid fa-paperclip" aria-hidden="true"></i></button>
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
  <img id="lc-lightbox-img" alt="">
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
  };

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
    const res = await fetch(url, opt);
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
    if (a.ai) cls.push('ai'); else if (a.group) cls.push('group'); else if (a.tone !== null && a.tone !== undefined) cls.push('t' + a.tone); else cls.push('group');
    return h('span', { class: cls.join(' '), 'aria-hidden': 'true', text: a.initials || '?' });
  }
  const aiMark = () => $('lc-ai-mark').content.firstElementChild.cloneNode(true);
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

  // Ang taas ng nav (at ng storage banner, kung meron) para sa fixed na panel.
  function fitTop() {
    const nav = document.querySelector('nav');
    const banner = document.querySelector('[data-storage-banner]');
    let top = nav ? nav.getBoundingClientRect().bottom : 64;
    if (banner) top = Math.max(top, banner.getBoundingClientRect().bottom);
    root.style.setProperty('--lc-top', Math.max(0, Math.round(top)) + 'px');
  }
  fitTop(); window.addEventListener('resize', fitTop);

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
    list.replaceChildren(...rows.map(rowEl));
    $('lc-list-loading').hidden = true;
    $('lc-list-empty').hidden = S.rows.size > 0;
    list.hidden = S.rows.size === 0;
    if (S.rows.size > 0 && rows.length === 0) list.append(h('li', { class: 'lc-empty', text: 'No chats match.' }));
    const total = [...S.rows.values()].reduce((n, r) => n + (r.unread || 0), 0);
    document.title = (total ? `(${total}) ` : '') + 'Chat';
  }
  function rowEl(r) {
    const last = r.last;
    // In a direct chat the other person's name is already the title; only "You:" is added there.
    const showSender = last && last.sender && (r.kind === 'group' || last.sender === 'You' || !r.member);
    const lastText = last ? (showSender ? `${last.sender}: ${last.text}` : last.text) : (r.kind === 'group' ? 'Group created' : 'No messages yet');
    const label = `${r.title}${r.unread ? `, ${r.unread} unread` : ''}`;
    return h('li', {},
      h('button', {
        type: 'button', class: 'lc-row' + (r.unread ? ' unread' : '') + (r.left ? ' left' : ''),
        'aria-current': S.open === r.id ? 'true' : 'false', 'aria-label': label, onclick: () => openConv(r.id),
      },
        avatar(r.avatar),
        h('span', { class: 'lc-row-main' },
          h('span', { class: 'lc-row-top' }, h('span', { class: 'lc-row-name', text: r.title }), h('span', { class: 'lc-row-time', text: listTime(r.at) })),
          h('span', { class: 'lc-row-bottom' },
            h('span', { class: 'lc-row-last', text: lastText }),
            r.unread ? h('span', { class: 'lc-pill', text: r.unread > 99 ? '99+' : String(r.unread) }) : null,
          ),
        ),
      ));
  }
  async function loadList() {
    const data = await api('GET', `/chat/conversations${S.all ? '?all=1' : ''}`);
    S.since = S.since || data.now;
    setRows(data.conversations, true);
  }
  $('lc-filter').addEventListener('input', renderList);
  root.querySelectorAll('[data-filter]').forEach((b) => b.addEventListener('click', async () => {
    S.all = b.dataset.filter === 'all';
    root.querySelectorAll('[data-filter]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    await loadList().catch(() => {});
  }));

  // ── thread ──
  async function openConv(id, opts = {}) {
    closeNew(); closeInfo();
    S.open = id;
    root.dataset.view = 'thread';
    if (!opts.keepUrl) history.replaceState(null, '', `/chat?c=${id}`);
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
    S.reply = null; S.files = [];
    const row = S.rows.get(id);
    if (row) { row.unread = 0; renderList(); }
    $('lc-placeholder').hidden = true; $('lc-thread').hidden = false;
    renderHead(); renderComposer(); renderMessages(true);
    if (!isPhone() && S.conv.me.can_post) $('lc-input').focus({ preventScroll: true });
  }
  function closeThread() {
    S.open = null; S.conv = null; root.dataset.view = 'list';
    history.replaceState(null, '', '/chat');
    $('lc-thread').hidden = true; $('lc-placeholder').hidden = false; closeInfo();
    renderList();
  }
  $('lc-back').addEventListener('click', closeThread);

  function renderHead() {
    const c = S.conv;
    $('lc-thread-av').replaceChildren(avatar(c.avatar));
    $('lc-thread-title').textContent = c.title;
    let sub = '';
    if (c.me.left) sub = 'You left this group';
    else if (c.kind === 'group') sub = `${c.members.length} member${c.members.length === 1 ? '' : 's'}`;
    else {
      const other = c.members.find((m) => m.id !== BOOT.me.id);
      sub = other && other.role ? other.role : 'Direct chat';
    }
    $('lc-thread-sub').textContent = sub;
    $('lc-info-btn').hidden = c.kind !== 'group';
  }
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
  function toBottom() { const s = $('lc-scroll'); s.scrollTop = s.scrollHeight; $('lc-new-pill').hidden = true; }

  function renderMessages(stick, arrivedIds = new Set()) {
    const list = $('lc-msgs');
    const out = [];
    let prev = null; let prevDay = null;
    for (const m of S.msgs) {
      const d = new Date(m.at);
      if (dayKey(d) !== prevDay) { out.push(h('li', { class: 'lc-day', text: dayLabel(d) })); prevDay = dayKey(d); prev = null; }
      if (m.sender.kind === 'system') { out.push(h('li', { class: 'lc-sys', 'data-id': m.id, text: m.text || '' })); prev = null; continue; }
      const cont = prev && senderKey(prev) === senderKey(m) && (d - new Date(prev.at)) < 5 * 60e3;
      out.push(msgEl(m, cont, arrivedIds.has(m.id)));
      prev = m;
    }
    list.replaceChildren(...out);
    $('lc-earlier').hidden = !S.hasMore;
    const empty = $('lc-thread-empty');
    empty.hidden = S.msgs.length > 0;
    if (!S.msgs.length) empty.textContent = S.conv.kind === 'group' ? 'No messages yet. Say hello to the group.' : `No messages yet. Say hello to ${S.conv.title}.`;
    renderSeen();
    if (stick) toBottom();
  }
  function msgEl(m, cont, arrived) {
    const me = m.sender.me;
    const cls = ['lc-msg']; if (me) cls.push('me'); if (cont) cls.push('cont'); if (arrived) cls.push('arrive');
    if (m.pending) cls.push('pending'); if (m.failed) cls.push('failed');
    const showName = !me && !cont && (S.conv.kind === 'group' || m.sender.kind === 'ai');
    const col = h('div', { class: 'lc-col' });
    if (showName) col.append(h('div', { class: 'lc-name' }, m.sender.name, m.sender.kind === 'ai' ? aiMark() : null));

    const bubble = h('div', { class: 'lc-bubble' + (m.removed ? ' removed' : '') });
    if (m.removed) bubble.textContent = 'Message removed';
    else {
      if (m.reply) {
        bubble.append(h('button', { type: 'button', class: 'lc-quote', onclick: (e) => { e.stopPropagation(); jumpTo(m.reply.id); } },
          h('b', { text: m.reply.name }), h('span', { text: m.reply.removed ? 'Message removed' : (m.reply.text || '') })));
      }
      if (m.html) { const t = h('div'); t.innerHTML = m.html; bubble.append(t); }   // escaped by the server (ChatText)
    }
    const imgs = m.removed ? [] : m.files.filter((f) => f.kind === 'image');
    const docs = m.removed ? [] : m.files.filter((f) => f.kind !== 'image');
    if (!m.removed && (m.html || m.reply || (!imgs.length && !docs.length))) col.append(bubble);
    else if (m.removed) col.append(bubble);
    if (imgs.length) col.append(h('div', { class: 'lc-imgs ' + (imgs.length === 1 ? 'n1' : 'multi') },
      imgs.map((f) => h('button', { type: 'button', 'aria-label': `Open photo ${f.name}`, onclick: (e) => { e.stopPropagation(); lightbox(f); } },
        h('img', { src: f.url, alt: f.name, loading: 'lazy' })))));
    for (const f of docs) col.append(h('a', { class: 'lc-file', href: f.url, download: f.name },
      icon('fa-regular fa-file-lines'), h('span', {}, f.name, h('small', { text: size(f.size) }))));
    const meta = m.failed ? h('div', { class: 'lc-meta' }, 'Not sent. ', h('button', { type: 'button', class: 'lc-btn', style: 'padding:1px 8px;font-size:12px', onclick: () => retry(m) }, 'Retry'))
      : h('div', { class: 'lc-meta', text: m.pending ? 'Sending…' : fullTime(m.at) });
    col.append(meta);

    const li = h('li', { class: cls.join(' '), 'data-id': m.id });
    li.append(h('div', { class: 'lc-av-slot' }, !me && !cont ? avatar({ ...m.sender, ai: m.sender.kind === 'ai' }, 'sm') : null), col);
    if (!m.pending && !m.failed && !m.removed && (S.conv.me.can_post)) {
      li.append(h('button', { type: 'button', class: 'lc-icon-btn lc-act', 'aria-label': 'Message actions', 'aria-haspopup': 'menu', onclick: (e) => { e.stopPropagation(); menu(li, m); } }, icon('fa-solid fa-ellipsis')));
    }
    li.addEventListener('click', (e) => { if (e.target.closest('a,button')) return; li.classList.toggle('show'); });
    return li;
  }
  function closeMenus() { root.querySelectorAll('.lc-menu').forEach((x) => x.remove()); }
  function menu(li, m) {
    const had = li.querySelector('.lc-menu'); closeMenus(); if (had) return;
    const items = [h('button', { type: 'button', role: 'menuitem', onclick: () => { closeMenus(); startReply(m); } }, icon('fa-solid fa-reply'), 'Reply')];
    if (m.sender.me) items.push(h('button', { type: 'button', role: 'menuitem', class: 'danger', style: 'color:var(--lc-danger)', onclick: () => { closeMenus(); removeMsg(m); } }, icon('fa-regular fa-trash-can'), 'Remove'));
    const box = h('div', { class: 'lc-menu', role: 'menu' }, items);
    li.append(box); items[0].focus();
  }
  document.addEventListener('click', (e) => { if (!e.target.closest('.lc-menu')) closeMenus(); });
  function jumpTo(id) {
    const el = $('lc-msgs').querySelector(`[data-id="${id}"]`);
    if (el) { el.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); el.classList.add('show'); }
  }
  function renderSeen() {
    const box = $('lc-seen');
    const mine = [...S.msgs].reverse().find((m) => m.sender.me && !m.removed && !m.pending && !m.failed);
    const last = S.msgs[S.msgs.length - 1];
    const who = mine && last && last.id === mine.id ? S.reads.filter((r) => r.last_read_message_id >= mine.id) : [];
    box.hidden = who.length === 0;
    if (!who.length) return;
    const names = who.map((r) => r.name).join(', ');
    box.replaceChildren(h('span', { text: 'Seen by', style: 'margin-right:6px' },
      h('span', { class: 'lc-sr', text: ` ${names}` })),
      ...who.slice(0, 5).map((r) => { const a = avatar(r, 'xs'); a.setAttribute('title', r.name); return a; }),
      ...(who.length > 5 ? [h('span', { text: `+${who.length - 5}`, style: 'margin-left:4px' })] : []));
  }
  $('lc-new-pill').addEventListener('click', toBottom);
  $('lc-scroll').addEventListener('scroll', () => { if (nearBottom()) $('lc-new-pill').hidden = true; });
  $('lc-earlier').addEventListener('click', async () => {
    const first = S.msgs.find((m) => m.id > 0); if (!first) return;
    const s = $('lc-scroll'); const before = s.scrollHeight;
    const data = await api('GET', `/chat/${S.open}?before=${first.id}`).catch(() => null);
    if (!data) return;
    S.msgs = [...data.messages, ...S.msgs]; S.hasMore = data.has_more;
    renderMessages(false); s.scrollTop = s.scrollHeight - before;
  });

  // ── composer ──
  const input = $('lc-input');
  function grow() { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight + 2, 150) + 'px'; }
  input.addEventListener('input', () => {
    grow();
    const n = input.value.length;
    $('lc-count').textContent = n > BOOT.max_body - 400 ? `${n} / ${BOOT.max_body}` : '';
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
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
    if (S.reply) $('lc-replytext').textContent = `Replying to ${S.reply.sender.me ? 'yourself' : S.reply.sender.name}: ${S.reply.text || 'a file'}`;
  }
  $('lc-reply-cancel').addEventListener('click', () => { S.reply = null; renderReply(); });

  let tempId = 0;
  async function send() {
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
    if (p._form.body) fd.append('body', p._form.body);
    if (p._form.reply) fd.append('reply_to_id', p._form.reply);
    p._form.files.forEach((f) => fd.append('files[]', f));
    const conv = S.open;
    try {
      const data = await api('POST', `/chat/${conv}/messages`, fd);
      if (S.open !== conv) return;
      const i = S.msgs.indexOf(p);
      if (byId(data.message.id)) S.msgs.splice(i, 1); else S.msgs[i] = data.message;
      renderMessages(nearBottom());
    } catch (e) {
      p.pending = false; p.failed = true;
      $('lc-err').textContent = e.message;
      if (S.open === conv) renderMessages(nearBottom());
    }
  }
  function retry(m) { m.failed = false; m.pending = true; renderMessages(nearBottom()); deliver(m); }
  async function removeMsg(m) {
    if (!confirm('Remove this message for everyone?')) return;
    try { await api('DELETE', `/chat/${S.open}/messages/${m.id}`); m.removed = true; m.html = null; m.text = null; m.files = []; renderMessages(false); }
    catch (e) { $('lc-err').textContent = e.message; }
  }
  async function join() {
    try { await api('POST', `/chat/${S.open}/join`); await openConv(S.open, { keepUrl: true }); } catch (e) { alert(e.message); }
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
      if (data.thread && S.open === conv) {
        const stick = nearBottom();
        const fresh = data.thread.messages.filter((m) => !byId(m.id));
        for (const id of data.thread.removed) { const m = byId(id); if (m) { m.removed = true; m.html = null; m.text = null; m.files = []; } }
        S.msgs.push(...fresh);
        S.reads = data.thread.reads;
        if (fresh.length || data.thread.removed.length) {
          renderMessages(stick, new Set(fresh.map((m) => m.id)));
          if (!stick && fresh.some((m) => !m.sender.me)) $('lc-new-pill').hidden = false;
        } else renderSeen();
      }
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
  function closeNew() { $('lc-new').hidden = true; }
  $('lc-new-btn').addEventListener('click', openNew);
  root.querySelectorAll('[data-open-new]').forEach((b) => b.addEventListener('click', openNew));
  root.querySelectorAll('[data-close-new]').forEach((b) => b.addEventListener('click', closeNew));
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
  function closeInfo() { $('lc-info').hidden = true; }
  $('lc-info-close').addEventListener('click', closeInfo);
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
    $('lc-info').hidden = false;
  }
  async function act(method, url, payload) {
    try { await api(method, url, payload); await openConv(S.open, { keepUrl: true }); poll(); }
    catch (e) { const err = $('lc-info-err'); if (err) { err.textContent = e.message; err.hidden = false; } }
  }

  // ── lightbox ──
  let lastFocus = null;
  function lightbox(f) {
    lastFocus = document.activeElement;
    $('lc-lightbox-img').src = f.url; $('lc-lightbox-img').alt = f.name; $('lc-lightbox-open').href = f.url;
    $('lc-lightbox').hidden = false; $('lc-lightbox-close').focus();
  }
  function closeLightbox() { $('lc-lightbox').hidden = true; $('lc-lightbox-img').src = ''; lastFocus && lastFocus.focus(); }
  $('lc-lightbox-close').addEventListener('click', closeLightbox);
  $('lc-lightbox').addEventListener('click', (e) => { if (e.target.id === 'lc-lightbox') closeLightbox(); });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (!$('lc-lightbox').hidden) closeLightbox();
    else if (root.querySelector('.lc-menu')) closeMenus();
    else if (!$('lc-info').hidden) closeInfo();
    else if (!$('lc-new').hidden) closeNew();
    else if (S.reply) { S.reply = null; renderReply(); }
  });

  // ── start ──
  (async () => {
    try { await loadList(); } catch (e) { $('lc-list-loading').textContent = 'Chats could not load. Reload the page.'; }
    if (BOOT.open) openConv(BOOT.open, { keepUrl: true }).catch(() => {});
    schedule();
  })();
})();
</script>
</x-layout>
