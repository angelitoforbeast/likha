<x-layout>
  <x-slot name="title">Flow Templates</x-slot>
  <x-slot name="heading">Conversation Tracker — Flow Templates</x-slot>

  <style>
    .ct-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .ct-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .ct-title { font-size:13px; font-weight:600; color:#0f172a; }
    .ct-input, .ct-select {
      padding:7px 10px; font-size:12.5px; color:#0f172a; background:#fff;
      border:1px solid #cbd5e1; border-radius:6px; width:100%;
    }
    .ct-input:focus, .ct-select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
    .ct-btn { display:inline-flex; align-items:center; gap:5px; background:#4f46e5; color:#fff; font-weight:600; font-size:12px; padding:7px 12px; border-radius:6px; cursor:pointer; }
    .ct-btn:hover { background:#4338ca; }
    .ct-btn-secondary { display:inline-flex; align-items:center; gap:5px; background:#fff; color:#4f46e5; font-weight:600; font-size:11px; padding:5px 10px; border:1px solid #c7d2fe; border-radius:6px; cursor:pointer; }
    .ct-btn-secondary:hover { background:#eef2ff; }
    .ct-btn-ghost { display:inline-flex; align-items:center; gap:5px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; }
    .ct-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
    .ct-btn-danger { background:transparent; color:#dc2626; font-size:11px; padding:3px 7px; border:1px solid #fecaca; border-radius:5px; cursor:pointer; }
    .ct-btn-danger:hover { background:#fef2f2; }

    /* Flow card — collapsible by default; expand to edit. */
    .flow-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 14px; margin-bottom:8px; transition:padding 0.15s; }
    .flow-card.expanded { padding:14px; box-shadow:0 2px 6px rgba(99,102,241,0.08); border-color:#c7d2fe; }
    .flow-head { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
    .flow-card.expanded .flow-head { margin-bottom:12px; padding-bottom:10px; border-bottom:1px dashed #e2e8f0; }
    .flow-name {
      font-size:12px; font-weight:700; color:#3730a3; background:#eef2ff;
      padding:4px 12px; border-radius:999px; letter-spacing:0.04em;
    }
    .flow-name.loop { color:#92400e; background:#fef3c7; }
    .flow-name.main { color:#166534; background:#dcfce7; }
    .flow-summary { font-size:11.5px; color:#64748b; }
    .flow-summary .unsaved { color:#d97706; font-weight:600; }
    .flow-summary .saved { color:#16a34a; font-weight:600; }
    .flow-actions { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    .flow-body { transition:max-height 0.2s ease-out; }
    .save-status { font-size:10.5px; }
    .save-status.saving { color:#6366f1; }
    .save-status.saved { color:#16a34a; }
    .save-status.error { color:#dc2626; }

    /* Bubble */
    .bubble {
      background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;
      padding:10px 12px; margin-bottom:8px; position:relative;
    }
    .bubble.dragging { opacity:0.4; }
    .bubble-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }
    .bubble-type {
      font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.06em;
    }
    .bubble-controls { display:flex; gap:4px; }
    .bubble-arrow {
      background:transparent; border:1px solid #cbd5e1; color:#475569;
      width:22px; height:22px; border-radius:5px; cursor:pointer; font-size:11px; line-height:1;
    }
    .bubble-arrow:hover { background:#f1f5f9; }
    .bubble textarea { width:100%; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px; font-size:12.5px; min-height:60px; resize:vertical; background:#fff; }
    .bubble textarea:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12); }

    /* Drop zone for image/video */
    .media-drop {
      border:2px dashed #cbd5e1; border-radius:8px; padding:18px;
      text-align:center; cursor:pointer; background:#fff; transition:all 0.15s;
      position:relative; outline:none;
    }
    .media-drop:hover, .media-drop.dragover { border-color:#6366f1; background:#eef2ff; }
    .media-drop.active { border-color:#16a34a; background:#f0fdf4; box-shadow:0 0 0 3px rgba(22,163,74,0.15); }
    .media-drop.active::after {
      content:'✓ active — Ctrl+V to paste'; position:absolute; top:6px; right:8px;
      font-size:10px; color:#16a34a; font-weight:600; letter-spacing:0.04em;
    }
    .media-drop.uploading { opacity:0.6; cursor:wait; }
    .media-drop p { margin:0; font-size:12px; color:#64748b; }
    .media-drop .hint { font-size:10.5px; color:#94a3b8; margin-top:4px; }
    .media-drop .pick-btn {
      display:inline-block; margin-top:6px; font-size:11px;
      color:#4f46e5; text-decoration:underline; cursor:pointer;
    }
    .media-drop .pick-btn:hover { color:#4338ca; }
    .media-preview { margin-top:8px; }
    .media-preview img, .media-preview video {
      max-width:100%; max-height:240px; border-radius:6px;
      box-shadow:0 1px 3px rgba(0,0,0,0.1);
    }
    .media-preview .url { font-size:10px; color:#94a3b8; word-break:break-all; margin-top:4px; font-family:monospace; }

    .empty-flow {
      padding:14px; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px;
      text-align:center; color:#94a3b8; font-size:12px; font-style:italic;
    }

    .add-bubble-row { display:flex; gap:6px; flex-wrap:wrap; padding-top:8px; border-top:1px dashed #e2e8f0; }
  </style>

  <div class="w-full flex flex-col gap-4 p-2"
       x-data="flowEditor()"
       x-init="init()">
    <!-- Header -->
    <div class="ct-card">
      <div class="ct-card-header">
        <div class="ct-title">🎯 Flow templates {{ $selectedPage ? '— ' . $selectedPage : '' }}</div>
        <div class="flex gap-2 flex-wrap">
          <a href="/conversation/tracker" class="ct-btn-ghost">← Import</a>
          <a href="/conversation/tracker/view" class="ct-btn-ghost">📋 Records</a>
          <a href="/conversation/tracker/stats" class="ct-btn-ghost">📊 Stats</a>
        </div>
      </div>

      <form method="GET" action="/conversation/tracker/flows" class="grid grid-cols-1 md:grid-cols-3 gap-3 p-3">
        <div class="md:col-span-2">
          <label class="block text-xs font-semibold text-slate-600 mb-1">📄 Page</label>
          <select name="page" class="ct-select" onchange="this.form.submit()">
            <option value="">— select page —</option>
            @foreach ($pages as $p)
              <option value="{{ $p }}" @selected($selectedPage === $p)>{{ $p }}</option>
            @endforeach
          </select>
        </div>
        <div class="flex items-end">
          <button type="submit" class="ct-btn">🔄 Refresh detected flows</button>
        </div>
      </form>
    </div>

    <!-- Empty state: no page selected -->
    @if ($selectedPage === '')
      <div class="ct-card p-6 text-center text-slate-400">
        Select a page above to view + edit its flow templates.
      </div>
    @elseif (empty($flowNames))
      <div class="ct-card p-6 text-center text-slate-400">
        No flows detected yet for <strong>{{ $selectedPage }}</strong>. Import some response_tracker data first via the
        <a href="/conversation/tracker" class="text-indigo-600 underline">Import page</a>.
      </div>
    @else
      <!-- Flow cards (collapsed by default) -->
      @foreach ($flowNames as $flow)
        @php
          $bubbles = $contentsByFlow[$flow] ?? [];
          $cls = 'flow-name';
          if (str_starts_with($flow, 'LOOP'))     $cls .= ' loop';
          elseif ($flow === 'MAIN FLOW')           $cls .= ' main';
          $bubbleCount = count($bubbles);
        @endphp
        <div class="flow-card" data-flow="{{ $flow }}" data-expanded="false" data-dirty="false">
          <div class="flow-head">
            <div class="flex items-center gap-3 flex-wrap">
              <span class="{{ $cls }}">{{ $flow }}</span>
              <span class="flow-summary">
                @if ($bubbleCount > 0)
                  <span class="saved">✓ {{ $bubbleCount }} bubble{{ $bubbleCount === 1 ? '' : 's' }} saved</span>
                @else
                  <span style="color:#94a3b8;">— no bubbles yet —</span>
                @endif
                <span class="dirty-tag hidden" style="margin-left:8px;color:#d97706;font-weight:600;">● Unsaved changes</span>
              </span>
              <span class="save-status" data-status="{{ $flow }}"></span>
            </div>
            <div class="flow-actions">
              <button class="ct-btn-secondary expand-btn" data-act="toggle">✏️ Edit</button>
              <button class="ct-btn save-btn" data-act="save" style="display:none;">💾 Save</button>
              <button class="ct-btn-ghost close-btn" data-act="close" style="display:none;">✕ Close</button>
            </div>
          </div>

          <div class="flow-body" style="display:none;">
            <div class="bubbles-container" data-flow="{{ $flow }}">
              @if (empty($bubbles))
                <div class="empty-flow">No bubbles yet. Click below to add the first one.</div>
              @else
                @foreach ($bubbles as $i => $b)
                  @include('conversation_tracker._bubble', ['bubble' => $b, 'index' => $i, 'flow' => $flow])
                @endforeach
              @endif
            </div>

            <div class="add-bubble-row">
              <button class="ct-btn-secondary" onclick="window.flowEd.addBubble('{{ $flow }}', 'text')">📝 Add Text</button>
              <button class="ct-btn-secondary" onclick="window.flowEd.addBubble('{{ $flow }}', 'image')">🖼️ Add Image</button>
              <button class="ct-btn-secondary" onclick="window.flowEd.addBubble('{{ $flow }}', 'video')">🎥 Add Video</button>
            </div>
          </div>
        </div>
      @endforeach
    @endif
  </div>

  <script>
    function flowEditor() {
      return {
        status: {},
        _activeDrop: null,
        init() {
          window.flowEd = this;
          // Wire up existing bubbles (autosave on blur, reorder, delete, drop)
          document.querySelectorAll('.flow-card').forEach((card) => {
            this.wireFlowCard(card);
          });
          // Single document-level paste listener for clipboard images.
          this.wireDocumentPaste();
        },

        // ====== Persistence ======
        async saveFlow(flowName) {
          const card = document.querySelector(`.flow-card[data-flow="${CSS.escape(flowName)}"]`);
          if (!card) return;
          const bubbles = this.collectBubbles(card);

          this.setStatus(card, 'saving', 'Saving…');
          try {
            const res = await fetch("{{ route('conversation.tracker.flows.save') }}", {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
              body: JSON.stringify({
                page_name: '{{ $selectedPage }}',
                flow_name: flowName,
                bubbles,
              }),
            });
            const data = await res.json();
            if (res.ok && data.ok) {
              this.setStatus(card, 'saved', `✅ Saved (${data.bubble_count})`);
              this.setDirty(card, false);
              this.updateSummary(card, data.bubble_count);
              setTimeout(() => this.setStatus(card, '', ''), 2000);
            } else {
              this.setStatus(card, 'error', '❌ ' + (data.error || 'save failed'));
            }
          } catch (e) {
            this.setStatus(card, 'error', '❌ ' + e.message);
          }
        },

        setStatus(card, cls, text) {
          const el = card.querySelector('.save-status');
          if (!el) return;
          el.className = 'save-status ' + (cls || '');
          el.textContent = text || '';
        },

        setDirty(card, dirty) {
          card.dataset.dirty = dirty ? 'true' : 'false';
          const tag = card.querySelector('.dirty-tag');
          if (tag) tag.classList.toggle('hidden', !dirty);
          // Highlight Save button when dirty
          const saveBtn = card.querySelector('.save-btn');
          if (saveBtn) saveBtn.style.opacity = dirty ? '1' : '0.7';
        },

        updateSummary(card, bubbleCount) {
          // Update the "✓ N bubbles saved" text after a successful save.
          const sum = card.querySelector('.flow-summary');
          if (!sum) return;
          // Remove any existing saved/empty span
          sum.querySelectorAll(':scope > span:not(.dirty-tag)').forEach(s => s.remove());
          const span = document.createElement('span');
          if (bubbleCount > 0) {
            span.className = 'saved';
            span.textContent = `✓ ${bubbleCount} bubble${bubbleCount === 1 ? '' : 's'} saved`;
          } else {
            span.style.color = '#94a3b8';
            span.textContent = '— no bubbles yet —';
          }
          sum.insertBefore(span, sum.firstChild);
        },

        toggleExpand(card) {
          const isOpen = card.dataset.expanded === 'true';
          if (isOpen) {
            // Closing — warn if dirty
            if (card.dataset.dirty === 'true') {
              if (!confirm('Close without saving? Unsaved changes will be lost.')) return;
            }
            card.dataset.expanded = 'false';
            card.classList.remove('expanded');
            card.querySelector('.flow-body').style.display = 'none';
            card.querySelector('.expand-btn').style.display = '';
            card.querySelector('.save-btn').style.display = 'none';
            card.querySelector('.close-btn').style.display = 'none';
          } else {
            card.dataset.expanded = 'true';
            card.classList.add('expanded');
            card.querySelector('.flow-body').style.display = '';
            card.querySelector('.expand-btn').style.display = 'none';
            card.querySelector('.save-btn').style.display = '';
            card.querySelector('.close-btn').style.display = '';
          }
        },

        collectBubbles(card) {
          const out = [];
          card.querySelectorAll('.bubble').forEach((b) => {
            const type = b.dataset.type;
            const item = { type };
            if (type === 'text') {
              item.text = (b.querySelector('textarea')?.value || '').trim();
            } else {
              item.url = b.dataset.url || '';
              const cap = (b.querySelector('input.caption-input')?.value || '').trim();
              if (cap) item.caption = cap;
            }
            out.push(item);
          });
          return out;
        },

        // ====== DOM manipulation ======
        wireFlowCard(card) {
          const flow = card.dataset.flow;
          // Edit / Save / Close buttons in the header.
          card.querySelector('[data-act="toggle"]')?.addEventListener('click', () => this.toggleExpand(card));
          card.querySelector('[data-act="close"]')?.addEventListener('click', () => this.toggleExpand(card));
          card.querySelector('[data-act="save"]')?.addEventListener('click', () => this.saveFlow(flow));
          // Wire all existing bubbles' controls.
          const container = card.querySelector('.bubbles-container');
          container.querySelectorAll('.bubble').forEach((b) => this.wireBubble(b, flow));
        },

        wireBubble(bubble, flow) {
          const card = bubble.closest('.flow-card');
          // Up/down/delete buttons → mark dirty + reorder DOM (no autosave)
          bubble.querySelector('[data-act="up"]')?.addEventListener('click', () => this.moveBubble(bubble, -1, card));
          bubble.querySelector('[data-act="down"]')?.addEventListener('click', () => this.moveBubble(bubble, +1, card));
          bubble.querySelector('[data-act="del"]')?.addEventListener('click', () => this.deleteBubble(bubble, card));

          // Mark dirty on input changes (text + caption fields)
          const ta = bubble.querySelector('textarea');
          if (ta) ta.addEventListener('input', () => this.setDirty(card, true));
          const cap = bubble.querySelector('input.caption-input');
          if (cap) cap.addEventListener('input', () => this.setDirty(card, true));

          // Media drop zone
          const drop = bubble.querySelector('.media-drop');
          if (drop) this.wireDropZone(drop, bubble, flow);
        },

        wireDropZone(drop, bubble, flow) {
          const kind = bubble.dataset.type; // 'image' or 'video'
          const fileInput = drop.querySelector('input[type="file"]');
          const pickBtn = drop.querySelector('.pick-btn');

          // Click on drop zone → make this the "active" zone for paste/drag.
          // Doesn't open file picker — user clicks the explicit "Choose file"
          // link OR the file input directly. This allows Ctrl+V to work after
          // clicking the area (Messenger-style).
          drop.addEventListener('click', (e) => {
            if (e.target.matches('.pick-btn, input[type="file"]')) return;
            // Mark this as active across the whole document.
            document.querySelectorAll('.media-drop.active').forEach((d) => {
              if (d !== drop) d.classList.remove('active');
            });
            drop.classList.add('active');
            window.flowEd._activeDrop = drop;
            drop.focus();
          });

          // Explicit "Choose file" link opens file picker.
          pickBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            fileInput?.click();
          });

          // Drag & drop
          drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('dragover'); });
          drop.addEventListener('dragleave', () => drop.classList.remove('dragover'));
          drop.addEventListener('drop', (e) => {
            e.preventDefault();
            drop.classList.remove('dragover');
            const f = e.dataTransfer.files[0];
            if (f) this.uploadMedia(f, kind, bubble, flow);
          });

          // File input change
          fileInput?.addEventListener('change', (e) => {
            const f = e.target.files[0];
            if (f) this.uploadMedia(f, kind, bubble, flow);
          });
        },

        // Document-level paste listener — image only (videos can't be
        // clipboard-pasted). Routes the pasted image to the most recently
        // clicked image drop zone. Mirrors Messenger / Discord UX.
        wireDocumentPaste() {
          document.addEventListener('paste', (e) => {
            const drop = window.flowEd._activeDrop;
            if (!drop) return;
            const bubble = drop.closest('.bubble');
            if (!bubble || bubble.dataset.type !== 'image') return;
            const flowCard = bubble.closest('.flow-card');
            if (!flowCard) return;

            const items = e.clipboardData?.items || [];
            for (const item of items) {
              if (item.type.startsWith('image/')) {
                const file = item.getAsFile();
                if (file) {
                  e.preventDefault();
                  this.uploadMedia(file, 'image', bubble, flowCard.dataset.flow);
                }
                break;
              }
            }
          });
        },

        async uploadMedia(file, kind, bubble, flow) {
          const drop = bubble.querySelector('.media-drop');
          const card = bubble.closest('.flow-card');
          drop?.classList.add('uploading');
          this.setStatus(card, 'saving', 'Uploading ' + kind + '…');

          const fd = new FormData();
          fd.append('file', file);
          fd.append('kind', kind);

          try {
            const res = await fetch("{{ route('conversation.tracker.flows.upload') }}", {
              method: 'POST',
              headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
              body: fd,
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
              this.setStatus(card, 'error', '❌ Upload failed: ' + (data.message || ('HTTP ' + res.status)));
              return;
            }
            // Update bubble with new URL + render preview
            bubble.dataset.url = data.url;
            const previewBox = bubble.querySelector('.media-preview');
            if (previewBox) {
              if (kind === 'image') {
                previewBox.innerHTML = `<img src="${data.url}" alt=""><div class="url">${data.url}</div>`;
              } else {
                previewBox.innerHTML = `<video controls src="${data.url}"></video><div class="url">${data.url}</div>`;
              }
            }
            // Mark dirty — user must click Save to persist the bubble URL.
            this.setDirty(card, true);
            this.setStatus(card, 'saved', '✅ Uploaded — click 💾 Save to persist');
          } catch (e) {
            this.setStatus(card, 'error', '❌ ' + e.message);
          } finally {
            drop?.classList.remove('uploading');
          }
        },

        addBubble(flow, type) {
          const card = document.querySelector(`.flow-card[data-flow="${CSS.escape(flow)}"]`);
          if (!card) return;
          const container = card.querySelector('.bubbles-container');
          // Remove the empty-state placeholder if present
          container.querySelector('.empty-flow')?.remove();

          const div = document.createElement('div');
          div.innerHTML = this.bubbleHtml(type, {});
          const bubble = div.firstElementChild;
          container.appendChild(bubble);
          this.wireBubble(bubble, flow);
          this.setDirty(card, true);
          // Focus the textarea (text) or click the drop zone (media)
          if (type === 'text') bubble.querySelector('textarea')?.focus();
        },

        moveBubble(bubble, direction, card) {
          const parent = bubble.parentElement;
          if (direction === -1 && bubble.previousElementSibling) {
            parent.insertBefore(bubble, bubble.previousElementSibling);
          } else if (direction === +1 && bubble.nextElementSibling) {
            parent.insertBefore(bubble.nextElementSibling, bubble);
          }
          this.setDirty(card, true);
        },

        deleteBubble(bubble, card) {
          if (!confirm('Delete this bubble?')) return;
          const container = bubble.parentElement;
          bubble.remove();
          if (!container.querySelector('.bubble')) {
            const empty = document.createElement('div');
            empty.className = 'empty-flow';
            empty.textContent = 'No bubbles yet. Click below to add the first one.';
            container.appendChild(empty);
          }
          this.setDirty(card, true);
        },

        bubbleHtml(type, data) {
          const url = data.url || '';
          const caption = (data.caption || '').replace(/"/g, '&quot;');
          const text = (data.text || '').replace(/</g, '&lt;');
          const head = `
            <div class="bubble-head">
              <span class="bubble-type">${type === 'text' ? '📝 Text' : type === 'image' ? '🖼️ Image' : '🎥 Video'}</span>
              <span class="bubble-controls">
                <button class="bubble-arrow" data-act="up"   title="Move up">↑</button>
                <button class="bubble-arrow" data-act="down" title="Move down">↓</button>
                <button class="bubble-arrow" data-act="del"  title="Delete">✕</button>
              </span>
            </div>`;
          if (type === 'text') {
            return `<div class="bubble" data-type="text">
              ${head}
              <textarea placeholder="Type the message...">${text}</textarea>
            </div>`;
          }
          // image/video
          const accept = type === 'image' ? 'image/*' : 'video/*';
          const previewInner = url
            ? (type === 'image'
                ? `<img src="${url}" alt=""><div class="url">${url}</div>`
                : `<video controls src="${url}"></video><div class="url">${url}</div>`)
            : '';
          const dropHint = type === 'image'
            ? '<p>1️⃣ <strong>Click here</strong> first to activate this slot</p>'
            + '<p class="hint">2️⃣ Then <strong>Ctrl+V</strong> to paste, drag-drop, or <span class="pick-btn">📂 choose file</span></p>'
            + '<p class="hint">jpg/png/gif/webp · max 5MB</p>'
            : '<p><strong>Drag-drop</strong> a video, or <span class="pick-btn">📂 choose file</span></p>'
            + '<p class="hint">mp4/webm/mov · max 50MB</p>';
          return `<div class="bubble" data-type="${type}" data-url="${url}">
            ${head}
            <div class="media-drop" tabindex="0">
              <input type="file" accept="${accept}" class="hidden" hidden>
              ${dropHint}
            </div>
            <div class="media-preview">${previewInner}</div>
            <input type="text" class="caption-input ct-input mt-2" placeholder="Optional caption" value="${caption}">
          </div>`;
        },
      };
    }
  </script>
</x-layout>
