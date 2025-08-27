<x-layout>
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

          <!-- Page filter for suggestions -->
          <div>
            <label class="block font-semibold">📄 Page (for suggestions)</label>
            <select id="pageSelect" class="w-full border rounded p-2 text-sm">
              <option value="all">All Pages</option>
              @foreach ($pages as $p)
                <option value="{{ $p }}">{{ $p }}</option>
              @endforeach
            </select>
            <p class="text-xs text-gray-500 mt-1">Affects only “Load Ad Copy Suggestions”.</p>
          </div>

          <div class="flex items-center gap-2">
            <input id="includeSuggestions" type="checkbox" class="h-4 w-4" checked />
            <label for="includeSuggestions" class="text-sm text-gray-700">
              Include suggestions in the GPT prompt
            </label>
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

        <div class="pt-3 flex flex-wrap gap-3">
          <button
            onclick="generateGPTSummary()"
            class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded text-sm"
          >
            🚀 Generate GPT Output
          </button>

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
    // ===== Height management to fit entire UI inside viewport (no page scrollbars) =====
    function computeLayoutHeights() {
      const root = document.getElementById('viewportFit');
      const topGrid = document.getElementById('topGrid');
      const outputWrap = document.getElementById('outputWrap');
      if (!root || !topGrid || !outputWrap) return;

      // Set the wrapper height to the remaining viewport space to avoid body scrollbars
      const topOffset = root.getBoundingClientRect().top;
      const gapFallback = 16; // ~gap-4
      const rootStyles = getComputedStyle(root);
      const rootGap = parseFloat(rootStyles.gap || rootStyles.rowGap || gapFallback) || gapFallback;

      const available = Math.max(480, Math.round(window.innerHeight - topOffset - 8)); // padding safety
      root.style.height = available + 'px';

      // Allocate vertical space: ~56% top (inputs+suggestions), rest for output table
      const topH = Math.max(260, Math.round(available * 0.56));
      const bottomH = Math.max(220, available - topH - rootGap);

      topGrid.style.height = topH + 'px';
      outputWrap.style.height = bottomH + 'px';
    }

    window.addEventListener('resize', computeLayoutHeights);
    document.addEventListener('DOMContentLoaded', () => {
      computeLayoutHeights();
      // Recompute after fonts/assets and any dynamic layout changes
      setTimeout(computeLayoutHeights, 0);
      setTimeout(computeLayoutHeights, 200);
    });

    // ===== Generate GPT Output =====
    async function generateGPTSummary() {
      const name = document.getElementById("productName").value.trim();
      const desc = document.getElementById("productDescription").value.trim();
      const customPrompt = document.getElementById("prompt").value.trim();
      const includeSug = document.getElementById("includeSuggestions").checked;
      const suggestions = (document.getElementById("suggestionsRaw").value || "").trim();

      const outputBox = document.getElementById("outputBox");
      const loadingBox = document.getElementById("loadingBox");
      const outputBody = document.getElementById("gptOutputBody");

      if (!name || !desc || !customPrompt) {
        alert("Please fill in all inputs.");
        return;
      }

      const finalPrompt =
        customPrompt +
        (includeSug && suggestions ? `\n\n${suggestions}` : "") +
        `\n\nProduct Name: ${name}\nProduct Description: ${desc}`;

      outputBox.classList.add("hidden");
      loadingBox.classList.remove("hidden");

      try {
        const response = await fetch("/api/generate-gpt-summary", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": "{{ csrf_token() }}",
          },
          body: JSON.stringify({ prompt: finalPrompt }),
        });

        const data = await response.json();

        if (data.output) {
          const parts = data.output.split("\t");
          const [item, primary, headline, message, q1, q2, q3] = [
            parts[0] ?? "",
            parts[1] ?? "",
            parts[2] ?? "",
            parts[3] ?? "",
            parts[4] ?? "",
            parts[5] ?? "",
            parts[6] ?? "",
          ];

          outputBody.innerHTML = `
            <tr class="hover:bg-blue-50">
              <td class="border px-3 py-2">${item}</td>
              <td class="border px-3 py-2">${primary}</td>
              <td class="border px-3 py-2">${headline}</td>
              <td class="border px-3 py-2">${message}</td>
              <td class="border px-3 py-2">${q1}</td>
              <td class="border px-3 py-2">${q2}</td>
              <td class="border px-3 py-2">${q3}</td>
            </tr>
          `;

          outputBox.classList.remove("hidden");
        } else {
          outputBody.innerHTML = `
            <tr>
              <td colspan="7" class="text-red-600 px-3 py-2">⚠️ GPT did not return a result.</td>
            </tr>
          `;
          outputBox.classList.remove("hidden");
        }
      } catch (error) {
        outputBody.innerHTML = `
          <tr>
            <td colspan="7" class="text-red-600 px-3 py-2">❌ Error occurred: ${error.message}</td>
          </tr>
        `;
        outputBox.classList.remove("hidden");
      } finally {
        loadingBox.classList.add("hidden");
        computeLayoutHeights(); // keep layout tight after render
      }
    }

    // ===== Load Suggestions (separate scrollable box, still fed to GPT) =====
    async function loadAdCopySuggestions() {
      const btn = document.getElementById("btnLoadSuggestions");
      const page = (document.getElementById("pageSelect")?.value || "all").trim();

      const box = document.getElementById("suggestionsBox");
      const raw = document.getElementById("suggestionsRaw");

      btn.disabled = true;
      const header = `=== Suggestions (Page: ${page}) ===`;
      box.textContent = `⏳ Loading ad copy suggestions for page: ${page}...`;
      raw.value = "";

      try {
        const qs = new URLSearchParams({ page });
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
        } else {
          data = { output: `❌ HTTP ${res.status}: ${text.slice(0, 800)}` };
        }

        const finalSug = `${header}\n${data.output ?? "⚠️ No output."}`;
        box.textContent = finalSug;
        raw.value = finalSug; // stored to feed into GPT
      } catch (error) {
        const msg = `⚠️ Error loading suggestions: ${error.message}`;
        box.textContent = msg;
        raw.value = msg;
      } finally {
        btn.disabled = false;
        computeLayoutHeights(); // ensure layout stays within viewport
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

    function copyOutput() {
      const row = document.querySelector("#gptOutputBody tr");
      if (!row) return alert("Nothing to copy.");
      const cells = [...row.querySelectorAll("td")];
      const tabSeparated = cells.map((cell) => cell.textContent.trim()).join("\t");
      navigator.clipboard.writeText(tabSeparated).then(() => {
        alert("✅ Copied to clipboard!");
      });
    }
  </script>
</x-layout>
