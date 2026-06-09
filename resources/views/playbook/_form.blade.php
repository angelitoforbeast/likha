@php $p = $p ?? null; $attachments = $attachments ?? []; @endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
  <div class="md:col-span-2">
    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Title / Problema *</label>
    <input name="title" required value="{{ old('title', $p->title ?? '') }}"
           placeholder="hal. Mataas ang RTS sa Page X"
           class="w-full border border-slate-300 rounded px-3 py-2 text-sm">
  </div>
  <div>
    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Category</label>
    <select name="category" class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white">
      <option value="">—</option>
      @foreach ($categories as $c)
        <option value="{{ $c }}" @selected(old('category', $p->category ?? '') === $c)>{{ $c }}</option>
      @endforeach
    </select>
  </div>
  <div class="grid grid-cols-2 gap-3">
    <div>
      <label class="block text-[11px] font-semibold text-slate-600 mb-1">Severity</label>
      <select name="severity" class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white">
        @foreach ($severities as $s)
          <option value="{{ $s }}" @selected(old('severity', $p->severity ?? 'medium') === $s)>{{ ucfirst($s) }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label class="block text-[11px] font-semibold text-slate-600 mb-1">Status</label>
      <select name="status" class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white">
        @foreach ($statuses as $st)
          <option value="{{ $st }}" @selected(old('status', $p->status ?? 'open') === $st)>{{ ucfirst($st) }}</option>
        @endforeach
      </select>
    </div>
  </div>
</div>

<div class="mb-3">
  <label class="block text-[11px] font-semibold text-slate-600 mb-1">Description (ang problema)</label>
  <textarea name="description" rows="3" class="w-full border border-slate-300 rounded px-3 py-2 text-sm">{{ old('description', $p->description ?? '') }}</textarea>
</div>
<div class="mb-3">
  <label class="block text-[11px] font-semibold text-slate-600 mb-1">Root cause (bakit nangyari)</label>
  <textarea name="root_cause" rows="2" class="w-full border border-slate-300 rounded px-3 py-2 text-sm">{{ old('root_cause', $p->root_cause ?? '') }}</textarea>
</div>
<div class="mb-3">
  <label class="block text-[11px] font-semibold text-emerald-700 mb-1">✅ Solution / Fix</label>
  <textarea name="solution" rows="3" class="w-full border border-emerald-300 rounded px-3 py-2 text-sm bg-emerald-50/40">{{ old('solution', $p->solution ?? '') }}</textarea>
</div>
<div class="mb-3">
  <label class="block text-[11px] font-semibold text-slate-600 mb-1">Prevention (paano maiwasan sa susunod)</label>
  <textarea name="prevention" rows="2" class="w-full border border-slate-300 rounded px-3 py-2 text-sm">{{ old('prevention', $p->prevention ?? '') }}</textarea>
</div>

{{-- Fix checklist (dynamic rows) --}}
<div class="mb-3 rounded-lg border border-indigo-200 bg-indigo-50 p-3">
  <div class="text-[11px] font-semibold text-indigo-900 mb-2">🛠 Fix Checklist — mga steps na need gawin/i-check</div>
  <template x-for="(it, idx) in items" :key="idx">
    <div class="flex gap-2 mb-1 items-center">
      <input type="hidden" :name="`checklist_items[${idx}][id]`" :value="it.id ?? ''">
      <span class="text-slate-400 text-xs" x-text="(idx+1)+'.'"></span>
      <input :name="`checklist_items[${idx}][label]`" x-model="it.label" placeholder="hal. I-check ang creative / audience"
             class="flex-1 border border-slate-300 rounded px-2 py-1.5 text-sm bg-white">
      <button type="button" @click="removeItem(idx)" class="text-red-500 hover:text-red-700 text-lg leading-none">×</button>
    </div>
  </template>
  <button type="button" @click="addItem()" class="text-xs font-semibold text-indigo-600 hover:underline mt-1">+ add step</button>
</div>

{{-- Attachments (screenshots) — click / drag / Ctrl+V --}}
<div class="mb-3">
  <label class="block text-[11px] font-semibold text-slate-600 mb-1">📎 Screenshots (click / drag / Ctrl+V — multiple)</label>
  @if (!empty($attachments))
    <div class="flex flex-wrap gap-2 mb-2">
      @foreach ($attachments as $a)
        <div class="relative" :class="isRemoved({{ $a['id'] }}) ? 'opacity-30' : ''">
          <img src="{{ $a['url'] }}" class="h-16 w-16 object-cover rounded border border-slate-200">
          <button type="button" @click="toggleRemove({{ $a['id'] }})"
                  class="absolute -top-1.5 -right-1.5 bg-red-500 text-white rounded-full w-4 h-4 text-[10px] leading-none flex items-center justify-center"
                  x-text="isRemoved({{ $a['id'] }}) ? '↺' : '×'"></button>
        </div>
      @endforeach
    </div>
    <template x-for="id in removed" :key="id"><input type="hidden" name="remove_attachment_ids[]" :value="id"></template>
  @endif
  <div @dragover.prevent @drop.prevent="add($event.dataTransfer.files)" @click="$refs.fileInput.click()"
       class="border-2 border-dashed border-slate-300 rounded-lg p-3 text-center text-[11px] text-slate-500 bg-white cursor-pointer hover:bg-slate-50">
    📎 I-click, i-drag, o <b>Ctrl+V</b> para mag-paste ng screenshot
  </div>
  <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" x-ref="fileInput" class="hidden" @change="add($event.target.files)">
  <div class="flex flex-wrap gap-2 mt-2" x-show="files.length" x-cloak>
    <template x-for="(f,i) in files" :key="i">
      <div class="relative">
        <template x-if="isImg(f)"><img :src="thumb(f)" class="h-16 w-16 object-cover rounded border border-slate-200"></template>
        <template x-if="!isImg(f)"><div class="h-16 w-16 rounded border border-slate-200 flex items-center justify-center text-[9px] text-slate-500 bg-slate-50">PDF</div></template>
        <button type="button" @click.stop="removeFile(i)" class="absolute -top-1.5 -right-1.5 bg-red-500 text-white rounded-full w-4 h-4 text-[10px] leading-none flex items-center justify-center">×</button>
      </div>
    </template>
  </div>
</div>
