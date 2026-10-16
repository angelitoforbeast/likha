<x-layout>
  <x-slot name="title">Item Logs</x-slot>
  <x-slot name="heading">/item — Edit Logs (quotes, category, lead time, photos)</x-slot>

  <style>
    .ct-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .ct-input, .ct-select { padding:7px 10px; font-size:12.5px; color:#0f172a; background:#fff; border:1px solid #cbd5e1; border-radius:6px; width:100%; }
    .ct-btn { display:inline-flex; align-items:center; gap:5px; background:#4f46e5; color:#fff; font-weight:600; font-size:12px; padding:7px 12px; border-radius:6px; border:none; cursor:pointer; }
    .ct-btn-ghost { display:inline-flex; align-items:center; gap:4px; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; text-decoration:none; }
    .l-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .l-table thead th { background:#f8fafc; color:#475569; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0; }
    .l-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; word-break:break-word; }
    .l-old { color:#b91c1c; }
    .l-new { color:#15803d; font-weight:600; }
    .l-muted { color:#94a3b8; }
  </style>

  <div class="ct-card mb-4">
    <form method="GET" action="{{ route('item.edit-logs') }}" class="grid grid-cols-2 md:grid-cols-6 gap-3 p-3">
      <label class="text-xs text-gray-600">Item
        <input type="text" name="item" value="{{ $item }}" placeholder="e.g. HAND GRIP" class="ct-input" />
      </label>
      <label class="text-xs text-gray-600">User
        <select name="user" class="ct-select">
          <option value="">All</option>
          @foreach($users as $u)
            <option value="{{ $u->id }}" @selected($user === (int) $u->id)>{{ $u->name }}</option>
          @endforeach
        </select>
      </label>
      <label class="text-xs text-gray-600">Action
        <select name="action" class="ct-select">
          <option value="">All</option>
          @foreach($actions as $a)
            <option value="{{ $a }}" @selected($action === $a)>{{ $a }}</option>
          @endforeach
        </select>
      </label>
      <label class="text-xs text-gray-600">From
        <input type="date" name="from_date" value="{{ $from }}" class="ct-input" />
      </label>
      <label class="text-xs text-gray-600">To
        <input type="date" name="to_date" value="{{ $to }}" class="ct-input" />
      </label>
      <div class="flex items-end gap-2">
        <button type="submit" class="ct-btn">Filter</button>
        <a href="{{ route('item.edit-logs') }}" class="ct-btn-ghost">Clear</a>
      </div>
    </form>
    <div class="px-3 pb-3 text-xs text-gray-500">
      RTS % at item value edits:
      <a href="{{ route('owner.private.edit-logs') }}" class="text-indigo-600 underline">📜 Logs (RTS / COGS)</a>
    </div>
  </div>

  <div class="ct-card overflow-x-auto">
    <table class="l-table">
      <thead>
        <tr><th>Time</th><th>User</th><th>Action</th><th>Item</th><th>Field</th><th>Old → New</th></tr>
      </thead>
      <tbody>
        @forelse($rows as $r)
          <tr>
            <td class="whitespace-nowrap">{{ \Illuminate\Support\Str::of((string) $r->created_at)->substr(0, 16) }}</td>
            <td>{{ $r->user_name ?? '—' }} @if($r->role)<div class="l-muted">{{ $r->role }}</div>@endif</td>
            <td>{{ $r->action }}</td>
            <td>{{ $r->item_name ?? $r->item_key }}@if($r->page_name)<div class="l-muted">{{ $r->page_name }}</div>@endif</td>
            <td>{{ $r->field }}</td>
            <td>
              <span class="{{ $r->old_value === null ? 'l-muted' : 'l-old' }}">{{ $r->old_value ?? '—' }}</span>
              →
              <span class="{{ $r->new_value === null ? 'l-muted' : 'l-new' }}">{{ $r->new_value ?? '—' }}</span>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="l-muted" style="text-align:center;padding:32px;">No edits yet.</td></tr>
        @endforelse
      </tbody>
    </table>
    <div class="p-3">{{ $rows->links() }}</div>
  </div>
</x-layout>
