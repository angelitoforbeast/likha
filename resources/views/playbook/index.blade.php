@php
  $sevColor = ['low'=>'bg-slate-100 text-slate-600','medium'=>'bg-amber-100 text-amber-700','high'=>'bg-orange-100 text-orange-700','critical'=>'bg-red-100 text-red-700'];
  $stColor  = ['open'=>'bg-blue-100 text-blue-700','resolved'=>'bg-emerald-100 text-emerald-700','recurring'=>'bg-red-100 text-red-700'];
@endphp
<x-layout>
  <x-slot name="title">Playbook — Problem &amp; Solution</x-slot>
  <x-slot name="heading">Playbook — Problem &amp; Solution</x-slot>

  <div class="max-w-6xl mx-auto p-4">
    @if (session('success'))
      <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-green-800">{{ session('success') }}</div>
    @endif

    {{-- Search + filters --}}
    <form method="GET" action="{{ route('playbook.index') }}" class="mb-4 flex flex-wrap items-end gap-2">
      <div class="flex-1 min-w-[200px]">
        <label class="block text-[11px] font-semibold text-slate-500 mb-1">Search</label>
        <input name="q" value="{{ $f['q'] }}" placeholder="Hanapin sa title / problema / solution…"
               class="w-full border border-slate-300 rounded px-3 py-2 text-sm">
      </div>
      <div><label class="block text-[11px] font-semibold text-slate-500 mb-1">Category</label>
        <select name="category" class="border border-slate-300 rounded px-2 py-2 text-sm bg-white">
          <option value="">All</option>
          @foreach ($categories as $c)<option value="{{ $c }}" @selected($f['category']===$c)>{{ $c }}</option>@endforeach
        </select></div>
      <div><label class="block text-[11px] font-semibold text-slate-500 mb-1">Status</label>
        <select name="status" class="border border-slate-300 rounded px-2 py-2 text-sm bg-white">
          <option value="">All</option>
          @foreach ($statuses as $s)<option value="{{ $s }}" @selected($f['status']===$s)>{{ ucfirst($s) }}</option>@endforeach
        </select></div>
      <div><label class="block text-[11px] font-semibold text-slate-500 mb-1">Severity</label>
        <select name="severity" class="border border-slate-300 rounded px-2 py-2 text-sm bg-white">
          <option value="">All</option>
          @foreach ($severities as $s)<option value="{{ $s }}" @selected($f['severity']===$s)>{{ ucfirst($s) }}</option>@endforeach
        </select></div>
      <button class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filter</button>
      <a href="{{ route('playbook.index') }}" class="rounded-lg px-3 py-2 text-sm text-slate-500 hover:bg-slate-100">Reset</a>
      <a href="{{ route('playbook.create') }}" class="ml-auto rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">+ New Problem</a>
    </form>

    {{-- List --}}
    <div class="space-y-2">
      @forelse ($problems as $p)
        <a href="{{ route('playbook.show', $p->id) }}" class="block rounded-xl border border-slate-200 p-3 hover:border-indigo-300 hover:bg-slate-50">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="font-semibold text-slate-800 truncate">{{ $p->title }}</div>
              @if ($p->description)<div class="text-xs text-slate-500 mt-0.5 line-clamp-2">{{ \Illuminate\Support\Str::limit($p->description, 140) }}</div>@endif
            </div>
            <div class="flex flex-wrap items-center gap-1.5 shrink-0">
              @if ($p->category)<span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ $p->category }}</span>@endif
              <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full {{ $sevColor[$p->severity] ?? 'bg-slate-100 text-slate-600' }}">{{ ucfirst($p->severity) }}</span>
              <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full {{ $stColor[$p->status] ?? 'bg-slate-100 text-slate-600' }}">{{ ucfirst($p->status) }}</span>
            </div>
          </div>
          <div class="flex items-center gap-3 mt-2 text-[11px] text-slate-400">
            <span>🛠 {{ $p->checklist_count }} steps</span>
            @if ($p->attachments->count())<span>📎 {{ $p->attachments->count() }}</span>@endif
            @if ($p->times_seen > 1)<span class="text-red-500 font-semibold">⚠ nangyari {{ $p->times_seen }}x</span>@endif
            <span class="ml-auto">{{ \Illuminate\Support\Carbon::parse($p->updated_at)->format('M j, Y') }}</span>
          </div>
        </a>
      @empty
        <div class="rounded-xl border border-dashed border-slate-200 p-10 text-center text-slate-400">
          Wala pang naka-record na problema. <a href="{{ route('playbook.create') }}" class="text-indigo-600 hover:underline">Mag-add ng una →</a>
        </div>
      @endforelse
    </div>

    <div class="mt-4">{{ $problems->links() }}</div>
  </div>
</x-layout>
