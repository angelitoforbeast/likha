<x-layout>
  <x-slot name="title">Prompt #{{ $row->id }}</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">🤖 Prompt #{{ $row->id }}</div></x-slot>

  <style>
    .pg-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .pg-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:6px 12px; border-radius:6px; border:1px solid #e2e8f0; }
    .pg-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
    .pg-textarea { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px; font-size:12px; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2">
    <div class="pg-card p-4">
      <div class="flex items-center justify-between mb-3">
        <div class="text-sm text-slate-500">
          {{ optional($row->created_at)->format('Y-m-d H:i:s') }} ·
          {{ $row->user_name ?: '—' }} ·
          <strong>{{ $row->mode === 'ai' ? ('AI ('.$row->model.')') : 'Template' }}</strong> ·
          {{ $row->store_name }} — {{ $row->product_name }}
        </div>
        <div class="flex gap-2">
          <button id="btnCopy" type="button" class="pg-btn-ghost">📄 Copy</button>
          <a href="{{ route('prompt.generator.history') }}" class="pg-btn-ghost">← History</a>
        </div>
      </div>
      <textarea id="output" class="pg-textarea font-mono leading-relaxed" style="min-height:70vh;" readonly>{{ $row->output }}</textarea>
    </div>
  </div>

  <script>
    document.getElementById('btnCopy').addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(document.getElementById('output').value); alert('Na-copy!'); }
      catch (e) { alert('Copy failed: ' + e.message); }
    });
  </script>
</x-layout>
