<x-layout>
  <x-slot name="title">Ad Captions</x-slot>
  <x-slot name="heading">Ad Copy Generator</x-slot>

  <!-- Viewport-fitting wrapper: height set via JS to avoid page scrollbars -->
  <div id="viewportFit" class="max-w-6xl mx-auto flex flex-col gap-4 overflow-hidden">
    <!-- TOP: Left (Inputs) + Right (Suggestions) -->
    <div id="topGrid" class="grid md:grid-cols-2 gap-4 overflow-hidden" style="height:auto;">
      <!-- LEFT: Inputs (scroll only inside) -->
      <div id="leftCard" class="bg-white p-3 md:p-4 rounded shadow h-full flex flex-col overflow-hidden">
        <div class="space-y-4 flex-1 overflow-auto pr-1">
          <div>
            <label class="block font-semibold">📦 Product Name</label>
            <input
              type="text"
              id="productName"
              class="w-full border rounded p-2 text-sm"
              placeholder="e.g., Tactical Flashlight"
              value="Tactical Flashlight"
            />
          </div>

          <div>
            <label class="block font-semibold">📝 Product Description</label>
            <textarea
              id="productDescription"
              class="w-full border rounded p-2 text-sm"
              rows="3"
              placeholder="e.g., Rechargeable, heavy duty, super liwanag, waterproof"
            >Rechargeable, Heavy Duty, Super liwanag, Waterproof, Pang emergency</textarea>
          </div>

          <!-- Page + Item filters for suggestions -->
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block font-semibold">📄 Page</label>
              <select id="pageSelect" class="w-full border rounded p-2 text-sm">
                <option value="all">All Pages</option>
                @foreach ($pages as $p)
                  <option value="{{ $p }}">{{ $p }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label class="block font-semibold">🛒 Item</label>
              <select id="itemSelect" class="w-full border rounded p-2 text-sm">
                <option value="all">All Items</option>
                @foreach ($items ?? [] as $i)
                  <option value="{{ $i }}">{{ $i }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <p class="text-xs text-gray-500 -mt-2">Filters affect “Load Ad Copy Suggestions”.</p>

          <div class="flex items-center gap-2">
            <input id="activeOnly" type="checkbox" class="h-4 w-4" checked />
            <label for="activeOnly" class="text-sm text-gray-700">
              Active ads only (currently running)
            </label>
          </div>

          <div class="flex items-center gap-2">
            <input id="includeSuggestions" type="checkbox" class="h-4 w-4" checked />
            <label for="includeSuggestions" class="text-sm text-gray-700">
              Include suggestions in the GPT prompt
            </label>
          </div>

          <div>
            <label class="block font-semibold">🤖 Model</label>
            <select id="modelSelect" class="w-full border rounded p-2 text-sm">
              @foreach (($models ?? []) as $mid => $mlabel)
                <option value="{{ $mid }}" @if(($defaultModel ?? '') === $mid) selected @endif>{{ $mlabel }}</option>
              @endforeach
            </select>
            <p class="text-xs text-gray-500 mt-1">Default = {{ $defaultModel ?? 'gpt-4o' }}. Switch para sa cheaper / better quality runs.</p>
          </div>

          <div>
            <label class="block font-semibold">🎨 Creativity (Temperature): <span id="tempVal">0.5</span></label>
            <input id="temperature" type="range" min="0" max="1" step="0.1" value="0.5"
                   oninput="document.getElementById('tempVal').textContent = this.value;"
                   class="w-full" />
            <p class="text-xs text-gray-500">0 = predictable / deterministic. 1 = more creative / varied.</p>
          </div>

          <div>
            <label class="block font-semibold">✏️ Custom GPT Prompt (editable)</label>
            <textarea
              id="prompt"
              class="w-full border rounded p-2 text-sm"
              rows="8"
            >{{ $promptText }}</textarea>
          </div>
        </div>

        <div class="pt-3 flex flex-wrap gap-3 items-center">
          <button
            onclick="generateGPTSummary()"
            class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded text-sm"
          >
            🚀 Generate GPT Output
          </button>

          <label class="flex items-center gap-1 text-sm">
            Variants:
            <select id="variantsCount" class="border rounded p-1 text-sm" onchange="syncStreamCheckbox()">
              <option value="1" selected>1</option>
              <option value="3">3</option>
              <option value="5">5</option>
            </select>
          </label>

          <label class="flex items-center gap-1 text-sm">
            <input id="streamOutput" type="checkbox" class="h-4 w-4" />
            Stream live (only when Variants = 1)
          </label>

          <button
            id="btnLoadSuggestions"
            onclick="loadAdCopySuggestions()"
            class="bg-purple-600 hover:bg-purple-700 text-white font-semibold px-4 py-2 rounded text-sm"
          >
            💡 Load Ad Copy Suggestions
          </button>

          <div
            id="loadingBox"
            class="ml-auto text-blue-600 font-medium hidden self-center text-sm"
            aria-live="polite"
          >
            Generating summary…
          </div>
        </div>

        <!-- Prompt Preview (debug) -->
        <details class="mt-2 text-sm">
          <summary class="cursor-pointer text-gray-600">👁 Preview final prompt (debug)</summary>
          <textarea id="finalPromptPreview" class="mt-2 w-full h-32 border rounded p-2 text-xs overflow-auto" readonly></textarea>
        </details>
      </div>

      <!-- RIGHT: Suggestions (same height as left; scroll only inside) -->
      <div id="sugCard" class="bg-white p-3 md:p-4 rounded shadow h-full flex flex-col overflow-hidden">
        <div class="flex items-center justify-between mb-2">
          <h2 class="font-semibold text-base md:text-lg text-gray-800">💡 Suggestions (auto-fed)</h2>
          <div class="flex gap-2">
            <button
              onclick="copySuggestions()"
              class="bg-gray-200 hover:bg-gray-300 text-gray-800 text-xs px-3 py-1 rounded"
            >
              📋 Copy
            </button>
            <button
              onclick="clearSuggestions()"
              class="bg-red-100 hover:bg-red-200 text-red-700 text-xs px-3 py-1 rounded"
            >
              🗑 Clear
            </button>
          </div>
        </div>

        <!-- Only the inside scrolls; card height controlled by JS -->
        <div
          id="suggestionsBox"
          class="flex-1 overflow-auto min-h-0 whitespace-pre-wrap text-sm text-gray-800 border rounded p-3"
        ></div>
        <textarea id="suggestionsRaw" class="hidden"></textarea>
      </div>
    </div>

    <!-- BOTTOM: FULL-WIDTH OUTPUT TABLE (fills remaining height; scroll inside) -->
    <div id="outputWrap" class="flex-1 overflow-hidden" style="height:auto;">
      <div id="outputBox" class="bg-white p-3 md:p-4 rounded shadow h-full flex flex-col overflow-hidden hidden relative">
        <div class="flex justify-between items-center mb-2">
          <h2 class="font-semibold text-base md:text-lg text-gray-800">📋 GPT Output (Tabular View)</h2>
          <button
            onclick="copyOutput()"
            class="bg-green-600 hover:bg-green-700 text-white text-xs md:text-sm px-3 py-1 rounded"
          >
            📋 Copy
          </button>
        </div>

        <div class="flex-1 overflow-auto min-h-0">
          <div class="overflow-auto">
            <table
              class="w-full table-auto text-xs md:text-sm border border-gray-200 text-left"
              id="gptOutputTable"
            >
              <thead class="bg-gray-100 text-gray-700">
                <tr>
                  <th class="border px-3 py-2">Item</th>
                  <th class="border px-3 py-2">Primary Text</th>
                  <th class="border px-3 py-2">Headline</th>
                  <th class="border px-3 py-2">Messaging Template</th>
                  <th class="border px-3 py-2">Quick Reply 1</th>
                  <th class="border px-3 py-2">Quick Reply 2</th>
                  <th class="border px-3 py-2">Quick Reply 3</th>
                  <th class="border px-2 py-2 w-20 text-center">Action</th>
                </tr>
              </thead>
              <tbody id="gptOutputBody"></tbody>
            </table>
          </div>
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
      if (!variants || variants.length === 0) {
        body.innerHTML = `<tr><td colspan="8" class="text-red-600 px-3 py-2">⚠️ GPT did not return a result.</td></tr>`;
        return;
      }
      body.innerHTML = variants.map((v, idx) => {
        const parts = (v || "").split("\t");
        const cells = [0,1,2,3,4,5,6].map(i => `<td class="border px-3 py-2">${escapeHtml(parts[i] ?? "")}</td>`).join("");
        return `
          <tr class="hover:bg-blue-50" data-variant="${idx}">
            ${cells}
            <td class="border px-2 py-2 text-center">
              <button onclick="copyVariant(${idx})"
                      class="bg-green-600 hover:bg-green-700 text-white text-xs px-2 py-1 rounded">
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
      loadingBox.textContent = streamWanted ? "Streaming live…" : `Generating ${variantsCount} variant(s)…`;

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
        loadingBox.textContent = "Generating summary…";
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
