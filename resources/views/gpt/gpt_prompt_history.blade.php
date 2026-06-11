<x-layout>
  <x-slot name="title">Prompt History</x-slot>
  <x-slot name="heading">Custom Prompt — Version History</x-slot>

  <style>
    .ph-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .ph-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; }
    .ph-card-title { font-size:13px; font-weight:600; color:#0f172a; }
    .ph-btn { display:inline-flex; align-items:center; gap:5px; background:#4f46e5; color:#fff; font-weight:600; font-size:12px; padding:7px 12px; border-radius:6px; }
    .ph-btn:hover { background:#4338ca; }
    .ph-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; }
    .ph-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
    .ph-btn-restore { background:#fff; color:#16a34a; border:1px solid #86efac; padding:5px 10px; font-size:11.5px; font-weight:600; border-radius:6px; }
    .ph-btn-restore:hover { background:#f0fdf4; }
    .ph-btn-restore:disabled { opacity:0.5; cursor:not-allowed; }

    .ph-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .ph-table thead th {
      position:sticky; top:0; z-index:1; background:#f8fafc; color:#475569;
      font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;
      padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0;
    }
    .ph-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; word-wrap:break-word; }
    .ph-table tbody tr:hover td { background:#f8fafc; }

    .pill-active { background:#dcfce7; color:#166534; padding:2px 9px; border-radius:999px; font-size:10.5px; font-weight:600; }
    .pill-version { background:#eef2ff; color:#4338ca; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; font-family:ui-monospace, monospace; }

    .ph-detail-row td { background:#f8fafc !important; padding:0 !important; }
    .ph-detail { padding:14px 18px; border-top:1px dashed #cbd5e1; }
    .ph-detail h4 { font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:6px; }
    .ph-detail pre {
      background:#fff; border:1px solid #e2e8f0; border-radius:6px;
      padding:12px 14px; font-size:11.5px; line-height:1.55;
      white-space:pre-wrap; max-height:380px; overflow:auto; color:#0f172a;
      font-family:ui-monospace, SFMono-Regular, Menlo, monospace;
    }
  </style>

  <div class="w-full flex flex-col gap-4" x-data="promptHistoryApp()">
    <div class="ph-card">
      <div class="ph-card-header">
        <div class="ph-card-title">📜 Prompt versions ({{ $rows->total() }} total)</div>
        <div class="flex gap-2">
          <a href="/gpt-ad-generator" class="ph-btn-ghost">← Back to Generator</a>
          <a href="{{ route('gpt.history') }}" class="ph-btn-ghost">📚 Generations</a>
        </div>
      </div>

      <div class="overflow-auto" style="max-height:calc(100vh - 220px);">
        <table class="ph-table">
          <thead>
            <tr>
              <th style="width:90px;">Version</th>
              <th style="width:160px;">When</th>
              <th style="width:200px;">Saved by</th>
              <th>Note</th>
              <th>Preview</th>
              <th style="width:170px;text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($rows as $i => $row)
              <tr data-id="{{ $row->id }}">
                <td>
                  <span class="pill-version">#{{ $row->id }}</span>
                  @if ($i === 0 && $rows->currentPage() === 1)
                    <div style="margin-top:4px;"><span class="pill-active">✓ active</span></div>
                  @endif
                </td>
                <td>
                  <div style="font-weight:600;">{{ \Carbon\Carbon::parse($row->created_at)->format('M j, Y') }}</div>
                  <div style="font-size:10.5px;color:#94a3b8;">{{ \Carbon\Carbon::parse($row->created_at)->format('g:i A') }}</div>
                </td>
                <td style="font-size:11.5px;">
                  @if ($row->saved_by_name || $row->saved_by_email)
                    <div style="font-weight:600;color:#0f172a;">{{ $row->saved_by_name ?: $row->saved_by_email }}</div>
                    @if ($row->saved_by_name && $row->saved_by_email)
                      <div style="font-size:10px;color:#94a3b8;">{{ $row->saved_by_email }}</div>
                    @endif
                  @else
                    <span style="color:#cbd5e1;">— anonymous —</span>
                  @endif
                </td>
                <td style="font-size:11.5px;color:#475569;">
                  @if ($row->note)
                    {{ $row->note }}
                  @else
                    <span style="color:#cbd5e1;">— no note —</span>
                  @endif
                </td>
                <td style="font-size:11px;color:#64748b;line-height:1.4;">
                  {{ \Illuminate\Support\Str::limit($row->prompt_text, 140) }}
                </td>
                <td style="text-align:right;">
                  <button @click="toggleDetail({{ $row->id }})" class="ph-btn-ghost" style="border:1px solid #c7d2fe;color:#4f46e5;">
                    👁 View
                  </button>
                  @if (!($i === 0 && $rows->currentPage() === 1))
                    <button @click="restoreVersion({{ $row->id }})" class="ph-btn-restore" style="margin-left:4px;">
                      ↺ Restore
                    </button>
                  @endif
                </td>
              </tr>
              <tr class="ph-detail-row" x-show="open === {{ $row->id }}" x-cloak>
                <td colspan="6">
                  <div class="ph-detail">
                    <h4>Full prompt content</h4>
                    <pre x-text="detailText[{{ $row->id }}] || 'Loading…'"></pre>
                  </div>
                </td>
              </tr>
            @empty
              <tr><td colspan="6" style="text-align:center;padding:36px;color:#94a3b8;">No prompt versions saved yet. Save your first one from <a href="/gpt-ad-generator" class="text-indigo-600 underline">the generator</a>.</td></tr>
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
    function promptHistoryApp() {
      return {
        open: null,
        detailText: {},
        async toggleDetail(id) {
          if (this.open === id) { this.open = null; return; }
          this.open = id;
          if (this.detailText[id]) return;
          try {
            const res = await fetch(`/gpt-ad-generator/prompt-history/${id}`);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const d = await res.json();
            this.detailText[id] = d.prompt_text || '';
          } catch (e) {
            this.detailText[id] = 'Failed to load: ' + e.message;
          }
        },
        async restoreVersion(id) {
          if (!confirm(`Restore version #${id} as the active prompt? A new version row will be created.`)) return;
          try {
            const res = await fetch(`/gpt-ad-generator/prompt-history/${id}/restore`, {
              method: 'POST',
              headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
            });
            if (res.status === 403) { alert('❌ Login required.'); return; }
            if (res.status === 419) { alert('⚠️ Session/CSRF expired — i-refresh ang page (Ctrl+Shift+R), tapos subukan ulit.'); return; }
            if (res.status === 429) { alert('⚠️ Sobrang bilis — sandali lang tapos subukan ulit (rate limit).'); return; }
            // Parse defensively — kung HTML (error page) ang nakuha, malinaw na mensahe.
            let d = null;
            try { d = await res.json(); } catch (_) {
              alert('❌ Restore failed (HTTP ' + res.status + '). Kung paulit-ulit, i-refresh ang page o i-clear ang route cache sa server (php artisan route:clear).');
              return;
            }
            if (res.ok && d.ok) {
              alert(`✅ Version #${id} restored as new version #${d.id}. Reloading…`);
              window.location.reload();
            } else {
              alert('❌ Restore failed: ' + (d.error || d.message || ('HTTP ' + res.status)));
            }
          } catch (e) {
            alert('❌ ' + e.message);
          }
        },
      };
    }
  </script>
</x-layout>
