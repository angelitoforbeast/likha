<x-layout>
  <x-slot name="title">Ad Catalog</x-slot>
  <x-slot name="heading">📋 Ad Catalog (Tree View)</x-slot>

  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

  <style>
    .ct-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .ct-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .ct-title { font-size:13px; font-weight:600; color:#0f172a; }
    .ct-input, .ct-select {
      padding:7px 10px; font-size:12.5px; color:#0f172a; background:#fff;
      border:1px solid #cbd5e1; border-radius:6px; width:100%;
    }
    .ct-input:focus, .ct-select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
    .ct-btn { display:inline-flex; align-items:center; gap:5px; background:#4f46e5; color:#fff; font-weight:600; font-size:12px; padding:7px 12px; border-radius:6px; }
    .ct-btn:hover { background:#4338ca; }
    .ct-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; }
    .ct-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }

    .stat-tile { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; }
    .stat-label { font-size:10.5px; color:#64748b; text-transform:uppercase; letter-spacing:0.04em; }
    .stat-value { font-size:20px; font-weight:700; color:#0f172a; margin-top:2px; }

    /* Tree structure styling */
    .tree-row { border-bottom:1px solid #f1f5f9; transition:background 0.1s; }
    .tree-row:hover { background:#f8fafc; }

    /* Campaign level — top */
    .tree-campaign { padding:12px 14px; cursor:pointer; display:flex; align-items:flex-start; gap:10px; }
    .tree-campaign:hover { background:#eef2ff; }
    .tree-campaign-toggle { color:#6366f1; font-size:14px; font-weight:700; user-select:none; line-height:1.5; width:14px; flex-shrink:0; }
    .tree-campaign-body { flex:1; min-width:0; }
    .tree-campaign-name { font-size:13.5px; font-weight:700; color:#0f172a; line-height:1.3; }
    .tree-campaign-meta { font-size:11px; color:#64748b; margin-top:3px; display:flex; gap:12px; flex-wrap:wrap; }
    .tree-campaign-meta strong { color:#334155; font-weight:600; }
    .tree-campaign-id { font-family:ui-monospace,monospace; color:#94a3b8; font-size:10.5px; }

    /* Ad Set level — nested under campaign */
    .tree-adsets-container { background:#fafbfc; border-top:1px solid #e2e8f0; padding:6px 0 6px 30px; }
    .tree-adset { padding:9px 14px 9px 18px; cursor:pointer; display:flex; align-items:flex-start; gap:10px; border-radius:6px; margin:2px 8px; }
    .tree-adset:hover { background:#ede9fe; }
    .tree-adset-toggle { color:#7c3aed; font-size:13px; font-weight:700; user-select:none; width:12px; flex-shrink:0; line-height:1.5; }
    .tree-adset-body { flex:1; min-width:0; }
    .tree-adset-name { font-size:12.5px; font-weight:600; color:#1e293b; }
    .tree-adset-meta { font-size:10.5px; color:#64748b; margin-top:2px; display:flex; gap:10px; flex-wrap:wrap; }
    .tree-adset-id { font-family:ui-monospace,monospace; color:#a78bfa; font-size:10px; }

    /* Ad level — leaf — shows ALL columns */
    .tree-ads-container { background:#fdfcff; border-top:1px solid #ede9fe; padding:6px 0 6px 40px; }
    .tree-ad { padding:10px 14px; margin:4px 8px; background:white; border:1px solid #e0e7ff; border-radius:6px; }
    .tree-ad-name { font-size:12.5px; font-weight:600; color:#0f172a; margin-bottom:6px; word-break:break-word; }
    .tree-ad-columns { display:grid; grid-template-columns:repeat(2, 1fr); gap:4px 12px; font-size:11px; }
    .col-key { color:#64748b; font-weight:600; font-size:10px; text-transform:uppercase; letter-spacing:0.03em; }
    .col-val { color:#0f172a; font-family:ui-monospace,monospace; font-size:10.5px; word-break:break-all; }
    .col-val.empty { color:#cbd5e1; font-style:italic; font-family:system-ui,sans-serif; }
    .col-row { padding:3px 0; border-bottom:1px dotted #f1f5f9; display:flex; gap:6px; }

    .pill { display:inline-flex; align-items:center; padding:2px 7px; border-radius:999px; font-size:10px; font-weight:600; }
    .pill-page { background:#dbeafe; color:#1e40af; }
    .pill-count { background:#f1f5f9; color:#475569; }
    .pill-date  { background:#dcfce7; color:#166534; font-family:ui-monospace,monospace; }

    .spin { display:inline-block; width:13px; height:13px; border:2px solid #cbd5e1; border-top-color:#6366f1; border-radius:50%; animation:rot 0.7s linear infinite; vertical-align:middle; }
    @keyframes rot { to { transform:rotate(360deg); } }
    .text-error { color:#dc2626; font-size:11px; padding:8px; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2" x-data="catalogTree()" x-cloak>

    @if (!empty($tableMissing))
      <div class="ct-card" style="padding:30px;text-align:center;">
        <p style="font-size:14px;color:#dc2626;font-weight:600;">⚠ ad_catalog table walang pa.</p>
        <p style="font-size:12px;color:#64748b;margin-top:8px;">Run migrations sa server: <code>php artisan migrate</code></p>
      </div>
    @else

      <!-- Stats summary -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="stat-tile">
          <div class="stat-label">Total Ads</div>
          <div class="stat-value">{{ number_format($totalAds) }}</div>
        </div>
        <div class="stat-tile">
          <div class="stat-label">Campaigns</div>
          <div class="stat-value">{{ number_format($totalCampaigns) }}</div>
        </div>
        <div class="stat-tile">
          <div class="stat-label">Ad Sets</div>
          <div class="stat-value">{{ number_format($totalAdSets) }}</div>
        </div>
        <div class="stat-tile">
          <div class="stat-label">Pages</div>
          <div class="stat-value">{{ number_format($totalPages) }}</div>
        </div>
      </div>

      <!-- Filters -->
      <div class="ct-card">
        <div class="ct-card-header">
          <div class="ct-title">🔎 Filters</div>
          <div class="flex gap-2 flex-wrap">
            <button type="button" @click="expandAllCampaigns()" class="ct-btn-ghost" title="Expand all campaigns (will fire many AJAX calls — for small filtered sets)">▼ Expand All</button>
            <button type="button" @click="collapseAll()" class="ct-btn-ghost">▲ Collapse All</button>
            <a href="/ads_manager/report" class="ct-btn-ghost">← Back to Report</a>
          </div>
        </div>

        <form method="GET" action="/ads_manager/catalog" class="grid grid-cols-2 md:grid-cols-6 gap-3 p-3">
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Page</label>
            <select name="page" class="ct-select">
              <option value="">All pages</option>
              @foreach ($allPages as $p)
                <option value="{{ $p }}" @selected($pageFilter === $p)>{{ $p }}</option>
              @endforeach
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs font-semibold text-slate-600 mb-1">Search (campaign / adset / ad / IDs)</label>
            <input type="text" name="q" value="{{ $qFilter }}"
                   placeholder="e.g. MESH SEAT, camp001, headline keyword"
                   class="ct-input" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">First Started ≥</label>
            <input type="date" name="from_date" value="{{ $fromDate }}" class="ct-input" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">First Started ≤</label>
            <input type="date" name="to_date" value="{{ $toDate }}" class="ct-input" />
          </div>
          <div class="flex gap-2 items-end">
            <button type="submit" class="ct-btn">Apply</button>
            <a href="/ads_manager/catalog" class="ct-btn-ghost">Reset</a>
          </div>
        </form>
      </div>

      <!-- Tree -->
      <div class="ct-card overflow-hidden">
        <div class="ct-card-header">
          <div class="ct-title">
            🌳 Tree ({{ number_format($campaigns->count()) }} campaigns)
            <span style="font-weight:400;color:#94a3b8;font-size:11.5px;margin-left:4px;">
              · click ▶ to expand · all data from ad_catalog
            </span>
          </div>
        </div>

        <div class="overflow-auto" style="max-height:calc(100vh - 360px);">
          @forelse ($campaigns as $c)
            <div class="tree-row">
              {{-- ── CAMPAIGN ROW ─────────────────────────────────────── --}}
              <div class="tree-campaign"
                   @click="toggleCampaign('{{ addslashes($c->campaign_id) }}')">
                <span class="tree-campaign-toggle"
                      x-text="(campaigns['{{ addslashes($c->campaign_id) }}']?.open ? '▼' : '▶')"></span>
                <div class="tree-campaign-body">
                  <div class="tree-campaign-name">{{ $c->campaign_name ?: '(no name)' }}</div>
                  <div class="tree-campaign-meta">
                    <span><span class="tree-campaign-id">{{ $c->campaign_id }}</span></span>
                    @if ($c->page_name)
                      <span class="pill pill-page">{{ $c->page_name }}</span>
                    @endif
                    <span><strong>{{ $c->total_ads }}</strong> ads · <strong>{{ $c->total_ad_sets }}</strong> ad sets</span>
                    @if ($c->min_first_started)
                      <span>📅 First Started: <span class="pill pill-date">{{ $c->min_first_started }}</span></span>
                    @endif
                    @if ($c->min_first_spend_day)
                      <span>💰 First Spend: <span class="pill pill-date">{{ $c->min_first_spend_day }}</span></span>
                    @endif
                    @if ($c->account_id)
                      <span style="font-size:10px;color:#94a3b8;">Account: <span class="tree-campaign-id">{{ $c->account_id }}</span></span>
                    @endif
                    <span style="font-size:10px;color:#cbd5e1;">Updated {{ \Carbon\Carbon::parse($c->last_updated)->diffForHumans() }}</span>
                  </div>
                </div>
              </div>

              {{-- ── AD SETS CONTAINER (lazy-loaded) ──────────────────── --}}
              <div x-show="campaigns['{{ addslashes($c->campaign_id) }}']?.open" x-transition>
                <div class="tree-adsets-container">

                  <template x-if="campaigns['{{ addslashes($c->campaign_id) }}']?.loading">
                    <div style="padding:8px 14px;color:#64748b;font-size:11px;"><span class="spin"></span> Loading ad sets…</div>
                  </template>

                  <template x-if="campaigns['{{ addslashes($c->campaign_id) }}']?.error">
                    <div class="text-error" x-text="'⚠ ' + campaigns['{{ addslashes($c->campaign_id) }}']?.error"></div>
                  </template>

                  <template x-if="!campaigns['{{ addslashes($c->campaign_id) }}']?.loading
                                  && !campaigns['{{ addslashes($c->campaign_id) }}']?.error
                                  && (campaigns['{{ addslashes($c->campaign_id) }}']?.adsets || []).length === 0
                                  && campaigns['{{ addslashes($c->campaign_id) }}']?.open">
                    <div style="padding:8px 14px;color:#94a3b8;font-style:italic;font-size:11px;">No ad sets found for this campaign.</div>
                  </template>

                  <template x-for="aset in (campaigns['{{ addslashes($c->campaign_id) }}']?.adsets || [])" :key="aset.ad_set_id">
                    <div>
                      {{-- ── AD SET ROW ───────────────────────────────── --}}
                      <div class="tree-adset" @click="toggleAdSet(aset.ad_set_id)">
                        <span class="tree-adset-toggle" x-text="(adsets[aset.ad_set_id]?.open ? '▼' : '▶')"></span>
                        <div class="tree-adset-body">
                          <div class="tree-adset-name" x-text="aset.ad_set_name || '(no name)'"></div>
                          <div class="tree-adset-meta">
                            <span><span class="tree-adset-id" x-text="aset.ad_set_id"></span></span>
                            <span><strong x-text="aset.total_ads"></strong> ads</span>
                            <template x-if="aset.min_first_started">
                              <span>📅 First Started: <span class="pill pill-date" x-text="aset.min_first_started"></span></span>
                            </template>
                            <template x-if="aset.min_first_spend_day">
                              <span>💰 First Spend: <span class="pill pill-date" x-text="aset.min_first_spend_day"></span></span>
                            </template>
                          </div>
                        </div>
                      </div>

                      {{-- ── ADS CONTAINER (lazy-loaded) ─────────────── --}}
                      <div x-show="adsets[aset.ad_set_id]?.open" x-transition>
                        <div class="tree-ads-container">
                          <template x-if="adsets[aset.ad_set_id]?.loading">
                            <div style="padding:8px;color:#64748b;font-size:11px;"><span class="spin"></span> Loading ads…</div>
                          </template>

                          <template x-if="adsets[aset.ad_set_id]?.error">
                            <div class="text-error" x-text="'⚠ ' + adsets[aset.ad_set_id]?.error"></div>
                          </template>

                          <template x-for="ad in (adsets[aset.ad_set_id]?.ads || [])" :key="ad.id">
                            <div class="tree-ad">
                              <div class="tree-ad-name" x-text="ad.ad_name || '(no headline)'"></div>
                              <div class="tree-ad-columns">
                                {{-- All 13 ad_catalog columns rendered as key/value rows --}}
                                <div class="col-row"><span class="col-key">id</span><span class="col-val" x-text="ad.id ?? '—'"></span></div>
                                <div class="col-row"><span class="col-key">ad_id</span><span class="col-val" x-text="ad.ad_id ?? '—'"></span></div>
                                <div class="col-row"><span class="col-key">ad_name</span><span :class="ad.ad_name ? 'col-val' : 'col-val empty'" x-text="ad.ad_name || '(empty)'"></span></div>
                                <div class="col-row"><span class="col-key">ad_set_id</span><span class="col-val" x-text="ad.ad_set_id ?? '—'"></span></div>
                                <div class="col-row"><span class="col-key">ad_set_name</span><span :class="ad.ad_set_name ? 'col-val' : 'col-val empty'" x-text="ad.ad_set_name || '(empty)'"></span></div>
                                <div class="col-row"><span class="col-key">campaign_id</span><span class="col-val" x-text="ad.campaign_id ?? '—'"></span></div>
                                <div class="col-row"><span class="col-key">campaign_name</span><span :class="ad.campaign_name ? 'col-val' : 'col-val empty'" x-text="ad.campaign_name || '(empty)'"></span></div>
                                <div class="col-row"><span class="col-key">page_name</span><span :class="ad.page_name ? 'col-val' : 'col-val empty'" x-text="ad.page_name || '(empty)'"></span></div>
                                <div class="col-row"><span class="col-key">account_id</span><span :class="ad.account_id ? 'col-val' : 'col-val empty'" x-text="ad.account_id || '(empty)'"></span></div>
                                <div class="col-row"><span class="col-key">first_started</span><span :class="ad.first_started ? 'col-val' : 'col-val empty'" x-text="ad.first_started || '(null)'"></span></div>
                                <div class="col-row"><span class="col-key">first_spend_day</span><span :class="ad.first_spend_day ? 'col-val' : 'col-val empty'" x-text="ad.first_spend_day || '(null)'"></span></div>
                                <div class="col-row"><span class="col-key">created_at</span><span class="col-val" x-text="ad.created_at ?? '—'"></span></div>
                                <div class="col-row"><span class="col-key">updated_at</span><span class="col-val" x-text="ad.updated_at ?? '—'"></span></div>
                              </div>
                            </div>
                          </template>

                          <template x-if="!adsets[aset.ad_set_id]?.loading
                                         && !adsets[aset.ad_set_id]?.error
                                         && (adsets[aset.ad_set_id]?.ads || []).length === 0
                                         && adsets[aset.ad_set_id]?.open">
                            <div style="padding:8px;color:#94a3b8;font-style:italic;font-size:11px;">No ads found under this ad set.</div>
                          </template>
                        </div>
                      </div>
                    </div>
                  </template>

                </div>
              </div>
            </div>
          @empty
            <div style="padding:36px;text-align:center;color:#94a3b8;">
              No campaigns in catalog matching filters.
              <div style="font-size:11px;margin-top:6px;">Try adjusting filters or upload Excel sa /ads_manager/report.</div>
            </div>
          @endforelse
        </div>
      </div>

    @endif

  </div>

  <script>
    function catalogTree() {
      return {
        // campaigns[campaign_id] = { open: bool, loading: bool, error: string|null, adsets: array }
        campaigns: {},
        // adsets[ad_set_id] = { open: bool, loading: bool, error: string|null, ads: array }
        adsets: {},

        async toggleCampaign(campaignId) {
          const cur = this.campaigns[campaignId] || {};
          // If already loaded, just toggle open state
          if (cur.adsets) {
            this.campaigns[campaignId] = Object.assign({}, cur, { open: !cur.open });
            return;
          }
          // First open — fetch ad sets
          this.campaigns[campaignId] = { open: true, loading: true, error: null, adsets: null };
          try {
            const url = '/ads_manager/catalog/adsets?campaign_id=' + encodeURIComponent(campaignId);
            const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const j = await r.json();
            if (!r.ok || !j.ok) {
              this.campaigns[campaignId] = { open: true, loading: false, error: j.message || ('HTTP ' + r.status), adsets: [] };
              return;
            }
            this.campaigns[campaignId] = { open: true, loading: false, error: null, adsets: j.rows || [] };
          } catch (e) {
            this.campaigns[campaignId] = { open: true, loading: false, error: 'Network: ' + e.message, adsets: [] };
          }
        },

        async toggleAdSet(adSetId) {
          const cur = this.adsets[adSetId] || {};
          if (cur.ads) {
            this.adsets[adSetId] = Object.assign({}, cur, { open: !cur.open });
            return;
          }
          this.adsets[adSetId] = { open: true, loading: true, error: null, ads: null };
          try {
            const url = '/ads_manager/catalog/ads?ad_set_id=' + encodeURIComponent(adSetId);
            const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const j = await r.json();
            if (!r.ok || !j.ok) {
              this.adsets[adSetId] = { open: true, loading: false, error: j.message || ('HTTP ' + r.status), ads: [] };
              return;
            }
            this.adsets[adSetId] = { open: true, loading: false, error: null, ads: j.rows || [] };
          } catch (e) {
            this.adsets[adSetId] = { open: true, loading: false, error: 'Network: ' + e.message, ads: [] };
          }
        },

        async expandAllCampaigns() {
          // Get all campaign_id from server-rendered DOM data attributes — but
          // simpler: trigger toggleCampaign for each visible campaign tile.
          const campIds = Array.from(document.querySelectorAll('.tree-campaign[onclick], .tree-campaign'))
            .map(el => el.getAttribute('@click') || el.getAttribute('x-on:click') || '')
            .map(s => {
              const m = /toggleCampaign\('([^']+)'\)/.exec(s);
              return m ? m[1] : null;
            })
            .filter(Boolean);
          // Note: para sa large lists, this fires N parallel requests. Use with care.
          await Promise.allSettled(campIds.map(id => {
            const cur = this.campaigns[id];
            if (cur && cur.open) return Promise.resolve();
            return this.toggleCampaign(id);
          }));
        },

        collapseAll() {
          // Close all open campaign + adset entries (don't clear loaded data)
          for (const cid in this.campaigns) {
            if (this.campaigns[cid].open) {
              this.campaigns[cid] = Object.assign({}, this.campaigns[cid], { open: false });
            }
          }
          for (const aid in this.adsets) {
            if (this.adsets[aid].open) {
              this.adsets[aid] = Object.assign({}, this.adsets[aid], { open: false });
            }
          }
        },
      };
    }
  </script>
</x-layout>
