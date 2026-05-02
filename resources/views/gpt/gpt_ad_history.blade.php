<x-layout>
  <x-slot name="title">Ad Captions History</x-slot>
  <x-slot name="heading">Ad Copy Generation History</x-slot>

  <style>
    .h-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .h-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .h-card-title { font-size:13px; font-weight:600; color:#0f172a; }
    .h-input, .h-select {
      padding:7px 10px; font-size:12px; color:#0f172a; background:#fff;
      border:1px solid #cbd5e1; border-radius:6px; min-width:0;
    }
    .h-input:focus, .h-select:focus {
      outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12);
    }
    .h-btn { display:inline-flex; align-items:center; gap:5px; background:#4f46e5; color:#fff; font-weight:600; font-size:12px; padding:7px 12px; border-radius:6px; }
    .h-btn:hover { background:#4338ca; }
    .h-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; }
    .h-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }

    .h-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .h-table thead th {
      position:sticky; top:0; z-index:1; background:#f8fafc; color:#475569;
      font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;
      padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0;
    }
    .h-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; word-wrap:break-word; }
    .h-table tbody tr:hover td { background:#f8fafc; }

    .pill { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:500; background:#eef2ff; color:#4338ca; white-space:nowrap; }
    .pill.gray { background:#f1f5f9; color:#475569; }
    .pill.green { background:#dcfce7; color:#166534; }

    /* Detail panel */
    .h-detail-row td { background:#f8fafc !important; padding:0 !important; }
    .h-detail { padding:14px 18px; border-top:1px dashed #cbd5e1; }
    .h-detail h4 { font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.06em; margin-top:10px; margin-bottom:4px; }
    .h-detail h4:first-child { margin-top:0; }
    .h-detail pre { background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; font-size:11.5px; line-height:1.5; white-space:pre-wrap; max-height:240px; overflow:auto; color:#0f172a; }
    .h-variant { background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-bottom:6px; font-size:11.5px; }
    .h-variant-fields { display:grid; grid-template-columns:90px 1fr; gap:4px 10px; align-items:start; }
    .h-variant-fields strong { color:#475569; font-size:10.5px; text-transform:uppercase; letter-spacing:0.04em; }
    .h-variant-fields span { color:#0f172a; word-wrap:break-word; }
  </style>

  <div class="w-full flex flex-col gap-4" x-data="historyApp()">
    <!-- Filter card -->
    <div class="h-card">
      <div class="h-card-header">
        <div class="h-card-title">📚 Generation History</div>
        <div class="flex items-center gap-2">
          <a href="{{ route('owner.private') }}" class="h-btn-ghost">← Owner Private</a>
          <a href="/gpt-ad-generator" class="h-btn">＋ Generate New</a>
        </div>
      </div>

      <form method="GET" action="{{ route('gpt.history') }}"
            class="grid grid-cols-2 md:grid-cols-4 gap-2 p-3">
        <input type="text" name="q" value="{{ $q }}" placeholder="🔎 Search product / page / item…" class="h-input" />
        <select name="user" class="h-select">
          <option value="">All users</option>
          @foreach ($allUsers as $u)
            <option value="{{ $u }}" @selected($userFilter === $u)>{{ $u }}</option>
          @endforeach
        </select>
        <select name="model" class="h-select">
          <option value="">All models</option>
          @foreach ($allModels as $m)
            <option value="{{ $m }}" @selected($modelFilter === $m)>{{ $m }}</option>
          @endforeach
        </select>
        <div class="flex gap-2">
          <button type="submit" class="h-btn">Apply</button>
          <a href="{{ route('gpt.history') }}" class="h-btn-ghost">Reset</a>
        </div>
      </form>
    </div>

    <!-- Table card -->
    <div class="h-card overflow-hidden">
      <div class="overflow-auto" style="max-height:calc(100vh - 280px);">
        <table class="h-table">
          <thead>
            <tr>
              <th style="width:140px;">When</th>
              <th style="width:200px;">User</th>
              <th>Product</th>
              <th style="width:160px;">Page / Item</th>
              <th style="width:130px;">Model</th>
              <th style="width:80px;">Variants</th>
              <th style="width:80px;text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($rows as $row)
              <tr data-id="{{ $row->id }}">
                <td>
                  <div style="font-weight:600;">{{ \Carbon\Carbon::parse($row->created_at)->format('M j, Y') }}</div>
                  <div style="font-size:10.5px;color:#94a3b8;">{{ \Carbon\Carbon::parse($row->created_at)->format('g:i A') }}</div>
                </td>
                <td>
                  @if ($row->user_email)
                    <span class="pill gray">{{ $row->user_email }}</span>
                  @else
                    <span style="color:#cbd5e1;font-size:11px;">— anonymous —</span>
                  @endif
                </td>
                <td>
                  <div style="font-weight:600;">{{ $row->product_name }}</div>
                  <div style="font-size:11px;color:#64748b;line-height:1.4;">
                    {{ \Illuminate\Support\Str::limit($row->product_description, 100) }}
                  </div>
                </td>
                <td style="font-size:11px;color:#475569;">
                  @if ($row->page_filter)<div>📄 {{ $row->page_filter }}</div>@endif
                  @if ($row->item_filter)<div>🛒 {{ $row->item_filter }}</div>@endif
                  @if (!$row->page_filter && !$row->item_filter)
                    <span style="color:#cbd5e1;">— none —</span>
                  @endif
                  @if ($row->active_only)
                    <div><span class="pill green">active only</span></div>
                  @endif
                </td>
                <td><span class="pill">{{ $row->model ?? '—' }}</span></td>
                <td style="font-size:11px;">
                  <div>req: <strong>{{ $row->variants_requested ?? 1 }}</strong></div>
                  @php $variants = json_decode($row->output_variants ?? '[]', true) ?: []; @endphp
                  <div style="color:#64748b;">got: {{ count($variants) }}</div>
                </td>
                <td style="text-align:right;">
                  <button @click="toggleDetail({{ $row->id }})"
                          class="h-btn-ghost"
                          style="border:1px solid #c7d2fe;color:#4f46e5;">
                    👁 View
                  </button>
                </td>
              </tr>
              <tr class="h-detail-row" x-show="open === {{ $row->id }}" x-cloak>
                <td colspan="7">
                  <div class="h-detail" x-html="detailHtml[{{ $row->id }}] || '<div style=\'color:#94a3b8;\'>Loading…</div>'"></div>
                </td>
              </tr>
            @empty
              <tr><td colspan="7" style="text-align:center;padding:36px;color:#94a3b8;">No generations recorded yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      @if ($rows->hasPages())
        <div class="p-3 border-t border-slate-100">
          {{ $rows->links() }}
        </div>
      @endif
    </div>
  </div>

  <script>
    function escapeHtml(s) {
      return String(s ?? "").replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;").replaceAll('"',"&quot;");
    }

    function historyApp() {
      return {
        open: null,
        detailHtml: {},
        async toggleDetail(id) {
          if (this.open === id) { this.open = null; return; }
          this.open = id;
          if (this.detailHtml[id]) return; // cached
          try {
            const res = await fetch(`/gpt-ad-generator/history/${id}`);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const d = await res.json();

            const variants = Array.isArray(d.output_variants) ? d.output_variants : [];
            const variantHtml = variants.map((v, idx) => {
              const parts = (v || "").split("\t");
              const labels = ['Item','Primary Text','Headline','Messaging Template','QR1','QR2','QR3'];
              const fields = labels.map((label, i) => `<strong>${label}</strong><span>${escapeHtml(parts[i] ?? '—')}</span>`).join('');
              return `<div class="h-variant"><div style="font-size:10.5px;font-weight:700;color:#4338ca;margin-bottom:6px;">VARIANT ${idx+1}</div><div class="h-variant-fields">${fields}</div></div>`;
            }).join('');

            const promptHtml = d.final_prompt ? `<h4>Final prompt sent to GPT</h4><pre>${escapeHtml(d.final_prompt)}</pre>` : '';
            const meta = `
              <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:11px;color:#64748b;margin-bottom:8px;">
                <div>🌡 Temperature: <strong style="color:#0f172a;">${d.temperature ?? '—'}</strong></div>
                <div>🔢 Variants requested: <strong style="color:#0f172a;">${d.variants_requested ?? '—'}</strong></div>
                <div>🤖 Model: <strong style="color:#0f172a;">${d.model ?? '—'}</strong></div>
                <div>👤 User: <strong style="color:#0f172a;">${escapeHtml(d.user_email ?? '— anonymous —')}</strong></div>
              </div>`;

            this.detailHtml[id] = meta + (variantHtml ? `<h4>Generated variants (${variants.length})</h4>${variantHtml}` : '<div style="color:#94a3b8;">No variants saved.</div>') + promptHtml;
          } catch (e) {
            this.detailHtml[id] = `<div style="color:#dc2626;">Failed to load: ${escapeHtml(e.message)}</div>`;
          }
        }
      };
    }
  </script>
</x-layout>
