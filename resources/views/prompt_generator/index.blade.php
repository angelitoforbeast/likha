<x-layout>
  <x-slot name="title">Prompt Generator</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">🤖 Chatbot Prompt Generator</div></x-slot>

  <style>
    .pg-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .pg-label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:4px; }
    .pg-input, .pg-textarea, .pg-select { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-size:13px; }
    .pg-input:focus, .pg-textarea:focus, .pg-select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,.2); }
    .pg-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; background:#4f46e5; color:#fff; font-weight:600; font-size:14px; padding:10px 16px; border-radius:8px; width:100%; }
    .pg-btn:hover { background:#4338ca; } .pg-btn:disabled { opacity:.6; cursor:not-allowed; }
    .pg-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:6px 12px; border-radius:6px; border:1px solid #e2e8f0; }
    .pg-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }
    .pg-toggle { display:flex; border:1px solid #d1d5db; border-radius:8px; overflow:hidden; }
    .pg-toggle button { flex:1; padding:8px; font-size:13px; font-weight:600; background:#fff; color:#64748b; }
    .pg-toggle button.active { background:#4f46e5; color:#fff; }
    .pg-req::after { content:" *"; color:#ef4444; }
  </style>

  <div class="w-full p-2">
    <div class="flex flex-col lg:flex-row gap-4">

      {{-- ── FORM ── --}}
      <div class="pg-card p-4 lg:w-[440px] lg:flex-shrink-0 space-y-3">
        <div class="flex items-center justify-between">
          <div class="text-sm font-semibold text-slate-800">Inputs</div>
          <a href="{{ route('prompt.generator.history') }}" class="pg-btn-ghost">📜 History</a>
        </div>

        {{-- Mode toggle --}}
        <div>
          <span class="pg-label">Mode</span>
          <div class="pg-toggle" id="modeToggle">
            <button type="button" data-mode="template" class="active">📄 Template</button>
            <button type="button" data-mode="ai">✨ AI (with reference)</button>
          </div>
          <p id="modeHint" class="text-[11px] text-slate-400 mt-1">Template: mabilis, walang AI cost — direktang fill ng template.</p>
        </div>

        {{-- Model (AI only) --}}
        <div id="modelWrap" class="hidden">
          <span class="pg-label">AI Model</span>
          <select id="model" class="pg-select">
            @foreach($models as $key => $label)
              <option value="{{ $key }}" @if($key===$defaultModel) selected @endif>{{ $label }}</option>
            @endforeach
          </select>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <span class="pg-label pg-req">Shop Name</span>
            <input id="store_name" class="pg-input" maxlength="150" placeholder="e.g. Flashlight">
          </div>
          <div>
            <span class="pg-label">Language</span>
            <select id="language" class="pg-select">
              <option>Taglish</option><option>Filipino</option><option>English</option>
            </select>
          </div>
        </div>

        <div>
          <span class="pg-label pg-req">Product Name</span>
          <input id="product_name" class="pg-input" maxlength="250" placeholder="e.g. Head & Shoulders Cool Menthol">
        </div>

        <div>
          <span class="pg-label">Description</span>
          <textarea id="product_description" rows="3" class="pg-textarea" maxlength="6000" placeholder="Ano ang product, benefits..."></textarea>
        </div>

        <div>
          <span class="pg-label">Key Features</span>
          <textarea id="features" rows="3" class="pg-textarea" maxlength="4000" placeholder="✅ Feature 1&#10;✅ Feature 2"></textarea>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div><span class="pg-label">Price</span><input id="price" class="pg-input" maxlength="150" placeholder="e.g. P299"></div>
          <div><span class="pg-label">Payment Method</span><input id="payment_method" class="pg-input" maxlength="150" placeholder="e.g. COD"></div>
        </div>

        <div>
          <span class="pg-label">Promo / Offer</span>
          <textarea id="promo" rows="2" class="pg-textarea" maxlength="600" placeholder="e.g. Buy 1 Take 1 P299 Free Shipping"></textarea>
        </div>

        <div>
          <span class="pg-label">Delivery Time</span>
          <input id="delivery_time" class="pg-input" maxlength="400" placeholder="e.g. 3 to 6 days Luzon...">
        </div>

        <div>
          <span class="pg-label">Legitimacy Info</span>
          <textarea id="legitimacy_info" rows="2" class="pg-textarea" maxlength="1500" placeholder="Bakit legit ang store..."></textarea>
        </div>

        <div>
          <span class="pg-label">Additional Instructions</span>
          <textarea id="additional_instructions" rows="2" class="pg-textarea" maxlength="2000" placeholder="Iba pang tagubilin sa bot..."></textarea>
        </div>

        <button id="btnGenerate" type="button" class="pg-btn">✨ Generate Prompt</button>
      </div>

      {{-- ── OUTPUT ── --}}
      <div class="pg-card p-4 lg:flex-1 min-w-0 flex flex-col">
        <div class="flex items-center justify-between mb-2">
          <div class="text-sm font-semibold text-slate-800">Generated Prompt <span id="outMeta" class="text-[11px] font-normal text-slate-400"></span></div>
          <button id="btnCopy" type="button" class="pg-btn-ghost" disabled>📄 Copy</button>
        </div>
        <textarea id="output" class="pg-textarea flex-1 font-mono text-[12px] leading-relaxed" style="min-height:70vh;" readonly
                  placeholder="Punuin ang form sa kaliwa, tapos pindutin ang Generate. Lalabas dito ang prompt na ipe-paste mo sa chatbot."></textarea>
      </div>
    </div>
  </div>

  <script>
    const csrf = '{{ csrf_token() }}';
    let mode = 'template';

    const el = (id) => document.getElementById(id);
    function toast(msg, err){
      const t = document.createElement('div');
      t.textContent = msg;
      t.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9999;padding:10px 14px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.18);'
        + (err ? 'background:#fee2e2;color:#991b1b;' : 'background:#dcfce7;color:#166534;');
      document.body.appendChild(t);
      setTimeout(() => t.remove(), 3500);
    }

    // Mode toggle
    document.querySelectorAll('#modeToggle button').forEach(b => {
      b.addEventListener('click', () => {
        mode = b.dataset.mode;
        document.querySelectorAll('#modeToggle button').forEach(x => x.classList.toggle('active', x === b));
        el('modelWrap').classList.toggle('hidden', mode !== 'ai');
        el('modeHint').textContent = mode === 'ai'
          ? 'AI: OpenAI ang bubuo gamit ang template bilang reference (may cost, mas natural).'
          : 'Template: mabilis, walang AI cost — direktang fill ng template.';
      });
    });

    function collect(){
      return {
        mode,
        model: el('model').value,
        language: el('language').value,
        store_name: el('store_name').value.trim(),
        product_name: el('product_name').value.trim(),
        product_description: el('product_description').value.trim(),
        features: el('features').value.trim(),
        price: el('price').value.trim(),
        promo: el('promo').value.trim(),
        delivery_time: el('delivery_time').value.trim(),
        payment_method: el('payment_method').value.trim(),
        legitimacy_info: el('legitimacy_info').value.trim(),
        additional_instructions: el('additional_instructions').value.trim(),
      };
    }

    el('btnGenerate').addEventListener('click', async () => {
      const data = collect();
      if (!data.store_name || !data.product_name) { toast('Kailangan ang Shop Name at Product Name.', true); return; }
      const btn = el('btnGenerate');
      btn.disabled = true; btn.textContent = (mode === 'ai' ? '✨ AI is writing…' : '📄 Generating…');
      try {
        const res = await fetch('{{ route('prompt.generator.generate') }}', {
          method: 'POST',
          headers: { 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify(data),
        });
        const json = await res.json();
        if (!res.ok || !json.ok) { toast(json.message || 'Nabigo ang generate.', true); return; }
        el('output').value = json.output || '';
        el('btnCopy').disabled = !json.output;
        el('outMeta').textContent = '· ' + (json.mode === 'ai' ? ('AI: ' + (json.model || '')) : 'Template');
        if (json.warning) toast(json.warning, true); else toast('Na-generate! ✅');
      } catch (e) {
        toast('Error: ' + e.message, true);
      } finally {
        btn.disabled = false; btn.textContent = '✨ Generate Prompt';
      }
    });

    el('btnCopy').addEventListener('click', async () => {
      const v = el('output').value;
      if (!v) return;
      try { await navigator.clipboard.writeText(v); toast('Na-copy ang prompt! ✅'); }
      catch (e) { toast('Copy failed: ' + e.message, true); }
    });
  </script>
</x-layout>
