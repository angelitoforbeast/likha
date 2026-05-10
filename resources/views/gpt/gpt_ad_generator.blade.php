<x-layout>
  <x-slot name="title">Ad Captions</x-slot>
  <x-slot name="heading">Ad Copy Generator</x-slot>

  <style>
    /* Form polish */
    .gpt-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .gpt-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; }
    .gpt-card-title { font-size:13px; font-weight:600; color:#0f172a; letter-spacing:0.02em; }
    .gpt-card-subtitle { font-size:11px; color:#64748b; margin-top:2px; }

    .gpt-section { padding:10px 14px; border-bottom:1px solid #f1f5f9; }
    .gpt-section:last-child { border-bottom:none; }
    .gpt-section-label { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.08em; margin-bottom:6px; }

    .gpt-label { display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px; }
    .gpt-hint  { font-size:11px; color:#94a3b8; margin-top:4px; line-height:1.4; }

    .gpt-input, .gpt-select, .gpt-textarea {
      width:100%; padding:8px 10px; font-size:13px; color:#0f172a;
      background:#fff; border:1px solid #cbd5e1; border-radius:6px;
      transition:border-color 0.15s, box-shadow 0.15s;
    }
    .gpt-input:focus, .gpt-select:focus, .gpt-textarea:focus {
      outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.12);
    }
    .gpt-textarea { resize:vertical; line-height:1.5; }

    /* Range slider */
    .gpt-range { width:100%; accent-color:#6366f1; }

    /* Buttons */
    .btn-primary {
      display:inline-flex; align-items:center; gap:6px;
      background:#4f46e5; color:#fff; font-weight:600; font-size:13px;
      padding:9px 16px; border-radius:7px; transition:background 0.12s;
      box-shadow:0 1px 2px rgba(79,70,229,0.25);
    }
    .btn-primary:hover { background:#4338ca; }
    .btn-secondary {
      display:inline-flex; align-items:center; gap:6px;
      background:#fff; color:#4f46e5; font-weight:600; font-size:13px;
      padding:8px 14px; border:1px solid #c7d2fe; border-radius:7px; transition:background 0.12s;
    }
    .btn-secondary:hover { background:#eef2ff; }
    .btn-ghost {
      display:inline-flex; align-items:center; gap:5px;
      background:transparent; color:#64748b; font-size:12px;
      padding:5px 10px; border-radius:6px; transition:background 0.12s, color 0.12s;
    }
    .btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
    .btn-ghost.danger { color:#dc2626; }
    .btn-ghost.danger:hover { background:#fef2f2; color:#b91c1c; }

    /* Pretty checkboxes — keep native, just tint */
    .gpt-check { accent-color:#4f46e5; width:15px; height:15px; }

    /* Filter "chips" preview */
    .gpt-chip { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:500; background:#eef2ff; color:#4338ca; }

    /* Output card layout — replaces the wide table. One card per variant. */
    .v-card {
      background:#fff; border:1px solid #e5e7eb; border-radius:10px;
      padding:12px 14px; margin-bottom:10px;
      transition:border-color 0.12s, box-shadow 0.12s;
    }
    .v-card:hover { border-color:#c7d2fe; box-shadow:0 2px 6px rgba(99,102,241,0.06); }
    .v-card-head {
      display:flex; align-items:center; justify-content:space-between;
      margin-bottom:10px; padding-bottom:8px; border-bottom:1px dashed #e2e8f0;
    }
    .v-card-head .v-no { font-size:10px; font-weight:700; color:#4338ca; text-transform:uppercase; letter-spacing:0.06em; }
    .v-card-head .v-item { font-size:14px; font-weight:600; color:#0f172a; margin-left:8px; }
    .v-grid {
      display:grid; grid-template-columns:1.1fr 1.4fr 0.9fr; gap:14px;
    }
    @media (max-width: 900px) {
      .v-grid { grid-template-columns:1fr; }
    }
    .v-cell { display:flex; flex-direction:column; gap:8px; }
    .v-field-label {
      font-size:9.5px; font-weight:700; color:#64748b;
      text-transform:uppercase; letter-spacing:0.06em; margin-bottom:2px;
    }
    .v-field-value {
      font-size:12.5px; color:#0f172a; line-height:1.5; word-wrap:break-word;
    }
    /* Messaging template needs preserved newlines */
    .v-field-value.preserve { white-space:pre-wrap; }
    .v-field-block { padding:8px 10px; background:#f8fafc; border-radius:6px; border:1px solid #f1f5f9; }
    .v-qr-list { display:flex; flex-direction:column; gap:6px; }
    .v-qr {
      padding:6px 10px; background:#eef2ff; border-radius:999px;
      font-size:11.5px; color:#3730a3; font-weight:500;
    }
    .v-qr.empty { background:#f1f5f9; color:#94a3b8; font-style:italic; }
    .v-empty {
      padding:36px; text-align:center; color:#94a3b8; font-size:13px; font-style:italic;
    }

    /* Resize handles */
    .resize-h {
      width:6px; cursor:col-resize; background:transparent; flex-shrink:0;
      transition:background 0.15s; position:relative;
    }
    .resize-h:hover, .resize-h.dragging { background:#c7d2fe; }
    .resize-h::before {
      content:''; position:absolute; left:50%; top:50%; transform:translate(-50%,-50%);
      width:2px; height:36px; background:#cbd5e1; border-radius:2px;
    }
    .resize-h:hover::before, .resize-h.dragging::before { background:#6366f1; }

    .resize-v {
      height:6px; cursor:row-resize; background:transparent; flex-shrink:0;
      transition:background 0.15s; position:relative;
    }
    .resize-v:hover, .resize-v.dragging { background:#c7d2fe; }
    .resize-v::before {
      content:''; position:absolute; left:50%; top:50%; transform:translate(-50%,-50%);
      width:36px; height:2px; background:#cbd5e1; border-radius:2px;
    }
    .resize-v:hover::before, .resize-v.dragging::before { background:#6366f1; }
    body.is-resizing { user-select:none; cursor:col-resize; }
    body.is-resizing-v { user-select:none; cursor:row-resize; }

    /* Save prompt button */
    .save-prompt-row { display:flex; align-items:center; gap:8px; margin-top:6px; }
    .save-prompt-status { font-size:11px; color:#64748b; }
    .save-prompt-status.ok { color:#16a34a; }
    .save-prompt-status.err { color:#dc2626; }

    /* Suggestions box: monospace-ish for ad copy readability */
    #suggestionsBox {
      background:#fafafa; font-family:ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size:12px; line-height:1.55; color:#1e293b;
      border:1px solid #e2e8f0; border-radius:8px;
    }
    #suggestionsBox:empty::before {
      content:"Click 'Load Ad Copy Suggestions' to fetch top/worst CPM patterns from existing ads.";
      color:#94a3b8; font-style:italic; font-family:inherit;
    }

    /* Action bar at bottom of left card */
    .gpt-action-bar {
      display:flex; flex-wrap:wrap; align-items:center; gap:8px;
      padding:10px 14px; border-top:1px solid #e5e7eb; background:#f8fafc; border-bottom-left-radius:12px; border-bottom-right-radius:12px;
    }
    .gpt-action-bar > .spacer { flex:1; }
    .gpt-action-bar .pill-input {
      display:inline-flex; align-items:center; gap:5px; font-size:12px; color:#475569;
      padding:5px 10px; background:#fff; border:1px solid #e2e8f0; border-radius:999px;
    }
    .gpt-action-bar .pill-input select {
      border:none; background:transparent; padding:0 4px; font-size:12px; font-weight:600; color:#0f172a;
    }
    .gpt-action-bar .pill-input select:focus { outline:none; }
  </style>

  <!-- Viewport-fitting wrapper. Layout:
       [ left column ] [ resize-h ] [ right column ]
       Left column = Settings (top) + resize-v + Suggestions (bottom).
       Right column = GPT Output card (full height). All 3 panels resizable. -->
  <div id="viewportFit" class="w-full flex overflow-hidden" style="gap:0;">
    <!-- LEFT COLUMN -->
    <div id="leftCol" class="flex flex-col overflow-hidden" style="width:42%; min-width:320px;">
      <!-- Settings card -->
      <div id="leftCard" class="gpt-card flex flex-col overflow-hidden" style="height:55%; min-height:200px;">
        <div class="gpt-card-header">
          <div>
            <div class="gpt-card-title">⚙️ Generation Settings</div>
            <div class="gpt-card-subtitle">Configure the prompt + GPT model. Click Generate to run.</div>
          </div>
          <a href="{{ route('gpt.history') }}" class="btn-ghost" title="Browse all past generations">
            📚 History
          </a>
        </div>

        <div class="flex-1 overflow-auto">
          <!-- Section: Product -->
          <div class="gpt-section">
            <div class="gpt-section-label">Product</div>
            <div class="space-y-3">
              <div>
                <label class="gpt-label" for="productName">📦 Product Name</label>
                <input type="text" id="productName" class="gpt-input" placeholder="e.g., Tactical Flashlight" value="Tactical Flashlight" />
              </div>
              <div>
                <label class="gpt-label" for="productDescription">📝 Description</label>
                <textarea id="productDescription" class="gpt-textarea" rows="3"
                  placeholder="e.g., Rechargeable, heavy duty, super liwanag, waterproof">Rechargeable, Heavy Duty, Super liwanag, Waterproof, Pang emergency</textarea>
              </div>
            </div>
          </div>

          <!-- Section: Suggestions filters -->
          <div class="gpt-section">
            <div class="gpt-section-label">Suggestions Source</div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="gpt-label" for="pageSelect">📄 Page</label>
                <select id="pageSelect" class="gpt-select">
                  <option value="all">All Pages</option>
                  @foreach ($pages as $p)
                    <option value="{{ $p }}">{{ $p }}</option>
                  @endforeach
                </select>
              </div>
              <div>
                <label class="gpt-label" for="itemSelect">🛒 Item</label>
                <select id="itemSelect" class="gpt-select">
                  <option value="all">All Items</option>
                  @foreach ($items ?? [] as $i)
                    <option value="{{ $i }}">{{ $i }}</option>
                  @endforeach
                </select>
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mt-3">
              <label class="flex items-center gap-2 text-sm text-slate-700">
                <input id="activeOnly" type="checkbox" class="gpt-check" checked />
                Active ads only
              </label>
              <label class="flex items-center gap-2 text-sm text-slate-700">
                <input id="includeSuggestions" type="checkbox" class="gpt-check" checked />
                Feed suggestions to GPT
              </label>
            </div>

            {{-- Date range — analyzes ads_manager_reports.day. Default last 30 days --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
              <div>
                <label class="gpt-label" for="datePreset">📅 Date range</label>
                <select id="datePreset" class="gpt-select" onchange="applyDatePreset()">
                  <option value="last7">Last 7 days</option>
                  <option value="last30" selected>Last 30 days (recommended)</option>
                  <option value="last60">Last 60 days</option>
                  <option value="last90">Last 90 days</option>
                  <option value="thisMonth">This month</option>
                  <option value="lastMonth">Last month (full)</option>
                  <option value="custom">Custom range...</option>
                </select>
              </div>
              <div>
                <label class="gpt-label" for="dateFrom">From</label>
                <input id="dateFrom" type="date" class="gpt-select" onchange="document.getElementById('datePreset').value='custom'">
              </div>
              <div>
                <label class="gpt-label" for="dateTo">To</label>
                <input id="dateTo" type="date" class="gpt-select" onchange="document.getElementById('datePreset').value='custom'">
              </div>
            </div>
          </div>

          <!-- Section: Model + creativity -->
          <div class="gpt-section">
            <div class="gpt-section-label">GPT Settings</div>
            <div class="space-y-3">
              <div>
                <label class="gpt-label" for="modelSelect">🤖 Model</label>
                <select id="modelSelect" class="gpt-select">
                  @foreach (($models ?? []) as $mid => $mlabel)
                    <option value="{{ $mid }}" @if(($defaultModel ?? '') === $mid) selected @endif>{{ $mlabel }}</option>
                  @endforeach
                </select>
                <div class="gpt-hint">Default: <span class="font-medium text-slate-600">{{ $defaultModel ?? 'gpt-4o' }}</span>. Pick cheaper / better-quality models per run.</div>
              </div>
              <div>
                <div class="flex items-center justify-between">
                  <label class="gpt-label !mb-0" for="temperature">🎨 Creativity</label>
                  <span class="text-sm font-mono font-semibold text-indigo-600" id="tempVal">0.5</span>
                </div>
                <input id="temperature" type="range" min="0" max="1" step="0.1" value="0.5"
                       oninput="document.getElementById('tempVal').textContent = this.value;"
                       class="gpt-range mt-2" />
                <div class="flex justify-between text-[10px] text-slate-400 mt-1 font-medium">
                  <span>0 · predictable</span>
                  <span>1 · creative</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Section: Custom prompt -->
          <div class="gpt-section">
            <div class="flex items-center justify-between mb-1">
              <div class="gpt-section-label" style="margin-bottom:0;">Custom Prompt</div>
              <a href="{{ route('gpt.prompt.history') }}" class="btn-ghost" style="padding:3px 8px;font-size:10.5px;">📜 Versions</a>
            </div>
            <textarea id="prompt" class="gpt-textarea text-xs" rows="7">{{ $promptText }}</textarea>
            <input id="promptNote" type="text" class="gpt-input mt-2" style="font-size:11.5px;" placeholder="Optional note about this change (e.g. 'added diversity rule')" />
            <div class="save-prompt-row">
              <button onclick="saveCustomPrompt()" class="btn-secondary" style="padding:5px 12px;font-size:11.5px;">💾 Save new version</button>
              <span id="savePromptStatus" class="save-prompt-status"></span>
            </div>
            <details class="mt-2 text-sm">
              <summary class="cursor-pointer text-slate-500 hover:text-slate-700 text-xs">👁 Preview final prompt (debug)</summary>
              <textarea id="finalPromptPreview" class="mt-2 gpt-textarea text-xs" rows="6" readonly></textarea>
            </details>
          </div>
        </div>

        <!-- Sticky action bar -->
        <div class="gpt-action-bar">
          <button onclick="generateGPTSummary()" class="btn-primary">🚀 Generate</button>

          <label class="pill-input" title="How many alternative ad copies to generate at once">
            Variants
            <select id="variantsCount" onchange="syncStreamCheckbox()">
              <option value="1" selected>1</option>
              <option value="3">3</option>
              <option value="5">5</option>
            </select>
          </label>

          <label class="pill-input" title="Stream output token-by-token (only with Variants=1)">
            <input id="streamOutput" type="checkbox" class="gpt-check" />
            <span>Stream live</span>
          </label>

          <button id="btnLoadSuggestions" onclick="loadAdCopySuggestions()" class="btn-secondary">💡 Load Suggestions</button>

          <div class="spacer"></div>

          <div id="loadingBox" class="hidden text-indigo-600 font-medium text-xs flex items-center gap-2" aria-live="polite">
            <span class="inline-block w-3 h-3 border-2 border-indigo-300 border-t-indigo-600 rounded-full animate-spin"></span>
            <span id="loadingText">Generating…</span>
          </div>
        </div>
      </div>

      <!-- Vertical resize handle between Settings and Suggestions -->
      <div id="resizeV" class="resize-v" title="Drag to resize"></div>

      <!-- Suggestions card (lower part of left column) -->
      <div id="sugCard" class="gpt-card flex flex-col overflow-hidden" style="height:45%; min-height:140px;">
        <div class="gpt-card-header">
          <div>
            <div class="gpt-card-title">💡 Suggestions <span class="text-slate-400 font-normal">(auto-fed)</span></div>
            <div class="gpt-card-subtitle">CPM-ranked patterns from your existing ads.</div>
          </div>
          <div class="flex gap-1">
            <button onclick="copySuggestions()" class="btn-ghost">📋 Copy</button>
            <button onclick="clearSuggestions()" class="btn-ghost danger">🗑 Clear</button>
          </div>
        </div>
        <div class="flex-1 overflow-hidden p-3">
          <div id="suggestionsBox" class="h-full overflow-auto whitespace-pre-wrap p-3"></div>
        </div>
        <textarea id="suggestionsRaw" class="hidden"></textarea>
      </div>
    </div>

    <!-- Horizontal resize handle between left column and right column -->
    <div id="resizeH" class="resize-h" title="Drag to resize"></div>

    <!-- RIGHT COLUMN: GPT Output (full height) -->
    <div id="outputWrap" class="flex-1 overflow-hidden">
      <div id="outputBox" class="gpt-card h-full flex flex-col overflow-hidden">
        <div class="gpt-card-header">
          <div>
            <div class="gpt-card-title">📋 GPT Output</div>
            <div class="gpt-card-subtitle"><span id="variantCountLabel">0</span> variant(s) generated · click <span class="font-mono text-xs bg-slate-100 px-1 py-0.5 rounded">📋 Use</span> per card to copy, or Copy All for tab-separated rows.</div>
          </div>
          <button onclick="copyOutput()" class="btn-secondary">📋 Copy All</button>
        </div>
        <div class="flex-1 overflow-auto p-3" id="outputCardsWrap">
          <div id="gptOutputBody">
            <div class="v-empty">Click 🚀 Generate to create ad copy variants. They'll appear here as cards.</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // STRICT rules used to be re-injected here, but they're already in the
    // base prompt file (gpt_ad_prompts.txt) — duplicating wasted tokens and
    // double-emphasized constraints, which collapsed multi-variant outputs
    // toward a single template. Removed 2026-05-03.

    // ===== Height management — fit the whole UI inside the viewport.
    // Layout = horizontal split (leftCol vs outputWrap) with handle in between.
    // leftCol is itself a vertical split (leftCard + sugCard).
    function computeLayoutHeights() {
      const root = document.getElementById('viewportFit');
      if (!root) return;
      const topOffset = root.getBoundingClientRect().top;
      const available = Math.max(480, Math.round(window.innerHeight - topOffset - 8));
      root.style.height = available + 'px';
    }

    // ===== Resizable panels =====
    function setupResizers() {
      const root = document.getElementById('viewportFit');
      const leftCol = document.getElementById('leftCol');
      const leftCard = document.getElementById('leftCard');
      const sugCard = document.getElementById('sugCard');
      const handleH = document.getElementById('resizeH');
      const handleV = document.getElementById('resizeV');

      // Horizontal: drag handleH to resize leftCol vs outputWrap.
      if (handleH && leftCol && root) {
        let dragging = false;
        handleH.addEventListener('mousedown', (e) => {
          dragging = true;
          handleH.classList.add('dragging');
          document.body.classList.add('is-resizing');
          e.preventDefault();
        });
        window.addEventListener('mousemove', (e) => {
          if (!dragging) return;
          const rect = root.getBoundingClientRect();
          const offset = e.clientX - rect.left;
          const min = 280, max = rect.width - 320;
          const newWidth = Math.min(max, Math.max(min, offset));
          leftCol.style.width = newWidth + 'px';
        });
        window.addEventListener('mouseup', () => {
          if (!dragging) return;
          dragging = false;
          handleH.classList.remove('dragging');
          document.body.classList.remove('is-resizing');
        });
      }

      // Vertical: drag handleV inside leftCol to resize leftCard vs sugCard.
      if (handleV && leftCard && sugCard && leftCol) {
        let dragging = false;
        handleV.addEventListener('mousedown', (e) => {
          dragging = true;
          handleV.classList.add('dragging');
          document.body.classList.add('is-resizing-v');
          e.preventDefault();
        });
        window.addEventListener('mousemove', (e) => {
          if (!dragging) return;
          const rect = leftCol.getBoundingClientRect();
          const offset = e.clientY - rect.top;
          const handleSize = 6;
          const min = 140, max = rect.height - 140 - handleSize;
          const topH = Math.min(max, Math.max(min, offset));
          const botH = rect.height - topH - handleSize;
          leftCard.style.height = topH + 'px';
          sugCard.style.height = botH + 'px';
        });
        window.addEventListener('mouseup', () => {
          if (!dragging) return;
          dragging = false;
          handleV.classList.remove('dragging');
          document.body.classList.remove('is-resizing-v');
        });
      }
    }

    window.addEventListener('resize', computeLayoutHeights);
    document.addEventListener('DOMContentLoaded', () => {
      computeLayoutHeights();
      setTimeout(computeLayoutHeights, 0);
      setTimeout(computeLayoutHeights, 200);
      setupResizers();
      if (typeof syncStreamCheckbox === 'function') syncStreamCheckbox();
    });

    // ===== Helpers =====
    function escapeHtml(s) {
      return String(s ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;");
    }

    // Streaming + multi-variants don't mix — disable the checkbox when N>1.
    function syncStreamCheckbox() {
      const n = parseInt(document.getElementById("variantsCount")?.value ?? "1", 10);
      const cb = document.getElementById("streamOutput");
      if (!cb) return;
      if (n !== 1) { cb.checked = false; cb.disabled = true; }
      else { cb.disabled = false; }
    }

    // Normalize a raw GPT variant string. GPT sometimes outputs literal
    // backslash-t / backslash-n (e.g. "Item \t Primary \t ...") instead of
    // real tabs/newlines. Convert to actual control chars so split + display
    // work correctly. Keep the original string accessible via data-raw too.
    function normalizeVariant(raw) {
      if (raw === null || raw === undefined) return "";
      return String(raw)
        .replace(/\\t/g, "\t")
        .replace(/\\n/g, "\n")
        .trim();
    }

    // Render an array of variant strings as cards. 3-column grid per card:
    //   col 1: Primary Text (top) + Headline (bottom)
    //   col 2: Messaging Template (preserves line breaks)
    //   col 3: Q1, Q2, Q3 stacked
    // "Use" button copies just that variant tab-separated.
    function renderVariants(variants) {
      const body = document.getElementById("gptOutputBody");
      const countLabel = document.getElementById("variantCountLabel");
      if (countLabel) countLabel.textContent = (variants && variants.length) || 0;

      if (!variants || variants.length === 0) {
        body.innerHTML = `<div class="v-empty" style="color:#dc2626;">⚠️ GPT did not return a result.</div>`;
        return;
      }

      body.innerHTML = variants.map((rawV, idx) => {
        const v = normalizeVariant(rawV);
        const parts = v.split("\t");
        const item    = parts[0] ?? "";
        const primary = parts[1] ?? "";
        const headline= parts[2] ?? "";
        const message = parts[3] ?? "";
        const q1      = parts[4] ?? "";
        const q2      = parts[5] ?? "";
        const q3      = parts[6] ?? "";

        const qrCell = (label, val) => val
          ? `<div class="v-qr"><strong style="opacity:0.65;font-size:10px;letter-spacing:0.04em;">${label}</strong> ${escapeHtml(val)}</div>`
          : `<div class="v-qr empty">${label} — empty —</div>`;

        return `
          <div class="v-card" data-variant="${idx}" data-raw="${escapeHtml(v)}">
            <div class="v-card-head">
              <div>
                <span class="v-no">Variant ${idx + 1}</span>
                <span class="v-item">${escapeHtml(item) || '<span style="color:#94a3b8;">(no item)</span>'}</span>
              </div>
              <button onclick="copyVariant(${idx})" class="btn-secondary" style="padding:4px 10px;font-size:11px;">
                📋 Use
              </button>
            </div>
            <div class="v-grid">
              <div class="v-cell">
                <div>
                  <div class="v-field-label">Primary Text</div>
                  <div class="v-field-block"><div class="v-field-value">${escapeHtml(primary)}</div></div>
                </div>
                <div>
                  <div class="v-field-label">Headline</div>
                  <div class="v-field-block"><div class="v-field-value">${escapeHtml(headline)}</div></div>
                </div>
              </div>
              <div class="v-cell">
                <div class="v-field-label">Messaging Template</div>
                <div class="v-field-block" style="flex:1;">
                  <div class="v-field-value preserve">${escapeHtml(message)}</div>
                </div>
              </div>
              <div class="v-cell">
                <div class="v-field-label">Quick Replies</div>
                <div class="v-qr-list">
                  ${qrCell('QR1', q1)}
                  ${qrCell('QR2', q2)}
                  ${qrCell('QR3', q3)}
                </div>
              </div>
            </div>
          </div>`;
      }).join("");
    }

    function copyVariant(idx) {
      const card = document.querySelector(`#gptOutputBody .v-card[data-variant="${idx}"]`);
      if (!card) return;
      const raw = card.dataset.raw || "";
      // Re-pack into tab-separated 7-field row using actual tabs.
      const parts = raw.split("\t");
      const seven = [0,1,2,3,4,5,6].map(i => parts[i] ?? "").join("\t");
      navigator.clipboard.writeText(seven).then(() => {
        const btn = card.querySelector("button");
        if (btn) { const old = btn.textContent; btn.textContent = "✅"; setTimeout(() => btn.textContent = old, 800); }
      });
    }

    // ===== Generate GPT Output =====
    async function generateGPTSummary() {
      const name = document.getElementById("productName").value.trim();
      const desc = document.getElementById("productDescription").value.trim();
      const customPrompt = document.getElementById("prompt").value.trim();
      const includeSug = document.getElementById("includeSuggestions").checked;
      const suggestions = (document.getElementById("suggestionsRaw").value || "").trim();
      const temperature = parseFloat(document.getElementById("temperature")?.value ?? "0.5");
      const variantsCount = parseInt(document.getElementById("variantsCount")?.value ?? "1", 10);
      const streamWanted = !!document.getElementById("streamOutput")?.checked && variantsCount === 1;

      const pageFilter   = document.getElementById("pageSelect")?.value ?? "";
      const itemFilter   = document.getElementById("itemSelect")?.value ?? "";
      const activeOnly   = !!document.getElementById("activeOnly")?.checked;
      const model        = document.getElementById("modelSelect")?.value ?? "";

      const outputBox = document.getElementById("outputBox");
      const loadingBox = document.getElementById("loadingBox");
      const outputBody = document.getElementById("gptOutputBody");

      if (!name || !desc || !customPrompt) {
        alert("Please fill in all inputs.");
        return;
      }

      // Final Prompt = base prompt (which already contains STRICT_MODE rules)
      // + optional suggestions + product info.
      const finalPrompt =
        customPrompt +
        (includeSug && suggestions ? `\n\n${suggestions}` : "") +
        `\n\nProduct Name: ${name}\nProduct Description: ${desc}`;

      const preview = document.getElementById('finalPromptPreview');
      if (preview) preview.value = finalPrompt;

      outputBox.classList.add("hidden");
      loadingBox.classList.remove("hidden");
      const loadingText = document.getElementById("loadingText");
      if (loadingText) loadingText.textContent = streamWanted ? "Streaming live…" : `Generating ${variantsCount} variant(s)…`;

      const body = {
        prompt: finalPrompt,
        temperature,
        n: variantsCount,
        stream: streamWanted,
        model,
        product_name: name,
        product_description: desc,
        page_filter: pageFilter && pageFilter !== "all" ? pageFilter : null,
        item_filter: itemFilter && itemFilter !== "all" ? itemFilter : null,
        active_only: activeOnly,
      };

      try {
        if (streamWanted) {
          await streamGenerate(body);
        } else {
          await jsonGenerate(body);
        }
        outputBox.classList.remove("hidden");
      } catch (error) {
        document.getElementById("gptOutputBody").innerHTML = `
          <tr>
            <td colspan="8" class="text-red-600 px-3 py-2">❌ Error occurred: ${escapeHtml(error.message)}</td>
          </tr>
        `;
        outputBox.classList.remove("hidden");
      } finally {
        loadingBox.classList.add("hidden");
        const loadingTextRef = document.getElementById("loadingText");
        if (loadingTextRef) loadingTextRef.textContent = "Generating…";
        computeLayoutHeights();
      }
    }

    // Non-streaming JSON path. Renders all returned variants.
    async function jsonGenerate(body) {
      const response = await fetch("/api/generate-gpt-summary", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": "{{ csrf_token() }}" },
        body: JSON.stringify(body),
      });

      if (response.status === 429) {
        document.getElementById("gptOutputBody").innerHTML = `
          <tr><td colspan="8" class="text-orange-600 px-3 py-2">⏳ Rate limit hit — 20 generations per hour max. Try again later.</td></tr>
        `;
        return;
      }

      const data = await response.json();
      const variants = Array.isArray(data.variants) && data.variants.length
        ? data.variants
        : (data.output ? [data.output] : []);
      renderVariants(variants);
    }

    // SSE streaming path. Updates a single output row live as deltas arrive.
    async function streamGenerate(body) {
      const response = await fetch("/api/generate-gpt-summary", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": "{{ csrf_token() }}" },
        body: JSON.stringify(body),
      });

      if (response.status === 429) {
        document.getElementById("gptOutputBody").innerHTML = `
          <tr><td colspan="8" class="text-orange-600 px-3 py-2">⏳ Rate limit hit — 20 generations per hour max. Try again later.</td></tr>
        `;
        return;
      }
      if (!response.ok || !response.body) {
        const t = await response.text();
        throw new Error(`HTTP ${response.status}: ${t.slice(0, 200)}`);
      }

      // Render placeholder row first.
      renderVariants([""]);

      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let accumulated = "";
      let buffer = "";

      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });

        // Split on SSE event boundaries.
        let idx;
        while ((idx = buffer.indexOf("\n\n")) !== -1) {
          const event = buffer.slice(0, idx);
          buffer = buffer.slice(idx + 2);
          for (const line of event.split("\n")) {
            const trimmed = line.trim();
            if (!trimmed.startsWith("data:")) continue;
            const payload = trimmed.slice(5).trim();
            if (payload === "[DONE]") continue;
            try {
              const parsed = JSON.parse(payload);
              if (parsed.delta) {
                accumulated += parsed.delta;
                renderVariants([accumulated]);
              } else if (parsed.error) {
                throw new Error(parsed.error);
              }
            } catch (e) {
              // ignore JSON parse errors on partial chunks
            }
          }
        }
      }
    }

    // ===== Date preset helper =====
    function applyDatePreset() {
      const sel = document.getElementById("datePreset");
      const from = document.getElementById("dateFrom");
      const to   = document.getElementById("dateTo");
      if (!sel || !from || !to) return;
      const today = new Date(new Date().toLocaleString("en-US", { timeZone: "Asia/Manila" }));
      const fmt = d => d.getFullYear() + "-" + String(d.getMonth()+1).padStart(2,"0") + "-" + String(d.getDate()).padStart(2,"0");
      let s, e = today;
      switch (sel.value) {
        case "last7":      s = new Date(today); s.setDate(today.getDate() - 7); break;
        case "last30":     s = new Date(today); s.setDate(today.getDate() - 30); break;
        case "last60":     s = new Date(today); s.setDate(today.getDate() - 60); break;
        case "last90":     s = new Date(today); s.setDate(today.getDate() - 90); break;
        case "thisMonth":  s = new Date(today.getFullYear(), today.getMonth(), 1); break;
        case "lastMonth":
          s = new Date(today.getFullYear(), today.getMonth() - 1, 1);
          e = new Date(today.getFullYear(), today.getMonth(), 0);
          break;
        case "custom":     return;  // don't auto-fill on custom
        default:           s = new Date(today); s.setDate(today.getDate() - 30);
      }
      from.value = fmt(s);
      to.value   = fmt(e);
    }
    // Initialize default range on page load
    document.addEventListener("DOMContentLoaded", applyDatePreset);

    // ===== Load Suggestions (separate scrollable box, still fed to GPT) =====
    async function loadAdCopySuggestions() {
      const btn = document.getElementById("btnLoadSuggestions");
      const page = (document.getElementById("pageSelect")?.value || "all").trim();
      const item = (document.getElementById("itemSelect")?.value || "all").trim();
      const activeOnly = document.getElementById("activeOnly")?.checked ? "1" : "0";
      const fromDate = document.getElementById("dateFrom")?.value || "";
      const toDate   = document.getElementById("dateTo")?.value || "";

      const box = document.getElementById("suggestionsBox");
      const raw = document.getElementById("suggestionsRaw");

      btn.disabled = true;
      const dateLabel  = fromDate && toDate ? `${fromDate} → ${toDate}` : "default";
      const scopeLabel = `Page: ${page} · Item: ${item} · ${activeOnly === "1" ? "Active only" : "All"} · ${dateLabel}`;
      const header = `=== Suggestions (${scopeLabel}) ===`;
      box.textContent = `⏳ Loading ad copy suggestions for ${scopeLabel}...`;
      raw.value = "";

      try {
        const qs = new URLSearchParams({ page, item, active_only: activeOnly });
        if (fromDate) qs.set("from_date", fromDate);
        if (toDate)   qs.set("to_date",   toDate);
        const res = await fetch(`/ad-copy-suggestions?${qs.toString()}`, {
          headers: { Accept: "application/json" },
        });

        const text = await res.text();
        let data;
        if (res.ok) {
          try {
            data = JSON.parse(text);
          } catch (e) {
            data = { output: `❌ JSON parse error: ${e.message}\n\n${text.slice(0, 800)}` };
          }
        } else if (res.status === 429) {
          data = { output: `⏳ Rate limit hit — please wait a moment then try again.` };
        } else {
          data = { output: `❌ HTTP ${res.status}: ${text.slice(0, 800)}` };
        }

        // Prepend fallback warning if backend had to fall back to all-time data.
        const fallbackBanner = data.fallback_used && data.fallback_reason
          ? `⚠️ ${data.fallback_reason}\n\n`
          : "";
        const finalSug = `${header}\n${fallbackBanner}${data.output ?? "⚠️ No output."}`;
        box.textContent = finalSug;
        raw.value = finalSug; // stored to feed into GPT
      } catch (error) {
        const msg = `⚠️ Error loading suggestions: ${error.message}`;
        box.textContent = msg;
        raw.value = msg;
      } finally {
        btn.disabled = false;
        computeLayoutHeights();
      }
    }

    function clearSuggestions() {
      document.getElementById("suggestionsBox").textContent = "";
      document.getElementById("suggestionsRaw").value = "";
      computeLayoutHeights();
    }

    function copySuggestions() {
      const text = document.getElementById("suggestionsBox").textContent.trim();
      if (!text) return alert("Nothing to copy.");
      navigator.clipboard.writeText(text).then(() => alert("✅ Suggestions copied!"));
    }

    // Copy ALL variants as multi-line tab-separated (1 row per variant).
    function copyOutput() {
      const cards = document.querySelectorAll("#gptOutputBody .v-card[data-variant]");
      if (!cards.length) return alert("Nothing to copy.");
      const lines = [...cards].map((c) => {
        const raw = c.dataset.raw || "";
        const parts = raw.split("\t");
        return [0,1,2,3,4,5,6].map((i) => parts[i] ?? "").join("\t");
      });
      navigator.clipboard.writeText(lines.join("\n")).then(() => {
        alert("✅ Copied " + lines.length + " variant(s) to clipboard!");
      });
    }

    // Save the custom prompt as a new version sa gpt_prompts table.
    async function saveCustomPrompt() {
      const status = document.getElementById("savePromptStatus");
      const prompt = (document.getElementById("prompt")?.value ?? "").trim();
      const note   = (document.getElementById("promptNote")?.value ?? "").trim();
      if (!prompt) {
        status.className = "save-prompt-status err";
        status.textContent = "⚠ Empty prompt — nothing to save.";
        return;
      }
      status.className = "save-prompt-status";
      status.textContent = "Saving…";
      try {
        const r = await fetch("{{ route('gpt.prompt.save') }}", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": "{{ csrf_token() }}" },
          body: JSON.stringify({ prompt, note: note || null }),
        });
        if (r.status === 403) {
          status.className = "save-prompt-status err";
          status.textContent = "❌ Login required to save.";
          return;
        }
        const data = await r.json();
        if (r.ok && data.ok) {
          status.className = "save-prompt-status ok";
          const by = data.saved_by ? ` by ${data.saved_by}` : "";
          status.textContent = `✅ Saved as version #${data.id}${by}.`;
          const nField = document.getElementById("promptNote");
          if (nField) nField.value = "";
        } else {
          status.className = "save-prompt-status err";
          status.textContent = "❌ " + (data.error || "Save failed");
        }
      } catch (e) {
        status.className = "save-prompt-status err";
        status.textContent = "❌ " + e.message;
      }
    }
  </script>
</x-layout>
