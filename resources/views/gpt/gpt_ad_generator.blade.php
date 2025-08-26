<x-layout>
  <x-slot name="heading">Ad Copy Generator</x-slot>

  <div class="max-w-5xl mx-auto space-y-6">
    {{-- Input Section --}}
    <div class="bg-white p-4 rounded shadow space-y-4">
      <div>
        <label class="block font-semibold">📦 Product Name</label>
        <input type="text" id="productName" class="w-full border rounded p-2"
               placeholder="e.g., Tactical Flashlight"
               value="Tactical Flashlight" />
      </div>

      <div>
        <label class="block font-semibold">📝 Product Description</label>
        <textarea id="productDescription" class="w-full border rounded p-2" rows="3"
                  placeholder="e.g., Rechargeable, heavy duty, super liwanag, waterproof">Rechargeable, Heavy Duty, Super liwanag, Waterproof, Pang emergency</textarea>
      </div>

      {{-- NEW: Page filter for suggestions --}}
      <div>
        <label class="block font-semibold">📄 Page (for suggestions)</label>
        <select id="pageSelect" class="w-full border rounded p-2">
          <option value="all">All Pages</option>
          @foreach ($pages as $p)
            <option value="{{ $p }}">{{ $p }}</option>
          @endforeach
        </select>
        <p class="text-xs text-gray-500 mt-1">This only affects “Load Ad Copy Suggestions.”</p>
      </div>

      <div>
        <label class="block font-semibold">✏️ Custom GPT Prompt (editable)</label>
        <textarea id="prompt" class="w-full border rounded p-2 text-sm" rows="10">{{ $promptText }}</textarea>
      </div>

      <div class="flex flex-wrap gap-3">
        <button onclick="generateGPTSummary()" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded">
          🚀 Generate GPT Output
        </button>

        <button id="btnLoadSuggestions" onclick="loadAdCopySuggestions()" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold px-4 py-2 rounded">
          💡 Load Ad Copy Suggestions
        </button>
      </div>
    </div>

    {{-- Output Section --}}
    <div id="outputBox" class="bg-white p-4 rounded shadow hidden relative">
      <div class="flex justify-between items-center mb-2">
        <h2 class="font-semibold text-lg text-gray-800">📋 GPT Output (Tabular View)</h2>
        <button onclick="copyOutput()" class="bg-green-600 hover:bg-green-700 text-white text-sm px-3 py-1 rounded">📋 Copy</button>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm border border-gray-300 text-left" id="gptOutputTable">
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

    {{-- Loading --}}
    <div id="loadingBox" class="text-center text-blue-600 font-medium hidden">Generating summary, please wait...</div>
  </div>

  <script>
    async function generateGPTSummary() {
      const name = document.getElementById("productName").value.trim();
      const desc = document.getElementById("productDescription").value.trim();
      const customPrompt = document.getElementById("prompt").value.trim();
      const outputBox = document.getElementById("outputBox");
      const loadingBox = document.getElementById("loadingBox");
      const outputBody = document.getElementById("gptOutputBody");

      if (!name || !desc || !customPrompt) {
        alert("Please fill in all inputs.");
        return;
      }

      const finalPrompt = `${customPrompt}\n\nProduct Name: ${name}\nProduct Description: ${desc}`;

      outputBox.classList.add("hidden");
      loadingBox.classList.remove("hidden");

      try {
        const response = await fetch('/api/generate-gpt-summary', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
          },
          body: JSON.stringify({ prompt: finalPrompt })
        });

        const data = await response.json();

        if (data.output) {
          const parts = data.output.split("\t");
          const [item, primary, headline, message, q1, q2, q3] = [
            parts[0] ?? "", parts[1] ?? "", parts[2] ?? "", parts[3] ?? "", parts[4] ?? "", parts[5] ?? "", parts[6] ?? ""
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
          outputBody.innerHTML = `<tr><td colspan="7" class="text-red-600 px-3 py-2">⚠️ GPT did not return a result.</td></tr>`;
          outputBox.classList.remove("hidden");
        }
      } catch (error) {
        outputBody.innerHTML = `<tr><td colspan="7" class="text-red-600 px-3 py-2">❌ Error occurred: ${error.message}</td></tr>`;
        outputBox.classList.remove("hidden");
      } finally {
        loadingBox.classList.add("hidden");
      }
    }

    function copyOutput() {
      const row = document.querySelector("#gptOutputBody tr");
      if (!row) return alert("Nothing to copy.");
      const cells = [...row.querySelectorAll("td")];
      const tabSeparated = cells.map(cell => cell.textContent.trim()).join("\t");
      navigator.clipboard.writeText(tabSeparated).then(() => {
        alert("✅ Copied to clipboard!");
      });
    }

    async function loadAdCopySuggestions() {
      const btn = document.getElementById('btnLoadSuggestions');
      const promptBox = document.getElementById("prompt");
      const original = promptBox.value;
      const page = document.getElementById("pageSelect").value;
      const loadingMsg = `⏳ Loading ad copy suggestions${page && page !== 'all' ? ' for page: ' + page : ''}...`;

      btn.disabled = true;
      promptBox.value = `${original}\n\n${loadingMsg}`;

      try {
        const qs = new URLSearchParams({ page });
        const response = await fetch(`/ad-copy-suggestions?${qs.toString()}`);
        const data = await response.json();
        if (data.output) {
          const header = (page && page !== 'all') ? `\n\n=== Suggestions (Page: ${page}) ===\n` : `\n\n=== Suggestions (All Pages) ===\n`;
          promptBox.value = `${original}${header}${data.output}`;
        } else {
          promptBox.value = `${original}\n\n⚠️ No suggestions returned.`;
        }
      } catch (error) {
        promptBox.value = `${original}\n\n❌ Error loading suggestions: ${error.message}`;
      } finally {
        btn.disabled = false;
      }
    }
  </script>
</x-layout>
