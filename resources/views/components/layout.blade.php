<!doctype html>
<html lang="en" class="h-full bg-gray-100">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $title ?? ($heading ?? 'Likha') }}</title>

  {{-- Sidebar state before paint (spec 295): rail and closed sections remembered in this browser. --}}
  <script>
    (function () {
      var d = document.documentElement;
      try {
        if (localStorage.getItem('likha.nav.rail') === '1') d.setAttribute('data-nav-rail', '');
        var c = JSON.parse(localStorage.getItem('likha.nav.closed') || '[]');
        if (Array.isArray(c)) d.setAttribute('data-nav-closed', c.join(' '));
      } catch (e) {}
    })();
  </script>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/alpinejs" defer></script>

  {{-- Icons (Font Awesome) --}}
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    [x-cloak] { display: none !important; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    /* Prevent horizontal page scroll — wide content uses its own internal scroll container */
    html, body { overflow-x: hidden; }

    /* ── Navigation (spec 295): sidebar, rail, drawer, top bar, palette. Tailwind gray scale, one accent (blue-600). ── */
    :root {
      --lk-w: 248px; --lk-rail: 64px; --lk-top: 4rem;
      --lk-ink: #111827; --lk-text: #374151; --lk-muted: #6b7280; --lk-faint: #9ca3af;
      --lk-line: #e5e7eb; --lk-hover: #f3f4f6; --lk-surface: #ffffff;
      --lk-accent: #2563eb; --lk-accent-ink: #1d4ed8; --lk-accent-soft: #eff6ff;
      --lk-ease: cubic-bezier(.2, .8, .2, 1);
    }
    :focus-visible { outline: 2px solid var(--lk-accent); outline-offset: 2px; }
    ::selection { background: #bfdbfe; color: var(--lk-ink); }

    .lk-sidebar {
      position: fixed; inset: 0 auto 0 0; z-index: 50; width: min(280px, 85vw);
      display: flex; flex-direction: column; background: var(--lk-surface); border-right: 1px solid var(--lk-line);
      transform: translateX(-100%); transition: transform .2s var(--lk-ease), width .2s var(--lk-ease);
      visibility: hidden;
    }
    html[data-nav-drawer] .lk-sidebar { transform: none; visibility: visible; box-shadow: 0 10px 30px -5px rgb(17 24 39 / .25); }
    .lk-scrim { position: fixed; inset: 0; z-index: 45; background: rgb(17 24 39 / .4); opacity: 0; pointer-events: none; transition: opacity .2s var(--lk-ease); }
    html[data-nav-drawer] .lk-scrim { opacity: 1; pointer-events: auto; }

    .lk-brand { display: flex; align-items: center; gap: .25rem; height: var(--lk-top); padding: 0 .75rem 0 1rem; flex-shrink: 0; }
    .lk-mark { display: flex; align-items: center; gap: .625rem; min-width: 0; flex: 1; border-radius: .5rem; color: var(--lk-ink); }
    .lk-mark-tile { display: grid; place-items: center; width: 2rem; height: 2rem; flex-shrink: 0; border-radius: .5rem;
      background: var(--lk-accent); color: #fff; font-weight: 700; font-size: 1rem; letter-spacing: -.02em; }
    .lk-mark-word { font-weight: 600; font-size: 1rem; letter-spacing: -.01em; }
    .lk-iconbtn { display: grid; place-items: center; width: 2.25rem; height: 2.25rem; flex-shrink: 0; border-radius: .5rem;
      color: var(--lk-muted); transition: background-color .15s, color .15s; }
    .lk-iconbtn:hover { background: var(--lk-hover); color: var(--lk-ink); }
    .lk-rail-toggle { display: none; }

    .lk-scroll { flex: 1; overflow-y: auto; overscroll-behavior: contain; padding: .25rem .75rem 1.5rem; scrollbar-width: thin; scrollbar-color: #d1d5db transparent; }
    .lk-section + .lk-section { margin-top: 1rem; }
    .lk-section-head-wrap { margin: 0; }
    .lk-section-head { display: flex; align-items: center; width: 100%; height: 1.75rem; padding: 0 .5rem; border-radius: .375rem;
      font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--lk-muted); }
    .lk-section-head:hover { color: var(--lk-ink); }
    .lk-section-head.is-static { cursor: default; }
    .lk-section-head.is-static:hover { color: var(--lk-muted); }
    .lk-chevron { margin-left: auto; font-size: .625rem; transition: transform .15s var(--lk-ease); }
    .lk-list { margin: .125rem 0 0; padding: 0; list-style: none; }
    .lk-row { position: relative; }
    .lk-row.is-dragging { opacity: .5; }
    .lk-link { display: flex; align-items: center; gap: .625rem; height: 2.25rem; padding: 0 .5rem; border-radius: .5rem;
      font-size: .875rem; color: var(--lk-text); transition: background-color .15s, color .15s; }
    .lk-link:hover { background: var(--lk-hover); color: var(--lk-ink); }
    .lk-link.is-active { background: var(--lk-accent-soft); color: var(--lk-accent-ink); font-weight: 500; }
    .lk-link.is-active .lk-icon { color: var(--lk-accent); }
    .lk-icon { position: relative; display: grid; place-items: center; width: 1.25rem; flex-shrink: 0; font-size: .9375rem; color: var(--lk-muted); }
    .lk-label { min-width: 0; flex: 1; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .lk-badge { flex-shrink: 0; min-width: 1.25rem; height: 1.25rem; padding: 0 .375rem; border-radius: 9999px;
      font-size: .6875rem; font-weight: 600; line-height: 1.25rem; text-align: center; font-variant-numeric: tabular-nums; }
    .lk-badge.is-red { background: #dc2626; color: #fff; }
    .lk-badge.is-neutral { background: var(--lk-line); color: var(--lk-text); }
    .lk-badge.is-team { background: #ffedd5; color: #9a3412; }
    .lk-dot { display: none; position: absolute; top: -2px; right: -3px; width: .5rem; height: .5rem; border-radius: 9999px; box-shadow: 0 0 0 2px #fff; }
    .lk-dot.is-red { background: #dc2626; } .lk-dot.is-neutral { background: var(--lk-faint); } .lk-dot.is-team { background: #f97316; }
    /* Pin: shows on row hover and keyboard focus; always shown when on. A link with a badge keeps its badge visible. */
    .lk-pin { position: absolute; top: .25rem; right: .25rem; display: grid; place-items: center; width: 1.75rem; height: 1.75rem;
      border-radius: .375rem; color: var(--lk-faint); background: var(--lk-hover); opacity: 0; font-size: .75rem; transition: opacity .15s, color .15s; }
    .lk-row:hover .lk-pin, .lk-pin:focus-visible { opacity: 1; }
    .lk-pin:hover { color: var(--lk-accent); }
    .lk-pin.is-on { color: var(--lk-accent); }
    .lk-pinned .lk-pin { background: transparent; }
    .lk-pinned .lk-row:not(:hover) .lk-pin:not(:focus-visible) { opacity: 0; }
    .lk-pin:disabled { cursor: not-allowed; color: #d1d5db; }
    .lk-pinned .lk-link { cursor: grab; }
    .lk-pin-error { margin: .25rem .5rem 0; font-size: .75rem; color: #b91c1c; }
    .lk-empty { margin: 1rem .25rem; padding: 1rem; border: 1px dashed #d1d5db; border-radius: .75rem; font-size: .8125rem; line-height: 1.45; color: var(--lk-muted); text-align: center; }
    .lk-empty > i { font-size: 1.25rem; color: var(--lk-faint); }
    .lk-empty-title { margin: .375rem 0 .25rem; font-weight: 600; color: var(--lk-ink); }
    .lk-tip { position: fixed; z-index: 70; transform: translateY(-50%); padding: .25rem .5rem; border-radius: .375rem;
      background: var(--lk-ink); color: #fff; font-size: .75rem; font-weight: 500; white-space: nowrap; pointer-events: none;
      box-shadow: 0 4px 12px -2px rgb(17 24 39 / .3); }

    .lk-topbar { position: fixed; top: 0; right: 0; left: 0; z-index: 40; height: var(--lk-top); display: flex; align-items: center; gap: .75rem;
      padding: 0 1rem; background: rgb(255 255 255 / .96); border-bottom: 1px solid var(--lk-line); transition: left .2s var(--lk-ease); }
    .lk-title { min-width: 0; flex: 1; margin: 0; overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
      font-size: 1.125rem; font-weight: 600; letter-spacing: -.01em; color: var(--lk-ink); }
    .lk-search { display: flex; align-items: center; gap: .5rem; height: 2.25rem; padding: 0 .625rem; border: 1px solid var(--lk-line);
      border-radius: .5rem; background: #f9fafb; color: var(--lk-muted); font-size: .875rem; transition: border-color .15s, background-color .15s; }
    .lk-search:hover { border-color: #d1d5db; background: #fff; }
    .lk-search-text { display: none; }
    .lk-kbd { display: inline-block; min-width: 1.25rem; padding: 0 .3rem; border: 1px solid var(--lk-line); border-bottom-width: 2px; border-radius: .3rem;
      background: #fff; font: 500 .6875rem/1.1rem ui-sans-serif, system-ui, sans-serif; color: var(--lk-muted); text-align: center; }
    .lk-search .lk-kbd { display: none; }
    .lk-avatar { width: 2.25rem; height: 2.25rem; border-radius: 9999px; object-fit: cover; flex-shrink: 0; }
    .lk-initials { display: grid; place-items: center; background: #e5e7eb; color: var(--lk-text); font-size: .8125rem; font-weight: 600; }
    .lk-menu { position: absolute; right: 0; top: calc(100% + .5rem); width: 16rem; padding: .375rem; border: 1px solid var(--lk-line); border-radius: .75rem;
      background: #fff; box-shadow: 0 12px 32px -8px rgb(17 24 39 / .2); }
    .lk-menu-item { display: flex; align-items: center; gap: .625rem; width: 100%; height: 2.25rem; padding: 0 .625rem; border-radius: .5rem;
      font-size: .875rem; color: var(--lk-text); text-align: left; }
    .lk-menu-item:hover { background: var(--lk-hover); color: var(--lk-ink); }
    .lk-menu-item > i { width: 1rem; color: var(--lk-muted); text-align: center; }
    .lk-chip { display: inline-block; padding: 0 .375rem; border-radius: .25rem; background: var(--lk-accent-soft); color: var(--lk-accent-ink);
      font-size: .625rem; font-weight: 700; line-height: 1rem; letter-spacing: .04em; text-transform: uppercase; }
    .lk-top-spacer { height: var(--lk-top); }

    .lk-pal-scrim { position: fixed; inset: 0; z-index: 60; background: rgb(17 24 39 / .4); }
    .lk-pal { position: fixed; z-index: 61; top: 1rem; left: 1rem; right: 1rem; max-width: 35rem; margin: 0 auto; display: flex; flex-direction: column;
      max-height: calc(100dvh - 2rem); border-radius: .75rem; background: #fff; box-shadow: 0 24px 48px -12px rgb(17 24 39 / .35); overflow: hidden; }
    .lk-pal-search { display: flex; align-items: center; gap: .75rem; padding: 0 1rem; height: 3.25rem; border-bottom: 1px solid var(--lk-line); color: var(--lk-muted); }
    .lk-pal-search input { flex: 1; min-width: 0; height: 100%; border: 0; outline: 0; background: transparent; font-size: 1rem; color: var(--lk-ink); }
    .lk-pal-search input::placeholder { color: var(--lk-muted); }
    .lk-pal-list { flex: 1; overflow-y: auto; margin: 0; padding: .375rem; list-style: none; scrollbar-width: thin; }
    .lk-pal-group { margin: .5rem .5rem .25rem; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--lk-muted); }
    .lk-pal-opt { display: flex; align-items: center; gap: .75rem; height: 2.5rem; padding: 0 .625rem; border-radius: .5rem; font-size: .875rem; color: var(--lk-text); }
    .lk-pal-opt.is-sel { background: var(--lk-accent-soft); color: var(--lk-accent-ink); }
    .lk-pal-opt.is-sel .lk-icon { color: var(--lk-accent); }
    .lk-pal-label { flex: 1; min-width: 0; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .lk-pal-section { flex-shrink: 0; font-size: .75rem; color: var(--lk-muted); }
    .lk-pal-empty { padding: 2rem 1rem; text-align: center; font-size: .875rem; color: var(--lk-muted); }
    .lk-pal-foot { display: none; gap: 1rem; padding: .5rem 1rem; border-top: 1px solid var(--lk-line); background: #f9fafb; font-size: .75rem; color: var(--lk-muted); }
    .lk-pal-foot .lk-kbd { margin-right: .125rem; }

    @media (min-width: 640px) {
      .lk-topbar { padding: 0 1.5rem; }
      .lk-search { width: 15rem; }
      .lk-search-text { display: inline; flex: 1; text-align: left; }
      .lk-search .lk-kbd { display: inline-block; }
      .lk-pal { top: 12vh; max-height: 70vh; }
      .lk-pal-foot { display: flex; }
    }
    @media (min-width: 1024px) {
      .lk-sidebar { width: var(--lk-w); transform: none; visibility: visible; box-shadow: none; }
      .lk-scrim, .lk-drawer-close, [data-nav-drawer-open] { display: none !important; }
      .lk-rail-toggle { display: grid; }
      .lk-topbar { left: var(--lk-w); padding: 0 2rem; }
      .lk-main { padding-left: var(--lk-w); transition: padding-left .2s var(--lk-ease); }
      /* Rail: icons only, section names become a hairline, labels move to tooltips. */
      html[data-nav-rail] .lk-sidebar { width: var(--lk-rail); }
      html[data-nav-rail] .lk-topbar { left: var(--lk-rail); }
      html[data-nav-rail] .lk-main { padding-left: var(--lk-rail); }
      html[data-nav-rail] .lk-brand { flex-direction: column; justify-content: center; gap: .25rem; height: auto; min-height: var(--lk-top); padding: .75rem 0 .5rem; }
      html[data-nav-rail] .lk-mark { flex: none; }
      html[data-nav-rail] .lk-rail-toggle i { transform: rotate(180deg); }
      html[data-nav-rail] .lk-scroll { padding: .25rem .75rem 1.5rem; scrollbar-width: none; }
      html[data-nav-rail] .lk-label, html[data-nav-rail] .lk-badge, html[data-nav-rail] .lk-chevron, html[data-nav-rail] .lk-pin,
      html[data-nav-rail] .lk-empty p { display: none; }
      html[data-nav-rail] .lk-section-head { height: .75rem; padding: 0; pointer-events: none; }
      html[data-nav-rail] .lk-section-head::after { content: ""; flex: 1; height: 1px; margin: 0 .5rem; background: var(--lk-line); }
      html[data-nav-rail] .lk-section + .lk-section { margin-top: .5rem; }
      html[data-nav-rail] .lk-link { justify-content: center; padding: 0; }
      html[data-nav-rail] .lk-dot { display: block; }
      @foreach(\App\Support\NavSections::names() as $sectionName)
      html[data-nav-rail] [data-section-body="{{ $sectionName }}"] { display: block !important; }
      @endforeach
    }
    @foreach(\App\Support\NavSections::names() as $sectionName)
    html[data-nav-closed~="{{ $sectionName }}"] [data-section-body="{{ $sectionName }}"] { display: none; }
    html[data-nav-closed~="{{ $sectionName }}"] [data-nav-toggle="{{ $sectionName }}"] .lk-chevron { transform: rotate(-90deg); }
    @endforeach
    @media (prefers-reduced-motion: reduce) {
      .lk-sidebar, .lk-scrim, .lk-topbar, .lk-main, .lk-chevron { transition: none !important; }
    }
  </style>
</head>
<body class="h-full">

@php
  // Actual role from DB — used for AUTH (page access still checked per-route).
  $actualRole = Auth::user()?->employeeProfile?->role ?? null;

  // CEO-only "view as role" override. Stored sa session via /nav/view-as.
  // Only honored when actual role is CEO (defense in depth — non-CEO can't fake it).
  $viewAsRole = ($actualRole === 'CEO') ? session('nav_view_as_role') : null;
  $effectiveRole = $viewAsRole ?: $actualRole;

  // DB-driven nav — manage at /owner/nav-settings (CEO only). Grouped in sections in the sidebar (spec 295);
  // Iisang Chatblast link para sa Chatblast at Page Settings (ChatblastNav::mainLinks, inside NavMenu::links).
  $navLinks = \App\Support\NavMenu::links($effectiveRole);
  $navSections = \App\Support\NavSections::group($navLinks);
  // Palette entries in sidebar order, section by section; pins only among the links shown.
  $navEntries = \App\Support\NavMenu::entries(collect($navSections)->flatMap(fn ($s) => $s['links']));
  $navPins = \App\Support\NavMenu::pins(Auth::user(), $navLinks);

  // Checklist badge ng naka-login (sariling role, hindi ang "view as"): naka-cache nang 60 segundo,
  // at null kapag pumalya. Ang link ay hinahanap sa URL dahil sa nav settings itinakda ang key nito.
  $checklistBadge = \App\Services\ChecklistBadge::forUser(Auth::user());

  // Pages that draw their own header under the bar (and their own mt-16).
  $ownHeader = request()->is('checklist') || request()->is('checklist/*') || request()->is('jnt_rts') || request()->is('jnt_rts/*') || request()->is('prompt-generator') || request()->is('boardroom') || request()->is('boardroom/*');
  $navUser = Auth::user();
  $navInitials = $navUser ? collect(preg_split('/\s+/', trim((string) $navUser->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') : '';
@endphp

<div class="min-h-full">
  <x-sidebar :sections="$navSections" :entries="$navEntries" :pins="$navPins" :can-pin="!$viewAsRole && (bool) $navUser" :checklist-badge="$checklistBadge" />
  <x-nav-palette :entries="$navEntries" />

  {{-- Top bar: page title, search, avatar menu. 64 px high: pages under it offset themselves by mt-16 / top-16. --}}
  <header class="lk-topbar">
    <button type="button" class="lk-iconbtn -ml-2" data-nav-drawer-open aria-controls="likha-sidebar" aria-expanded="false"
            aria-label="Open menu" onclick="window.likhaDrawer && window.likhaDrawer.open(this)">
      <i class="fa-solid fa-bars text-base" aria-hidden="true"></i>
    </button>
    @if($ownHeader)
      <p class="lk-title">{{ $heading ?? 'Likha' }}</p>
    @else
      <h1 class="lk-title">{{ $heading ?? 'Dashboard' }}</h1>
    @endif

    <button type="button" class="lk-search" data-nav-search aria-label="Search pages (Ctrl K)" aria-haspopup="dialog"
            onclick="window.dispatchEvent(new CustomEvent('likha-palette', { detail: this }))">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <span class="lk-search-text">Search pages</span>
      <kbd class="lk-kbd">Ctrl K</kbd>
    </button>

    @if($navUser)
      <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open && (open = false, $refs.btn.focus())">
        <button type="button" x-ref="btn" class="flex items-center gap-2 rounded-full p-0.5 hover:bg-gray-100" x-on:click="open = !open"
                :aria-expanded="open.toString()" aria-haspopup="menu" aria-label="Account menu for {{ $navUser->name }}">
          @if($navUser->profile_picture)
            <img src="{{ $navUser->profile_picture }}" class="lk-avatar" alt="">
          @else
            <span class="lk-avatar lk-initials" aria-hidden="true">{{ $navInitials }}</span>
          @endif
          @if($viewAsRole)
            <span class="lk-chip hidden sm:inline-block" data-nav-preview>Preview</span>
          @endif
        </button>
        <div class="lk-menu" role="menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
          <div class="px-2.5 pb-2 pt-1.5">
            <div class="truncate text-sm font-semibold text-gray-900">{{ $navUser->name }}</div>
            <div class="text-xs text-gray-500">{{ $actualRole ?? 'No role' }}</div>
          </div>

          {{-- CEO-only "view as role": posts to /nav/view-as, which stores it in session; the nav above re-renders per
               chosen role. Auth/access still based on actual CEO role — this is preview only. --}}
          @if($actualRole === 'CEO')
            <form method="POST" action="{{ route('nav.view-as') }}" class="border-t border-gray-100 px-2.5 py-2">
              @csrf
              <label for="nav-view-as" class="mb-1 flex items-center justify-between text-xs font-medium text-gray-500">
                <span>View nav as</span>
                @if($viewAsRole)<span class="lk-chip" title="Showing the nav as {{ $viewAsRole }}. Choose CEO (actual) to undo.">Preview</span>@endif
              </label>
              <select id="nav-view-as" name="role" onchange="this.form.submit()"
                      class="w-full rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/30">
                <option value="" {{ empty($viewAsRole) ? 'selected' : '' }}>CEO (actual)</option>
                @foreach(['Marketing - OIC', 'Marketing', 'Data Encoder - OIC', 'Data Encoder'] as $r)
                  <option value="{{ $r }}" {{ $viewAsRole === $r ? 'selected' : '' }}>{{ $r }}</option>
                @endforeach
              </select>
            </form>
          @endif

          <div class="border-t border-gray-100 pt-1">
            <a href="{{ url('/profile') }}" class="lk-menu-item" role="menuitem"><i class="fa-regular fa-user" aria-hidden="true"></i>Profile</a>
            <form method="POST" action="{{ route('logout') }}">
              @csrf
              <button type="submit" class="lk-menu-item" role="menuitem"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>Log out</button>
            </form>
          </div>
        </div>
      </div>
    @endif
  </header>

  <div class="lk-main">
@php
  // Storage banner (spec 242): the roles checked for the Storage link in nav settings (spec 243) and the CEO, at 80 %
  // used or more; null for everyone else and when
  // the disk cannot be read. Cached 60 s and fed by the disk functions only, so it never slows a page. It sits under
  // the fixed top bar (mt-16), so the spacer below is left out while it shows. These lines start at column 0 and
  // the comment lives in PHP so that, without a banner, the page is byte for byte what it was before.
  $storageBanner = \App\Services\StorageWarning::banner(Auth::user());
@endphp
@if ($storageBanner)
  <div data-storage-banner="{{ $storageBanner['tone'] }}" role="alert"
       class="mt-16 border-b px-4 py-2 text-sm font-medium sm:px-6 lg:px-8 {{ $storageBanner['tone'] === 'red' ? 'border-red-300 bg-red-600 text-white' : 'border-amber-300 bg-amber-100 text-amber-900' }}">
    {{ $storageBanner['text'] }}
    <a href="{{ route('system.monitor.storage') }}" class="ml-2 underline">View storage</a>
  </div>
@endif
  {{-- Room for the fixed top bar; pages with their own header carry their own mt-16 (spec 295 moved the title into the bar). --}}
  @unless($ownHeader || $storageBanner)
  <div class="lk-top-spacer"></div>
  @endunless

  {{-- Page content --}}
  <main>
    @if (request()->is([
      'task/my-tasks',
      'macro/gsheet/index',
      'task/team-tasks',
      'ads-manager/edit-messaging-template',
      'encoder/checker_1',
      'ads_manager/campaigns',
      'ads_manager/cpp',
      'jnt/hold',
      'macro/orders',
      'pancake/retrieve-orders',
      'jnt/order-management',
      'pancake/index',
      'checklist',
      'checklist/*',
      'jnt_rts',
      'jnt_rts/*',
      'jnt/supply',
      'jnt/supply/*',
      'jnt_upload_v2',
      'jnt_upload_v2/*',
      'queue-manager',
      'queue-manager/*',
      'prompt-generator',
      'boardroom',
      'boardroom/*',
    ]))
      <div class="w-full px-0">
        {{ $slot }}
      </div>

    @elseif (request()->is('cpp') || request()->is('cpp/*') || request()->is('gpt-ad-generator') || request()->is('gpt-ad-generator/*') || request()->is('conversation/tracker') || request()->is('conversation/tracker/*'))
      <div class="w-full px-4 md:px-6 lg:px-8">
        {{ $slot }}
      </div>

    @else
      <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        {{ $slot }}
      </div>
    @endif
  </main>
  </div>
</div>
</body>
</html>
