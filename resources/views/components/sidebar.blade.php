@props([
  // NavSections::group(): [['name', 'icon', 'links' => Collection], ...], only sections with a link.
  'sections' => [],
  // NavMenu::entries() of every shown link (the pinned group is drawn from it in the browser).
  'entries' => [],
  // The person's pins among the shown links, in order.
  'pins' => [],
  // False while the CEO previews another role (pins belong to the CEO's own nav).
  'canPin' => true,
  // Checklist badge of the signed-in person (ChecklistBadge::forUser) or null.
  'checklistBadge' => null,
])

@php
  $isChecklistLink = fn ($link) => trim((string) $link->route_url, '/') === 'checklist';
  // A link with no pattern covers its url and the paths below it, on whole segments (as AccessRule, spec 294).
  $isActive = function ($link) {
    if (trim((string) $link->active_pattern) !== '') return request()->is(ltrim(trim($link->active_pattern), '/'));
    $url = trim((string) $link->route_url, '/');
    return $url === '' ? request()->is('/') : request()->is($url, $url . '/*');
  };
  // Only one row is marked as the current page: the first match in sidebar order.
  $activeId = null;
  foreach ($sections as $s) {
    foreach ($s['links'] as $l) { if ($activeId === null && $isActive($l)) $activeId = (int) $l->id; }
  }
@endphp

{{-- Sidebar (spec 295): fixed 248 px at ≥ 1024 px, a 64 px rail when collapsed, a drawer below 1024 px. The open/closed
     state of the rail, drawer and sections lives in attributes on <html> (set before paint in the layout's head). --}}
<div class="lk-scrim" data-nav-drawer-close aria-hidden="true"></div>
<aside id="likha-sidebar" class="lk-sidebar" data-nav-sidebar aria-label="Main navigation"
       x-data="likhaPins(@js($pins), {{ $canPin ? 'true' : 'false' }})">
  <div class="lk-brand">
    <a href="{{ url('/') }}" class="lk-mark" data-nav-tip="Likha home">
      <span class="lk-mark-tile" aria-hidden="true">L</span>
      <span class="lk-label lk-mark-word">Likha</span>
    </a>
    <button type="button" class="lk-iconbtn lk-rail-toggle" data-nav-rail-toggle aria-label="Collapse sidebar" aria-pressed="false">
      <i class="fa-solid fa-angles-left" aria-hidden="true"></i>
    </button>
    <button type="button" class="lk-iconbtn lk-drawer-close" data-nav-drawer-close aria-label="Close menu">
      <i class="fa-solid fa-xmark" aria-hidden="true"></i>
    </button>
  </div>

  <nav class="lk-scroll" aria-label="Pages">
    @if(empty($sections))
      <div class="lk-empty" data-nav-empty>
        <i class="fa-regular fa-compass" aria-hidden="true"></i>
        <p class="lk-empty-title">No pages yet</p>
        <p>No pages are shared with your role yet. Ask the owner to open them in Nav settings.</p>
      </div>
    @endif

    {{-- Pinned: drawn from the entries in the browser, so pinning, unpinning and dragging need no reload. --}}
    <section class="lk-section lk-pinned" x-show="pinned().length" x-cloak aria-labelledby="lk-pinned-head">
      <h2 class="lk-section-head is-static" id="lk-pinned-head"><span class="lk-label">Pinned</span></h2>
      <ul class="lk-list" x-on:dragover.prevent>
        <template x-for="(p, i) in pinned()" :key="p.id">
          <li class="lk-row" draggable="true"
              x-on:dragstart="dragFrom = i; $event.dataTransfer.effectAllowed = 'move'"
              x-on:drop.prevent="move(dragFrom, i)" x-on:dragend="dragFrom = null"
              :class="dragFrom === i && 'is-dragging'">
            <a :href="p.url" class="lk-link" :class="p.id === {{ $activeId ?? 'null' }} && 'is-here'"
               :data-nav-tip="p.label" x-on:keydown.alt.arrow-up.prevent="move(i, i - 1, $event)"
               x-on:keydown.alt.arrow-down.prevent="move(i, i + 1, $event)">
              <span class="lk-icon" aria-hidden="true"><i :class="p.icon"></i></span>
              <span class="lk-label" x-text="p.label"></span>
            </a>
            <button type="button" class="lk-pin is-on" x-on:click="toggle(p.id)" :aria-label="'Unpin ' + p.label" title="Unpin">
              <i class="fa-solid fa-thumbtack" aria-hidden="true"></i>
            </button>
          </li>
        </template>
      </ul>
      <p class="lk-pin-note" x-show="pins.length >= 6 && !error" x-cloak>Six pins is the most. Unpin one to pin another.</p>
      <p class="lk-pin-error" role="status" x-show="error" x-text="error" x-cloak></p>
    </section>

    @foreach($sections as $section)
      @php $sid = 'lk-sec-' . strtolower($section['name']); @endphp
      @php $hasCurrent = $section['links']->contains(fn ($l) => (int) $l->id === $activeId); @endphp
      <section class="lk-section {{ $hasCurrent ? 'has-current' : '' }}" data-nav-section="{{ $section['name'] }}">
        <h2 class="lk-section-head-wrap">
          <button type="button" class="lk-section-head" data-nav-toggle="{{ $section['name'] }}" aria-expanded="true" aria-controls="{{ $sid }}">
            <span class="lk-label">{{ $section['name'] }}</span>
            <i class="fa-solid fa-chevron-down lk-chevron" aria-hidden="true"></i>
          </button>
        </h2>
        <ul class="lk-list" id="{{ $sid }}" data-section-body="{{ $section['name'] }}">
          @foreach($section['links'] as $link)
            @php $cb = $checklistBadge && $isChecklistLink($link) ? $checklistBadge : null; @endphp
            <li class="lk-row {{ $canPin ? 'has-pin' : '' }}">
              <x-navlink
                :href="$link->route_url"
                :active="(int) $link->id === $activeId"
                :label="$link->label"
                :icon="$link->icon ?: 'fa-solid fa-link'"
                :badge="$cb ? $cb['count'] : 0"
                :badge-tone="$cb['tone'] ?? 'red'"
                :badge-title="$cb['title'] ?? null"
                :badge-key="$cb ? 'checklist' : null"
                :badge-team="$cb['team'] ?? 0"
                :badge-team-title="$cb['team_title'] ?? null" />
              @if($canPin)
                <button type="button" class="lk-pin" x-on:click="toggle({{ (int) $link->id }})"
                        :class="has({{ (int) $link->id }}) && 'is-on'"
                        :aria-pressed="has({{ (int) $link->id }}).toString()"
                        :disabled="!has({{ (int) $link->id }}) && pins.length >= 6"
                        :title="has({{ (int) $link->id }}) ? 'Unpin' : (pins.length >= 6 ? 'Six pins is the most; unpin one first' : 'Pin to the top')"
                        aria-label="Pin {{ $link->label }}">
                  <i class="fa-solid fa-thumbtack" aria-hidden="true"></i>
                </button>
              @endif
            </li>
          @endforeach
        </ul>
      </section>
    @endforeach
  </nav>
</aside>
<div class="lk-tip" id="lk-tip" role="tooltip" hidden></div>

<script type="application/json" id="nav-pins-data">@json($pins)</script>
<script>
  // Pins (spec 295): the person's own list, saved to /nav/pins; reverted with a message when the save fails.
  window.likhaPins = function (initial, canPin) {
    const entries = JSON.parse(document.getElementById('nav-palette-data')?.textContent || '[]');
    const byId = Object.fromEntries(entries.map(e => [e.id, e]));
    return {
      pins: initial.filter(id => byId[id]),
      dragFrom: null,
      error: '',
      has(id) { return this.pins.includes(id); },
      pinned() { return this.pins.map(id => byId[id]).filter(Boolean); },
      toggle(id) {
        if (!canPin) return;
        if (this.has(id)) this.save(this.pins.filter(p => p !== id));
        else if (this.pins.length < 6) this.save([...this.pins, id]);
      },
      move(from, to, ev) {
        if (from === null || to < 0 || to >= this.pins.length || from === to) return;
        const next = [...this.pins];
        next.splice(to, 0, next.splice(from, 1)[0]);
        this.save(next);
        if (ev) this.$nextTick(() => this.$el.querySelectorAll('.lk-pinned .lk-link')[to]?.focus());
      },
      save(next) {
        const before = this.pins;
        this.pins = next;
        this.error = '';
        fetch(@js(route('nav.pins')), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                     'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' },
          body: JSON.stringify({ pins: next }),
        }).then(r => r.ok ? r.json() : Promise.reject(r))
          .then(d => { this.pins = d.pins; })
          .catch(() => { this.pins = before; this.error = 'Could not save pins. Try again.'; });
      },
    };
  };

  // Rail, drawer, sections and rail tooltips. Plain script: works before (and without) Alpine.
  (function () {
    const html = document.documentElement;
    const store = {
      get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
      set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} },
    };
    const closed = () => (html.getAttribute('data-nav-closed') || '').split(' ').filter(Boolean);
    const desktop = () => window.matchMedia('(min-width: 1024px)').matches;

    document.querySelectorAll('[data-nav-toggle]').forEach(btn => {
      const name = btn.getAttribute('data-nav-toggle');
      btn.setAttribute('aria-expanded', String(!closed().includes(name)));
      btn.addEventListener('click', () => {
        if (desktop() && html.hasAttribute('data-nav-rail')) return;
        let list = closed();
        list = list.includes(name) ? list.filter(n => n !== name) : [...list, name];
        html.setAttribute('data-nav-closed', list.join(' '));
        btn.setAttribute('aria-expanded', String(!list.includes(name)));
        store.set('likha.nav.closed', JSON.stringify(list));
      });
    });

    const railBtn = document.querySelector('[data-nav-rail-toggle]');
    const syncRail = () => {
      const on = html.hasAttribute('data-nav-rail');
      railBtn.setAttribute('aria-pressed', String(on));
      railBtn.setAttribute('aria-label', on ? 'Expand sidebar' : 'Collapse sidebar');
      railBtn.setAttribute('data-nav-tip', on ? 'Expand sidebar' : '');
      // In the rail the section headers are only hairlines: out of the tab order and hidden from screen readers.
      const rail = on && desktop();
      document.querySelectorAll('[data-nav-toggle]').forEach(b => {
        rail ? b.setAttribute('tabindex', '-1') : b.removeAttribute('tabindex');
        rail ? b.setAttribute('aria-hidden', 'true') : b.removeAttribute('aria-hidden');
      });
    };
    railBtn.addEventListener('click', () => {
      html.toggleAttribute('data-nav-rail');
      store.set('likha.nav.rail', html.hasAttribute('data-nav-rail') ? '1' : '0');
      syncRail();
      tip.hidden = true;
    });
    syncRail();
    window.matchMedia('(min-width: 1024px)').addEventListener('change', syncRail);

    // Drawer below 1024 px: focus moves in, Esc / scrim / close button / a link closes it, focus returns.
    const sidebar = document.getElementById('likha-sidebar');
    let opener = null;
    const behind = () => document.querySelectorAll('.lk-main, .lk-topbar');
    window.likhaDrawer = {
      open(from) {
        opener = from || null;
        html.setAttribute('data-nav-drawer', '');
        behind().forEach(el => { el.inert = true; });
        document.querySelectorAll('[data-nav-drawer-open]').forEach(b => b.setAttribute('aria-expanded', 'true'));
        sidebar.querySelector('.lk-drawer-close').focus();
      },
      close() {
        if (!html.hasAttribute('data-nav-drawer')) return;
        html.removeAttribute('data-nav-drawer');
        behind().forEach(el => { el.inert = false; });
        document.querySelectorAll('[data-nav-drawer-open]').forEach(b => b.setAttribute('aria-expanded', 'false'));
        if (opener) opener.focus();
      },
    };
    document.querySelectorAll('[data-nav-drawer-close]').forEach(el => el.addEventListener('click', () => window.likhaDrawer.close()));
    document.addEventListener('keydown', e => {
      if (!html.hasAttribute('data-nav-drawer')) return;
      if (e.key === 'Escape') { window.likhaDrawer.close(); return; }
      if (e.key !== 'Tab') return;
      const f = [...sidebar.querySelectorAll('a[href], button:not([disabled])')].filter(el => el.offsetParent !== null);
      if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    });
    window.addEventListener('resize', () => { if (desktop()) window.likhaDrawer.close(); });

    // Tooltips in the rail: one fixed chip, so the sidebar's scroll box cannot clip it.
    const tip = document.getElementById('lk-tip');
    const show = el => {
      const text = el.getAttribute('data-nav-tip');
      if (!text || !desktop() || !html.hasAttribute('data-nav-rail')) return;
      const r = el.getBoundingClientRect();
      tip.textContent = text;
      tip.hidden = false;
      tip.style.top = (r.top + r.height / 2) + 'px';
      tip.style.left = (r.right + 10) + 'px';
    };
    sidebar.addEventListener('mouseover', e => { const el = e.target.closest('[data-nav-tip]'); if (el) show(el); });
    sidebar.addEventListener('mouseout', e => { if (e.target.closest('[data-nav-tip]')) tip.hidden = true; });
    sidebar.addEventListener('focusin', e => { const el = e.target.closest('[data-nav-tip]'); el ? show(el) : (tip.hidden = true); });
    sidebar.addEventListener('focusout', () => { tip.hidden = true; });
    sidebar.querySelector('.lk-scroll').addEventListener('scroll', () => { tip.hidden = true; }, { passive: true });
  })();
</script>
