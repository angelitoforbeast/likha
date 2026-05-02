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

    /* Output table — fixed layout so cells wrap instead of forcing horizontal scroll */
    .gpt-output-wrap { width:100%; overflow-x:hidden; }
    .gpt-output-table {
      width:100%; table-layout:fixed; border-collapse:separate; border-spacing:0; font-size:12.5px;
    }
    .gpt-output-table thead th {
      position:sticky; top:0; z-index:1;
      background:#f8fafc; color:#475569; font-weight:600; font-size:10.5px;
      text-transform:uppercase; letter-spacing:0.04em;
      padding:9px 10px; border-bottom:2px solid #e2e8f0; text-align:left;
    }
    .gpt-output-table tbody td {
      padding:8px 10px; border-bottom:1px solid #f1f5f9; color:#0f172a;
      vertical-align:top; word-wrap:break-word; overflow-wrap:break-word; white-space:normal;
    }
    .gpt-output-table tbody tr:hover td { background:#f8fafc; }
    .gpt-output-table tbody tr:last-child td { border-bottom:none; }
    /* Column widths — proportional, last (action) is fixed */
    .gpt-output-table colgroup col.c-item    { width:9%; }
    .gpt-output-table colgroup col.c-primary { width:22%; }
    .gpt-output-table colgroup col.c-head    { width:14%; }
    .gpt-output-table colgroup col.c-msg     { width:21%; }
    .gpt-output-table colgroup col.c-qr      { width:9%; }
    .gpt-output-table colgroup col.c-action  { width:74px; }

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

  <!-- Viewport-fitting wrapper: height set via JS to avoid page scrollbars -->
  <div id="viewportFit" class="w-full flex flex-col gap-4 overflow-hidden">
    <!-- TOP: Left (Inputs) + Right (Suggestions). Form takes ~40%, suggestions ~60% on wide screens. -->
    <div id="topGrid" class="grid md:grid-cols-5 gap-4 overflow-hidden" style="height:auto;">
      <!-- LEFT: Inputs -->
      <div id="leftCard" class="gpt-card h-full flex flex-col overflow-hidden md:col-span-2">
        <div class="gpt-card-header">
          <div>
            <div class="gpt-card-title">⚙️ Generation Settings</div>
            <div class="gpt-card-subtitle">Configure the prompt + GPT model. Click Generate to run.</div>
          </div>
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
            <div class="gpt-section-label">Custom Prompt</div>
            <textarea id="prompt" class="gpt-textarea text-xs" rows="7">{{ $promptText }}</textarea>
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

      <!-- RIGHT: Suggestions -->
      <div id="sugCard" class="gpt-card h-full flex flex-col overflow-hidden md:col-span-3">
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

    <!-- BOTTOM: FULL-WIDTH OUTPUT TABLE -->
    <div id="outputWrap" class="flex-1 overflow-hidden" style="height:auto;">
      <div id="outputBox" class="gpt-card h-full flex flex-col overflow-hidden hidden">
        <div class="gpt-card-header">
          <div>
            <div class="gpt-card-title">📋 GPT Output</div>
            <div class="gpt-card-subtitle"><span id="variantCountLabel">0</span> variant(s) generated · click <span class="font-mono text-xs bg-slate-100 px-1 py-0.5 rounded">📋 Use</span> to copy a single row, or Copy All for all rows tab-separated.</div>
          </div>
          <button onclick="copyOutput()" class="btn-secondary">📋 Copy All</button>
        </div>
        <div class="flex-1 overflow-y-auto overflow-x-hidden gpt-output-wrap">
          <table class="gpt-output-table" id="gptOutputTable">
            <colgroup>
              <col class="c-item">
              <col class="c-primary">
              <col class="c-head">
              <col class="c-msg">
              <col class="c-qr">
              <col class="c-qr">
              <col class="c-qr">
              <col class="c-action">
            </colgroup>
            <thead>
              <tr>
                <th>Item</th>
                <th>Primary Text</th>
                <th>Headline</th>
                <th>Messaging Template</th>
                <th>QR 1</th>
                <th>QR 2</th>
                <th>QR 3</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody id="gptOutputBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <script>
    // ===== Strict rules injected into the final prompt so GPT follows Suggestions only =====
    const STRICT_RULES = `
[SUGGESTION-STRICT MODE]
- Use ONLY themes/messages that appear in the "=== Suggestions" block or in the Product Description.
- Do NOT mention colors, sizes, fit, materials, variants, bundles, warranty, COD, promos, or delivery details UNLESS explicitly present in Suggestions or Product Description.
- Prefer tone/phrasing of TOP-PERFORMING items. Avoid WORST list patterns.
- If Suggestions include "Welcome Message" and "QR1/QR2/QR3", base your "Messaging Template" and "Quick Reply 1–3" on them (rephrase ok, but keep same intent).
- Output must be a SINGLE LINE with 7 tab-separated fields, no extra text.
[/SUGGESTION-STRICT MODE]
`.trim();

    // ===== Height management to fit entire UI inside viewport (no page scrollbars) =====
    function computeLayoutHeights() {
      const root = document.getElementById('viewportFit');
      const topGrid = document.getElementById('topGrid');
      const outputWrap = document.getElementById('outputWrap');
      if (!root || !topGrid || !outputWrap) return;

      const topOffset = root.getBoundingClientRect().top;
      const gapFallback = 16; // ~gap-4
      const rootStyles = getComputedStyle(root);
      const rootGap = parseFloat(rootStyles.gap || rootStyles.rowGap || gapFallback) || gapFallback;

      const available = Math.max(480, Math.round(window.innerHeight - topOffset - 8));
      root.style.height = available + 'px';

      const topH = Math.max(260, Math.round(available * 0.56));
      const bottomH = Math.max(220, available - topH - rootGap);

      topGrid.style.height = topH + 'px';
      outputWrap.style.height = bottomH + 'px';
    }

    window.addEventListener('resize', computeLayoutHeights);
    document.addEventListener('DOMContentLoaded', () => {
      computeLayoutHeights();
      setTimeout(computeLayoutHeights, 0);
      setTimeout(computeLayoutHeights, 200);
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

    // Render an array of 7-tab-separated variant strings into output rows.
    // Each row gets a "Use this" copy button.
    function renderVariants(variants) {
      const body = document.getElementById("gptOutputBody");
      const countLabel = document.getElementById("variantCountLabel");
      if (countLabel) countLabel.textContent = (variants && variants.length) || 0;

      if (!variants || variants.length === 0) {
        body.innerHTML = `<tr><td colspan="8" style="color:#dc2626;text-align:center;padding:18px;">⚠️ GPT did not return a result.</td></tr>`;
        return;
      }
      body.innerHTML = variants.map((v, idx) => {
        const parts = (v || "").split("\t");
        const cells = [0,1,2,3,4,5,6].map(i => `<td>${escapeHtml(parts[i] ?? "")}</td>`).join("");
        return `
          <tr data-variant="${idx}">
            ${cells}
            <td class="text-center">
              <button onclick="copyVariant(${idx})"
                      class="btn-secondary"
                      style="padding:4px 10px;font-size:11px;">
                📋 Use
              </button>
            </td>
          </tr>`;
      }).join("");
    }

    function copyVariant(idx) {
      const row = document.querySelector(`#gptOutputBody tr[data-variant="${idx}"]`);
      if (!row) return;
      const cells = [...row.querySelectorAll("td")].slice(0, 7);
      const tabSeparated = cells.map(c => c.textContent.trim()).join("\t");
      navigator.clipboard.writeText(tabSeparated).then(() => {
        const btn = row.querySelector("button");
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

      // Final Prompt = base prompt + STRICT_RULES + optional suggestions + product info
      const finalPrompt =
        customPrompt +
        "\n\n" + STRICT_RULES +
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

    // ===== Load Suggestions (separate scrollable box, still fed to GPT) =====
    async function loadAdCopySuggestions() {
      const btn = document.getElementById("btnLoadSuggestions");
      const page = (document.getElementById("pageSelect")?.value || "all").trim();
      const item = (document.getElementById("itemSelect")?.value || "all").trim();
      const activeOnly = document.getElementById("activeOnly")?.checked ? "1" : "0";

      const box = document.getElementById("suggestionsBox");
      const raw = document.getElementById("suggestionsRaw");

      btn.disabled = true;
      const scopeLabel = `Page: ${page} · Item: ${item} · ${activeOnly === "1" ? "Active only" : "All-time"}`;
      const header = `=== Suggestions (${scopeLabel}) ===`;
      box.textContent = `⏳ Loading ad copy suggestions for ${scopeLabel}...`;
      raw.value = "";

      try {
        const qs = new URLSearchParams({ page, item, active_only: activeOnly });
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
    // Action column is excluded.
    function copyOutput() {
      const rows = document.querySelectorAll("#gptOutputBody tr[data-variant]");
      if (!rows.length) return alert("Nothing to copy.");
      const lines = [...rows].map((r) => {
        const cells = [...r.querySelectorAll("td")].slice(0, 7);
        return cells.map((c) => c.textContent.trim()).join("\t");
      });
      navigator.clipboard.writeText(lines.join("\n")).then(() => {
        alert("✅ Copied " + lines.length + " variant(s) to clipboard!");
      });
    }
  </script>
</x-layout>
