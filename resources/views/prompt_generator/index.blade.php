<x-layout>
  <x-slot name="title">Prompt Generator</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">🤖 Prompt Generator V2</div></x-slot>

  <style>
    .pg-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .pg-head { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-shrink:0; flex-wrap:wrap; }
    .pg-head h2 { font-size:14px; font-weight:700; color:#0f172a; margin:0; }
    .pg-sec { padding:0 0 14px; border-bottom:1px solid #f1f5f9; margin-bottom:14px; }
    .pg-sec:last-child { border-bottom:0; margin-bottom:0; }
    .pg-sec h3 { font-size:11px; text-transform:uppercase; letter-spacing:.1em; color:#6366f1; margin:0 0 10px; font-weight:700; }
    .pg-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .pg-field { display:flex; flex-direction:column; gap:4px; }
    .pg-field > span { font-size:11.5px; color:#475569; font-weight:600; }
    .pg-field input, .pg-field textarea, .pg-field select { width:100%; border:1px solid #d1d5db; border-radius:8px; padding:7px 9px; font-size:12.5px; font-family:inherit; }
    .pg-field input:focus, .pg-field textarea:focus, .pg-field select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,.18); }
    .pg-field textarea { resize:vertical; min-height:60px; line-height:1.4; }
    .btn { border:0; border-radius:8px; padding:8px 12px; font-weight:600; font-size:12.5px; cursor:pointer; background:#eef2ff; color:#4338ca; }
    .btn:hover { filter:brightness(.97); }
    .btn.primary { background:#4f46e5; color:#fff; } .btn.primary:hover { background:#4338ca; }
    .btn.ghost { background:#fff; border:1px solid #e2e8f0; color:#64748b; }
    .btn.add { width:100%; background:#eef2ff; color:#4338ca; border:1px dashed #a5b4fc; margin-top:8px; }
    .btn.danger { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:6px 9px; }
    .btn.star { background:#fff; border:1px solid #e2e8f0; color:#64748b; padding:6px 9px; }
    .btn.star.active { background:#eef2ff; border-color:#a5b4fc; color:#4338ca; }
    .note { padding:10px 12px; border:1px solid #c7d2fe; background:#eef2ff; border-radius:10px; color:#3730a3; font-size:12px; line-height:1.5; margin-bottom:14px; }
    .ai-panel { border:1px solid #c7d2fe; background:#f5f3ff; border-radius:12px; padding:12px; margin-bottom:16px; }
    .ai-panel-head { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; margin-bottom:10px; }
    .ai-panel h3 { margin:0; font-size:13px; color:#4338ca; font-weight:700; }
    .ai-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    @media(max-width:640px){ .ai-grid, .pg-grid, .mini-grid { grid-template-columns:1fr !important; } }
    .dropzone { border:1px dashed #a5b4fc; border-radius:10px; background:#fff; padding:14px; text-align:center; cursor:pointer; min-height:130px; display:flex; align-items:center; justify-content:center; overflow:hidden; }
    .dropzone:hover { border-color:#6366f1; background:#faf5ff; }
    .dropzone img { max-width:100%; max-height:190px; border-radius:8px; }
    .upload-copy { color:#64748b; font-size:12px; line-height:1.5; }
    .upload-copy strong { display:block; color:#0f172a; margin-bottom:4px; }
    .ai-controls { display:flex; flex-direction:column; gap:8px; justify-content:center; }
    .ai-status { font-size:11.5px; border-radius:8px; padding:8px 10px; background:#fff; border:1px solid #e2e8f0; color:#64748b; }
    .ai-status.busy { border-color:#a5b4fc; color:#4338ca; background:#f5f3ff; }
    .ai-status.success { border-color:#bbf7d0; color:#166534; background:#f0fdf4; }
    .ai-status.error { border-color:#fecaca; color:#991b1b; background:#fef2f2; }
    .bundle-card { border:1px solid #e5e7eb; border-radius:10px; background:#f8fafc; padding:10px; margin:8px 0; }
    .bundle-card.ai-filled { border-color:#86efac; }
    .bundle-top { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px; }
    .bundle-title { font-weight:700; font-size:12.5px; color:#0f172a; }
    .bundle-actions { display:flex; gap:6px; }
    .mini-grid { display:grid; grid-template-columns:1.2fr .9fr .9fr; gap:8px; }
    .ship-row { display:flex; align-items:center; gap:6px; margin-top:8px; flex-wrap:wrap; }
    .ship-row .ship-label { font-size:11.5px; color:#475569; font-weight:600; flex:0 0 auto; }
    .ship-row select, .ship-row input { border:1px solid #d1d5db; border-radius:8px; padding:6px 9px; font-size:12px; font-family:inherit; }
    .ship-row select:focus, .ship-row input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,.18); }
    .ship-row .ship-mode, .ship-row .ship-type { flex:0 0 auto; width:auto; }
    .ship-row .ship-amt { flex:0 1 90px; min-width:0; }
    .ship-row .ship-loc { flex:1 1 160px; min-width:0; }
    .reco { font-size:9px; font-weight:800; padding:2px 6px; border-radius:999px; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe; margin-left:6px; }
    .dynamic-box { border:1px solid #e5e7eb; background:#f8fafc; border-radius:10px; padding:10px; margin-top:8px; }
    .help { font-size:11px; color:#94a3b8; line-height:1.4; margin-top:4px; }
    .hidden { display:none !important; }
    .lock { font-size:10px; color:#6366f1; border:1px solid #c7d2fe; border-radius:999px; padding:3px 8px; background:#eef2ff; white-space:nowrap; }
    .field-flag { font-size:9px; font-weight:800; border-radius:999px; padding:2px 5px; margin-left:5px; vertical-align:middle; }
    .field-flag.review { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
    .field-flag.filled { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
    .pg-field.review input, .pg-field.review textarea, .pg-field.review select { border-color:#f87171; box-shadow:0 0 0 2px rgba(248,113,113,.12); }
    .pg-field.ai-filled input, .pg-field.ai-filled textarea, .pg-field.ai-filled select { border-color:#34d399; }
    .output { flex:1; width:100%; min-height:45vh; resize:none; background:#f8fafc; color:#0f172a; border:1px solid #e5e7eb; border-radius:10px; padding:12px; line-height:1.5; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; }
    .status-bar { display:flex; justify-content:space-between; gap:12px; color:#94a3b8; font-size:11.5px; margin-top:8px; }
    .status-bar .ok { color:#16a34a; } .status-bar .warn { color:#d97706; }
    .pg-tabs { display:flex; gap:3px; flex-wrap:wrap; }
    .pg-tab { padding:6px 11px; font-size:12.5px; font-weight:600; border-radius:8px; background:transparent; color:#64748b; border:1px solid transparent; cursor:pointer; }
    .pg-tab:hover { background:#f1f5f9; }
    .pg-tab.active { background:#eef2ff; color:#4338ca; border-color:#c7d2fe; }
    .pg-pane { display:flex; flex-direction:column; height:100%; min-height:0; }
    .pg-hidden { display:none !important; }
    .pane-actions { display:flex; align-items:center; gap:8px; margin-bottom:8px; flex-wrap:wrap; flex-shrink:0; }
    .seq-item { border:1px solid #e5e7eb; border-radius:10px; background:#f8fafc; padding:10px; margin-bottom:8px; }
    .seq-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
    .seq-num { font-size:11px; font-weight:700; color:#6366f1; }
    .seq-item pre, .reply-box { white-space:pre-wrap; word-break:break-word; font-family:inherit; font-size:12.5px; margin:0; color:#0f172a; line-height:1.5; }
    .reply-box { background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; padding:12px; overflow:auto; }
  </style>

  <div class="pt-16 flex flex-col lg:h-screen lg:overflow-hidden">
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row gap-3 p-3 lg:overflow-hidden">

      {{-- ── LEFT: INPUTS ── --}}
      <div class="pg-card flex flex-col lg:w-[55%] lg:flex-shrink-0 lg:min-h-0 lg:overflow-hidden">
        <div class="pg-head">
          <h2>1. Product &amp; Business Inputs</h2>
          <div class="flex items-center gap-2">
            <a href="{{ route('prompt.generator.settings.get') }}" class="btn ghost">⚙️ Settings</a>
            <a href="{{ route('prompt.generator.history') }}" class="btn ghost">📜 History</a>
            <button id="sampleBtn" type="button" class="btn ghost">Load Sample</button>
            <button id="clearBtn" type="button" class="btn ghost">Clear</button>
          </div>
        </div>
        <div class="flex-1 lg:min-h-0 lg:overflow-y-auto p-4">
          <p class="note"><strong>Paano gumagana:</strong> Naka-lock ang core prompt. Ang pricing at shipping ay conditional — tinatanggal ang hindi kailangan para walang conflicting rules.</p>

          {{-- AI auto-fill --}}
          <div class="ai-panel">
            <div class="ai-panel-head">
              <div>
                <h3>✨ AI Auto-Fill from Product Image</h3>
                <div class="help">Mag-upload ng product poster, price list, packaging, o promo creative. AI ang magfi-fill ng form (server-side — walang API key na kailangan).</div>
              </div>
              <span class="lock">IMAGE → FORM</span>
            </div>
            <div class="ai-grid">
              <div>
                <input id="imageUpload" type="file" accept="image/*" hidden>
                <div id="dropzone" class="dropzone">
                  <div id="uploadCopy" class="upload-copy"><strong>Click o i-drop ang image dito</strong>JPG, PNG, WEBP</div>
                  <img id="imagePreview" class="hidden" alt="preview">
                </div>
              </div>
              <div class="ai-controls">
                <button class="btn primary" id="analyzeImageBtn" type="button">✨ Analyze Image &amp; Auto-Fill</button>
                <button class="btn ghost" id="clearAIFlagsBtn" type="button">Clear AI Review Marks</button>
                <div id="aiStatus" class="ai-status">Wala pang na-analyze na image.</div>
              </div>
            </div>
          </div>

          {{-- Pricing --}}
          <section class="pg-sec">
            <h3>Pricing Setup</h3>
            <div class="pg-grid">
              <label class="pg-field"><span>Selling Type</span>
                <select id="sellingType"><option value="single">Single Selling Price</option><option value="bundles" selected>Bundle / Multiple Offers</option></select>
              </label>
              <label class="pg-field" id="singlePriceWrap"><span>Official Selling Price</span><input id="singlePrice" value="₱399"></label>
            </div>
            <div id="bundleMode">
              <div class="dynamic-box">
                <div><strong style="font-size:12.5px">Official Bundle Offers</strong><div class="help">Idagdag lang ang mga bundle na talagang inaalok. Tanggalin ang hindi ginagamit.</div></div>
                <div id="bundleList"></div>
                <button class="btn add" id="addBundleBtn" type="button">＋ Add Bundle</button>
                <div class="help">Recommended offer: automatic na middle bundle. Pwede mong i-override sa ★ Recommend.</div>
              </div>
            </div>
          </section>

          {{-- Shipping (single-price mode only; bundle mode sets shipping per bundle) --}}
          <section class="pg-sec" id="shippingSetupSection">
            <h3>Shipping Setup</h3>
            <div class="pg-grid">
              <label class="pg-field"><span>Shipping Mode</span>
                <select id="shippingMode"><option value="free">Free Shipping</option><option value="declared">Shipping Fee — Declared</option><option value="hidden" selected>Shipping Fee — Hidden Until Asked</option></select>
              </label>
              <label class="pg-field hidden" id="shippingFeeTypeWrap"><span>Shipping Fee Type</span>
                <select id="shippingFeeType"><option value="fixed">Fixed Amount</option><option value="location" selected>Depends on Location</option></select>
              </label>
              <label class="pg-field hidden" id="shippingAmountWrap"><span>Shipping Fee Amount</span><input id="shippingAmount" value="₱99"></label>
              <label class="pg-field hidden" id="shippingLocationTextWrap"><span>Location-Based Response</span><input id="shippingLocationText" value="May applicable shipping fee po depende sa delivery area ninyo."></label>
            </div>
            <div id="shippingExplanation" class="help"></div>
          </section>

          {{-- Store & Assistant --}}
          <section class="pg-sec"><h3>Store &amp; Assistant</h3><div class="pg-grid">
            <label class="pg-field"><span>Store Name</span><input data-key="STORE_NAME" type="text"></label>
            <label class="pg-field"><span>Assistant Name</span><input data-key="ASSISTANT_NAME" type="text"></label>
            <label class="pg-field"><span>Product Name</span><input data-key="PRODUCT_NAME" type="text"></label>
            <label class="pg-field"><span>Product Category</span><input data-key="PRODUCT_CATEGORY" type="text"></label>
            <label class="pg-field"><span>Language <span class="text-[10px] text-slate-400">(Main Flow / Sequence)</span></span><select id="language"><option>Taglish</option><option>Filipino</option><option>English</option></select></label>
          </div></section>

          {{-- Product Details --}}
          <section class="pg-sec"><h3>Product Details</h3><div class="pg-grid">
            <label class="pg-field"><span>Product Description</span><textarea data-key="PRODUCT_DESCRIPTION" rows="3"></textarea></label>
            <label class="pg-field"><span>Primary Benefit</span><textarea data-key="PRIMARY_BENEFIT" rows="3"></textarea></label>
            <label class="pg-field"><span>Key Benefits</span><textarea data-key="PRODUCT_BENEFITS" rows="3"></textarea></label>
            <label class="pg-field"><span>Key Features</span><textarea data-key="PRODUCT_FEATURES" rows="3"></textarea></label>
            <label class="pg-field"><span>Ingredients / Materials</span><textarea data-key="INGREDIENTS" rows="3"></textarea></label>
            <label class="pg-field"><span>How to Use</span><textarea data-key="HOW_TO_USE" rows="3"></textarea></label>
            <label class="pg-field"><span>Usage Tips</span><textarea data-key="USAGE_TIPS" rows="3"></textarea></label>
            <label class="pg-field"><span>Product Origin</span><input data-key="PRODUCT_ORIGIN" type="text"></label>
            <label class="pg-field"><span>Certification / Safety Info</span><textarea data-key="PRODUCT_CERTIFICATION" rows="3"></textarea></label>
          </div></section>

          {{-- Policies & Delivery + Bot Flow Loops → managed sa hiwalay na ⚙️ Settings page (protected defaults). --}}
          <div class="pg-sec" style="border-bottom:0;">
            <div class="help">Policies &amp; Delivery at Bot Flow Loops ay naka-set na sa <a href="{{ route('prompt.generator.settings.get') }}" style="color:#4f46e5;font-weight:600;">⚙️ Settings</a> (protected defaults). Automatic na kasama sa prompt at Copy for Sheet.</div>
          </div>

          {{-- Sales & Ordering --}}
          <section class="pg-sec"><h3>Sales &amp; Ordering</h3><div class="pg-grid">
            <label class="pg-field"><span>Promo Information</span><textarea data-key="PROMO_INFORMATION" rows="3"></textarea></label>
            <label class="pg-field"><span>Unit Name</span><input data-key="UNIT_NAME" type="text"></label>
            <label class="pg-field"><span>Quantity (pcs) — Sheet</span><input data-key="QUANTITY_PCS" type="text" placeholder="1"></label>
            <label class="pg-field"><span>Additional Order Fields</span><textarea data-key="ORDER_FIELDS" rows="3"></textarea></label>
          </div></section>

        </div>
      </div>

      {{-- ── RIGHT: OUTPUT (tabbed) ── --}}
      <div class="pg-card flex flex-col lg:flex-1 min-w-0 lg:min-h-0 lg:overflow-hidden">
        <div class="pg-head" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
          <div class="pg-tabs" id="pgTabs">
            <button class="pg-tab active" type="button" data-tab="sales">Sales Prompt</button>
            <button class="pg-tab" type="button" data-tab="aftersales">After-Sales</button>
            <button class="pg-tab" type="button" data-tab="mainflow">Main Flow</button>
            <button class="pg-tab" type="button" data-tab="sequence">Sequence</button>
            <button class="pg-tab" type="button" data-tab="test">Test</button>
          </div>
          <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
            <button class="btn primary" id="genAllBtn" type="button">⚡ Generate All</button>
            <button class="btn" id="copySheetBtn" type="button">📋 Copy for Sheet</button>
            <span id="genAllStatus" class="text-[11px] text-slate-400"></span>
          </div>
        </div>
        <div class="flex-1 lg:min-h-0 p-3">
          {{-- SALES --}}
          <div class="pg-pane" data-pane="sales">
            <div class="pane-actions">
              <button class="btn ghost" id="copyBtn" type="button">Copy All</button>
              <button class="btn ghost" id="copyBtn1" type="button">Copy 1</button>
              <button class="btn ghost" id="copyBtn2" type="button">Copy 2</button>
              <button class="btn ghost" id="downloadBtn" type="button">Download .txt</button>
              <button class="btn ghost" id="generateBtn" type="button">Regenerate</button>
            </div>
            <textarea id="output" class="output" spellcheck="false" readonly></textarea>
            <div class="status-bar"><span id="status">Ready.</span><span id="count"></span></div>
          </div>
          {{-- AFTER-SALES --}}
          <div class="pg-pane pg-hidden" data-pane="aftersales">
            <div class="pane-actions">
              <button class="btn ghost" id="copyAfterBtn" type="button">Copy All</button>
              <button class="btn ghost" id="copyAfterBtn1" type="button">Copy 1</button>
              <button class="btn ghost" id="copyAfterBtn2" type="button">Copy 2</button>
            </div>
            <textarea id="afterOutput" class="output" spellcheck="false" readonly></textarea>
          </div>
          {{-- MAIN FLOW --}}
          <div class="pg-pane pg-hidden" data-pane="mainflow">
            <div class="pane-actions">
              <button class="btn primary" id="genMainFlowBtn" type="button">✨ Generate Main Flow</button>
              <button class="btn ghost" id="copyMainFlowBtn" type="button">Copy</button>
              <span id="mainFlowStatus" class="text-[11.5px] text-slate-400"></span>
            </div>
            <textarea id="mainFlowOutput" class="output" spellcheck="false" placeholder="Pindutin ang Generate Main Flow — AI ang gagawa ng unang auto-reply (greeting + promo + benefits + CTA)."></textarea>
          </div>
          {{-- SEQUENCE --}}
          <div class="pg-pane pg-hidden" data-pane="sequence">
            <div class="pane-actions">
              <label class="text-[12px] text-slate-600 font-semibold">Messages:</label>
              <select id="seqCount" class="pg-select" style="width:auto;border:1px solid #d1d5db;border-radius:8px;padding:6px 8px;font-size:12.5px;">
                <option>1</option><option>2</option><option>3</option><option>4</option><option>5</option><option>6</option><option>7</option><option>8</option><option>9</option><option selected>10</option>
              </select>
              <label class="text-[12px] text-slate-600 font-semibold">Price %:</label>
              <input id="seqPricePct" type="number" min="0" max="100" value="30" title="Percentage ng messages na babanggit ng presyo" style="width:62px;border:1px solid #d1d5db;border-radius:8px;padding:6px 8px;font-size:12.5px;">
              <button class="btn primary" id="genSeqBtn" type="button">✨ Generate Sequence</button>
              <span id="seqStatus" class="text-[11.5px] text-slate-400"></span>
            </div>
            <div id="seqList" class="flex-1 overflow-auto"></div>
          </div>
          {{-- TEST --}}
          <div class="pg-pane pg-hidden" data-pane="test">
            <div class="pane-actions">
              <label class="text-[12px] text-slate-600 font-semibold">Prompt:</label>
              <select id="testTarget" class="pg-select" style="width:auto;border:1px solid #d1d5db;border-radius:8px;padding:6px 8px;font-size:12.5px;">
                <option value="sales">Sales Prompt</option><option value="aftersales">After-Sales</option>
              </select>
            </div>
            <textarea id="testInput" class="pg-input" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:12.5px;min-height:70px;" placeholder="Type a customer message… e.g. Magkano po? Legit ba kayo?"></textarea>
            <div class="pane-actions" style="margin-top:8px;"><button class="btn primary" id="testBtn" type="button">Send</button></div>
            <div id="testReply" class="reply-box flex-1" style="margin-top:4px;">— AI reply lalabas dito —</div>
          </div>
        </div>
      </div>

    </div>
  </div>

  {{-- Config script (Blade-rendered). Kept OUTSIDE the protected block below so csrf/routes resolve. --}}
  <script>
    window.PG_CONFIG = {
      csrf:        '{{ csrf_token() }}',
      analyzeUrl:  '{{ route('prompt.generator.analyze') }}',
      saveUrl:     '{{ route('prompt.generator.save') }}',
      mainflowUrl: '{{ route('prompt.generator.mainflow') }}',
      sequenceUrl: '{{ route('prompt.generator.sequence') }}',
      testUrl:     '{{ route('prompt.generator.test') }}',
      settingsGetUrl:   '{{ route('prompt.generator.settings.get') }}',
      settingsSaveUrl:  '{{ route('prompt.generator.settings.save') }}',
      settingsResetUrl: '{{ route('prompt.generator.settings.reset') }}',
    };
    window.PG_SETTINGS  = @json($settings ?? []);
    window.PG_DEFAULTS  = @json($defaults ?? []);
    window.PG_PROMPTREF = @json($promptRef ?? []);
    window.PG_LOCKED    = @json($locked ?? []);
    window.PG_TPL       = @json($templates ?? []);
  </script>

  {{-- Main script is wrapped below so the double-brace placeholder tokens in the master template stay literal. --}}
  @verbatim
  <script>
  (function(){
    const csrf = window.PG_CONFIG.csrf;

    // Full editable templates (galing ⚙️ Settings). Ang {{OFFERS_AND_POLICY}} marker ang pinapalitan
    // ng auto na pricing/shipping/policy sections. Isang field na lang bawat prompt (madaling i-edit sa ChatGPT).
    const OFFERS_MARKER = '{{OFFERS_AND_POLICY}}';
    function withMiddle(tpl, middle){
      return tpl.includes(OFFERS_MARKER) ? tpl.split(OFFERS_MARKER).join(middle) : (tpl + '\n\n' + middle);
    }
    function salesPromptText(data){
      const tpl=(window.PG_TPL && window.PG_TPL.sales_template) || '';
      const middle=[pricingSection(),shippingSection(),policySection(data)].join('\n\n');
      return fillPlaceholders(withMiddle(tpl, middle), data);
    }
    function afterSalesPromptText(data){
      const tpl=(window.PG_TPL && window.PG_TPL.aftersales_template) || '';
      const middle=[pricingSection(),shippingSection()].join('\n\n');
      return fillPlaceholders(withMiddle(tpl, middle), data);
    }

    const SAMPLE = {"STORE_NAME":"Ginhawa Naturals","ASSISTANT_NAME":"Mia","PRODUCT_NAME":"Herbal Comfort Oil","PRODUCT_CATEGORY":"External-use herbal massage oil","PRODUCT_DESCRIPTION":"A topical massage oil made for everyday body comfort and relaxing massage.","PRIMARY_BENEFIT":"Helps provide a soothing and relaxing massage experience for tired areas of the body.","PRODUCT_BENEFITS":"• Helps support a relaxing massage routine\n• Convenient for home use\n• Easy to apply to targeted body areas","PRODUCT_FEATURES":"Non-greasy feel, easy-to-use bottle, external use only.","INGREDIENTS":"Coconut oil, ginger extract, eucalyptus oil. Use only if these are the verified ingredients of the actual product.","HOW_TO_USE":"Apply a small amount to the desired external body area and massage gently. Follow the product label.","USAGE_TIPS":"Patch test first and avoid eyes, wounds, and irritated skin.","PRODUCT_ORIGIN":"Philippines","PRODUCT_CERTIFICATION":"Only state certifications actually verified by the seller. Sample: Product information is based on the official label.","WARRANTY_POLICY":"Damaged or incorrect items may be reported to customer support for verification and applicable replacement.","COVERAGE_AREA":"Nationwide delivery within the Philippines, subject to courier serviceability.","DELIVERY_TIME":"2 to 5 days Luzon, 5 to 10 days Visayas and Mindanao, 11 to 15 days Palawan, Sulu, Tawi-Tawi.","PAYMENT_METHOD":"Cash on Delivery (COD)","LEGITIMACY_INFO":"Orders are processed through the official store and shipped with trackable courier details.","PROMO_INFORMATION":"Current official bundle prices are promotional while the promotion is active.","AVAILABILITY_INFORMATION":"Available while current inventory lasts. Do not claim low stock unless confirmed.","ORDER_FIELDS":"Preferred landmark (if needed for delivery)","UNIT_NAME":"bottle","OPEN_PARCEL_POLICY":"No Open Before Payment. Customers may inspect the parcel after completing COD payment, subject to courier policy."};
    const SAMPLE_BUNDLES = [{"name":"Buy 1","qty":"1 bottle","price":"₱399","shipMode":"hidden","shipFeeType":"fixed","shipAmount":"₱99","shipLocationText":""},{"name":"Buy 2 Save More","qty":"2 bottles","price":"₱699","shipMode":"free","shipFeeType":"location","shipAmount":"","shipLocationText":""},{"name":"Family Bundle","qty":"3 bottles","price":"₱899","shipMode":"free","shipFeeType":"location","shipAmount":"","shipLocationText":""}];
    const LS_KEY = 'pg_v2_state';

    // Protected defaults galing sa server (⚙️ Settings tab, DB-backed). QUANTITY_PCS ay local default.
    // `let` para ma-update kapag nag-save/reset sa Settings.
    let POLICY_DEFAULTS = Object.assign({ QUANTITY_PCS: '1' }, window.PG_SETTINGS || {});
    // Protected (locked) keys — configurable sa ⚙️ Settings. HINDI hinahawakan ng Analyze Image
    // autofill (walang overwrite, walang REVIEW flag). Galing sa PG_LOCKED (DB-backed).
    const PROTECTED_KEYS = Array.isArray(window.PG_LOCKED) ? window.PG_LOCKED : Object.keys(window.PG_DEFAULTS || {});
    // force=true: palitan lagi. force=false: punan lang ang blangko (di sinisira ang na-edit ng user).
    function applyPolicyDefaults(force){
      Object.keys(POLICY_DEFAULTS).forEach(k=>{
        const el=document.querySelector('[data-key="'+k+'"]');
        if(el && (force || el.value.trim()==='')) el.value=POLICY_DEFAULTS[k];
      });
    }

    let bundles = [];
    let manualRecommended = null;
    let uploadedImageFile = null;
    let seqMessages = [];   // last-generated follow-up sequence (for Copy for Sheet)
    const $ = (id) => document.getElementById(id);

    function escapeHtml(s){return String(s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}

    function addBundle(data){
      data=data||{name:'',qty:'',price:''};
      bundles.push({id:crypto.randomUUID(),name:data.name||'',qty:data.qty||'',price:data.price||'',shipMode:data.shipMode||'free',shipFeeType:data.shipFeeType||'location',shipAmount:data.shipAmount||'',shipLocationText:data.shipLocationText||''});
      if(manualRecommended===null) autoRecommend();
      renderBundles(); generate();
    }
    function removeBundle(id){
      bundles=bundles.filter(b=>b.id!==id);
      if(manualRecommended===id) manualRecommended=null;
      if(!bundles.length) manualRecommended=null;
      autoRecommend(); renderBundles(); generate();
    }
    function autoRecommend(){
      if(manualRecommended || !bundles.length) return;
      const index=Math.floor((bundles.length-1)/2);
      manualRecommended=bundles[index]?.id || null;
    }
    function setRecommended(id){ manualRecommended=id; renderBundles(); generate(); }

    function renderBundles(){
      const list=$('bundleList'); list.innerHTML='';
      bundles.forEach((b,i)=>{
        const rec=b.id===manualRecommended;
        const card=document.createElement('div');
        card.className='bundle-card';
        card.innerHTML=`
          <div class="bundle-top">
            <div class="bundle-title">Bundle #${i+1} ${rec?'<span class="reco">RECOMMENDED</span>':''}</div>
            <div class="bundle-actions">
              <button class="btn star ${rec?'active':''}" data-reco="${b.id}">★</button>
              <button class="btn danger" data-remove="${b.id}">Remove</button>
            </div>
          </div>
          <div class="mini-grid">
            <label class="pg-field"><span>Name</span><input data-bundle="${b.id}" data-prop="name" value="${escapeHtml(b.name)}"></label>
            <label class="pg-field"><span>Quantity</span><input data-bundle="${b.id}" data-prop="qty" value="${escapeHtml(b.qty)}"></label>
            <label class="pg-field"><span>Price</span><input data-bundle="${b.id}" data-prop="price" value="${escapeHtml(b.price)}"></label>
          </div>
          <div class="ship-row">
            <span class="ship-label">Shipping</span>
            <select class="ship-mode" data-bundle="${b.id}" data-prop="shipMode">
              <option value="free" ${(b.shipMode||'free')==='free'?'selected':''}>Free</option>
              <option value="declared" ${b.shipMode==='declared'?'selected':''}>Fee — Declared</option>
              <option value="hidden" ${b.shipMode==='hidden'?'selected':''}>Fee — Hidden Until Asked</option>
            </select>
            <select class="ship-type ${(b.shipMode||'free')==='free'?'pg-hidden':''}" data-bundle="${b.id}" data-prop="shipFeeType">
              <option value="fixed" ${b.shipFeeType==='fixed'?'selected':''}>Fixed</option>
              <option value="location" ${(b.shipFeeType||'location')==='location'?'selected':''}>By Location</option>
            </select>
            <input class="ship-amt ${((b.shipMode||'free')!=='free'&&b.shipFeeType==='fixed')?'':'pg-hidden'}" data-bundle="${b.id}" data-prop="shipAmount" value="${escapeHtml(b.shipAmount||'')}" placeholder="₱99">
            <input class="ship-loc ${((b.shipMode||'free')!=='free'&&(b.shipFeeType||'location')==='location')?'':'pg-hidden'}" data-bundle="${b.id}" data-prop="shipLocationText" value="${escapeHtml(b.shipLocationText||'')}" placeholder="Depende sa delivery area...">
          </div>`;
        list.appendChild(card);
      });
      list.querySelectorAll('[data-bundle]').forEach(el=>el.addEventListener('input',e=>{
        const b=bundles.find(x=>x.id===e.target.dataset.bundle);
        if(!b) return;
        b[e.target.dataset.prop]=e.target.value.trim();
        if(e.target.dataset.prop==='shipMode'||e.target.dataset.prop==='shipFeeType'){ renderBundles(); }
        generate();
      }));
      list.querySelectorAll('[data-remove]').forEach(el=>el.onclick=()=>removeBundle(el.dataset.remove));
      list.querySelectorAll('[data-reco]').forEach(el=>el.onclick=()=>setRecommended(el.dataset.reco));
    }

    function fieldValues(){
      const data={};
      document.querySelectorAll('[data-key]').forEach(el=>data[el.dataset.key]=el.value.trim());
      // Protected defaults (Policies & Delivery + LOOP 1/2) galing sa ⚙️ Settings (PG_SETTINGS) —
      // wala nang form field ang mga ito, kaya kunin dito para may laman ang prompt + Copy for Sheet.
      const s=window.PG_SETTINGS||{};
      Object.keys(s).forEach(k=>{ if(data[k]==null || data[k]==='') data[k]=s[k]; });
      return data;
    }
    function fillPlaceholders(text,data){
      return text.replace(/\{\{([A-Z0-9_]+)\}\}/g,(_,key)=>data[key]||`[NOT PROVIDED: ${key}]`);
    }

    function pricingSection(){
      const type=$('sellingType').value;
      if(type==='single'){
        const price=$('singlePrice').value.trim() || '[NOT PROVIDED: SELLING PRICE]';
        return `# PRICING & OFFER RULES\n\nThere is only **one official selling price** for this product.\n\n**Official Selling Price:** ${price}\n\nDo not create, suggest, calculate, or imply any bundle, custom quantity price, per-piece price, discount, or alternate offer unless explicitly provided elsewhere in this prompt.\n\nWhen the customer asks:\n- HM\n- How much\n- Magkano\n- Price\n- Presyo\n\nAnswer the official selling price clearly and directly.\n\nIf the customer wants to order multiple units, do not invent a volume discount. Confirm the quantity and use only the official pricing information available.\n\nThere is no preferred bundle because this product uses a single selling price.\n\n---`;
      }
      if(!bundles.length){
        return `# PRICING & OFFER RULES\n\nNo official bundle or selling-price configuration has been provided.\n\nDo not invent any price, bundle, quantity offer, discount, or promotion.\n\nIf the customer asks for price, explain that the confirmed pricing information is not currently available.\n\n---`;
      }
      const clean=bundles.map((b,i)=>({...b,index:i+1}));
      const recommended=clean.find(b=>b.id===manualRecommended) || clean[Math.floor((clean.length-1)/2)];
      const offers=clean.map(b=>`### Offer ${b.index}\nName: ${b.name||'[NOT PROVIDED]'}\nQuantity: ${b.qty||'[NOT PROVIDED]'}\nPrice: ${b.price||'[NOT PROVIDED]'}\nShipping: ${bundleShipSummary(b)}${b.id===recommended?.id?'\nStatus: RECOMMENDED OFFER':''}`).join('\n\n');
      const allowed=clean.map(b=>b.qty).filter(Boolean).join(', ') || '[NOT PROVIDED]';
      return `# OFFICIAL OFFERS\n\nOnly use the official offers below.\n\nNever invent a custom bundle, price, quantity, discount, freebie, or promotion.\n\n${offers}\n\n**Allowed Quantity Choices:** ${allowed}\n\n---\n\n# RECOMMENDED OFFER LOGIC\n\nThe preferred offer is:\n\n**${recommended?.name||'Recommended Offer'} — ${recommended?.qty||''} — ${recommended?.price||''}**\n\nWhen the customer:\n- asks which offer is the best value,\n- asks what you recommend,\n- seems undecided between offers,\n- asks which bundle is most practical,\n\nnaturally recommend the preferred offer above.\n\nDo not force the recommended offer when the customer clearly requests another specific quantity or bundle.\n\nNever auto-upgrade the customer's order.\n\nIf the customer asks for a specific quantity, match it only to the official offer that corresponds to that quantity.\n\nIf the requested quantity does not match an official offer, politely explain the available official choices instead of calculating a custom price.\n\n---\n\n# PRICING QUESTIONS\n\nWhen the customer asks:\n- HM\n- How much\n- Magkano\n- Price\n- Presyo\n\nAnswer clearly using only the official offers above.\n\nIf they ask generally and do not specify quantity, you may mention the available offers and naturally highlight the recommended offer.\n\nDo not bury the price inside a long sales message.\n\n---`;
    }

    // Per-bundle shipping (full solo-style modes) — short summary for OFFICIAL OFFERS.
    function bundleShipSummary(b){
      const mode=b.shipMode||'free';
      if(mode==='free') return 'FREE';
      if(mode==='hidden') return 'Not disclosed unless asked (see Shipping Rule)';
      const ft=b.shipFeeType||'location';
      return ft==='fixed' ? (b.shipAmount||'[NOT PROVIDED]') : 'Depends on delivery location';
    }
    // Per-bundle shipping — full rule text for the SHIPPING RULE section.
    function bundleShipRule(b){
      const mode=b.shipMode||'free';
      const label=`${b.name||'[Offer]'}${b.qty?` (${b.qty})`:''}`;
      if(mode==='free') return `### ${label}\nShipping is FREE for this offer.`;
      const ft=b.shipFeeType||'location';
      const fee = ft==='fixed'
        ? `a shipping fee of **${b.shipAmount||'[NOT PROVIDED]'}**`
        : `a shipping fee that depends on the delivery location: **${b.shipLocationText||'Shipping fee depends on the delivery area.'}**`;
      if(mode==='declared') return `### ${label}\nThis offer has ${fee}. You may mention it whenever it is relevant to pricing, ordering, or delivery. Never invent or change the fee.`;
      return `### ${label}\nThis offer has ${fee}. Do NOT volunteer this shipping fee. Only state it if the customer directly asks about shipping, shipping fee, SF, or delivery fee. Never falsely say shipping is free for this offer.`;
    }
    function shippingSection(){
      // Bundle mode → shipping is defined PER OFFER (each bundle uses full solo-style modes).
      if($('sellingType').value==='bundles' && bundles.length){
        const rules=bundles.map(bundleShipRule).join('\n\n');
        const anyHidden=bundles.some(b=>(b.shipMode||'free')==='hidden');
        const anyFree=bundles.some(b=>(b.shipMode||'free')==='free');
        let guide='';
        if(anyHidden) guide+='\n- For any offer above marked "do not volunteer", never bring up its shipping fee proactively — answer honestly only when the customer directly asks. Never deny the fee exists when asked.';
        if(anyFree) guide+='\n- You may highlight a FREE-shipping offer as an incentive to encourage a bigger bundle, when relevant.';
        return `# SHIPPING RULE\n\nShipping is defined PER OFFER. Always use only the shipping that matches the specific offer the customer is considering.\n\n${rules}\n\n**General shipping rules:**\n- Never quote a shipping fee for the wrong offer.\n- Never invent a shipping fee that is not listed above.${guide}\n\n---`;
      }
      const mode=$('shippingMode').value;
      const feeType=$('shippingFeeType').value;
      const amount=$('shippingAmount').value.trim();
      const locText=$('shippingLocationText').value.trim();
      if(mode==='free'){
        return `# SHIPPING RULE\n\nShipping is **FREE**.\n\nYou do not need to proactively mention free shipping in every reply.\n\nIf the customer asks about shipping, shipping fee, SF, or delivery fee, clearly explain that shipping is free.\n\nNever invent any shipping charge.\n\n---`;
      }
      const feeInfo = feeType==='fixed'
        ? `The official shipping fee is **${amount||'[NOT PROVIDED]'}**.`
        : `The shipping fee depends on the customer's delivery location. Use this verified response when needed: **${locText||'Shipping fee depends on the delivery area.'}**`;
      if(mode==='declared'){
        return `# SHIPPING RULE\n\nThere is a shipping fee.\n\n${feeInfo}\n\nThe shipping fee may be mentioned whenever it is relevant to pricing, ordering, or delivery.\n\nIf the customer asks about shipping, shipping fee, SF, or delivery fee, answer clearly using only the verified shipping information above.\n\nNever invent or calculate a shipping fee that is not provided.\n\n---`;
      }
      return `# SHIPPING RULE\n\nThere is a shipping fee, but it should **NOT be proactively disclosed during the initial sales conversation unless the customer asks about shipping or the fee becomes necessary to complete the order**.\n\n${feeInfo}\n\nBefore the customer asks:\n- Do not volunteer the shipping fee.\n- Do not include it in ordinary price replies.\n- Do not falsely say shipping is free.\n- Do not imply that the product price already includes shipping unless explicitly verified.\n\nIf the customer directly asks about:\n- shipping,\n- shipping fee,\n- SF,\n- delivery fee,\n- whether there are additional delivery charges,\n\nanswer honestly using only the verified shipping information above.\n\nNever deny the existence of the shipping fee when directly asked.\n\nNever invent a different fee.\n\n---`;
    }

    function policySection(data){
      return `# DELIVERY QUESTIONS\n\nIf asked when the item will arrive, use:\n\n**${data.DELIVERY_TIME||'[NOT PROVIDED: DELIVERY_TIME]'}**\n\nMake it clear that actual delivery may depend on location when applicable.\n\nNever guarantee an exact arrival date unless verified information explicitly allows it.\n\n---\n\n# PAYMENT\n\nThe official payment method is:\n\n**${data.PAYMENT_METHOD||'[NOT PROVIDED: PAYMENT_METHOD]'}**\n\nDo not offer unsupported payment methods.\n\n---\n\n# OPEN-PARCEL POLICY\n\nFollow this policy exactly:\n\n**${data.OPEN_PARCEL_POLICY||'[NOT PROVIDED: OPEN_PARCEL_POLICY]'}**\n\nDo not contradict this policy.\n\nNever tell the customer that opening before payment is allowed unless the provided policy specifically says so.\n\n---`;
    }

    // ── Verification code (prompt fingerprint) — template galing sa ⚙️ Settings (editable). ──
    // Placeholders: {{STORE_NAME}}, {{PRODUCT_NAME}}, {{VERIFY_STAMP}} (date-time). Kung blangko → walang section.
    function verifyStamp(){
      const d=new Date(), p=n=>String(n).padStart(2,'0');
      return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());
    }
    function verificationSection(data){
      const tpl=(window.PG_TPL && window.PG_TPL.verify) || '';
      if(!tpl.trim()) return '';
      return tpl.replace(/\{\{STORE_NAME\}\}/g, data.STORE_NAME||'[STORE]')
                .replace(/\{\{PRODUCT_NAME\}\}/g, data.PRODUCT_NAME||'[ITEM]')
                .replace(/\{\{VERIFY_STAMP\}\}/g, verifyStamp());
    }

    function generate(){
      const data=fieldValues();
      const prompt=[salesPromptText(data),verificationSection(data)].filter(Boolean).join('\n\n');
      $('output').value=prompt;
      $('count').textContent=prompt.length.toLocaleString()+' characters';
      // After-Sales prompt (deterministic, reuses pricing/shipping sections)
      if($('afterOutput')) $('afterOutput').value=[afterSalesPromptText(data),verificationSection(data)].filter(Boolean).join('\n\n');
      const missing=(prompt.match(/\[NOT PROVIDED:[^\]]+\]/g)||[]).length;
      const st=$('status');
      if(missing){st.className='warn';st.textContent='Generated — may '+missing+' missing value(s).';}
      else{st.className='ok';st.textContent='Complete — conditional rules generated, master structure preserved.';}
      saveState();
    }

    function syncPricingUI(){
      const bundlesMode=$('sellingType').value==='bundles';
      $('bundleMode').classList.toggle('hidden',!bundlesMode);
      $('singlePriceWrap').classList.toggle('hidden',bundlesMode);
      // In bundle mode, shipping is set per bundle → hide the global Shipping Setup.
      if($('shippingSetupSection')) $('shippingSetupSection').classList.toggle('hidden',bundlesMode);
      generate();
    }
    function syncShippingUI(){
      const mode=$('shippingMode').value;
      const needsFee=mode!=='free';
      const feeType=$('shippingFeeType').value;
      $('shippingFeeTypeWrap').classList.toggle('hidden',!needsFee);
      $('shippingAmountWrap').classList.toggle('hidden',!(needsFee&&feeType==='fixed'));
      $('shippingLocationTextWrap').classList.toggle('hidden',!(needsFee&&feeType==='location'));
      const ex=$('shippingExplanation');
      ex.textContent = mode==='free' ? 'Customer asks about shipping → sasabihin na free.' :
        mode==='declared' ? 'Pwedeng banggitin ang shipping fee kapag relevant.' :
        'Huwag ibunyag ang shipping fee sa umpisa. Kung magtanong, sagutin nang totoo.';
      generate();
    }

    // ── Persist / restore (localStorage) ──
    function saveState(){
      try {
        const st={ fields:fieldValues(), bundles, manualRecommended,
          sellingType:$('sellingType').value, singlePrice:$('singlePrice').value,
          shippingMode:$('shippingMode').value, shippingFeeType:$('shippingFeeType').value,
          shippingAmount:$('shippingAmount').value, shippingLocationText:$('shippingLocationText').value,
          language:($('language')||{}).value, seqCount:($('seqCount')||{}).value,
          seqPricePct:($('seqPricePct')||{}).value };
        localStorage.setItem(LS_KEY, JSON.stringify(st));
      } catch(e){}
    }
    function restoreState(){
      let st=null; try { st=JSON.parse(localStorage.getItem(LS_KEY)||'null'); } catch(e){}
      if(!st) return false;
      if(st.fields) document.querySelectorAll('[data-key]').forEach(el=>{ if(st.fields[el.dataset.key]!=null) el.value=st.fields[el.dataset.key]; });
      if(st.sellingType) $('sellingType').value=st.sellingType;
      if(st.singlePrice!=null) $('singlePrice').value=st.singlePrice;
      if(st.shippingMode) $('shippingMode').value=st.shippingMode;
      if(st.shippingFeeType) $('shippingFeeType').value=st.shippingFeeType;
      if(st.shippingAmount!=null) $('shippingAmount').value=st.shippingAmount;
      if(st.shippingLocationText!=null) $('shippingLocationText').value=st.shippingLocationText;
      if(st.language && $('language')) $('language').value=st.language;
      if(st.seqCount && $('seqCount')) $('seqCount').value=st.seqCount;
      if(st.seqPricePct!=null && st.seqPricePct!=='' && $('seqPricePct')) $('seqPricePct').value=st.seqPricePct;
      bundles=Array.isArray(st.bundles)?st.bundles.map(b=>({id:b.id||crypto.randomUUID(),name:b.name||'',qty:b.qty||'',price:b.price||'',shipMode:['free','declared','hidden'].includes(b.shipMode)?b.shipMode:(b.shipMode==='fee'?'declared':'free'),shipFeeType:['fixed','location'].includes(b.shipFeeType)?b.shipFeeType:'location',shipAmount:b.shipAmount||'',shipLocationText:b.shipLocationText||''})):[];
      manualRecommended=st.manualRecommended||null;
      renderBundles(); syncPricingUI(); syncShippingUI(); generate();
      return true;
    }

    function loadSample(){
      document.querySelectorAll('[data-key]').forEach(el=>el.value=SAMPLE[el.dataset.key]??'');
      $('sellingType').value='bundles'; $('singlePrice').value='₱399';
      $('shippingMode').value='hidden'; $('shippingFeeType').value='location';
      $('shippingAmount').value='₱99'; $('shippingLocationText').value='May applicable shipping fee po depende sa delivery area ninyo.';
      bundles=[]; SAMPLE_BUNDLES.forEach(x=>bundles.push({id:crypto.randomUUID(),...x}));
      manualRecommended=bundles[Math.floor((bundles.length-1)/2)]?.id||null;
      renderBundles(); syncPricingUI(); syncShippingUI(); applyPolicyDefaults(true); generate();
    }
    function clearFields(){
      document.querySelectorAll('[data-key]').forEach(el=>el.value='');
      $('sellingType').value='single'; $('singlePrice').value='';
      $('shippingMode').value='free'; bundles=[]; manualRecommended=null;
      renderBundles(); syncPricingUI(); syncShippingUI(); applyPolicyDefaults(true); generate();
    }

    async function saveToHistory(){
      try {
        await fetch(window.PG_CONFIG.saveUrl,{ method:'POST',
          headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
          body:JSON.stringify({ inputs:fieldValues(), output:$('output').value }) });
      } catch(e){}
    }
    async function copyPrompt(){
      generate(); const o=$('output');
      try{ await navigator.clipboard.writeText(o.value); const st=$('status'); st.className='ok'; st.textContent='Copied to clipboard.'; saveToHistory(); }
      catch(e){ o.removeAttribute('readonly'); o.select(); document.execCommand('copy'); o.setAttribute('readonly','readonly'); }
    }
    function downloadPrompt(){
      generate(); const data=fieldValues();
      const name=(data.PRODUCT_NAME||'generated_prompt').replace(/[^a-z0-9]+/gi,'_').replace(/^_|_$/g,'');
      const blob=new Blob([$('output').value],{type:'text/plain;charset=utf-8'});
      const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download=name+'_AI_Sales_Prompt.txt';
      document.body.appendChild(a); a.click(); a.remove(); saveToHistory();
    }

    // ── AI image auto-fill (server-side) ──
    function setAIStatus(msg,type){ const el=$('aiStatus'); el.textContent=msg; el.className='ai-status'+(type?' '+type:''); }
    function getFieldLabel(el){ const f=el.closest('.pg-field'); return f?f.querySelector(':scope > span'):null; }
    function clearFieldFlag(el){ const f=el.closest('.pg-field'); if(!f) return; f.classList.remove('review','ai-filled'); const o=f.querySelector('.field-flag'); if(o) o.remove(); }
    function markField(el,status){
      if(!el) return; clearFieldFlag(el);
      const f=el.closest('.pg-field'), lb=getFieldLabel(el); if(!f||!lb) return;
      f.classList.add(status==='filled'?'ai-filled':'review');
      const b=document.createElement('span'); b.className='field-flag '+(status==='filled'?'filled':'review');
      b.textContent=status==='filled'?'AI FILLED':'REVIEW'; lb.appendChild(b);
    }
    function clearAIFlags(){
      document.querySelectorAll('.pg-field').forEach(f=>{ f.classList.remove('review','ai-filled'); f.querySelectorAll('.field-flag').forEach(x=>x.remove()); });
      document.querySelectorAll('.bundle-card').forEach(x=>x.classList.remove('ai-filled'));
      setAIStatus('AI review marks cleared.');
    }
    function markAllReview(){
      document.querySelectorAll('[data-key]').forEach(el=>{ if(!PROTECTED_KEYS.includes(el.dataset.key)) markField(el,'review'); });
      ['sellingType','singlePrice','shippingMode','shippingFeeType','shippingAmount','shippingLocationText'].forEach(id=>markField($(id),'review'));
    }
    function normalizeKnown(v){
      if(v===null||v===undefined) return null;
      if(typeof v==='string'){ const s=v.trim(); if(!s||/^(unknown|not visible|not provided|n\/a|null)$/i.test(s)) return null; return s; }
      return v;
    }
    function applySimpleAIField(key,value){
      const el=document.querySelector(`[data-key="${key}"]`); if(!el) return false;
      value=normalizeKnown(value); if(value===null){ markField(el,'review'); return false; }
      if(Array.isArray(value)) value=value.filter(Boolean).map(x=>'• '+x).join('\n');
      else if(typeof value==='object') value=JSON.stringify(value);
      el.value=String(value); markField(el,'filled'); return true;
    }
    function applyPricingAI(p){
      const selling=$('sellingType'), single=$('singlePrice'); const mode=normalizeKnown(p?.mode);
      if(mode==='single'){
        selling.value='single'; markField(selling,'filled');
        const price=normalizeKnown(p?.single_price);
        if(price){ single.value=price; markField(single,'filled'); } else markField(single,'review');
        bundles=[]; manualRecommended=null; renderBundles(); syncPricingUI(); return true;
      }
      if(mode==='bundles' && Array.isArray(p?.bundles) && p.bundles.length){
        selling.value='bundles'; markField(selling,'filled');
        bundles=p.bundles.filter(x=>normalizeKnown(x?.name)||normalizeKnown(x?.quantity)||normalizeKnown(x?.price))
          .map(x=>{const sf=normalizeKnown(x?.shipping)||normalizeKnown(x?.ship_fee);const hasFee=sf&&!/free/i.test(sf);return {id:crypto.randomUUID(),name:normalizeKnown(x?.name)||'',qty:normalizeKnown(x?.quantity)||'',price:normalizeKnown(x?.price)||'',shipMode:hasFee?'declared':'free',shipFeeType:hasFee?'fixed':'location',shipAmount:hasFee?sf:'',shipLocationText:''};});
        manualRecommended=null;
        if(bundles.length){ manualRecommended=bundles[Math.floor((bundles.length-1)/2)].id; renderBundles();
          document.querySelectorAll('.bundle-card').forEach(c=>c.classList.add('ai-filled')); syncPricingUI(); return true; }
      }
      markField(selling,'review'); markField(single,'review'); return false;
    }
    function applyShippingAI(s){
      const modeEl=$('shippingMode'),typeEl=$('shippingFeeType'),amtEl=$('shippingAmount'),locEl=$('shippingLocationText'); const mode=normalizeKnown(s?.mode);
      if(mode==='free'){ modeEl.value='free'; markField(modeEl,'filled'); syncShippingUI(); return true; }
      if(mode==='declared'||mode==='hidden'){
        modeEl.value=mode; markField(modeEl,'filled'); const ft=normalizeKnown(s?.fee_type);
        if(ft==='fixed'||ft==='location'){ typeEl.value=ft; markField(typeEl,'filled');
          if(ft==='fixed'){ const a=normalizeKnown(s?.amount); if(a){amtEl.value=a;markField(amtEl,'filled');} else markField(amtEl,'review'); }
          else { const m=normalizeKnown(s?.location_response); if(m){locEl.value=m;markField(locEl,'filled');} else markField(locEl,'review'); }
        } else markField(typeEl,'review');
        syncShippingUI(); return true;
      }
      markField(modeEl,'review'); return false;
    }
    function applyAIResult(result){
      markAllReview(); let filled=0, review=0;
      const fields=result?.fields||{};
      Object.keys(fields).forEach(k=>{ if(PROTECTED_KEYS.includes(k)) return; if(applySimpleAIField(k,fields[k])) filled++; });
      if(applyPricingAI(result?.pricing||{})) filled++;
      if(applyShippingAI(result?.shipping||{})) filled++;
      document.querySelectorAll('.pg-field.review').forEach(()=>review++);
      generate();
      setAIStatus(`Tapos ang scan: ${filled} field group updated. ${review} field(s) na kailangang i-review.`,'success');
    }
    async function analyzeUploadedImage(){
      if(!uploadedImageFile){ setAIStatus('Mag-upload muna ng image.','error'); return; }
      setAIStatus('Sinusuri ang image (pricing, bundles, shipping, product details)…','busy');
      const btn=$('analyzeImageBtn'); btn.disabled=true;
      try{
        const fd=new FormData(); fd.append('image',uploadedImageFile);
        const res=await fetch(window.PG_CONFIG.analyzeUrl,{ method:'POST', headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'}, body:fd });
        const json=await res.json();
        if(!json.ok){ setAIStatus('Hindi ma-auto-fill: '+(json.message||'error'),'error'); return; }
        applyAIResult(json.result);
      }catch(e){ setAIStatus('Error: '+e.message,'error'); }
      finally{ btn.disabled=false; }
    }
    function loadImageFile(file){
      if(!file||!file.type.startsWith('image/')){ setAIStatus('Pumili ng valid na image.','error'); return; }
      uploadedImageFile=file;
      const r=new FileReader();
      r.onload=()=>{ const img=$('imagePreview'); img.src=r.result; img.classList.remove('hidden'); $('uploadCopy').classList.add('hidden'); setAIStatus('Image loaded. Pindutin ang Analyze.'); };
      r.readAsDataURL(file);
    }

    // ── wiring ──
    const dz=$('dropzone'), iu=$('imageUpload');
    dz.onclick=()=>iu.click();
    iu.onchange=e=>loadImageFile(e.target.files?.[0]);
    dz.ondragover=e=>{e.preventDefault();dz.style.borderColor='#6366f1';};
    dz.ondragleave=()=>{dz.style.borderColor='';};
    dz.ondrop=e=>{e.preventDefault();dz.style.borderColor='';loadImageFile(e.dataTransfer.files?.[0]);};
    $('analyzeImageBtn').onclick=analyzeUploadedImage;
    $('clearAIFlagsBtn').onclick=clearAIFlags;
    $('addBundleBtn').onclick=()=>addBundle({name:'New Bundle',qty:'',price:''});
    $('sellingType').onchange=syncPricingUI;
    $('shippingMode').onchange=syncShippingUI;
    $('shippingFeeType').onchange=syncShippingUI;
    $('shippingAmount').oninput=generate;
    $('shippingLocationText').oninput=generate;
    $('singlePrice').oninput=generate;
    $('generateBtn').onclick=generate;
    $('sampleBtn').onclick=loadSample;
    $('clearBtn').onclick=clearFields;
    $('copyBtn').onclick=copyPrompt;
    $('downloadBtn').onclick=downloadPrompt;

    // ── Split copy (Copy 1 = first ~10k up to a clean section boundary; Copy 2 = the rest) ──
    function splitPromptText(text, target){
      target=target||10000; text=String(text||'');
      if(text.length<=target) return [text.trim(), ''];
      let idx=text.lastIndexOf('\n\n# ', target);        // last section header at/before target
      if(idx<=0) idx=text.lastIndexOf('\n\n', target);    // fallback: paragraph break
      if(idx<=0) idx=target;                              // hard fallback
      return [text.slice(0,idx).trim(), text.slice(idx).trim()];
    }
    async function copyText(t){
      try{ await navigator.clipboard.writeText(t); return true; }
      catch(e){
        try{ const ta=document.createElement('textarea'); ta.value=t; ta.style.cssText='position:fixed;top:0;left:0;opacity:0';
          document.body.appendChild(ta); ta.focus(); ta.select(); const ok=document.execCommand('copy'); document.body.removeChild(ta); return ok; }
        catch(e2){ return false; }
      }
    }
    function wireCopyPart(btn, getText, part){
      if(!btn) return;
      const label='Copy '+part;
      btn.onclick=async()=>{
        generate(); // refresh output (+ verification timestamp)
        const [a,b]=splitPromptText(getText(), 10000);
        const t = part===1 ? a : b;
        if(!t){ btn.textContent='(walang part '+part+')'; setTimeout(()=>btn.textContent=label,1600); return; }
        const ok=await copyText(t);
        btn.textContent = ok ? ('Copied '+t.length.toLocaleString()+' ✓') : 'Copy failed';
        setTimeout(()=>btn.textContent=label,1800);
      };
    }
    wireCopyPart($('copyBtn1'), ()=>$('output').value, 1);
    wireCopyPart($('copyBtn2'), ()=>$('output').value, 2);
    wireCopyPart($('copyAfterBtn1'), ()=>$('afterOutput').value, 1);
    wireCopyPart($('copyAfterBtn2'), ()=>$('afterOutput').value, 2);

    document.querySelectorAll('[data-key]').forEach(el=>el.addEventListener('input',generate));

    // ── Tabs ──
    function showTab(name){
      document.querySelectorAll('.pg-tab').forEach(t=>t.classList.toggle('active',t.dataset.tab===name));
      document.querySelectorAll('.pg-pane').forEach(p=>p.classList.toggle('pg-hidden',p.dataset.pane!==name));
    }
    document.querySelectorAll('.pg-tab').forEach(t=>t.onclick=()=>showTab(t.dataset.tab));

    // derive price/promo string for Main Flow (from single price or recommended bundle)
    function derivePricePromo(){
      const data=fieldValues(); const type=$('sellingType').value;
      if(type==='single') return { price:$('singlePrice').value.trim(), promo:data.PROMO_INFORMATION||'' };
      const rec=bundles.find(b=>b.id===manualRecommended)||bundles[Math.floor((bundles.length-1)/2)]||bundles[0];
      const list=bundles.map(b=>`${b.name} (${b.qty}) ${b.price}`).filter(x=>x.trim()).join('; ');
      return { price:(rec?rec.price:''), promo:[list,data.PROMO_INFORMATION].filter(Boolean).join(' — ') };
    }
    const jhead={'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf};
    // BotCake salutation token. The AI weaves the [[SALUTATION]] marker naturally into its
    // greeting; we swap the marker for this exact token so it renders inline (e.g. "Hi po Sir/Ma'am Juan!").
    const MAINFLOW_SALUTATION = "#GENDER{{Sir|Ma'am|Sir/Ma'am}} {{user_first_name}}";

    // ── Main Flow ──
    async function generateMainFlow(){
      const data=fieldValues();
      if(!data.PRODUCT_NAME){ $('mainFlowStatus').textContent='Kailangan ng Product Name.'; return; }
      const {price,promo}=derivePricePromo();
      const btn=$('genMainFlowBtn'); btn.disabled=true; btn.textContent='Generating…'; $('mainFlowStatus').textContent='AI is writing…';
      try{
        const res=await fetch(window.PG_CONFIG.mainflowUrl,{method:'POST',headers:jhead,
          body:JSON.stringify({product_name:data.PRODUCT_NAME,product_description:data.PRODUCT_DESCRIPTION,features:data.PRODUCT_FEATURES,price,promo,language:($('language')||{}).value||'Taglish'})});
        const j=await res.json();
        if(!j.ok){ $('mainFlowStatus').textContent=j.message||'Failed'; return; }
        let mf=j.main_flow||'';
        mf = mf.includes('[[SALUTATION]]') ? mf.split('[[SALUTATION]]').join(MAINFLOW_SALUTATION) : (MAINFLOW_SALUTATION+' '+mf);
        $('mainFlowOutput').value=mf; $('mainFlowStatus').textContent='Done ✅';
      }catch(e){ $('mainFlowStatus').textContent='Error: '+e.message; }
      finally{ btn.disabled=false; btn.textContent='✨ Generate Main Flow'; }
    }

    // ── Sequence ──
    function renderSeq(msgs){
      const list=$('seqList'); list.innerHTML='';
      if(!msgs.length){ list.innerHTML='<div class="help">Walang na-generate.</div>'; return; }
      msgs.forEach((m,i)=>{
        const d=document.createElement('div'); d.className='seq-item';
        const head=document.createElement('div'); head.className='seq-head';
        const num=document.createElement('span'); num.className='seq-num'; num.textContent='Message '+(i+1);
        const cp=document.createElement('button'); cp.className='btn ghost'; cp.textContent='Copy';
        cp.onclick=()=>{ navigator.clipboard.writeText(m); cp.textContent='Copied'; setTimeout(()=>cp.textContent='Copy',1200); };
        head.appendChild(num); head.appendChild(cp);
        const pre=document.createElement('pre'); pre.textContent=m;
        d.appendChild(head); d.appendChild(pre); list.appendChild(d);
      });
    }
    async function generateSequence(){
      const data=fieldValues();
      if(!data.PRODUCT_NAME){ $('seqStatus').textContent='Kailangan ng Product Name.'; return; }
      const count=parseInt($('seqCount').value,10)||5;
      const {price,promo}=derivePricePromo();
      const pricing=[price?('Price: '+price):'', promo?('Offer/Promo: '+promo):''].filter(Boolean).join('\n');
      let price_pct=parseInt(($('seqPricePct')||{}).value,10); if(isNaN(price_pct)) price_pct=30; price_pct=Math.max(0,Math.min(100,price_pct));
      const btn=$('genSeqBtn'); btn.disabled=true; btn.textContent='Generating…'; $('seqStatus').textContent='AI is writing…';
      try{
        const res=await fetch(window.PG_CONFIG.sequenceUrl,{method:'POST',headers:jhead,
          body:JSON.stringify({product_name:data.PRODUCT_NAME,product_description:data.PRODUCT_DESCRIPTION,features:data.PRODUCT_FEATURES,language:($('language')||{}).value||'Taglish',pricing,price_pct,count})});
        const j=await res.json();
        if(!j.ok){ $('seqStatus').textContent=j.message||'Failed'; return; }
        seqMessages=j.messages||[];
        renderSeq(seqMessages); $('seqStatus').textContent='Done — '+seqMessages.length+' messages ✅';
      }catch(e){ $('seqStatus').textContent='Error: '+e.message; }
      finally{ btn.disabled=false; btn.textContent='✨ Generate Sequence'; }
    }

    // ── Test chat ──
    async function runTest(){
      const target=$('testTarget').value;
      const prompt = target==='aftersales' ? $('afterOutput').value : $('output').value;
      const msg=$('testInput').value.trim();
      if(!prompt){ $('testReply').textContent='Wala pang prompt — i-generate muna sa Sales/After-Sales tab.'; return; }
      if(!msg) return;
      const btn=$('testBtn'); btn.disabled=true; btn.textContent='…'; $('testReply').textContent='AI is replying…';
      try{
        const res=await fetch(window.PG_CONFIG.testUrl,{method:'POST',headers:jhead,body:JSON.stringify({system_prompt:prompt,message:msg})});
        const j=await res.json();
        $('testReply').textContent = j.ok ? j.reply : ('⚠️ '+(j.message||'Failed'));
      }catch(e){ $('testReply').textContent='Error: '+e.message; }
      finally{ btn.disabled=false; btn.textContent='Send'; }
    }

    // ── Generate All + Copy for Sheet (one GSheet row) ──
    // Column order matches the sheet: Type of Selling, Bundle, Quantity, Item Name,
    // PROMO, MAIN FLOW, LOOP 1, LOOP 2, Sequence 1..N, then Sales Prompt, After-Sales.
    function sheetColumns(){
      generate(); // refresh Sales/After-Sales output (incl. fresh verification timestamp)
      const data=fieldValues();
      const isBundle=$('sellingType').value==='bundles';
      const count=parseInt($('seqCount').value,10)||10;
      const cols=[
        isBundle ? 'Multiple Offers' : 'Single Selling Price',              // Type of Selling
        isBundle ? bundles.map(b=>b.name).filter(Boolean).join('; ') : '',  // Bundle
        data.QUANTITY_PCS||'1',                                            // Quantity (pieces)
        data.PRODUCT_NAME||'',                                              // Item Name
        (derivePricePromo().price||''),                                     // PROMO (price)
        $('mainFlowOutput').value||'',                                      // MAIN FLOW
        data.LOOP1||'',                                                     // LOOP 1
        data.LOOP2||'',                                                     // LOOP 2
      ];
      for(let i=0;i<count;i++) cols.push(seqMessages[i]||'');               // Sequence 1..N
      cols.push($('output').value||'');                                     // Sales Prompt
      cols.push($('afterOutput').value||'');                               // After-Sales
      return cols;
    }
    function tsvCell(v){ v=(v==null?'':String(v)); return /[\t\n\r"]/.test(v) ? '"'+v.replace(/"/g,'""')+'"' : v; }
    function htmlCell(v){ v=(v==null?'':String(v)); return v.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>'); }
    async function copyForSheet(){
      const cols=sheetColumns();
      const tsv=cols.map(tsvCell).join('\t');
      const html='<table><tr>'+cols.map(c=>'<td>'+htmlCell(c)+'</td>').join('')+'</tr></table>';
      const st=$('genAllStatus');
      try{
        await navigator.clipboard.write([new ClipboardItem({
          'text/html': new Blob([html],{type:'text/html'}),
          'text/plain': new Blob([tsv],{type:'text/plain'})
        })]);
        st.textContent='Copied! Paste sa "Type of Selling" cell ng row.';
      }catch(e){
        try{ await navigator.clipboard.writeText(tsv); st.textContent='Copied (plain text). Paste sa row.'; }
        catch(e2){ st.textContent='Copy failed: '+e2.message; }
      }
      setTimeout(()=>{ if(st) st.textContent=''; }, 4500);
    }
    async function generateAll(){
      const btn=$('genAllBtn'); btn.disabled=true; const label=btn.textContent; btn.textContent='Generating…';
      const st=$('genAllStatus');
      try{
        generate();                                   // Sales Prompt + After-Sales (deterministic)
        st.textContent='Main Flow…'; await generateMainFlow();
        st.textContent='Sequence…';  await generateSequence();
        st.textContent='Tapos ✅ — Copy for Sheet na para i-paste sa GSheet.';
      }catch(e){ st.textContent='Error: '+e.message; }
      finally{ btn.disabled=false; btn.textContent=label; }
    }

    // extra wiring
    $('genMainFlowBtn').onclick=generateMainFlow;
    $('copyMainFlowBtn').onclick=()=>{ const v=$('mainFlowOutput').value; if(v) navigator.clipboard.writeText(v); };
    $('genSeqBtn').onclick=generateSequence;
    $('testBtn').onclick=runTest;
    $('genAllBtn').onclick=generateAll;
    $('copySheetBtn').onclick=copyForSheet;
    $('copyAfterBtn').onclick=()=>{ const v=$('afterOutput').value; if(v) navigator.clipboard.writeText(v); };
    if($('language')) $('language').addEventListener('change',saveState);
    if($('seqCount')) $('seqCount').addEventListener('change',saveState);
    if($('seqPricePct')) $('seqPricePct').addEventListener('change',saveState);

    // init: restore last state, else load sample. Punan ang blangkong Policies & Delivery
    // ng standard defaults (di sinisira ang na-edit na ng user).
    if(!restoreState()) loadSample();
    applyPolicyDefaults(false);
    generate();
  })();
  </script>
  @endverbatim
</x-layout>
