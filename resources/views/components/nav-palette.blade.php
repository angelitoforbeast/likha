@props([
  // NavMenu::entries(): every link the person sees, [{id, label, section, url, icon}].
  'entries' => [],
])

{{-- Ctrl+K palette (spec 295): every link the person can see, searchable by label, section and url words, with the
     last 5 pages visited in this browser on top. ↑ ↓ move, Enter opens, Esc closes. --}}
<script type="application/json" id="nav-palette-data">@json($entries)</script>

<div x-data="likhaPalette()" x-on:keydown.window="hotkey($event)" x-on:likha-palette.window="show($event.detail)">
  <div class="lk-pal-scrim" x-show="open" x-cloak x-transition.opacity.duration.150ms x-on:click="close()"></div>
  <div class="lk-pal" role="dialog" aria-modal="true" aria-label="Go to a page" x-show="open" x-cloak
       x-transition.opacity.duration.150ms x-on:keydown="keys($event)">
    <div class="lk-pal-search">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <input type="text" x-ref="q" x-model="q" x-on:input="sel = 0"
             placeholder="Go to a page…" aria-label="Search pages" autocomplete="off" spellcheck="false"
             role="combobox" aria-expanded="true" aria-controls="lk-pal-list" aria-autocomplete="list"
             :aria-activedescendant="rows().length ? 'lk-pal-opt-' + sel : null">
      <kbd class="lk-kbd">Esc</kbd>
    </div>
    <ul class="lk-pal-list" id="lk-pal-list" role="listbox" aria-label="Pages" x-ref="list">
      <template x-for="(r, i) in rows()" :key="r.key">
        <li role="presentation">
          <p class="lk-pal-group" x-show="r.group" x-text="r.group"></p>
          <a :href="r.url" class="lk-pal-opt" role="option" :id="'lk-pal-opt-' + i" :aria-selected="(i === sel).toString()"
             :class="i === sel && 'is-sel'" x-on:mousemove="sel = i" tabindex="-1">
            <span class="lk-icon" aria-hidden="true"><i :class="r.icon"></i></span>
            <span class="lk-pal-label" x-text="r.label"></span>
            <span class="lk-pal-section" x-text="r.section"></span>
          </a>
        </li>
      </template>
    </ul>
    <p class="lk-pal-empty" x-show="!rows().length" x-cloak>
      <template x-if="q.trim()"><span>No page matches “<span x-text="q.trim()"></span>”.</span></template>
      <template x-if="!q.trim()"><span>No pages are shared with your role yet.</span></template>
    </p>
    <div class="lk-pal-foot" aria-hidden="true">
      <span><kbd class="lk-kbd">↑</kbd><kbd class="lk-kbd">↓</kbd> to move</span>
      <span><kbd class="lk-kbd">Enter</kbd> to open</span>
      <span><kbd class="lk-kbd">Esc</kbd> to close</span>
    </div>
  </div>
</div>

<script>
  window.likhaPalette = function () {
    const entries = JSON.parse(document.getElementById('nav-palette-data').textContent || '[]');
    const KEY = 'likha.nav.recent';
    const read = () => { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } };
    const path = u => '/' + String(u).replace(/^\/+|\/+$/g, '');
    const here = path(location.pathname);
    // Remember this page when it is one of the person's links (the last 5, newest first).
    const current = entries.find(e => path(e.url) === here);
    if (current) {
      try { localStorage.setItem(KEY, JSON.stringify([current.url, ...read().filter(u => u !== current.url)].slice(0, 5))); } catch (e) {}
    }
    const words = s => String(s).toLowerCase().split(/[^a-z0-9]+/).filter(Boolean);
    const hay = e => [e.label, e.section, e.url].join(' ').toLowerCase();

    return {
      open: false, q: '', sel: 0, opener: null,
      rows() {
        const terms = words(this.q);
        if (terms.length) {
          return entries.filter(e => terms.every(t => hay(e).includes(t)))
            .map((e, i) => ({ ...e, key: 'm' + e.id, group: i === 0 ? 'Pages' : '' }));
        }
        const recent = read().map(u => entries.find(e => e.url === u)).filter(Boolean);
        const out = recent.map((e, i) => ({ ...e, key: 'r' + e.id, group: i === 0 ? 'Recent' : '' }));
        let last = null;
        for (const e of entries) { // already in sidebar order, section by section
          out.push({ ...e, key: 'a' + e.id, group: e.section !== last ? e.section : '' });
          last = e.section;
        }
        return out;
      },
      show(from) {
        this.opener = from instanceof HTMLElement ? from : document.activeElement;
        this.q = ''; this.sel = 0; this.open = true;
        this.$nextTick(() => this.$refs.q.focus());
      },
      close() {
        if (!this.open) return;
        this.open = false;
        this.opener?.focus?.();
      },
      hotkey(e) {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'k') {
          e.preventDefault();
          this.open ? this.close() : this.show();
        }
      },
      keys(e) {
        const n = this.rows().length;
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.close(); }
        else if (e.key === 'ArrowDown' && n) { e.preventDefault(); this.sel = (this.sel + 1) % n; this.reveal(); }
        else if (e.key === 'ArrowUp' && n) { e.preventDefault(); this.sel = (this.sel - 1 + n) % n; this.reveal(); }
        else if (e.key === 'Enter' && n) { e.preventDefault(); location.href = this.rows()[this.sel].url; }
        else if (e.key === 'Tab') { e.preventDefault(); this.$refs.q.focus(); }
      },
      reveal() { this.$nextTick(() => document.getElementById('lk-pal-opt-' + this.sel)?.scrollIntoView({ block: 'nearest' })); },
    };
  };
</script>
