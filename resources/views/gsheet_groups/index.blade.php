<x-layout>
    <x-slot name="heading">GSheet Groups</x-slot>

    <div class="w-full max-w-5xl mx-auto mt-6 px-4">

        @if(session('success'))
            <div class="mb-4 rounded border border-green-200 bg-green-50 text-green-800 px-4 py-2 text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-800 px-4 py-2 text-sm">
                {{ session('error') }}
            </div>
        @endif
        @if($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-800 px-4 py-2 text-sm">
                <ul class="list-disc ml-5">
                    @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="flex items-center justify-between mb-4 gap-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-800">GSheet groups</h2>
                <p class="text-sm text-gray-500">Likha &rarr; Macro &rarr; After-macro &middot; live values mula sa bawat sheet</p>
            </div>
            <button type="button" onclick="refreshAllGroups()"
                    class="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded border border-gray-300 hover:bg-gray-50">
                🔄 Refresh all
            </button>
        </div>

        {{-- ───────── Groups ───────── --}}
        <div id="groups" class="space-y-4">
            @forelse($groups as $g)
                <div class="bg-white border rounded-xl p-4 shadow-sm" data-group="{{ $g->id }}"
                     x-data="{ edit: false, delL: false, delA: false }">

                    <div class="flex items-center justify-between mb-3 gap-2">
                        <div class="font-medium text-gray-800">{{ $g->name }}</div>
                        <div class="flex gap-2 flex-wrap justify-end">
                            <form method="POST" action="/gsheet_groups/{{ $g->id }}/stop"
                                  onsubmit="return confirm('Magsusulat ng STOP sa LINKS!G1 (macro) + API KEY!C1 (after-macro). Tuloy?')">
                                @csrf
                                <button type="submit"
                                        class="px-2.5 py-1 text-xs rounded border border-red-300 text-red-700 hover:bg-red-50">🛑 Stop</button>
                            </form>
                            <form method="POST" action="/gsheet_groups/{{ $g->id }}/resume">
                                @csrf
                                <button type="submit"
                                        class="px-2.5 py-1 text-xs rounded border border-emerald-300 text-emerald-700 hover:bg-emerald-50">▶️ Resume</button>
                            </form>
                            <form method="POST" action="/gsheet_groups/{{ $g->id }}/clear-logs"
                                  onsubmit="return confirm('Bubura ang rows (row 2 pababa) sa GPT_VERIFY, GPT_DEBUG, GPT_NAMEADDR ng after-macro (3rd) sheet. Tuloy?')">
                                @csrf
                                <button type="submit"
                                        class="px-2.5 py-1 text-xs rounded border border-orange-300 text-orange-700 hover:bg-orange-50">🧹 Clear logs</button>
                            </form>
                            <button type="button" onclick="loadGroupValues(this.closest('[data-group]'))"
                                    class="px-2.5 py-1 text-xs rounded border border-gray-300 hover:bg-gray-50">🔄 Refresh</button>
                            <button type="button" @click="edit = !edit"
                                    class="px-2.5 py-1 text-xs rounded border border-gray-300 hover:bg-gray-50">✏️ Edit</button>
                            <form method="POST" action="/gsheet_groups/{{ $g->id }}"
                                  onsubmit="return confirm('Delete this group?')">
                                @csrf @method('DELETE')
                                <button type="submit"
                                        class="px-2.5 py-1 text-xs rounded border border-red-300 text-red-700 hover:bg-red-50">🗑️ Delete</button>
                            </form>
                        </div>
                    </div>

                    {{-- View mode: links --}}
                    <div x-show="!edit" class="space-y-2">
                        @php
                            $rows = [
                                ['likha', 'Likha', $g->likha_url, 'bg-blue-50 text-blue-800'],
                                ['macro', 'Macro', $g->macro_url, 'bg-amber-50 text-amber-800'],
                                ['after', 'After-macro', $g->after_url, 'bg-emerald-50 text-emerald-800'],
                            ];
                        @endphp
                        @foreach($rows as [$key, $label, $url, $cls])
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="w-24 shrink-0 text-xs font-medium text-center px-2 py-1 rounded {{ $cls }}">{{ $label }}</span>
                                    <input type="text" value="{{ $url }}" readonly
                                           class="flex-1 min-w-0 text-xs font-mono bg-gray-50 border border-gray-200 rounded px-2 py-1.5 text-gray-600">
                                    @if($url)
                                        <a href="{{ $url }}" target="_blank" rel="noopener"
                                           class="px-2 py-1.5 text-xs rounded border border-gray-300 hover:bg-gray-50">↗</a>
                                    @endif
                                </div>
                                <div class="text-[11px] text-gray-500 mt-1 ml-[6.5rem]" data-title="{{ $key }}">…</div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Edit mode: form --}}
                    <div x-show="edit" x-cloak>
                        <form method="POST" action="/gsheet_groups/{{ $g->id }}" class="space-y-2">
                            @csrf @method('PUT')
                            <div class="flex items-center gap-2">
                                <span class="w-24 shrink-0 text-xs text-gray-500">Name</span>
                                <input type="text" name="name" value="{{ $g->name }}" required
                                       class="flex-1 min-w-0 text-sm border border-gray-300 rounded px-2 py-1.5">
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="w-24 shrink-0 text-xs font-medium text-center px-2 py-1 rounded bg-blue-50 text-blue-800">Likha</span>
                                <input type="text" name="likha_url" value="{{ $g->likha_url }}"
                                       class="flex-1 min-w-0 text-xs font-mono border border-gray-300 rounded px-2 py-1.5">
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="w-24 shrink-0 text-xs font-medium text-center px-2 py-1 rounded bg-amber-50 text-amber-800">Macro</span>
                                <input type="text" name="macro_url" value="{{ $g->macro_url }}"
                                       class="flex-1 min-w-0 text-xs font-mono border border-gray-300 rounded px-2 py-1.5">
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="w-24 shrink-0 text-xs font-medium text-center px-2 py-1 rounded bg-emerald-50 text-emerald-800">After-macro</span>
                                <input type="text" name="after_url" value="{{ $g->after_url }}"
                                       class="flex-1 min-w-0 text-xs font-mono border border-gray-300 rounded px-2 py-1.5">
                            </div>
                            <div class="flex gap-2 pt-1">
                                <button type="submit" class="px-3 py-1.5 text-sm rounded bg-gray-800 text-white hover:bg-gray-700">💾 Save</button>
                                <button type="button" @click="edit = false" class="px-3 py-1.5 text-sm rounded border border-gray-300 hover:bg-gray-50">Cancel</button>
                            </div>
                        </form>
                    </div>

                    {{-- Value boxes --}}
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4">
                        <div class="rounded-lg bg-gray-50 p-3 border-l-4 border-blue-400">
                            <div class="text-xs text-gray-500">Likha</div>
                            <div class="text-[11px] font-mono text-gray-400">TO ENCODER!L1</div>
                            <div class="text-2xl font-semibold mt-1 text-gray-800" data-val="likha">…</div>
                            <div class="text-[11px] text-red-600 mt-0.5 hidden" data-err="likha"></div>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 border-l-4 border-amber-400">
                            <div class="text-xs text-gray-500">Macro &middot; task count</div>
                            <div class="text-[11px] font-mono text-gray-400">LINKS!E2</div>
                            <div class="text-2xl font-semibold mt-1 text-gray-800" data-val="macro">…</div>
                            <div class="text-[11px] text-red-600 mt-0.5 hidden" data-err="macro"></div>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 border-l-4 border-emerald-400">
                            <div class="text-xs text-gray-500">After-macro &middot; last row</div>
                            <div class="text-[11px] font-mono text-gray-400">DATABASE!N1 &minus; 1</div>
                            <div class="text-2xl font-semibold mt-1 text-gray-800" data-val="after">…</div>
                            <div class="text-[11px] text-red-600 mt-0.5 hidden" data-err="after"></div>
                        </div>
                    </div>

                    {{-- ✂️ Delete rows (hiwalay per gsheet) --}}
                    <div class="mt-4 border-t pt-3 space-y-3">
                        <p class="text-xs text-red-800">⚠️ I-click muna ang 🛑 Stop at siguraduhing <b>tumigil na</b> ang scripts bago mag-delete.</p>

                        {{-- Likha --}}
                        <div>
                            <button type="button" @click="delL = !delL"
                                    class="text-xs px-2.5 py-1 rounded border border-red-300 text-red-700 hover:bg-red-50">✂️ Delete rows — Likha…</button>
                            <div x-show="delL" x-cloak class="mt-2 rounded-lg border border-red-200 bg-red-50 p-3">
                                <form method="POST" action="/gsheet_groups/{{ $g->id }}/delete-rows"
                                      onsubmit="return confirm('LIKHA — bubura ng rows 3 hanggang ' + this.end_row.value + ' (shift up) sa: All Orders, TO WEBSITE!I, TO ENCODER!J. Tuloy?')">
                                    @csrf
                                    <input type="hidden" name="scope" value="likha">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <label class="text-xs text-gray-700">Hanggang anong row?</label>
                                        <input type="number" name="end_row" min="3" required placeholder="hal. 50"
                                               class="w-28 text-sm border border-gray-300 rounded px-2 py-1.5">
                                        <button type="submit" class="px-3 py-1.5 text-sm rounded bg-red-600 text-white hover:bg-red-700">🗑️ Delete (Likha)</button>
                                        <button type="button" @click="delL = false" class="px-3 py-1.5 text-sm rounded border border-gray-300 hover:bg-gray-50">Cancel</button>
                                    </div>
                                    <p class="text-[11px] text-gray-500 mt-2">Buong rows: <b>All Orders</b> &middot; Column-only: <b>TO WEBSITE!I</b>, <b>TO ENCODER!J</b></p>
                                </form>
                            </div>
                        </div>

                        {{-- After-macro --}}
                        <div>
                            <button type="button" @click="delA = !delA"
                                    class="text-xs px-2.5 py-1 rounded border border-red-300 text-red-700 hover:bg-red-50">✂️ Delete rows — After-macro…</button>
                            <div x-show="delA" x-cloak class="mt-2 rounded-lg border border-red-200 bg-red-50 p-3">
                                <form method="POST" action="/gsheet_groups/{{ $g->id }}/delete-rows"
                                      onsubmit="return confirm('AFTER-MACRO — bubura ng rows 3 hanggang ' + this.end_row.value + ' (shift up) sa: DATABASE, DATABASE - MIRRORED!Q. Tuloy?')">
                                    @csrf
                                    <input type="hidden" name="scope" value="after">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <label class="text-xs text-gray-700">Hanggang anong row?</label>
                                        <input type="number" name="end_row" min="3" required placeholder="hal. 50"
                                               class="w-28 text-sm border border-gray-300 rounded px-2 py-1.5">
                                        <button type="submit" class="px-3 py-1.5 text-sm rounded bg-red-600 text-white hover:bg-red-700">🗑️ Delete (After-macro)</button>
                                        <button type="button" @click="delA = false" class="px-3 py-1.5 text-sm rounded border border-gray-300 hover:bg-gray-50">Cancel</button>
                                    </div>
                                    <p class="text-[11px] text-gray-500 mt-2">Buong rows: <b>DATABASE</b> &middot; Column-only: <b>DATABASE - MIRRORED!Q</b></p>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-gray-500 text-sm">Wala pang group. Mag-add sa baba. 👇</p>
            @endforelse
        </div>

        {{-- ───────── Add group ───────── --}}
        <div class="bg-gray-50 border border-dashed border-gray-300 rounded-xl p-4 mt-6">
            <div class="font-medium text-gray-800 mb-3">➕ Add group</div>
            <form method="POST" action="/gsheet_groups" class="space-y-2">
                @csrf
                <div class="flex items-center gap-2">
                    <span class="w-24 shrink-0 text-xs text-gray-500">Group name</span>
                    <input type="text" name="name" placeholder="Gsheet 3" required
                           class="flex-1 min-w-0 text-sm border border-gray-300 rounded px-2 py-1.5">
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-24 shrink-0 text-xs font-medium text-center px-2 py-1 rounded bg-blue-50 text-blue-800">Likha</span>
                    <input type="text" name="likha_url" placeholder="https://docs.google.com/spreadsheets/d/..."
                           class="flex-1 min-w-0 text-xs font-mono border border-gray-300 rounded px-2 py-1.5">
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-24 shrink-0 text-xs font-medium text-center px-2 py-1 rounded bg-amber-50 text-amber-800">Macro</span>
                    <input type="text" name="macro_url" placeholder="https://docs.google.com/spreadsheets/d/..."
                           class="flex-1 min-w-0 text-xs font-mono border border-gray-300 rounded px-2 py-1.5">
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-24 shrink-0 text-xs font-medium text-center px-2 py-1 rounded bg-emerald-50 text-emerald-800">After-macro</span>
                    <input type="text" name="after_url" placeholder="https://docs.google.com/spreadsheets/d/..."
                           class="flex-1 min-w-0 text-xs font-mono border border-gray-300 rounded px-2 py-1.5">
                </div>
                <div class="pt-1">
                    <button type="submit" class="px-3 py-1.5 text-sm rounded bg-gray-800 text-white hover:bg-gray-700">💾 Save &amp; fetch values</button>
                </div>
            </form>
        </div>

        <p class="text-xs text-gray-400 mt-4">
            ⚠️ Siguraduhing naka-share ang service account (Viewer) sa 3 sheets para makuha ang values.
        </p>
    </div>

    <script>
        function setBox(card, key, cell) {
            const valEl = card.querySelector(`[data-val="${key}"]`);
            const errEl = card.querySelector(`[data-err="${key}"]`);
            if (!valEl) return;

            if (cell && cell.error) {
                valEl.textContent = '—';
                errEl.textContent = cell.error;
                errEl.classList.remove('hidden');
                return;
            }

            errEl.classList.add('hidden');
            errEl.textContent = '';

            let v = cell ? cell.value : null;
            if (v === null || v === undefined || v === '') {
                valEl.textContent = '—';
            } else if (!isNaN(v) && v !== '') {
                valEl.textContent = Number(v).toLocaleString();
            } else {
                valEl.textContent = v;
            }
        }

        function setTitle(card, key, cell) {
            const tEl = card.querySelector(`[data-title="${key}"]`);
            if (!tEl) return;
            tEl.classList.remove('text-red-600', 'text-gray-500');
            if (cell && cell.title) {
                tEl.textContent = '📄 ' + cell.title;
                tEl.classList.add('text-gray-500');
            } else if (cell && cell.error) {
                tEl.textContent = '⚠️ ' + cell.error;
                tEl.classList.add('text-red-600');
            } else {
                tEl.textContent = '—';
                tEl.classList.add('text-gray-500');
            }
        }

        async function loadGroupValues(card) {
            const id = card.dataset.group;
            ['likha', 'macro', 'after'].forEach(k => {
                const el = card.querySelector(`[data-val="${k}"]`);
                if (el) el.textContent = '…';
                const er = card.querySelector(`[data-err="${k}"]`);
                if (er) er.classList.add('hidden');
                const tEl = card.querySelector(`[data-title="${k}"]`);
                if (tEl) tEl.textContent = '…';
            });

            try {
                const res = await fetch(`/gsheet_groups/${id}/values`, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await res.json();
                ['likha', 'macro', 'after'].forEach(k => {
                    setBox(card, k, data[k]);
                    setTitle(card, k, data[k]);
                });
            } catch (e) {
                ['likha', 'macro', 'after'].forEach(k => {
                    const el = card.querySelector(`[data-val="${k}"]`);
                    if (el) el.textContent = '—';
                });
            }
        }

        function refreshAllGroups() {
            document.querySelectorAll('#groups [data-group]').forEach(loadGroupValues);
        }

        document.addEventListener('DOMContentLoaded', refreshAllGroups);
    </script>
</x-layout>
