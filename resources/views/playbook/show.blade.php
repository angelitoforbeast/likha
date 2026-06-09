@php
  $sevColor = ['low'=>'bg-slate-100 text-slate-600','medium'=>'bg-amber-100 text-amber-700','high'=>'bg-orange-100 text-orange-700','critical'=>'bg-red-100 text-red-700'];
  $stColor  = ['open'=>'bg-blue-100 text-blue-700','resolved'=>'bg-emerald-100 text-emerald-700','recurring'=>'bg-red-100 text-red-700'];
  $done = $p->checklist->where('is_done', true)->count();
  $total = $p->checklist->count();
@endphp
<x-layout>
  <x-slot name="title">{{ $p->title }} · Playbook</x-slot>
  <x-slot name="heading">{{ $p->title }}</x-slot>

  <div class="max-w-3xl mx-auto p-4">
    <div class="mb-3 flex items-center justify-between">
      <a href="{{ route('playbook.index') }}" class="text-sm text-indigo-600 hover:underline">← Back to Playbook</a>
      @if ($canWrite)
        <div class="flex items-center gap-3">
          <a href="{{ route('playbook.edit', $p->id) }}" class="text-sm text-indigo-600 hover:underline">edit</a>
          @if ($canDelete)
            <form method="POST" action="{{ route('playbook.destroy', $p->id) }}" onsubmit="return confirm('Burahin ang problema na ito?')">
              @csrf @method('DELETE')
              <button class="text-sm text-red-500 hover:underline">delete</button>
            </form>
          @endif
        </div>
      @endif
    </div>

    @if (session('success'))
      <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800">{{ session('success') }}</div>
    @endif

    {{-- badges --}}
    <div class="flex flex-wrap items-center gap-1.5 mb-4">
      @if ($p->category)<span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ $p->category }}</span>@endif
      <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full {{ $sevColor[$p->severity] ?? '' }}">{{ ucfirst($p->severity) }}</span>
      <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full {{ $stColor[$p->status] ?? '' }}">{{ ucfirst($p->status) }}</span>
      @if ($p->times_seen > 1)<span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-red-100 text-red-700">⚠ nangyari {{ $p->times_seen }}x</span>@endif
    </div>

    @php
      $sections = [
        ['Description (problema)', $p->description, 'text-slate-700'],
        ['Root cause', $p->root_cause, 'text-slate-700'],
        ['✅ Solution / Fix', $p->solution, 'text-emerald-800'],
        ['Prevention', $p->prevention, 'text-slate-700'],
      ];
    @endphp
    @foreach ($sections as [$label, $val, $cls])
      @if ($val)
        <div class="mb-3">
          <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-400 mb-1">{{ $label }}</div>
          <div class="text-sm {{ $cls }} whitespace-pre-line rounded-lg border border-slate-100 bg-slate-50/60 p-3">{{ $val }}</div>
        </div>
      @endif
    @endforeach

    {{-- Fix checklist --}}
    @if ($total)
      <div class="mb-4 rounded-xl border border-indigo-200 p-3">
        <div class="flex items-center justify-between mb-2">
          <div class="text-sm font-semibold text-indigo-900">🛠 Fix Checklist</div>
          <div class="text-[11px] text-slate-500">{{ $done }}/{{ $total }} done</div>
        </div>
        <div class="h-1.5 bg-slate-100 rounded-full mb-3 overflow-hidden">
          <div class="h-full bg-emerald-500" style="width: {{ $total ? round($done/$total*100) : 0 }}%"></div>
        </div>
        <form method="POST" action="{{ route('playbook.checklist', $p->id) }}">
          @csrf
          <div class="space-y-1.5">
            @foreach ($p->checklist as $it)
              <label class="flex items-start gap-2 text-sm cursor-pointer">
                <input type="checkbox" name="done_ids[]" value="{{ $it->id }}" @checked($it->is_done) class="mt-0.5">
                <span class="{{ $it->is_done ? 'line-through text-slate-400' : 'text-slate-700' }}">{{ $it->label }}</span>
              </label>
            @endforeach
          </div>
          @if ($canWrite)
            <div class="flex justify-end mt-2">
              <button class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Save progress</button>
            </div>
          @endif
        </form>
      </div>
    @endif

    {{-- Attachments --}}
    @if (count($attachments))
      <div class="mb-4">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-400 mb-1">📎 Screenshots</div>
        <div class="flex flex-wrap gap-2">
          @foreach ($attachments as $a)
            <a href="{{ $a['url'] }}" target="_blank" title="View">
              <img src="{{ $a['url'] }}" class="h-20 w-20 object-cover rounded border border-slate-200 hover:ring-2 hover:ring-indigo-300"
                   onerror="this.replaceWith(Object.assign(document.createElement('span'),{textContent:'📎 PDF',className:'inline-flex h-20 w-20 items-center justify-center text-[11px] text-indigo-600 rounded border border-slate-200'}))">
            </a>
          @endforeach
        </div>
      </div>
    @endif

    {{-- Recurrence --}}
    <div class="mb-4 rounded-xl border border-slate-200 p-3">
      <div class="text-sm font-semibold text-slate-800 mb-2">🔁 Recurrence log <span class="text-[11px] font-normal text-slate-400">(nangyari ulit?)</span></div>
      @if ($p->recurrences->count())
        <ul class="divide-y divide-slate-100 text-sm mb-3">
          @foreach ($p->recurrences as $r)
            <li class="py-1.5">
              <span class="font-medium text-slate-700">{{ \Illuminate\Support\Carbon::parse($r->occurred_at)->format('M j, Y') }}</span>
              @if ($r->note)<span class="text-slate-500"> — {{ $r->note }}</span>@endif
            </li>
          @endforeach
        </ul>
      @else
        <div class="text-[11px] text-slate-400 mb-3">Wala pang naka-log na recurrence.</div>
      @endif
      @if ($canWrite)
        <form method="POST" action="{{ route('playbook.recurrence', $p->id) }}" class="flex flex-wrap items-end gap-2">
          @csrf
          <div><label class="block text-[10px] font-semibold text-slate-500 mb-1">Nangyari ulit (date)</label>
            <input type="date" name="occurred_at" required value="{{ \Illuminate\Support\Carbon::now('Asia/Manila')->toDateString() }}" class="border border-slate-300 rounded px-2 py-1.5 text-sm"></div>
          <div class="flex-1 min-w-[160px]"><label class="block text-[10px] font-semibold text-slate-500 mb-1">Note (optional)</label>
            <input name="note" class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm"></div>
          <button class="rounded-md bg-slate-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">+ Log recurrence</button>
        </form>
      @endif
    </div>

    <div class="text-[11px] text-slate-400">
      Naidagdag {{ \Illuminate\Support\Carbon::parse($p->created_at)->format('M j, Y') }}
      @if ($p->resolved_at) · Na-resolve {{ \Illuminate\Support\Carbon::parse($p->resolved_at)->format('M j, Y') }}@endif
    </div>
  </div>
</x-layout>
