@extends('layouts.chatblast')

@section('title', 'Chatbot · Page settings')
@section('heading', 'Chatbot')

@section('description')
    One row per page with a Botcake ID and token: switch the bot On or Off and set the values its replies will use. Values save as you type; pasted values save with one button.
@endsection

@push('styles')
<style>
    /* Ang table lang ang nag-i-scroll (pataas at patagilid sa loob ng kahon, .cb-scroll ng layout: ang natitirang taas ng
       screen, kaya laging kita ang horizontal bar); ang page ay hindi kailanman patagilid. */
    #cb-table th, #cb-table td { background: #fff; }
    /* Paste: ang napiling mga cell (asul) at ang hindi pa naka-save (dilaw, ang dating value sa hover). */
    #cb-table td.cb-sel { background: #eff6ff; box-shadow: inset 0 0 0 1px #93c5fd; }
    #cb-table td.cb-pending { background: #fef3c7; }
    #cb-table td.cb-pending.cb-sel { background: #fde68a; }
    #cb-table:focus-within td.cb-cell:focus { outline: 2px solid #3b82f6; outline-offset: -2px; }
    /* Edit ng isang page: ang binago (dilaw, may "Was: …") at ang hindi link sa image field (pula, hindi sine-save). */
    #pe .pe-in { resize: none; overflow-y: auto; max-height: 20rem; }
    #pe .pe-changed .pe-in { background: #fef3c7; border-color: #f59e0b; }
    #pe .pe-flagged .pe-in { background: #fef2f2; border-color: #ef4444; }
    /* Text cell: buo ang line break, 3 linya kapag sarado (may fade kapag may kasunod pa). Ang click sa cell ay nagbubukas ng
       buong row: bawat cell hanggang 15rem (mga 240 px), may sariling scroll. Ang lapis lang ang nagbubukas ng editor. */
    #cb-table .cb-txt { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow: hidden; max-width: 14rem; white-space: pre-line; overflow-wrap: anywhere; }
    #cb-table .cb-txt.cb-clamped { -webkit-mask-image: linear-gradient(to bottom, #000 55%, transparent); mask-image: linear-gradient(to bottom, #000 55%, transparent); }
    #cb-table tr.cb-open .cb-txt { display: block; -webkit-line-clamp: unset; max-height: 15rem; overflow-y: auto; -webkit-mask-image: none; mask-image: none; }
    #cb-table td.cb-cell { cursor: pointer; }
    /* Ang laman ng bawat value cell ay hiwalay sa pintura (contain: paint): kapag gumalaw ang mga row sa ilalim ng row na
       bumukas o nagbago, ang cell lang ang inililipat ng browser, hindi bawat bahagi nito (libu-libong cell). Ang 4px ay
       para buo pa rin ang focus ring ng lapis. */
    #cb-table td.cb-cell > div { contain: paint; overflow-clip-margin: 4px; }
    /* History at Changes: ang value ay 3 linya kapag sarado ("Show all" para sa buo). */
    .cb-fold { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow: hidden; white-space: pre-line; overflow-wrap: anywhere; }
    .cb-fold.cb-unfold { display: block; -webkit-line-clamp: unset; }
    #cb-table .cb-edit { opacity: 0; }
    #cb-table td.cb-cell:hover .cb-edit, #cb-table td.cb-cell:focus-within .cb-edit, #cb-table .cb-edit:focus { opacity: 1; }
    @media (hover: none), (max-width: 639px) { #cb-table .cb-edit { opacity: 1; } }
    #cb-table thead th { position: sticky; top: 0; z-index: 20; }
    /* Ang mga naka-freeze na column ay may tiyak na lapad (min = max), kaya hindi lumalapad sa mahabang pangalan o select
       at hindi kumikitid: ang left ng bawat isa ay ang kabuuan ng lapad ng mga nauna, kaya hindi natatakpan ang kasunod. */
    #cb-table { --cb-w1: 8rem; --cb-w2: 11rem; --cb-w3: 10rem; --cb-w4: 14rem; }
    #cb-table .stick-1 { width: var(--cb-w1); min-width: var(--cb-w1); max-width: var(--cb-w1); }
    #cb-table .stick-2 { width: var(--cb-w2); min-width: var(--cb-w2); max-width: var(--cb-w2); }
    #cb-table .stick-3 { width: var(--cb-w3); min-width: var(--cb-w3); max-width: var(--cb-w3); }
    #cb-table .stick-4 { width: var(--cb-w4); min-width: var(--cb-w4); max-width: var(--cb-w4); }
    #cb-table .stick-1, #cb-table .stick-2, #cb-table .stick-3, #cb-table .stick-4 { box-sizing: border-box; overflow-wrap: anywhere; }
    #cb-table .stick-1 { position: sticky; left: 0; z-index: 10; }
    #cb-table thead .stick-1, #cb-table thead .stick-2, #cb-table thead .stick-3, #cb-table thead .stick-4 { z-index: 30; }
    @media (min-width: 640px) {
        #cb-table { --cb-w1: 12rem; }
        #cb-table .stick-2 { position: sticky; left: var(--cb-w1); z-index: 10; }
        #cb-table .stick-3 { position: sticky; left: calc(var(--cb-w1) + var(--cb-w2)); z-index: 10; box-shadow: 1px 0 0 #e2e8f0; }
    }
    /* Desktop: ang Check ay naka-freeze din (maikling cell); ang buong listahan ay lumalabas sa hover o pindot. */
    @media (min-width: 1024px) {
        #cb-table .stick-3 { box-shadow: none; }
        #cb-table .stick-4 { position: sticky; left: calc(var(--cb-w1) + var(--cb-w2) + var(--cb-w3)); z-index: 11; box-shadow: 1px 0 0 #e2e8f0; }
        #cb-table .cb-chk-pop { display: none; position: absolute; left: 0.5rem; top: 100%; z-index: 40; width: 20rem; max-width: calc(100vw - 2rem); }
        #cb-table td.stick-4:hover .cb-chk-pop, #cb-table .cb-chk.cb-chk-open .cb-chk-pop, #cb-table .cb-chk:focus-within .cb-chk-pop { display: block; }
        /* Ang bukas na cell ay nasa ibabaw ng mga sticky na cell ng susunod na row. */
        #cb-table td.stick-4:hover, #cb-table td.stick-4:focus-within { z-index: 35; }
        /* Isinara sa Escape: nakasara kahit nasa linya pa ang focus, hanggang sa susunod na pindot. */
        #cb-table .cb-chk.cb-chk-shut .cb-chk-pop { display: none !important; }
    }
    /* Ad-based na page: ang mga sub-row (Default, mga group, mga ad ID) ay kulay-abo; ang buong-lapad na laman ay nakadikit
       sa kaliwa at kasing-lapad ng kahon (--cbw), kaya nababasa kahit naka-scroll pakanan o sa phone. */
    #cb-table tr.ag-sub > td { background: #f8fafc; }
    #cb-table .ag-pin { position: sticky; left: 0; width: calc(var(--cbw, 100vw) - 2px); max-width: 64rem; box-sizing: border-box; }
    /* Ang pagpapangkat: makapal na guhit sa pagitan ng mga page, manipis at mas maputla sa pagitan ng mga ad group ng
       isang page, at ang mga sub-row ay nakausog na may guhit sa kaliwa, kaya kita kung kaninong page sila. */
    #cb-table tbody tr:not(.ag-sub):not(:first-child) > td { border-top: 2px solid #94a3b8; }
    #cb-table tr.ag-sub[data-ag-group] > td { border-top: 1px solid #cbd5e1; }
    #cb-table tr.ag-sub > td.stick-1, #cb-table tr.ag-sub .ag-pin { padding-left: 1.75rem; box-shadow: inset 3px 0 0 #cbd5e1; }
</style>
@endpush

@section('content')
@include('chatbot.partials.switch', ['repliesOn' => $repliesOn])
@include('chatbot.partials.send-lag-alarm', ['sendLagAlarm' => $sendLagAlarm ?? null])
<div id="cbot" class="cb-page"
     data-data-url="{{ route('chatbot.data') }}"
     data-cell-url="{{ route('chatbot.cell') }}"
     data-cells-url="{{ route('chatbot.cells') }}"
     data-paste-map-url="{{ route('chatbot.paste-map') }}"
     data-column-url="{{ route('chatbot.column') }}"
     data-check-url="{{ route('chatbot.check') }}"
     data-preview-url="{{ route('chatbot.preview') }}"
     data-history-url="{{ route('chatbot.history') }}"
     data-restore-url="{{ route('chatbot.restore') }}"
     data-changes-url="{{ route('chatbot.changes') }}"
     data-provider-url="{{ route('chatbot.provider') }}"
     data-gateway-url="{{ route('chatbot.gateway') }}"
     data-gateway-app-url="{{ route('chatbot.gateway-app') }}"
     data-ag-url="{{ route('chatbot.ad-groups') }}" data-ag-add-url="{{ route('chatbot.ad-groups.add') }}" data-ag-delete-url="{{ route('chatbot.ad-groups.delete') }}"
     data-ag-history-url="{{ route('chatbot.ad-groups.history') }}" data-ag-restore-url="{{ route('chatbot.ad-groups.restore') }}"
     data-ag-mode-url="{{ route('chatbot.ad-groups.mode') }}" data-ag-value-url="{{ route('chatbot.ad-groups.value') }}" data-ag-remove-ad-url="{{ route('chatbot.ad-groups.remove-ad') }}" data-ag-ad-url="{{ route('chatbot.ad-groups.ad') }}"
     data-ai-url="{{ route('chatbot.ai') }}" data-ai-site-url="{{ route('chatbot.ai.site') }}" data-ai-compare-url="{{ route('chatbot.ai.compare') }}"
     data-needs-token="{{ \App\Services\Chatbot\ForbeastPageSetup::NEEDS_TOKEN }}"
     @if($canSetUp) data-active-url="{{ route('chatbot.active') }}" data-pages-url="{{ route('chatbot.pages') }}"
     data-connections-url="{{ route('chatbot.connections') }}" data-connections-save-url="{{ url('/chatbot/connections') }}" @endif>

    <div class="cb-filters mb-3 flex flex-wrap items-end gap-3 text-sm">
        <label class="flex flex-col text-xs">Search
            <input id="f-q" type="search" placeholder="Page name" class="border px-2 py-1 text-sm text-gray-900">
        </label>
        <label class="flex flex-col text-xs">Bot
            <select id="f-state" class="border px-2 py-1 text-sm text-gray-900">
                <option value="">All</option><option value="on">On</option><option value="off">Off</option>
            </select>
        </label>
        <button id="add-column" type="button" class="cb-btn">+ Column</button>
        <button id="changes-open" type="button" class="cb-btn">Changes</button>
        {{-- Aling AI ang sumasagot: ang site default (at ang Local cap at breaker), at ang paghahambing ng mga AI ng isang page. --}}
        <button id="ai-site-open" type="button" class="cb-btn">AI default</button>
        <button id="ai-compare-open" type="button" class="cb-btn">Compare AI</button>
        {{-- Ang pool ng AI key ng kumpanya (spec 210): CEO lang, kaya ang link ay sa CEO lang. --}}
        @if(\App\Http\Controllers\ChatbotAiKeyController::allowed())<a href="{{ route('chatbot.ai-keys') }}" class="cb-btn">AI keys</a>@endif
        @if($canSetUp)<button id="add-page" type="button" class="cb-btn cb-btn-primary">+ Page</button>@endif
        {{-- Paste: ang Edit ng bawat page (may paste box), o Ctrl/Cmd+V sa napiling cell ng grid. --}}
        <label class="flex items-center gap-1 text-xs"><input id="f-blanks" type="checkbox"> Paste blanks as empty</label>
        <span id="count" class="text-xs text-slate-500"></span>
        {{-- Bulk "Replies via": ang mga page na may check (o lahat ng nakikita) sa isang kilos. --}}
        <span class="flex items-end gap-1 text-xs">
            <label class="flex flex-col">Replies via
                <select id="bulk-provider" class="border px-2 py-1 text-sm text-gray-900"><option value="botcake">Botcake</option><option value="gateway">Forbeast</option></select>
            </label>
            <button id="bulk-apply" type="button" class="cb-btn" disabled>Apply to selected</button>
        </span>
    </div>
    <p id="notice" class="mb-2 min-h-[1rem] text-xs" aria-live="polite"></p>

    <div id="cb-box" class="cb-card cb-scroll">
        <table id="cb-table" class="text-sm" style="border-collapse: separate; border-spacing: 0;"></table>
    </div>

    {{-- Ang bar ng paste: mga hindi pa naka-save (Save / Cancel), o pagkatapos ng Save, ang Undo. --}}
    <div id="bar" class="mt-2 hidden flex-wrap items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm" role="status" aria-live="polite">
        <span id="bar-text" class="font-medium"></span>
        <button id="bar-save" type="button" class="cb-btn cb-btn-primary cb-btn-sm">Save</button>
        <button id="bar-cancel" type="button" class="cb-btn cb-btn-sm">Cancel</button>
        <button id="bar-undo" type="button" class="cb-btn cb-btn-sm">Undo</button>
    </div>

    {{-- Edit ng isang page: lahat ng botfield nito, pababa at nag-i-scroll. Ang paste box sa itaas ay pumupuno sa mga field
         mula sa isang row ng Google Sheets; isang Save para sa lahat ng binago. Sa phone, buong screen. --}}
    <div id="pe" class="fixed inset-0 z-50 hidden bg-slate-900/30 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="pe-title">
        <div class="mx-auto flex h-full max-h-full w-full max-w-2xl flex-col bg-white text-sm shadow-lg sm:rounded-xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div id="pe-title" class="min-w-0 break-words font-semibold"><span id="pe-name"></span> <span id="pe-id" class="font-mono text-xs font-normal text-slate-400"></span></div><span id="pe-fb" class="shrink-0"></span>
                <button id="pe-preview" type="button" class="cb-btn cb-btn-sm ml-auto shrink-0">Preview</button>
                <button id="pe-close" type="button" class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <div class="border-b border-slate-200 px-4 py-2">
                <label for="pe-paste" class="text-xs text-slate-600">Paste a row copied from Google Sheets (with or without the header line)</label>
                <textarea id="pe-paste" rows="2" class="mt-1 w-full rounded-md border border-slate-300 p-2 font-mono text-xs" placeholder="Ctrl/Cmd+V here"></textarea>
                <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600">
                    <label id="pe-start-box" class="flex items-center gap-1">Starting at field <select id="pe-start" class="max-w-[12rem] rounded border border-slate-300 px-1 py-0.5 text-xs text-gray-900"></select></label>
                    <label class="flex items-center gap-1"><input id="pe-blanks" type="checkbox"> Paste blanks as empty</label>
                </div>
                <ul id="pe-notes" class="mt-1 space-y-0.5 text-xs text-slate-600" aria-live="polite"></ul>
                {{-- Ang AI ang pumipili ng field ng bawat na-paste na value: preview muna, Apply ang pumupuno, Save pa rin ang nagse-save. --}}
                <div id="pe-map" class="mt-2 hidden rounded-md border border-slate-200 bg-slate-50 p-2">
                    <p id="pe-map-status" class="text-xs text-slate-600" aria-live="polite"></p>
                    <ol id="pe-map-list" class="mt-1 max-h-60 space-y-1 overflow-y-auto"></ol>
                    {{-- Mga follow-up na lampas sa huling Seq column: ang button ay gumagawa lang ng mga blangkong column. --}}
                    <div id="pe-map-missing" class="mt-1 hidden flex-wrap items-center gap-x-2 gap-y-1 text-xs text-amber-800"><span id="pe-map-missing-text" class="min-w-0 break-words"></span><button id="pe-map-add" type="button" class="cb-btn cb-btn-sm"></button></div>
                    <p id="pe-map-error" class="mt-1 text-xs text-red-600 empty:hidden" aria-live="polite"></p>
                    <p id="pe-map-item" class="mt-1 break-words rounded border border-amber-300 bg-amber-50 px-2 py-1 text-xs text-amber-800 empty:hidden" aria-live="polite"></p>
                    <div class="mt-2 flex flex-wrap gap-2"><button id="pe-map-apply" type="button" class="cb-btn cb-btn-sm cb-btn-primary" disabled>Apply</button><button id="pe-map-classic" type="button" class="cb-btn cb-btn-sm">Use header/position instead</button></div>
                </div>
            </div>
            <div id="pe-fields" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto px-4"></div>
            <div class="border-t border-slate-200 px-4 py-3">
                <p id="pe-error" class="mb-1 text-xs text-red-600 empty:hidden" aria-live="polite"></p>
                <div class="flex flex-wrap gap-2"><button id="pe-save" type="button" class="cb-btn cb-btn-primary" disabled>Save</button><button id="pe-cancel" type="button" class="cb-btn">Cancel</button></div>
            </div>
        </div>
    </div>

    {{-- Preview, History ng isang field, at Changes: mga modal na nasa ibabaw ng Edit (z-index 60). Basa lang, maliban sa
         Restore. Sa phone, buong screen. --}}
    <div id="pv" class="fixed inset-0 hidden bg-slate-900/30 sm:p-8" style="z-index: 60" role="dialog" aria-modal="true" aria-labelledby="pv-title">
        <div class="mx-auto flex h-full max-h-full w-full max-w-lg flex-col bg-white text-sm shadow-lg sm:rounded-xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div class="min-w-0"><div id="pv-title" class="break-words font-semibold"></div><div id="pv-sub" class="text-xs text-slate-500"></div></div>
                <button type="button" data-close-dialog class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <div id="pv-list" class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50 px-4 py-3"></div>
        </div>
    </div>
    <div id="hist" class="fixed inset-0 hidden bg-slate-900/30 sm:p-8" style="z-index: 60" role="dialog" aria-modal="true" aria-labelledby="hist-title">
        <div class="mx-auto flex h-full max-h-full w-full max-w-lg flex-col bg-white text-sm shadow-lg sm:rounded-xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div class="min-w-0"><div id="hist-title" class="break-words font-semibold"></div><div id="hist-sub" class="break-words text-xs text-slate-500"></div></div>
                <button type="button" data-close-dialog class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <p id="hist-note" class="px-4 pt-2 text-xs empty:hidden" aria-live="polite"></p>
            <ol id="hist-list" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto px-4"></ol>
        </div>
    </div>
    <div id="chg" class="fixed inset-0 hidden bg-slate-900/30 sm:p-8" style="z-index: 60" role="dialog" aria-modal="true" aria-labelledby="chg-title">
        <div class="mx-auto flex h-full max-h-full w-full max-w-4xl flex-col bg-white text-sm shadow-lg sm:rounded-xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div id="chg-title" class="font-semibold">Changes on chatbot pages</div>
                <button type="button" data-close-dialog class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <form id="chg-filters" class="grid grid-cols-2 gap-2 border-b border-slate-200 px-4 py-2 text-xs sm:grid-cols-5">
                <label class="flex min-w-0 flex-col">Page<select name="page" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"><option value="">All pages</option></select></label>
                <label class="flex min-w-0 flex-col">User<select name="user" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"><option value="">Everyone</option></select></label>
                <label class="flex min-w-0 flex-col">Field<select name="field" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"><option value="">All fields</option></select></label>
                <label class="flex min-w-0 flex-col">From<input name="from" type="date" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></label>
                <label class="flex min-w-0 flex-col">To<input name="to" type="date" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></label>
            </form>
            <ol id="chg-list" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto px-4" aria-live="polite"></ol>
            <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 px-4 py-2 text-xs">
                <button id="chg-prev" type="button" class="cb-btn cb-btn-sm">Newer</button>
                <span id="chg-count" class="text-slate-600"></span>
                <button id="chg-next" type="button" class="cb-btn cb-btn-sm">Older</button>
            </div>
        </div>
    </div>

    {{-- Ang AI ng isang page (o ang site default): provider, fallback, Local model at timeout; sa site, pati ang cap at breaker
         ng Local. Walang key o URL dito: nasa server lang ang mga iyon. Ang palyang save ay ibinabalik ang dating value. --}}
    <div id="ai-box" class="fixed inset-0 hidden overflow-y-auto bg-slate-900/30 p-2 sm:p-8" style="z-index: 60" role="dialog" aria-modal="true" aria-labelledby="ai-title">
        <form id="ai-form" class="mx-auto w-full max-w-md rounded-xl bg-white p-4 text-sm shadow-lg">
            <div class="mb-2 flex items-start justify-between gap-3">
                <div id="ai-title" class="min-w-0 break-words font-semibold"></div>
                <button type="button" data-close-dialog class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <p id="ai-local-note" class="mb-2 hidden text-xs text-amber-700">Local AI is not set up on the server yet, so only OpenAI can be chosen.</p>
            {{-- Ang note ng Forbeast kapag low o stopped ang AI load ng account ng page (spec 210). --}}
            <p id="ai-load-dialog-note" class="ai-load-note mb-2 hidden break-words text-xs"></p>
            <div class="grid grid-cols-1 gap-2 text-xs text-slate-600 sm:grid-cols-2">
                <label class="flex flex-col">AI provider<select name="provider" class="mt-0.5 rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></select></label>
                <label class="flex flex-col">If it fails, then<select name="fallback" class="mt-0.5 rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></select></label>
                <label class="flex flex-col">If that fails too<select name="fallback2" class="mt-0.5 rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></select></label>
                <label class="flex flex-col">Claude model<input name="anthropic_model" maxlength="100" autocomplete="off" spellcheck="false" class="mt-0.5 rounded border border-slate-300 px-1 py-1 font-mono text-sm text-gray-900"></label>
                <label class="flex flex-col">DeepSeek model<input name="deepseek_model" maxlength="100" autocomplete="off" spellcheck="false" class="mt-0.5 rounded border border-slate-300 px-1 py-1 font-mono text-sm text-gray-900"></label>
                <label class="flex flex-col">Local model<input name="local_model" maxlength="100" autocomplete="off" spellcheck="false" placeholder="e.g. qwen3:14b" class="mt-0.5 rounded border border-slate-300 px-1 py-1 font-mono text-sm text-gray-900"></label>
                <label class="flex flex-col">Local timeout (s)<input name="local_timeout" type="number" min="1" max="60" inputmode="numeric" class="mt-0.5 rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></label>
                <label data-ai-site-only class="flex flex-col">Local at a time<input name="local_concurrency" type="number" min="1" max="50" inputmode="numeric" class="mt-0.5 rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></label>
                <label data-ai-site-only class="flex flex-col">Pause Local after failures<input name="breaker_failures" type="number" min="1" max="20" inputmode="numeric" class="mt-0.5 rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></label>
                <label data-ai-site-only class="flex flex-col">Local reasoning<select name="local_reasoning_effort" class="mt-0.5 rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"><option value="none">None (fastest)</option><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="">Not sent</option></select></label>
                <label data-ai-site-only class="flex flex-col">Pause for (s)<input name="breaker_seconds" type="number" min="5" max="600" inputmode="numeric" class="mt-0.5 rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></label>
            </div>
            <p id="ai-chain" class="mt-2 text-xs text-slate-600"></p>
            <p id="ai-error" class="mt-1 min-h-[1rem] text-xs text-red-600" aria-live="polite"></p>
            <div class="mt-2 flex gap-2"><button class="cb-btn cb-btn-primary">Save</button><button type="button" data-close-dialog-alt class="cb-btn">Cancel</button></div>
        </form>
    </div>
    <div id="ai-compare" class="fixed inset-0 hidden bg-slate-900/30 sm:p-8" style="z-index: 60" role="dialog" aria-modal="true" aria-labelledby="aic-title">
        <div class="mx-auto flex h-full max-h-full w-full max-w-4xl flex-col bg-white text-sm shadow-lg sm:rounded-xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div id="aic-title" class="font-semibold">Compare AI on a page</div>
                <button type="button" data-close-dialog class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <form id="aic-filters" class="grid grid-cols-2 gap-2 border-b border-slate-200 px-4 py-2 text-xs sm:grid-cols-3">
                <label class="col-span-2 flex min-w-0 flex-col sm:col-span-1">Page<select name="page" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></select></label>
                <label class="flex min-w-0 flex-col">From<input name="from" type="date" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></label>
                <label class="flex min-w-0 flex-col">To<input name="to" type="date" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></label>
            </form>
            <p id="aic-note" class="px-4 pt-2 text-xs text-slate-600" aria-live="polite"></p>
            <div class="min-h-0 flex-1 overflow-x-auto overflow-y-auto px-4 py-2">
                <table class="min-w-[40rem] text-xs"><thead><tr class="text-left text-slate-500">
                    <th class="py-1 pr-3">AI</th><th class="py-1 pr-3 text-right">Requests</th><th class="py-1 pr-3 text-right">Answered</th><th class="py-1 pr-3 text-right">Failed</th>
                    <th class="py-1 pr-3 text-right">Fell back</th><th class="py-1 pr-3 text-right">Avg ms</th><th class="py-1 pr-3 text-right">p95 ms</th>
                    <th class="py-1 pr-3 text-right">Tokens in</th><th class="py-1 pr-3 text-right">Tokens out</th>
                </tr></thead><tbody id="aic-rows"></tbody></table>
            </div>
        </div>
    </div>

    {{-- Mahabang text: modal sa gitna. Sine-save habang nagta-type at sa pagsara. --}}
    <div id="long" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="long-title">
        <div class="mx-auto w-full max-w-2xl rounded-xl bg-white p-5 text-sm shadow-lg">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div id="long-title" class="font-semibold break-words"></div>
                <button id="long-close" type="button" class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <textarea id="long-text" rows="12" class="w-full rounded-md border border-slate-300 p-2 font-mono text-sm"></textarea>
            <button id="long-template" type="button" class="mt-1 hidden text-xs text-blue-700 underline hover:text-blue-900">Use template</button>
            <p id="long-mark" class="mt-1 min-h-[1rem] text-xs" aria-live="polite"></p>
            <p id="long-warn" class="min-h-[1rem] text-xs text-amber-700" aria-live="polite"></p>
        </div>
    </div>

    {{-- "+ Column" --}}
    <div id="col-box" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="col-title">
        <form id="col-form" class="mx-auto w-full max-w-md rounded-xl bg-white p-5 text-sm shadow-lg">
            <div id="col-title" class="mb-3 font-semibold">New botfield column</div>
            <label class="flex flex-col text-xs text-slate-600">Column name
                <input name="name" maxlength="100" required placeholder="e.g. Promo Text" class="mt-1 rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
            </label>
            <p class="mt-2 text-xs text-slate-500">It appears for every page.</p>
            <p id="col-error" class="mt-1 min-h-[1rem] text-xs text-red-600"></p>
            <div class="mt-2 flex gap-2"><button class="cb-btn cb-btn-primary">Add column</button><button type="button" data-close class="cb-btn">Cancel</button></div>
        </form>
    </div>

    {{-- Connections ng isang page: Botcake at Forbeast, iisang ayos (Page ID at token). Ang token ay huling 4 lang galing sa
         server; ang bagong token ay sinusubukan muna bago i-save. --}}
    <div id="conn" class="fixed inset-0 hidden overflow-y-auto bg-slate-900/30 p-2 sm:p-8" style="z-index: 60" role="dialog" aria-modal="true" aria-labelledby="conn-title">
        <div class="mx-auto w-full max-w-2xl rounded-xl bg-white text-sm shadow-lg">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div class="min-w-0"><div class="flex items-center gap-1"><div id="conn-title" class="break-words font-semibold">Connections</div><span id="conn-fb" class="shrink-0"></span></div><div id="conn-fbname" class="break-words text-xs"></div><div class="text-xs text-slate-500">Paste a token and Save: it is tested right away and saved only when it belongs to this page.</div></div>
                <button type="button" data-close-dialog class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <div class="grid gap-3 p-4 sm:grid-cols-2">
                @foreach(['botcake' => 'Botcake', 'forbeast' => 'Forbeast'] + (\App\Services\Chatbot\Gateways::setUp('incepxionbot') ? ['incepxionbot' => 'Incepxionbot'] : []) as $p => $label)
                <form class="conn-card min-w-0 rounded-lg border border-slate-200 p-3" data-provider="{{ $p }}" autocomplete="off">
                    <div class="mb-2 flex items-center justify-between gap-2"><span class="font-semibold">{{ $label }}</span><span class="conn-state text-xs"></span></div>
                    <label class="flex flex-col text-xs text-slate-600">Page ID
                        <input name="page_id" inputmode="numeric" maxlength="30" class="mt-1 rounded-md border border-slate-300 px-2 py-1 font-mono text-sm text-gray-900">
                    </label>
                    <label class="mt-2 flex flex-col text-xs text-slate-600">Token <span class="conn-token font-mono text-slate-400"></span>
                        <input name="token" type="password" maxlength="1000" placeholder="Paste a new token" class="mt-1 rounded-md border border-slate-300 px-2 py-1 font-mono text-sm text-gray-900">
                    </label>
                    <p class="conn-tested mt-2 text-xs text-slate-500"></p>
                    <p class="conn-msg mt-1 min-h-[1rem] break-words text-xs" aria-live="polite"></p>
                    @if($p === 'botcake')<p class="conn-kw mt-1 break-words text-xs" aria-live="polite"></p>@endif
                    @if($p === 'botcake')<p class="mt-1 text-xs text-slate-500">This replaces the page's Default Reply in Botcake.</p>@endif
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button class="cb-btn cb-btn-primary cb-btn-sm">Save and test</button>
                        <button type="button" class="conn-test cb-btn cb-btn-sm">Test</button>
                        @if($p === 'botcake')<button type="button" class="conn-fix cb-btn cb-btn-sm">Fix chat block</button>@endif
                        <button type="button" class="conn-clear cb-btn cb-btn-sm">Clear</button>
                    </div>
                </form>
                @endforeach
            </div>
        </div>
    </div>

    {{-- FLOW ng isang page: hanapin, piliin, saka kumpirmahin (ang dropdown sa row ay madaling mapalitan nang hindi sinasadya). --}}
    <div id="flow-box" class="fixed inset-0 hidden overflow-y-auto bg-slate-900/30 p-2 sm:p-8" style="z-index: 60" role="dialog" aria-modal="true" aria-labelledby="flow-title">
        <div class="mx-auto flex max-h-full w-full max-w-md flex-col rounded-xl bg-white text-sm shadow-lg">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div id="flow-title" class="min-w-0 break-words font-semibold">Flow</div>
                <button type="button" data-close-dialog class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <div class="px-4 pt-3"><input id="flow-q" type="search" placeholder="Search flows" aria-label="Search flows" class="w-full rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900"></div>
            <div id="flow-list" class="min-h-0 flex-1 overflow-y-auto px-4 py-2" role="radiogroup" aria-label="Flows"></div>
            <div class="flex gap-2 border-t border-slate-200 px-4 py-3"><button id="flow-save" type="button" class="cb-btn cb-btn-primary">Save</button><button type="button" data-close-dialog class="cb-btn">Cancel</button></div>
        </div>
    </div>

    {{-- Ang filter ng isang column (gaya ng Google Sheets): hanap, Check all · Uncheck all, at isang check kada value. --}}
    <div id="hf" class="fixed hidden w-64 max-w-[calc(100vw-1rem)] rounded-lg border border-slate-200 bg-white p-2 text-xs shadow-lg" style="z-index: 40" role="dialog" aria-label="Column filter">
        <input id="hf-q" type="search" placeholder="Search values" aria-label="Search values" class="w-full rounded border border-slate-300 px-2 py-1 text-sm text-gray-900">
        <div id="hf-bulk" class="my-1 flex gap-1 text-blue-700"><button id="hf-all" type="button" class="underline">Check all</button><span aria-hidden="true">·</span><button id="hf-none" type="button" class="underline">Uncheck all</button></div>
        <div id="hf-list" class="max-h-60 space-y-0.5 overflow-y-auto"></div>
        <div class="mt-1 text-right"><button id="hf-close" type="button" class="cb-btn cb-btn-sm">Done</button></div>
    </div>

    @if($canSetUp)
    {{-- "+ Page": bago, o isang page na nasa page settings na. Ang token ay hindi kailanman ipinapakita. --}}
    <div id="page-box" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="page-title">
        <form id="page-form" class="mx-auto w-full max-w-md rounded-xl bg-white p-5 text-sm shadow-lg" autocomplete="off">
            <div id="page-title" class="mb-3 font-semibold">Add a page</div>
            <label class="flex flex-col text-xs text-slate-600">Page name, exactly as PAGE in orders, or pick one from page settings
                <input name="page_name" list="other-pages" maxlength="255" required class="mt-1 rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
            </label>
            <datalist id="other-pages">@foreach($otherPages as $p)<option value="{{ $p }}"></option>@endforeach</datalist>
            <label class="mt-2 flex flex-col text-xs text-slate-600">Botcake ID (leave blank if page settings has it)
                <input name="page_id" inputmode="numeric" maxlength="30" class="mt-1 rounded-md border border-slate-300 px-2 py-1 font-mono text-sm text-gray-900">
            </label>
            <label class="mt-2 flex flex-col text-xs text-slate-600">Botcake token (leave blank if page settings has it)
                <input name="token" type="password" maxlength="1000" class="mt-1 rounded-md border border-slate-300 px-2 py-1 font-mono text-sm text-gray-900">
            </label>
            <p class="mt-2 text-xs text-slate-500">Saved to page settings, then the token is tested and the bot is set up and switched On. This replaces the page's Default Reply in Botcake. If Botcake fails, the page is saved and stays Off.</p>
            <p id="page-error" class="mt-1 min-h-[1rem] text-xs text-red-600"></p>
            <div class="mt-2 flex gap-2"><button class="cb-btn cb-btn-primary">Add page</button><button type="button" data-close class="cb-btn">Cancel</button></div>
        </form>
    </div>
    @endif
</div>

<script src="{{ asset('js/chatbot-paste.js') }}?v={{ substr(md5_file(public_path('js/chatbot-paste.js')), 0, 10) }}"></script>
<script src="{{ asset('js/chatbot-adgroups.js') }}?v={{ substr(md5_file(public_path('js/chatbot-adgroups.js')), 0, 10) }}"></script>
<script>
(function () {
    const box = document.getElementById('cbot'), url = box.dataset, $ = id => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const PAUSE_MS = 800, table = $('cb-table');
    let columns = [], rows = [], flows = [], editing = null, gatewayReady = false, bots = [], aiSite = null, gatewayApps = {};
    const NEEDS_TOKEN = url.needsToken; // ang dahilan kapag hindi mapipili ang Forbeast
    const picked = new Set(); // mga page na may check (bulk "Replies via")
    const marks = new Map(), seqs = new Map(), errors = new Map();
    let openRow = null; // ang page ng row na bukas (isa lang)
    // Paste: ang hindi pa naka-save (id => {page, key, value, old}), ang napiling mga cell (dalawang sulok, {page, key}),
    // at ang dating value ng huling Save (para sa Undo).
    const pending = new Map();
    let sel = null, lastUndo = null;
    const botCols = () => columns.filter(c => c.key !== 'flow');
    // Ang SHOP DETAILS (kind auto) ay binubuo sa server: ipinapakita lang, hindi target ng edit, paste o mapping.
    const editCols = () => botCols().filter(c => c.kind !== 'auto');
    const SHOP_NOTE = @json(\App\Services\Chatbot\ShopDetails::NOTE);
    // "Use template": ang draft ng Comment PM at Comment Reply 1–3 (ang item at presyo ay variable ng botfield ng page). Pumupuno
    // lang ng field; ang Save (o ang pagsara ng cell editor) ang nagse-save.
    const commentTemplates = @json(\App\Services\Chatbot\CommentCheck::templates());
    const REPLY_KEYS = ['comment_reply_1', 'comment_reply_2', 'comment_reply_3'];
    // Ang hindi value ng ad group (pagkakakilanlan at mga switch ng page, at ang binubuo): wala sa Edit ng group.
    const GROUP_EXCLUDED = @json(\App\Services\Chatbot\AdGroups::EXCLUDED);

    function el(tag, attrs = {}, children = []) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (v == null) continue;
            if (k === 'class') e.className = v; else if (k === 'text') e.textContent = v; else e.setAttribute(k, v);
        }
        for (const c of [].concat(children)) if (c != null) e.append(c);
        return e;
    }
    const post = (u, body) => fetch(u, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(body)})
        .then(async r => ({ok: r.ok, status: r.status, data: await r.json().catch(() => ({}))}));
    function notice(text, cls = 'text-slate-600') { $('notice').className = 'mb-2 min-h-[1rem] text-xs ' + cls; $('notice').textContent = text; }
    const id = (page, key) => page + '\u0000' + key;
    const isUrl = v => /^https?:\/\//i.test(v || '');
    // FB icon katabi ng pangalan ng page: bukas ang chat ng page sa facebook.com (messages/t, mula sa Page ID; digits lang, ang iba ay
    // walang icon), sa bagong tab.
    // fb-link:start
    const fbLink = (id, page) => /^\d+$/.test(id || '') ? {href: 'https://www.facebook.com/messages/t/' + id, target: '_blank', rel: 'noopener noreferrer',
        title: 'Chat with ' + page + ' on Facebook', 'aria-label': 'Chat with ' + page + ' on Facebook'} : null;
    // fb-link:end
    // Ang pangalan ng page sa Facebook (naitala ng Test o ng gabi-gabing run, text lang mula sa labas): babala kapag iba sa
    // pangalan ng row o may ibang row na may parehong page ID, at ang linya ng Connections (may link sa Facebook kapag hindi alam).
    // facebook-lines:start
    function facebookLines(f, page, pageId) {
        if (!f) return {warnings: [], line: '', link: null};
        const norm = s => String(s || '').trim().replace(/\s+/g, ' ').toLowerCase();
        const warnings = [];
        if (f.differs) warnings.push('Facebook name: ' + f.name);
        if (f.same_id && f.same_id.length) warnings.push('Same page ID as ' + f.same_id.join(', ') + ': ' + (f.match
            ? (norm(f.match) === norm(page) ? 'this row matches the Facebook name' : f.match + ' matches the Facebook name')
            : (f.name ? 'no row matches the Facebook name' : 'Facebook name: unknown')));
        const line = f.name ? 'Facebook name: ' + f.name + (f.source === 'unknown' ? ' (last known; could not read it at ' + f.checked_at + ')' : '') : 'Facebook name: unknown';
        return {warnings, line, link: f.name ? null : fbLink(pageId, page)};
    }
    // facebook-lines:end
    function fbAnchor(r) {
        const a = r ? fbLink(r.page_id, r.page) : null;
        if (!a) return '';
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        for (const [k, v] of Object.entries({viewBox: '0 0 24 24', fill: '#1877F2', class: 'h-4 w-4', 'aria-hidden': 'true', focusable: 'false'})) svg.setAttribute(k, v);
        // Ang Facebook "f" (nakapirming SVG, walang data mula sa labas).
        svg.innerHTML = '<path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/>';
        return el('a', {...a, class: 'ml-1 inline-flex shrink-0 rounded align-middle hover:opacity-80 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500'}, svg);
    }

    // row-cache:start
    // Ang row ng table ay binubuo ulit lang kapag nagbago ang ipinapakita nito: ang signature ay ang data ng row at ang
    // lahat ng state na nasa row (mark, hindi pa naka-save na paste, error, Check, Ad groups, mga column). Ang reload
    // (pagkatapos ng Save, Undo o Connections) ay nag-iiwan ng lumang object ng page na walang nagbago, dahil doon
    // nakaturo ang mga button ng row niya; ang may nagbago (kahit ayos lang ng key) ay ang bagong object at bagong row.
    // Ang Chats today at Last chat ay nagbabago sa halos bawat reload (bawat bagong chat): hindi sila dahilan para buuin
    // ulit ang row; isinusulat sila sa lumang object at sa cell mismo (render).
    const RowCache = {
        VOLATILE: ['today', 'last_chat'],
        steady(r) { const out = {...r}; for (const k of RowCache.VOLATILE) delete out[k]; return out; },
        // Pareho ang data kahit iba ang ayos ng mga key (ang cell na isinulat dito ay nasa dulo ng cells sa server).
        same: (a, b) => JSON.stringify(RowCache.steady(a), RowCache.sorted) === JSON.stringify(RowCache.steady(b), RowCache.sorted),
        sorted: (k, v) => v && typeof v === 'object' && !Array.isArray(v) ? Object.fromEntries(Object.entries(v).sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))) : v,
        merge(oldRows, newRows) {
            const old = new Map(oldRows.map(r => [r.page, r]));
            return newRows.map(n => {
                const o = old.get(n.page);
                if (!o || !RowCache.same(o, n)) return n;
                for (const k of RowCache.VOLATILE) o[k] = n[k];
                return o;
            });
        },
        signature: (r, state) => JSON.stringify([RowCache.steady(r), state]),
        // Ang signature nang walang Check: kapag ito lang ang nagbago, ang Check cell lang ang pinipintura (paintCheck).
        withoutCheck: (r, state) => RowCache.signature({...r, check: null}, {...state, check: null, checking: false}),
    };
    // row-cache:end

    async function load() {
        const res = await fetch(url.dataUrl, {headers: {'Accept': 'application/json'}});
        if (!res.ok) { notice('Could not load the pages (' + res.status + ').', 'text-red-600'); return; }
        const data = await res.json();
        columns = data.columns; rows = RowCache.merge(rows, data.rows); flows = data.flows || []; gatewayReady = !!data.gateway_ready; gatewayApps = data.gateway_apps || {}; bots = data.bots || []; aiSite = data.ai_site || null;
        fillBulkVia();
        // Ang naka-cache na Check ay kasama sa data; ang wala pa ay kinukuha pagkatapos ng render (checkQueue).
        checks.clear();
        for (const r of rows) if (r.check) checks.set(r.page, r.check);
        render();
        firstPages.clear(); // ang reload na ito ang hinihintay nila
        // Pagkatapos ng frame ng render: ang pila ay tumitingin kung aling row ang nasa screen (layout), at sa loob ng
        // parehong task ay buong layout iyon ng bagong table, kasama ang render.
        const stale = staleChecks(rows);
        requestAnimationFrame(() => setTimeout(() => queueChecks(stale)));
    }

    function visible() {
        const q = $('f-q').value.trim().toLowerCase(), st = $('f-state').value;
        return rows.filter(r => (!q || r.page.toLowerCase().includes(q)) && (!st || (st === 'on') === r.active)
            && Object.entries(hfOn).every(([k, on]) => FILTERS[k].values(r).some(v => on.has(v))) && CF.match(r.connected || [], cf));
    }

    // ---- Ang Connected filter: isang row kada bot (Lahat | May | Wala), AND sa isa't isa at sa ibang column. ----
    // cf.bots[bot] = 'has' | 'not' (wala = Lahat); cf.none = walang kahit anong kumpletong koneksyon (nananaig sa mga row).
    // connected-filter:start
    const CF = {
        // Ang mga bot na alam ng data, saka ang anumang nasa connected ng mga row (ang bagong bot ay lalabas nang kusa).
        bots: (known, rows) => [...new Set([...known, ...rows.flatMap(r => r.connected || [])])],
        active: cf => cf.none || Object.values(cf.bots).some(m => m !== 'any'),
        match: (connected, cf) => cf.none ? connected.length === 0
            : Object.entries(cf.bots).every(([bot, m]) => m === 'any' || (m === 'has') === connected.includes(bot)),
        reset: () => ({bots: {}, none: false}),
    };
    // connected-filter:end
    const cf = CF.reset();

    // ---- Header filters (gaya ng Google Sheets): bawat column ay may listahan ng value; AND sa pagitan ng mga column. ----
    // hfOn[key] = ang mga value na naka-check; wala = lahat. Ang Connected ay may sariling filter (cf, sa itaas).
    const PROVIDER_LABEL = {botcake: 'Botcake', gateway: 'Forbeast'};
    // Ang gateway ng page (chatbot.gateway): ang "gateway" na provider ay ang pangalan ng aktibong gateway ng row.
    const GATEWAY_LABEL = {forbeast: 'Forbeast', incepxionbot: 'Incepxionbot'};
    const provLabel = (r, p) => p === 'gateway' ? (GATEWAY_LABEL[r.gateway] || PROVIDER_LABEL.gateway) : PROVIDER_LABEL.botcake;
    const checkKind = r => { const c = checks.get(r.page); return !c || !c.status ? 'Not checked' : (c.status === 'none' ? 'No flow' : (c.status !== 'ok' ? 'x' : (c.warnings?.length ? 'warn' : 'ok'))); };
    const FILTERS = {
        page: {label: 'Page', values: r => [r.page]},
        active: {label: 'Active', values: r => [r.active ? 'On' : 'Off']},
        bot: {label: 'Bot', values: r => [provLabel(r, r.provider)]},
        connected: {label: 'Connected', values: r => (r.connected || []).length ? r.connected : ['None']},
        flow: {label: 'Flow', values: r => [r.cells.flow || 'No flow']},
        check: {label: 'Check', values: r => [checkKind(r)]},
    };
    const hfOn = {};
    let hfKey = null;
    function filterButton(key) {
        const b = el('button', {type: 'button', class: 'ml-1 rounded px-1 font-normal normal-case ' + ((key === 'connected' ? CF.active(cf) : hfOn[key]) ? 'bg-blue-100 text-blue-800' : 'text-slate-400 hover:text-slate-700'),
            'aria-label': 'Filter ' + FILTERS[key].label, 'aria-haspopup': 'dialog', title: 'Filter ' + FILTERS[key].label, text: '▾'});
        b.addEventListener('click', ev => { ev.stopPropagation(); openFilter(key, b); });
        return b;
    }
    function openFilter(key, b) {
        hfKey = key;
        const r = b.getBoundingClientRect(), box = $('hf');
        $('hf-q').value = '';
        box.classList.remove('hidden');
        paintFilter(); // bago sukatin: ang taas ay ayon sa listahan
        box.style.top = Math.max(8, Math.min(r.bottom + 4, window.innerHeight - box.offsetHeight - 8)) + 'px';
        box.style.left = Math.max(8, Math.min(r.left, window.innerWidth - box.offsetWidth - 8)) + 'px';
        if (key === 'connected') $('hf-list').querySelector('button')?.focus(); else $('hf-q').focus();
    }
    function filterValues(key) {
        const all = new Set();
        for (const r of rows) for (const v of FILTERS[key].values(r)) all.add(v);
        return [...all].sort((a, b) => a.localeCompare(b));
    }
    function paintFilter() {
        // Ang Connected: walang hanap at Check all; ang mga row ng bot ang laman ng parehong panel.
        $('hf-q').classList.toggle('hidden', hfKey === 'connected');
        $('hf-bulk').classList.toggle('hidden', hfKey === 'connected');
        if (hfKey === 'connected') return paintConnected();
        const q = $('hf-q').value.trim().toLowerCase(), on = hfOn[hfKey];
        $('hf-list').replaceChildren(...filterValues(hfKey).filter(v => !q || v.toLowerCase().includes(q)).map(v => {
            const c = el('input', {type: 'checkbox', class: 'mr-1 align-middle'});
            c.checked = !on || on.has(v);
            c.addEventListener('change', () => setValues([v], c.checked));
            return el('label', {class: 'flex items-center break-words'}, [c, v]);
        }));
    }
    function paintConnected() {
        const focused = document.activeElement?.dataset?.cf;
        // Ang pagbabago: muling ipinipinta ang listahan, kaya ang click ay hindi umaabot sa "click sa labas" na nagsasara.
        const change = (ev, fn) => { ev.stopPropagation(); fn(); render(); paintFilter(); };
        const segment = bot => el('div', {class: 'inline-flex shrink-0 overflow-hidden rounded border border-slate-300', role: 'group', 'aria-label': bot},
            [['any', 'Lahat'], ['has', 'May'], ['not', 'Wala']].map(([m, label]) => {
                const on = (cf.bots[bot] || 'any') === m;
                const b = el('button', {type: 'button', 'data-cf': bot + ':' + m, 'aria-pressed': on ? 'true' : 'false', text: label,
                    class: 'px-2 py-0.5 ' + (on ? 'bg-blue-600 text-white' : 'bg-white text-slate-700 hover:bg-slate-100')});
                b.disabled = cf.none;
                b.addEventListener('click', ev => change(ev, () => { if (m === 'any') delete cf.bots[bot]; else cf.bots[bot] = m; }));
                return b;
            }));
        const none = el('input', {type: 'checkbox', class: 'mr-1 align-middle', 'data-cf': 'none'});
        none.checked = cf.none;
        none.addEventListener('change', ev => change(ev, () => { cf.none = none.checked; }));
        const reset = el('button', {type: 'button', class: 'text-blue-700 underline', 'data-cf': 'reset', text: 'I-reset'});
        reset.addEventListener('click', ev => change(ev, () => Object.assign(cf, CF.reset())));
        $('hf-list').replaceChildren(
            ...CF.bots(bots, rows).map(bot => el('div', {class: 'flex flex-wrap items-center justify-between gap-1 py-0.5' + (cf.none ? ' opacity-50' : '')},
                [el('span', {class: 'min-w-0 break-words', text: bot}), segment(bot)])),
            el('label', {class: 'flex items-center break-words pt-1'}, [none, 'Walang kahit anong bot']),
            el('div', {class: 'pt-1'}, reset));
        if (focused) $('hf-list').querySelector('[data-cf="' + CSS.escape(focused) + '"]')?.focus();
    }
    function setValues(values, checked) {
        const all = filterValues(hfKey), on = hfOn[hfKey] || new Set(all);
        for (const v of values) { if (checked) on.add(v); else on.delete(v); }
        if (all.every(x => on.has(x))) delete hfOn[hfKey]; else hfOn[hfKey] = on;
        render(); paintFilter();
    }
    // Check all / Uncheck all: ang mga value na nakikita sa hanap lang (gaya ng Sheets).
    const shownValues = () => { const q = $('hf-q').value.trim().toLowerCase(); return filterValues(hfKey).filter(v => !q || v.toLowerCase().includes(q)); };
    $('hf-q').addEventListener('input', paintFilter);
    $('hf-all').addEventListener('click', () => setValues(shownValues(), true));
    $('hf-none').addEventListener('click', () => setValues(shownValues(), false));
    const closeFilter = () => { $('hf').classList.add('hidden'); hfKey = null; };
    $('hf-close').addEventListener('click', closeFilter);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && hfKey) closeFilter(); });
    document.addEventListener('click', e => { if (hfKey && !$('hf').contains(e.target)) closeFilter(); });

    /** Ang header ng table: binubuo ulit lang kapag nagbago ang mga column o ang mga filter (ang bagong header ay buong layout ng table). */
    let headKey = null;
    function header() {
        const key = JSON.stringify([columns.map(c => c.key + ':' + c.label), Object.keys(hfOn).sort(), CF.active(cf)]);
        if (key === headKey && table.tHead) return;
        headKey = key;
        const head = el('tr', {}, [
            el('th', {class: 'stick-1 border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500'}, [el('input', {type: 'checkbox', id: 'sel-all', class: 'mr-1 align-middle', 'aria-label': 'Select every page in the list', title: 'Select all in the list'}), 'Page', filterButton('page')]),
            el('th', {class: 'stick-2 border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500'}, ['Active', filterButton('active'), ' Bot', filterButton('bot')]),
            el('th', {class: 'stick-3 border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500'}, ['Flow', filterButton('flow')]),
            el('th', {class: 'stick-4 border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', title: 'Values the flow uses, image links, Seq pairs and unknown variables'}, ['Check', filterButton('check')]),
            el('th', {class: 'min-w-[7rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', title: 'Bots that are fully connected'}, ['Connected', filterButton('connected')]),
            el('th', {class: 'min-w-[5rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Chats today'}),
            el('th', {class: 'min-w-[8rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Last chat'}),
            ...columns.filter(c => c.key !== 'flow').map(c => el('th', {class: 'min-w-[12rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium text-slate-500', text: c.label})),
        ]);
        const thead = el('thead', {}, head);
        if (table.tHead) table.tHead.replaceWith(thead); else table.prepend(thead);
        $('sel-all').addEventListener('change', ev => { for (const r of visible()) { if (ev.target.checked) picked.add(r.page); else picked.delete(r.page); } render(); });
    }

    function render() {
        header();
        const list = visible(), shown = new Set(list.map(r => r.page));
        // Ang bawat row ay mula sa cache kapag pareho ang signature; ang nakatago ng filter ay nasa table pa rin (hidden),
        // kaya ang filter ay nagpapakita at nagtatago lang, at ang Save ay ang row lang ng page na nagbago ang binubuo.
        // Ang row na bago o nagbago ay binubuo sa reveal (sa ibaba), at ang nakatago ay hindi binubuo hangga't hindi
        // ipinapakita; hanggang doon, ang dating row ng page (replacing) ay naiiwang nakikita, kaya walang row na nawawala.
        const states = rowStates(), want = [], keep = new Set(), ops = [];
        for (const r of rows) {
            const st = states(r), sig = RowCache.signature(r, st), hit = rowCache.get(r.page), show = shown.has(r.page);
            let entry = hit;
            // Ad groups lang ang nagbago (ipinakita, itinago o dumating ang data): ang mga sub-row at ang button lang ang
            // pinapalitan, hindi ang buong row ng page.
            if (hit && hit.sig !== sig && hit.sig !== null && hit.r === r && show && hit.nodes && !hit.lazy && !hit.nodes[0].hidden && !hit.replacing
                && RowCache.signature(r, {...st, ag: null}) === hit.main) {
                const [tr, ...oldSubs] = hit.nodes, subs = subRowsOf(r);
                for (const n of oldSubs) n.remove();
                tr.after(...subs);
                tr.querySelector('[data-reply-control]')?.replaceWith(replyControl(r));
                Object.assign(hit, {sig, base: RowCache.withoutCheck(r, st), nodes: [tr, ...subs]});
            }
            if (!hit || hit.sig !== sig || hit.r !== r) {
                const old = hit && (hit.replacing || (hit.nodes && !hit.nodes[0].hidden ? hit.nodes : null));
                entry = {sig, base: RowCache.withoutCheck(r, st), main: RowCache.signature(r, {...st, ag: null}), r, nodes: null, replacing: old && old[0].isConnected ? old : null};
                // Ang dating row habang hinihintay ang bago: nakikita pero hindi na mapipindot (ang mga button nito ay sa lumang data).
                for (const n of entry.replacing || []) n.inert = true;
            }
            rowCache.set(r.page, entry);
            keep.add(r.page);
            if (!show) entry.replacing = null;
            if (entry.replacing) want.push(...entry.replacing);
            if (entry.nodes) want.push(...entry.nodes);
            // Ang hidden ay isinusulat lang kapag nagbago (ang parehong value ay style recalc pa rin ng row).
            if (!show) { for (const n of entry.nodes || []) if (!n.hidden) n.hidden = true; } else if (!entry.nodes || entry.nodes[0].hidden) ops.push(entry);
            if (entry.nodes) paintVolatile(entry);
        }
        // Ang page na kasusulat lang (Save, Undo, paste) ang unang ipinapakita.
        ops.sort((a, b) => firstPages.has(b.r.page) - firstPages.has(a.r.page));
        for (const p of [...rowCache.keys()]) if (!keep.has(p)) rowCache.delete(p);
        if (!table.contains(tbody)) table.append(tbody);
        placeRows(tbody, want);
        table.querySelector('tbody.cb-empty')?.remove();
        $('count').textContent = list.length + ' of ' + rows.length + ' page' + (rows.length === 1 ? '' : 's');
        if (!rows.length) table.append(el('tbody', {class: 'cb-empty'}, el('tr', {}, el('td', {class: 'px-3 py-3 text-slate-500', colspan: '7', text: 'No page has a Botcake ID and token yet.'}))));
        for (const entry of rowCache.values()) if (entry.nodes) rowState(entry);
        paintSel();
        paintBar();
        paintPicked();
        reveal(ops, rows.map(r => r.page));
    }
    const firstPages = new Set();
    /** Ang Chats today at Last chat ng row na mula sa cache (hindi bahagi ng signature). */
    function paintVolatile(entry) {
        const set = (sel, text) => { const td = entry.nodes[0].querySelector(sel); if (td && td.textContent !== text) td.textContent = text; };
        set('td[data-today]', String(entry.r.today));
        set('td[data-last-chat]', entry.r.last_chat || '—');
    }
    /** Ang bukas na row at ang naka-check: sa mga row mismo (hindi bahagi ng signature, para hindi buuin ulit). */
    function rowState(entry) {
        for (const tr of entry.nodes) tr.classList.toggle('cb-open', openRow !== null && tr.dataset.page === openRow);
        const box = entry.nodes[0].querySelector('.cb-pick');
        if (box) box.checked = picked.has(entry.r.page);
    }
    /**
     * Inilalagay at ipinapakita ang mga row ng ops. Ang buong row (mga 400 element) ay binubuo lang dito kapag pinapalitan
     * nito ang buong row na nakalagay na (Save, switch) o kasusulat lang ang page; ang iba ay magaan na kapalit (lazyRow),
     * na binubuo kapag malapit nang makita (buildNear). Ang binubuo ay hanggang BUILD_MS kada frame (kahit isa), saka ang
     * kasunod sa susunod na frame. Ang bagong render ay pumapalit sa naiwang reveal (kinukuwenta nito ulit ang lahat ng
     * hindi pa nakikita). Habang may hinihintay pa, aria-busy ang table. Ang fade ay sinusukat pagkatapos ng huling bahagi.
     */
    let revealRun = 0, revealLeft = 0;
    function reveal(ops, order) {
        const run = ++revealRun;
        const step = later => {
            if (run !== revealRun) return;
            // Ang fade ng naunang bahagi (nailatag na sa nakaraang frame, kaya basa lang): ang klase ng libu-libong cell
            // nang sabay sa dulo ay isa pang mahabang frame.
            if (later) markClamped();
            const t0 = performance.now();
            let built = 0;
            while (ops.length) {
                const entry = ops[0];
                if (!entry.nodes) {
                    const full = !nearIo || (entry.replacing && !entry.replacing[0].cbLazy) || firstPages.has(entry.r.page);
                    if (full && built && performance.now() - t0 > BUILD_MS) break;
                    entry.lazy = !full;
                    entry.nodes = full ? rowNodes(entry.r) : [lazyRow(entry.r)];
                    if (full) built++;
                    rowState(entry);
                    // Sa puwesto ng dating row, o bago ang unang nakalagay na row na kasunod nito sa listahan.
                    const at = entry.replacing ? entry.replacing[0] : nextPlaced(entry.r.page, order);
                    for (const n of entry.nodes) tbody.insertBefore(n, at);
                    watchRow(entry);
                }
                ops.shift();
                for (const n of entry.nodes) n.hidden = false;
                for (const n of entry.replacing || []) { n.remove(); unwatch(n); }
                entry.replacing = null;
            }
            revealLeft = ops.length;
            paintBusy();
            paintSel();
            requestAnimationFrame(ops.length ? () => step(true) : markClamped);
        };
        step(false);
    }
    // lazy-rows:start
    // Ang row na malayo sa nakikita ay magaan na kapalit (ang pangalan ng page at ang taas ng row): ang buong row ay
    // binubuo kapag pumasok sa NEAR ng kahon ng table, at ibinabalik sa kapalit kapag lumabas sa FAR (maliban sa row na
    // bukas, ine-edit, may focus o bukas ang Ad groups). Kaya ilang screen lang ng row ang nasa table kahit ilang page:
    // ang style, layout at paint ng bawat frame (ang pagbukas ng Ad groups, ang Save) ay hindi lumalaki sa bilang ng page.
    // Ang data ng bawat row ay nasa rows pa rin: ang filter, Copy, Paste, Save at Check ay hindi nakadepende sa DOM.
    const BUILD_MS = 12, VIEW_MS = 60, NEAR_PX = 800, NEAR = NEAR_PX + 'px 0px', FAR = '3200px 0px';
    const lazyWait = new Set(), lazyNear = new Set(); // ang kapalit na wala pang sagot ng observer; ang malapit na
    let lazyH = 120, lazyFrame = 0; // ang karaniwang taas ng row (sinusukat sa markClamped)
    const IO = 'IntersectionObserver' in window ? window.IntersectionObserver : null;
    const nearIo = IO && new IO(list => {
        for (const e of list) {
            lazyWait.delete(e.target);
            // Nasa kahon mismo (hindi lang sa NEAR): binubuo agad, at aria-busy ang table hanggang doon.
            e.target.cbInView = e.isIntersecting && e.boundingClientRect.bottom > e.rootBounds.top + NEAR_PX && e.boundingClientRect.top < e.rootBounds.bottom - NEAR_PX;
            if (e.isIntersecting) lazyNear.add(e.target); else lazyNear.delete(e.target);
        }
        if (lazyNear.size && !lazyFrame) lazyFrame = requestAnimationFrame(buildNear);
        paintBusy();
    }, {root: $('cb-box'), rootMargin: NEAR});
    // Ang taas ay mula sa observer (walang basa ng layout kada row, na sunod-sunod sa pagpalit ay buong layout bawat isa).
    const farIo = IO && new IO(list => { for (const e of list) if (!e.isIntersecting) fold(e.target, e.boundingClientRect.height); }, {root: $('cb-box'), rootMargin: FAR});
    function lazyRow(r, h = lazyH) {
        const tr = el('tr', {'data-page': r.page, class: 'cb-lazy'}, [
            el('td', {class: 'stick-1 border-b border-slate-100 px-3 py-2 align-top font-medium break-words', text: r.page}),
            el('td', {class: 'border-b border-slate-100', colspan: String(6 + botCols().length), style: 'height: ' + Math.max(h, 40) + 'px'})]);
        tr.cbLazy = true;
        return tr;
    }
    /** Ang bagong lagay na row: ang kapalit ay hinihintay ng NEAR, ang buong row ay binabantayan ng FAR. */
    function watchRow(entry) {
        const tr = entry.nodes[0];
        if (entry.lazy) { lazyWait.add(tr); nearIo.observe(tr); } else farIo?.observe(tr);
    }
    function unwatch(tr) {
        nearIo?.unobserve(tr); farIo?.unobserve(tr);
        lazyWait.delete(tr); lazyNear.delete(tr);
    }
    /**
     * Ang mga kapalit na malapit na: ang nasa kahon mismo ay lahat sa frame na ito (isang layout ng table, hindi isa kada
     * row: ang bawat frame ay layout ng buong table), saka ang nasa NEAR lang hanggang BUILD_MS (kahit isa). Pagkatapos ng
     * unang screen, ang nasa kahon ay hanggang VIEW_MS kada frame: sa mabilis na scroll, ang pangalan ng page muna ang
     * kita sa isang iglap, hindi isang mahabang frame.
     */
    let firstScreen = true;
    function buildNear() {
        lazyFrame = 0;
        const t0 = performance.now();
        let built = 0;
        const states = rowStates();
        for (const tr of [...lazyNear].sort((a, b) => !!b.cbInView - !!a.cbInView)) {
            if (built && performance.now() - t0 > (!tr.cbInView ? BUILD_MS : firstScreen ? Infinity : VIEW_MS)) break;
            lazyNear.delete(tr);
            const entry = rowCache.get(tr.dataset.page);
            if (!entry || !entry.lazy || entry.nodes[0] !== tr || !tr.isConnected) { unwatch(tr); continue; }
            if (tr.hidden) continue;
            unwatch(tr);
            // Ang signature ngayon (hal. dumating ang Check habang kapalit pa ito), kaya hindi ito binubuo ulit sa susunod na render.
            const st = states(entry.r);
            Object.assign(entry, {sig: RowCache.signature(entry.r, st), base: RowCache.withoutCheck(entry.r, st), main: RowCache.signature(entry.r, {...st, ag: null}),
                nodes: rowNodes(entry.r), lazy: false});
            rowState(entry);
            tr.replaceWith(...entry.nodes);
            watchRow(entry);
            built++;
        }
        if (built) { firstScreen = false; paintSel(); requestAnimationFrame(markClamped); }
        if (lazyNear.size) lazyFrame = requestAnimationFrame(buildNear);
        paintBusy();
    }
    /** Ang buong row na napakalayo na: ibinabalik sa kapalit na kasing-taas nito (walang gumagalaw sa nakikita). */
    function fold(tr, h) {
        const entry = rowCache.get(tr.dataset.page), page = tr.dataset.page;
        if (!entry || !entry.nodes || entry.lazy || entry.replacing || entry.nodes[0] !== tr || !tr.isConnected || page === openRow || AG.isOpen(page)
            || (editing && editing.r.page === page) || entry.nodes.some(n => n.contains(document.activeElement))) return;
        const ph = lazyRow(entry.r, h || lazyH);
        ph.hidden = tr.hidden;
        tr.before(ph);
        for (const n of entry.nodes) { n.remove(); unwatch(n); }
        Object.assign(entry, {nodes: [ph], lazy: true});
        watchRow(entry);
    }
    function paintBusy() {
        if (revealLeft || lazyWait.size || [...lazyNear].some(tr => tr.cbInView)) table.setAttribute('aria-busy', 'true'); else table.removeAttribute('aria-busy');
    }
    // lazy-rows:end
    function nextPlaced(page, order) {
        for (let i = order.indexOf(page) + 1; i < order.length; i++) {
            const e = rowCache.get(order[i]), first = e && ((e.replacing && e.replacing[0]) || (e.nodes && e.nodes[0]));
            if (first && first.parentNode === tbody) return first;
        }
        return null;
    }
    const tbody = el('tbody'), rowCache = new Map(); // page => {sig, r, nodes, replacing}
    // Ang row na ginalaw na ng sariling kontrol nito (switch, select habang naghihintay): binubuo ulit kahit pareho ang data.
    // Ang dating row ay naiiwang nakikita hanggang lumabas ang bago (signature na walang katapat).
    // Ang row object ng page ngayon: ang modal o kontrol na binuksan bago ang reload ay may lumang object ng page.
    const current = r => rows.find(x => x.page === r.page) || r;
    const redraw = page => { const hit = rowCache.get(page); if (hit) hit.sig = null; render(); };
    const agVersion = new Map(); // page => ilang beses nagbago ang data ng Ad groups nito (bahagi ng signature)
    /** Ang state ng bawat row na wala sa data nito, kada page (isang ikot sa marks at pending para sa lahat ng row). */
    function rowStates() {
        const marksBy = new Map(), pendingBy = new Map();
        for (const [k, m] of marks) { const [page, key] = k.split('\u0000'); if (!marksBy.has(page)) marksBy.set(page, []); marksBy.get(page).push([key, m]); }
        for (const p of pending.values()) { if (!pendingBy.has(p.page)) pendingBy.set(p.page, []); pendingBy.get(p.page).push([p.key, p.value, p.old]); }
        const cols = columns.map(c => c.key + ':' + c.kind + ':' + c.label).join(',') + (gatewayReady ? ':gw' : ''), frozen = FROZEN.matches;
        return r => ({cols, frozen, marks: marksBy.get(r.page) || [], pending: pendingBy.get(r.page) || [], error: errors.get(r.page) || null,
            check: checks.get(r.page) || null, checking: checking.has(r.page), ag: [AG.isOpen(r.page), agVersion.get(r.page) || 0]});
    }
    function subRowsOf(r) {
        return r.reply === 'ad' && AG.isOpen(r.page) ? AG.subRows(r, {cols: 7 + botCols().length, botCols: botCols(), defaultRow: () => defaultRow(r), manage: () => manageAdGroups(r)}) : [];
    }
    /** Ang row ng page at, kapag Ad-based at bukas, ang mga sub-row nito. */
    function rowNodes(r) {
        // Ang Ad-based na page na bukas: ang mga sub-row nito (chatbot-adgroups.js) sa ilalim mismo ng row ng page; pantingin
        // lang, ang pag-ayos ay sa Edit (Manage in Edit).
        const subs = subRowsOf(r);
        return [el('tr', {'data-page': r.page}, [
            el('td', {class: 'stick-1 border-b border-slate-100 px-3 py-2 align-top font-medium break-words'}, [pickBox(r), r.page, fbAnchor(r), el('div', {class: 'font-mono text-xs text-slate-400', text: r.page_id || ''}),
                el('div', {class: 'flex flex-wrap gap-1'}, [editButton(r), previewButton(r), url.connectionsUrl ? connectionsButton(r) : null]), replyControl(r)]),
            activeCell(r),
            cell(r, columns.find(c => c.key === 'flow'), 'stick-3'),
            checkCell(r),
            // Ang mga kumpletong koneksyon lang, text.
            el('td', {class: 'border-b border-slate-100 px-3 py-2 align-top text-xs'}, [(r.connected || []).join(', ') || '—']
                .concat(r.botcake_keywords === 'on' ? [el('div', {class: 'break-words text-red-600', title: 'May naka-on na keyword sa Botcake: i-off doon, saka i-Test ulit', text: 'Botcake: keyword on'})] : [])),
            el('td', {class: 'border-b border-slate-100 px-3 py-2 align-top', 'data-today': '', text: String(r.today)}),
            el('td', {class: 'border-b border-slate-100 px-3 py-2 align-top text-xs text-slate-500', 'data-last-chat': '', text: r.last_chat || '—'}),
            ...columns.filter(c => c.key !== 'flow').map(c => cell(r, c)),
        ]), ...subs];
    }
    /**
     * Inilalagay ang mga row sa ayos ng want, ginagalaw lang ang nagbago: inaalis muna ang wala na, saka isinisingit ang
     * bago sa puwesto nito. Ang row na hindi gumalaw ay hindi inaalis at ibinabalik (ang muling pagkabit ay buong style at
     * layout ulit ng row).
     */
    function placeRows(parent, want) {
        const wanted = new Set(want);
        for (const n of [...parent.children]) if (!wanted.has(n)) { n.remove(); unwatch(n); }
        let at = parent.firstElementChild;
        for (const n of want) {
            if (n === at) { at = at.nextElementSibling; continue; }
            parent.insertBefore(n, at);
        }
    }

    // ---- Reply values: Page-based (ang default: page values sa lahat) o Ad-based (ang mga ad group ng page) ----
    const AG = ChatbotAdGroups.create({csrf, urls: {index: url.agUrl, save: url.agUrl, add: url.agAddUrl, del: url.agDeleteUrl, history: url.agHistoryUrl,
        restore: url.agRestoreUrl, mode: url.agModeUrl, value: url.agValueUrl, removeAd: url.agRemoveAdUrl, ad: url.agAdUrl}, onChange: page => { agVersion.set(page, (agVersion.get(page) || 0) + 1); if (editing || pe) agStale = true; else render(); }, edit: (r, g) => openGroupEdit(r, g)});
    // Nagbago ang Ad groups habang bukas ang Edit (o may ine-edit na cell): ang table ay ipinipinta pagsara ng Edit, hindi
    // sa likod nito sa bawat sulat.
    let agStale = false;
    /** Manage in Edit: ang Edit ng page, nakatapat sa Ad groups. */
    function manageAdGroups(r) {
        openPe(r);
        $('pe-ag').scrollIntoView({block: 'start'});
        $('pe-ag-title').focus();
    }
    // Ang lapad ng kahon para sa buong-lapad na sub-row (--cbw): isinusulat lang kapag nagbago.
    function setBoxWidth(px = $('cb-box').clientWidth) {
        const w = px + 'px';
        if ($('cb-box').style.getPropertyValue('--cbw') !== w) $('cb-box').style.setProperty('--cbw', w);
    }
    window.addEventListener('resize', () => setBoxWidth());
    function replyControl(r) {
        const sel = el('select', {class: 'mt-0.5 block w-full max-w-[9rem] rounded border border-slate-300 px-1 py-0.5 text-xs text-gray-900', 'aria-label': 'Reply values of ' + r.page,
            title: 'Page-based: the page values for every customer. Ad-based: the values of the customer\'s ad group.'},
            [el('option', {value: 'page', text: 'Page-based'}), el('option', {value: 'ad', text: 'Ad-based'})]);
        sel.value = r.reply === 'ad' ? 'ad' : 'page';
        sel.addEventListener('change', async () => {
            const ad = sel.value === 'ad';
            if (!confirm(ad ? 'Make ' + r.page + ' Ad-based? Customers whose latest ad is in one of its ad groups get that group\'s values; everyone else gets the page values.'
                : 'Make ' + r.page + ' Page-based? Every customer gets the page values; its ad groups are kept but not used.')) { sel.value = r.reply === 'ad' ? 'ad' : 'page'; return; }
            sel.disabled = true;
            const res = await AG.setMode(r.page, ad);
            if (res.error) errors.set(r.page, res.error); else { r.reply = res.mode; errors.delete(r.page); }
            redraw(r.page);
        });
        const out = [el('div', {class: 'mt-1 text-xs font-normal text-slate-500', text: 'Replies'}), sel];
        if (r.reply === 'ad') {
            const open = AG.isOpen(r.page);
            const b = el('button', {type: 'button', class: 'mt-1 inline-flex max-w-full flex-wrap items-center gap-1 rounded border border-blue-300 bg-blue-50 px-1.5 py-0.5 text-left text-xs font-normal text-blue-800 hover:bg-blue-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
                'aria-expanded': open ? 'true' : 'false', 'aria-label': (open ? 'Hide' : 'Show') + ' the ad groups of ' + r.page + (r.ad_new ? ' (' + r.ad_new + ' new ads not in a group)' : '')},
                [el('span', {'aria-hidden': 'true', text: open ? '▾' : '▸'}), 'Ad groups', r.ad_new ? el('span', {class: 'rounded bg-blue-600 px-1 text-[11px] font-semibold text-white', 'aria-hidden': 'true', text: r.ad_new + ' new'}) : null]);
            b.addEventListener('click', () => { AG.toggle(r.page); render(); });
            out.push(b);
        }
        return el('div', {'data-reply-control': ''}, out);
    }
    /** Ang Default na sub-row ng Ad-based na page: ang page values mismo (parehong mga cell ng row ng page). */
    function defaultRow(r) {
        const td = (cls = '') => el('td', {class: cls + ' border-b border-slate-100 px-3 py-2 align-top'});
        return el('tr', {'data-page': r.page, class: 'ag-sub' + (r.page === openRow ? ' cb-open' : '')}, [
            el('td', {class: 'stick-1 border-b border-slate-100 px-3 py-2 align-top text-xs break-words'}, [
                el('div', {class: 'flex items-start gap-1 font-medium'}, [el('span', {'aria-hidden': 'true', class: 'text-slate-400', text: '↳'}), 'Default (page values)']),
                el('div', {class: 'text-slate-500', text: 'The page values: customers whose ad is in no group, or with no ad.'})]),
            td('stick-2'), cell(r, columns.find(c => c.key === 'flow'), 'stick-3'), td('stick-4'), td(), td(), td(),
            ...botCols().map(c => cell(r, c)),
        ]);
    }

    // Edit: binubuksan ang lahat ng botfield ng page (sa phone, lapis lang; ang pangalan ay nasa aria-label).
    function editButton(r) {
        const b = el('button', {type: 'button', class: 'mt-1 inline-flex items-center gap-1 rounded border border-slate-300 px-1.5 py-0.5 text-xs font-normal text-slate-600 hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
            'aria-label': 'Edit ' + r.page, title: 'Edit ' + r.page}, [el('span', {'aria-hidden': 'true', text: '✎'}), el('span', {class: 'hidden sm:inline', text: 'Edit'})]);
        b.addEventListener('click', () => openPe(r));
        return b;
    }

    function previewButton(r) {
        const b = el('button', {type: 'button', class: 'mt-1 inline-flex items-center gap-1 rounded border border-slate-300 px-1.5 py-0.5 text-xs font-normal text-slate-600 hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
            'aria-label': 'Preview ' + r.page, title: 'Preview what a customer sees'}, [el('span', {'aria-hidden': 'true', text: '👁'}), el('span', {class: 'hidden sm:inline', text: 'Preview'})]);
        b.addEventListener('click', () => openPreview(r.page));
        return b;
    }

    // ---- Check column ----
    // Ang mga icon na gamit sa Likha (gaya ng Check sa page settings): bilog na may check, bilog na may X, o tatsulok na
    // babala (amber: ang content check na hindi sigurado).
    const ICON_PATHS = {ok: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>', x: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        warn: '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>'};
    const ICON_COLORS = {ok: 'text-green-600', x: 'text-red-600', warn: 'text-amber-600'};
    function icon(kind, label) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        for (const [k, v] of Object.entries({viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round',
            class: 'h-4 w-4 shrink-0 ' + ICON_COLORS[kind], role: 'img', 'aria-label': label})) svg.setAttribute(k, v);
        svg.innerHTML = ICON_PATHS[kind]; // nakapirming SVG, walang data mula sa labas
        return svg;
    }
    const checks = new Map(), checking = new Set();
    function checkCell(r) {
        const td = el('td', {class: 'stick-4 border-b border-slate-100 bg-white px-3 py-2 align-top text-xs', 'data-check': r.page});
        fillCheck(td, r.page);
        return td;
    }
    // Ang buod ng Check para sa maikling cell (naka-freeze): ang icon, ilang problema (mali at babala) at ang unang linya;
    // null = walang problema o wala pang resulta (ang buong cell na ang ipinapakita).
    // check-summary:start
    function checkSummary(c, extra = []) {
        const done = c && c.status && c.status !== 'none';
        if (!done && !extra.length) return null;
        const reasons = done && c.status !== 'ok' ? (c.reasons || []) : [], warnings = [...(done ? c.warnings || [] : []), ...extra];
        if (!reasons.length && !warnings.length) return null;
        return {icon: reasons.length ? 'x' : 'warn', count: reasons.length + warnings.length, first: (reasons[0] ?? warnings[0])};
    }
    // check-summary:end
    const FROZEN = window.matchMedia('(min-width: 1024px)');
    FROZEN.addEventListener('change', () => render());
    function fillCheck(td, page) {
        const c = checks.get(page), out = [];
        if (checking.has(page) || !c) out.push(el('span', {class: 'text-slate-400', text: c?.error || 'Checking…'}));
        else if (c.status === 'none') out.push(el('span', {class: 'text-slate-400', text: '—', title: 'No flow picked'}));
        else if (c.status === 'ok' && !c.warnings?.length) out.push(el('div', {class: 'flex items-start gap-1'}, [icon('ok', 'Check passed'), el('span', {class: 'text-green-700', text: 'All values pass'})]));
        else if (c.status !== 'ok') out.push(el('div', {class: 'flex items-start gap-1'}, [icon('x', 'Check failed'), el('ul', {class: 'min-w-0 space-y-0.5 text-red-700'}, c.reasons.map(t => el('li', {class: 'break-words', text: t})))]));
        // Ang content check na hindi sigurado: babala (amber), hindi X.
        if (c?.warnings?.length) out.push(el('div', {class: 'flex items-start gap-1' + (c.status === 'ok' ? '' : ' mt-1')}, [icon('warn', 'Check warning'), el('ul', {class: 'min-w-0 space-y-0.5 text-amber-700'}, c.warnings.map(t => el('li', {class: 'break-words', text: t})))]));
        if (c?.notes?.length) out.push(el('ul', {class: 'mt-1 min-w-0 space-y-0.5 text-slate-500'}, c.notes.map(t => el('li', {class: 'break-words', text: t}))));
        if (c?.unchecked) out.push(el('div', {class: 'text-slate-500', text: c.unchecked + ' image link' + (c.unchecked === 1 ? '' : 's') + ' not checked yet'}));
        if (c?.content?.unchecked) out.push(el('div', {class: 'text-slate-500', text: c.content.unchecked + ' content check' + (c.content.unchecked === 1 ? '' : 's') + ' not done yet'}));
        // Ang item na pinagkukumparahan at kung saan galing (orders, ads o item settings).
        const exp = c?.content?.expected;
        if (exp) out.push(el('div', {class: 'text-slate-500 break-words', text: 'Item: ' + exp.item + (exp.price !== null ? ' ₱' + exp.price : '') + ' (' + ({orders: 'orders, 3 days', ads: 'ads', item_settings: 'item settings'}[exp.source] || exp.source) + ')'}));
        // Ang pangalan sa Facebook at ang parehong page ID: babala rin, kasama sa bilang ng maikling cell.
        const r = rows.find(x => x.page === page), fbWarn = facebookLines(r ? r.facebook : null, page, r ? r.page_id : null).warnings;
        if (fbWarn.length) out.push(el('div', {class: 'mt-1 flex items-start gap-1', 'data-facebook': ''}, [icon('warn', 'Facebook name warning'), el('ul', {class: 'min-w-0 space-y-0.5 text-amber-700'}, fbWarn.map(t => el('li', {class: 'break-words', text: t})))]));
        if (c && !checking.has(page) && c.status) {
            const again = el('button', {type: 'button', class: 'text-blue-700 underline hover:text-blue-900', text: 'Check again', 'aria-label': 'Check ' + page + ' again'});
            again.addEventListener('click', ev => { ev.stopPropagation(); runCheck(page, true); });
            out.push(el('div', {class: 'mt-1 flex flex-wrap items-center gap-x-2 text-slate-400'}, [c.checked_at ? el('span', {text: c.checked_at}) : null, again]));
        }
        const sum = FROZEN.matches && !checking.has(page) ? checkSummary(c, fbWarn) : null;
        if (!sum) { td.replaceChildren(...out); return; }
        // Naka-freeze: isang linya; ang buong listahan (kasama ang Check again) ay sa hover, sa pindot o sa focus.
        const box = el('div', {class: 'cb-chk'});
        const toggle = el('button', {type: 'button', class: 'flex w-full min-w-0 items-start gap-1 text-left', 'aria-expanded': 'false',
            'aria-label': sum.count + ' problem' + (sum.count === 1 ? '' : 's') + ' on ' + page + ': show all'}, [icon(sum.icon, sum.icon === 'x' ? 'Check failed' : 'Check warning'),
            el('span', {class: 'shrink-0 font-semibold ' + (sum.icon === 'x' ? 'text-red-700' : 'text-amber-700'), text: String(sum.count)}),
            el('span', {class: 'min-w-0 truncate ' + (sum.icon === 'x' ? 'text-red-700' : 'text-amber-700'), text: sum.first})]);
        toggle.addEventListener('click', ev => { ev.stopPropagation(); const open = box.classList.toggle('cb-chk-open'); toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); });
        // Escape: isinasara at ibinabalik ang focus sa linya (ang focus-within ay hindi na nagpapakita ng listahan).
        box.addEventListener('keydown', ev => { if (ev.key !== 'Escape') return; ev.stopPropagation(); box.classList.remove('cb-chk-open'); box.classList.add('cb-chk-shut'); toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); });
        box.addEventListener('mouseleave', () => box.classList.remove('cb-chk-shut'));
        toggle.addEventListener('click', () => box.classList.remove('cb-chk-shut'));
        box.append(toggle, el('div', {class: 'cb-chk-pop rounded-lg border border-slate-200 bg-white p-2 shadow-lg'}, out));
        td.replaceChildren(box);
    }
    // Ang Check cell lang ng page ang pinipintura; ang naka-cache na row ay may bagong signature (hindi binubuo ulit sa
    // susunod na render).
    function paintCheck(page) {
        // Lahat ng cell ng page: kasama ang dating row na nakikita pa habang hinihintay ng bago ang reveal.
        const tds = [...table.querySelectorAll('td[data-check="' + CSS.escape(page) + '"]')], entry = rowCache.get(page);
        for (const td of tds) fillCheck(td, page);
        // Kapag ang Check lang ang nagbago mula nang buuin ang row; kung may iba pa (hal. mark ng pumalyang save habang may
        // ine-edit), naiiwan ang lumang signature para buuin ulit ang row sa susunod na render.
        if (entry && entry.nodes && tds.some(td => entry.nodes[0].contains(td))) {
            const st = rowStates()(entry.r);
            if (RowCache.withoutCheck(entry.r, st) === entry.base) { entry.sig = RowCache.signature(entry.r, st); entry.main = RowCache.signature(entry.r, {...st, ag: null}); }
        }
    }
    async function runCheck(page, again = false) {
        checking.add(page); paintCheck(page);
        try {
            const res = again ? await post(url.checkUrl, {page_name: page})
                : await fetch(url.checkUrl + '?page_name=' + encodeURIComponent(page), {headers: {'Accept': 'application/json'}}).then(async r => ({ok: r.ok, status: r.status, data: await r.json().catch(() => ({}))}));
            // Ang resulta lang (walang page), gaya ng ibabalik ng /chatbot/data sa susunod na reload (ang naka-cache na
            // Check): kaya ang row ay hindi "nagbago" sa reload at hindi binubuo ulit.
            const {page: _page, ...result} = res.ok ? res.data : {};
            checks.set(page, res.ok ? result : {error: 'Could not check (' + res.status + ')'});
            const row = rows.find(x => x.page === page);
            if (row && res.ok) row.check = result;
        } catch (e) {
            checks.set(page, {error: 'Could not check: no connection'});
        }
        checking.delete(page); paintCheck(page);
    }
    // check-queue:start
    // Ang Check ng mga page na walang naka-cache na resulta sa data (ang may resulta ay hindi na tinatanong). Dalawa nang
    // sabay, para hindi maipon ang mga request sa likod ng Connections at ng ibang tawag ng page; ang susunod ay laging
    // ang pinili ng pick (ang nasa screen muna).
    const CHECKS_AT_ONCE = 2;
    const staleChecks = rows => rows.filter(r => !r.check).map(r => r.page);
    function checkQueue(pages, run, pick) {
        const todo = [...pages];
        const next = () => {
            if (!todo.length) return Promise.resolve();
            const p = pick(todo);
            todo.splice(todo.indexOf(p), 1);
            return Promise.resolve(run(p)).then(next, next);
        };
        return Promise.all(Array.from({length: Math.min(CHECKS_AT_ONCE, todo.length)}, next));
    }
    // check-queue:end
    // Ang susunod na Check: ang row na nakikita sa screen, saka ang ipinapakita ng mga filter, saka ang iba (sa ayos ng listahan).
    function nextCheck(todo) {
        const cells = new Map([...table.querySelectorAll('td[data-check]')].map(td => [td.dataset.check, td]));
        const onScreen = p => { const b = cells.get(p)?.getBoundingClientRect(); return !!b && b.bottom > 0 && b.top < window.innerHeight; };
        const shown = new Set(visible().map(r => r.page));
        return todo.find(onScreen) ?? todo.find(p => shown.has(p)) ?? todo[0];
    }
    const queueChecks = pages => checkQueue(pages, runCheck, nextCheck);

    // ---- Preview, History, Changes (mga modal sa ibabaw) ----
    function dialog(boxId, show) {
        $(boxId).classList.toggle('hidden', !show);
        if (show) $(boxId).querySelector('[data-close-dialog]').focus();
    }
    document.querySelectorAll('[data-close-dialog]').forEach(b => b.addEventListener('click', () => dialog(b.closest('[role="dialog"]').id, false)));
    ['pv', 'hist', 'chg', 'conn', 'flow-box', 'ai-box', 'ai-compare'].forEach(d => $(d).addEventListener('click', e => { if (e.target === $(d)) dialog(d, false); }));
    const getJson = u => fetch(u, {headers: {'Accept': 'application/json'}}).then(async r => ({ok: r.ok, status: r.status, data: await r.json().catch(() => ({}))}));
    /** Ang value na 3 linya kapag sarado, may "Show all" kapag may kasunod pa. */
    // Lahat ng value ng isang listahan ay sinusukat sa iisang frame (basa muna lahat, saka ang mga "Show all").
    const foldQueue = [];
    function folded(text, cls = '') {
        const box = el('div', {class: 'cb-fold ' + cls, text: text === '' ? '(empty)' : text});
        const wrap = el('div', {class: 'min-w-0'}, box);
        box.foldWrap = wrap;
        if (foldQueue.push(box) === 1) requestAnimationFrame(() => foldPass(foldQueue.splice(0), b => {
            const more = el('button', {type: 'button', class: 'text-xs text-blue-700 underline', text: 'Show all'});
            more.addEventListener('click', () => { const open = b.classList.toggle('cb-unfold'); more.textContent = open ? 'Show less' : 'Show all'; });
            b.foldWrap.append(more);
        }));
        return wrap;
    }

    async function openPreview(page) {
        $('pv-title').textContent = 'Preview · ' + page;
        $('pv-sub').textContent = 'Loading…';
        $('pv-list').replaceChildren();
        dialog('pv', true);
        const {ok, status, data} = await getJson(url.previewUrl + '?page_name=' + encodeURIComponent(page));
        if (!ok) { $('pv-sub').textContent = data.error || 'Could not load (' + status + ').'; return; }
        $('pv-sub').textContent = 'As the customer "' + data.sample + '" would see it. Read-only.';
        if (!data.items.length) { $('pv-list').append(el('p', {class: 'text-slate-500', text: 'No main texts or Seq values yet.'})); return; }
        $('pv-list').replaceChildren(...data.items.map(it => el('section', {}, [
            el('div', {class: 'mb-1 text-xs font-medium text-slate-500', text: it.label}),
            it.image ? el('img', {src: it.image, alt: it.label, loading: 'lazy', referrerpolicy: 'no-referrer', class: 'mb-1 max-h-48 max-w-[80%] rounded-2xl border border-slate-200 object-contain'}) : null,
            it.text !== null ? el('div', {class: 'max-w-[85%] whitespace-pre-line break-words rounded-2xl bg-white px-3 py-2 shadow-sm ring-1 ring-slate-200', text: it.text}) : null,
        ])));
    }
    $('pe-preview').addEventListener('click', () => pe && openPreview(pe.r.page));

    let hist = null; // {page, key, label}
    async function openHistory(page, c) {
        hist = {page, key: c.key, label: c.label};
        $('hist-title').textContent = 'History · ' + c.label;
        $('hist-sub').textContent = page;
        $('hist-note').textContent = '';
        dialog('hist', true);
        await loadHistory();
    }
    async function loadHistory() {
        $('hist-list').replaceChildren(el('li', {class: 'py-2 text-slate-500', text: 'Loading…'}));
        const {ok, status, data} = await getJson(url.historyUrl + '?page_name=' + encodeURIComponent(hist.page) + '&key=' + encodeURIComponent(hist.key));
        if (!ok) { $('hist-list').replaceChildren(el('li', {class: 'py-2 text-red-600', text: data.error || 'Could not load (' + status + ').'})); return; }
        if (!data.versions.length) { $('hist-list').replaceChildren(el('li', {class: 'py-2 text-slate-500', text: 'No versions yet.'})); return; }
        $('hist-list').replaceChildren(...data.versions.map(v => {
            const restore = v.restorable ? el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Restore', 'aria-label': 'Restore the version of ' + v.at}) : null;
            restore?.addEventListener('click', () => restoreVersion(v, restore));
            return el('li', {class: 'py-2'}, [
                el('div', {class: 'mb-1 flex flex-wrap items-center gap-x-2 text-xs text-slate-500'}, [
                    el('span', {class: 'rounded px-1.5 py-0.5 font-medium ' + (v.current ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600'), text: v.current ? 'Current' : 'Old'}),
                    el('span', {text: v.by}), el('span', {text: v.at || ''}), restore ? el('span', {class: 'ml-auto'}, restore) : null,
                ]),
                folded(v.value, v.secret ? 'italic text-slate-400' : ''),
            ]);
        }));
    }
    async function restoreVersion(v, btn) {
        if (!confirm('Restore this ' + hist.label + ' value for ' + hist.page + '? The current value stays in the history.')) return;
        btn.disabled = true;
        const {ok, status, data} = await post(url.restoreUrl, {id: v.id});
        if (!ok) { btn.disabled = false; $('hist-note').className = 'px-4 pt-2 text-xs text-red-600'; $('hist-note').textContent = data.error || 'Not restored (' + status + ').'; return; }
        $('hist-note').className = 'px-4 pt-2 text-xs text-green-700';
        $('hist-note').textContent = data.status === 'unchanged' ? 'That is already the current value.' : 'Restored. It is now the current value.';
        // Ang bukas na Edit ng page: ang field na ito ay ang naibalik na ngayon (hindi "binago").
        const r = rows.find(x => x.page === hist.page);
        if (r) r.cells[data.key] = data.value;
        if (pe && pe.r.page === hist.page && peInput(data.key)) { pe.orig[data.key] = data.value; peInput(data.key).value = data.value; grow(peInput(data.key)); paintPe(); }
        render();
        await loadHistory();
    }

    let chgPage = 1, chgLists = false;
    async function loadChanges() {
        const f = new FormData($('chg-filters')), q = new URLSearchParams();
        for (const [k, v] of f.entries()) if (v) q.set(k, v);
        q.set('p', chgPage);
        $('chg-list').replaceChildren(el('li', {class: 'py-2 text-slate-500', text: 'Loading…'}));
        const {ok, status, data} = await getJson(url.changesUrl + '?' + q);
        if (!ok) { $('chg-list').replaceChildren(el('li', {class: 'py-2 text-red-600', text: data.error || 'Could not load (' + status + ').'})); return; }
        if (!chgLists) {
            const fill = (name, items) => $('chg-filters').elements[name].append(...items.map(([v, t]) => el('option', {value: v, text: t})));
            fill('page', data.pages.map(p => [p, p]));
            fill('user', data.users.map(u => [u.id, u.name]));
            fill('field', data.fields.map(x => [x.key, x.label]));
            chgLists = true;
        }
        chgPage = data.page;
        $('chg-count').textContent = data.total ? 'Page ' + data.page + ' of ' + data.last_page + ' · ' + plural(data.total, 'change') : 'No changes match.';
        $('chg-prev').disabled = data.page <= 1;
        $('chg-next').disabled = data.page >= data.last_page;
        $('chg-list').replaceChildren(...data.entries.map(e => el('li', {class: 'py-2'}, [
            el('div', {class: 'mb-1 flex flex-wrap gap-x-2 text-xs text-slate-500'}, [el('span', {text: e.at || ''}), el('span', {text: e.by}), el('span', {class: 'font-medium text-slate-800', text: e.page}), el('span', {class: 'font-medium text-slate-700', text: e.field})]),
            el('div', {class: 'grid gap-1 sm:grid-cols-[1fr_auto_1fr] sm:gap-2'}, [
                e.old === null ? el('div', {class: 'text-xs italic text-slate-400', text: '(new)'}) : folded(e.old, e.old === '(none)' ? 'italic text-slate-400' : 'text-slate-500 line-through decoration-slate-300'),
                el('span', {class: 'text-slate-400', 'aria-label': 'changed to', text: '→'}),
                folded(e.new, ['(hidden)', '(none)'].includes(e.new) ? 'italic text-slate-400' : 'text-slate-900'),
            ]),
        ])));
        $('chg-list').scrollTop = 0;
    }
    $('changes-open').addEventListener('click', () => { dialog('chg', true); chgPage = 1; loadChanges(); });
    // ?changes=<page>&field=<key> (mula sa banner ng mga problema): bukas agad ang Changes, naka-filter sa page at field.
    const chgDeep = new URLSearchParams(location.search);
    if (chgDeep.has('changes')) {
        dialog('chg', true);
        loadChanges().then(() => {
            const f = $('chg-filters').elements;
            f.page.value = chgDeep.get('changes');
            // Ang auto_switch (talaan ng kusang lipat) ay wala sa listahan ng field: idinadagdag para ma-filter.
            const key = chgDeep.get('field') || '';
            if (key && ![...f.field.options].some(o => o.value === key)) f.field.append(el('option', {value: key, text: key === 'auto_switch' ? 'Auto switch' : key}));
            f.field.value = key;
            loadChanges();
        });
    }
    $('chg-filters').addEventListener('change', () => { chgPage = 1; loadChanges(); });
    $('chg-filters').addEventListener('submit', e => e.preventDefault());
    $('chg-prev').addEventListener('click', () => { chgPage--; loadChanges(); });
    $('chg-next').addEventListener('click', () => { chgPage++; loadChanges(); });

    // Ang fade: sa text na lampas sa 3 linya ng row na sarado.
    // Ang basa pagkatapos ng sulat ay buong layout ng table bawat cell (libu-libong cell: ilang segundo, "Page Unresponsive"):
    // kaya sukat muna ang lahat (iisang layout), saka ang sulat.
    // clamp:start
    function clampPass(cells) {
        const seen = cells.map(t => [t, t.scrollTop, t.scrollHeight > t.clientHeight + 1]);
        for (const [t, top, over] of seen) { if (top) t.scrollTop = 0; t.classList.toggle('cb-clamped', over); }
    }
    /** Ang mga value na lampas sa 3 linya (basa lahat), saka ang add(box) sa bawat isa. */
    function foldPass(boxes, add) {
        for (const b of boxes.filter(b => b.scrollHeight > b.clientHeight + 1)) add(b);
    }
    // clamp:end
    // Ang row na nasukat na sa parehong lapad ng table ay hindi na sinusukat ulit: ang 3 linya ay nakadepende lang sa
    // laman (bagong row kapag nagbago) at sa lapad ng column (nagbabago ang lapad ng table kapag may column na lumapad o
    // kumitid). Ang row na isinara ay sinusukat ulit (toggleRow).
    function markClamped() {
        const w = $('cb-box').clientWidth, tw = table.offsetWidth; // basa rin, kasama ng sukat ng mga cell
        const trs = [...table.querySelectorAll('tbody tr:not(.cb-open):not([hidden])')].filter(tr => tr.cbClampWidth !== tw);
        // Ang karaniwang taas ng buong row, para sa taas ng kapalit (lazyRow): basa rin, sa parehong layout.
        const full = trs.filter(tr => !tr.cbLazy && !tr.classList.contains('ag-sub'));
        if (full.length) lazyH = Math.round(full.reduce((s, tr) => s + tr.offsetHeight, 0) / full.length);
        clampPass(trs.flatMap(tr => [...tr.querySelectorAll('.cb-txt')]));
        for (const tr of trs) tr.cbClampWidth = tw;
        setBoxWidth(w);
    }

    /** Buksan ang buong row (isara ang dati), o isara kapag ito na ang bukas. */
    function toggleRow(page) {
        openRow = openRow === page ? null : page;
        table.querySelectorAll('tbody tr').forEach(tr => { if (tr.classList.contains('cb-open') && tr.dataset.page !== openRow) tr.cbClampWidth = null; tr.classList.toggle('cb-open', tr.dataset.page === openRow); });
        requestAnimationFrame(markClamped);
    }

    function activeCell(r) {
        const td = el('td', {class: 'stick-2 border-b border-slate-100 px-3 py-2 align-top'});
        const b = el('button', {type: 'button', role: 'switch', class: 'inline-flex items-center gap-2 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed', title: r.active ? 'Switch the bot off' : 'Switch the bot on', 'aria-label': (r.active ? 'Switch off ' : 'Switch on ') + r.page}, [
            el('span', {class: 'relative h-[22px] w-10 shrink-0 rounded-full transition-colors duration-200'}, el('span', {class: 'absolute left-[2px] top-[2px] h-[18px] w-[18px] rounded-full bg-white shadow transition-transform duration-200'})),
            el('span', {class: 'w-6 text-left text-xs font-medium'}),
        ]);
        paintSwitch(b, r.active);
        if (url.activeUrl) b.addEventListener('click', () => toggle(r, b)); else b.disabled = true; // CEO lang ang makakapag-switch
        td.append(b, repliesViaSelect(r), gatewaySelect(r), aiButton(r));
        if (r.ai_load) td.append(aiLoadNote(r.ai_load));
        if (r.auto_switched) td.append(autoSwitchNote(r.auto_switched));
        const err = errors.get(r.page);
        if (err) td.append(el('div', {class: 'mt-1 max-w-[12rem] text-xs text-red-600 break-words', text: err}));
        return td;
    }

    // ---- Flow popup: hanap, pili ("No flow" o isang flow), saka "Change flow: A → B?". Isinusulat gaya ng isang cell
    // (FLOW, nakatali ayon sa id), kaya nasa Changes ang user at oras. ----
    let flowPick = null; // {r, c, value}
    function openFlow(r, c) {
        flowPick = {r, c, value: r.cells[c.key] ?? ''};
        $('flow-title').textContent = 'Flow · ' + r.page;
        $('flow-q').value = '';
        paintFlows();
        dialog('flow-box', true);
        $('flow-q').focus();
    }
    function paintFlows() {
        const q = $('flow-q').value.trim().toLowerCase();
        const names = [''].concat(flows).filter(n => !q || (n || 'No flow').toLowerCase().includes(q));
        $('flow-list').replaceChildren(...names.map(n => {
            const radio = el('input', {type: 'radio', name: 'flow-pick', class: 'mr-2 align-middle'});
            radio.checked = n === flowPick.value;
            radio.addEventListener('change', () => { flowPick.value = n; });
            return el('label', {class: 'flex items-center break-words rounded px-1 py-1 hover:bg-slate-50' + (n === '' ? ' italic text-slate-500' : '')}, [radio, n || 'No flow']);
        }));
        if (!names.length) $('flow-list').append(el('p', {class: 'py-2 text-slate-500', text: 'No flow matches.'}));
    }
    $('flow-q').addEventListener('input', paintFlows);
    $('flow-save').addEventListener('click', () => {
        const {c, value} = flowPick, r = current(flowPick.r), before = r.cells[c.key] ?? '';
        if (value === before && !(value === '' && r.flow_text)) { dialog('flow-box', false); return; }
        const from = before || (r.flow_text ? 'Not linked: ' + r.flow_text : 'No flow');
        if (!confirm('Change flow: ' + from + ' → ' + (value || 'No flow') + '?')) return;
        dialog('flow-box', false);
        r.flow_text = null; save(r, c, value);
    });

    // ---- Connections (Botcake at Forbeast ng isang page) ----
    // Ang token ay huling 4 lang galing sa server. Ang Save ay sumusubok muna; ang ibang site na nakakabit na ay tinatanong
    // (409) bago ilipat. Ang pangalan ng page mula sa provider ay text lang.
    let connPage = null;
    function connectionsButton(r) {
        const b = el('button', {type: 'button', class: 'mt-1 inline-flex items-center gap-1 rounded border border-slate-300 px-1.5 py-0.5 text-xs font-normal text-slate-600 hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
            'aria-label': 'Connections of ' + r.page, title: 'Botcake and Forbeast of this page'}, [el('span', {'aria-hidden': 'true', text: '🔌'}), el('span', {class: 'hidden sm:inline', text: 'Connections'})]);
        b.addEventListener('click', () => openConnections(r.page));
        return b;
    }
    const connCard = p => document.querySelector('#conn .conn-card[data-provider="' + p + '"]');
    function paintConnections(data) {
        // Ang pangalan sa Facebook (o "unknown", may link sa page sa Facebook) at ang mga babala nito.
        const fb = facebookLines(data.facebook || null, data.page || connPage, (data.botcake || {}).page_id || null), fbLine = $('conn-fbname');
        fbLine.replaceChildren(...(fb.line ? [el('span', {class: 'text-slate-600', text: fb.line})] : []), ...(fb.link ? [el('a', {...fb.link, class: 'ml-1 text-blue-700 underline hover:text-blue-900', text: 'Open on Facebook'})] : []),
            ...fb.warnings.map(t => el('div', {class: 'flex items-start gap-1 text-amber-700'}, [icon('warn', 'Facebook name warning'), el('span', {text: t})])));
        for (const p of ['botcake', 'forbeast', 'incepxionbot']) {
            const c = data[p] || {}, card = connCard(p);
            if (!card) continue; // ang pangalawang gateway: kapag naka-set up lang
            const state = card.querySelector('.conn-state');
            card.elements.page_id.value = c.page_id || '';
            card.elements.token.value = '';
            card.querySelector('.conn-token').textContent = c.token ? '(saved ' + c.token + ')' : '(none)';
            card.querySelector('.conn-tested').textContent = 'Last test: ' + (c.tested_at ? c.tested_at + ' · ' + (c.status || '') : 'never')
                + (p === 'botcake' && c.chat_block_status ? ' · chat block: ' + c.chat_block_status : '');
            // Ang Clear ng Botcake: kapag may naka-save na Botcake page ID lang (ang ID na iyon ang nasa tanong).
            if (p === 'botcake') { card.dataset.pageId = c.page_id || ''; card.dataset.gateways = JSON.stringify(c.gateways || []); card.querySelector('.conn-clear').hidden = !botcakeClearShown(c); }
            const bad = c.status && !String(c.status).startsWith('ok');
            state.textContent = c.connected ? 'Connected' : (p === 'botcake' && c.token && !c.chat_block ? 'No chat block' : (bad ? c.status : (c.token ? 'Not connected' : 'Not set up')));
            state.className = 'conn-state break-words text-xs ' + (c.connected ? 'text-green-700' : (bad ? 'text-red-600' : 'text-slate-500'));
            // Naka-on na keyword sa Botcake (ang huling nabasa, kahit unknown ang Test ngayon): hindi pa gumagana nang buo ang bot, kahit Connected.
            if (p === 'botcake' && !bad && c.keywords_on) {
                state.textContent = 'Keyword on: not working yet';
                state.className = 'conn-state break-words text-xs text-red-600';
            }
        }
    }
    // Ang linya ng keyword check ng Botcake pagkatapos ng Test o Save (text lang ang pangalan ng flow mula sa Botcake).
    // Hindi mabasa ngayon pero naka-on sa huling nabasa: pula pa rin, hanggang may Test na makabasa at walang naka-on.
    function kwLine(d) {
        const m = connCard('botcake').querySelector('.conn-kw'), last = d.connections && d.connections.botcake ? d.connections.botcake.keywords_on : null;
        const [text, cls] = d.keywords === 'on'
            ? ['May naka-on na keyword sa Botcake (flow: ' + (d.keywords_note || '?') + '). Hindi pa gumagana nang buo ang bot: may mga message na hindi aabot dito. I-off ang keyword sa Botcake, saka i-Test ulit.', 'text-red-600']
            : (d.keywords === 'none' ? ['Walang naka-on na keyword', 'text-green-700']
                : (d.keywords === 'unknown' && last ? ['Keyword on (last seen ' + last.at + ', flow: ' + (last.names || '?') + '); could not re-check now. I-off ang keyword sa Botcake, saka i-Test ulit.', 'text-red-600']
                    : (d.keywords === 'unknown' ? ['Keywords: ' + (d.keywords_note || 'unknown'), 'text-slate-500'] : ['', ''])));
        m.textContent = text; m.className = 'conn-kw mt-1 break-words text-xs ' + cls;
    }
    async function openConnections(page) {
        connPage = page;
        $('conn-title').textContent = 'Connections · ' + page;
        $('conn-fb').replaceChildren(fbAnchor(rows.find(r => r.page === page)));
        $('conn-fbname').replaceChildren();
        document.querySelectorAll('#conn .conn-msg, #conn .conn-kw').forEach(m => { m.textContent = ''; });
        dialog('conn', true);
        const {ok, status, data} = await getJson(url.connectionsUrl + '?page_name=' + encodeURIComponent(page));
        if (connPage !== page) return; // ibang page na ang bukas
        if (!ok) { connCard('botcake').querySelector('.conn-msg').textContent = data.error || 'Could not load (' + status + ').'; return; }
        paintConnections(data);
    }
    function connMsg(card, text, good) {
        const m = card.querySelector('.conn-msg');
        m.textContent = text; m.className = 'conn-msg mt-2 min-h-[1rem] break-words text-xs ' + (good ? 'text-green-700' : 'text-red-600');
    }
    async function connSave(card, path, body) {
        const btns = card.querySelectorAll('button'), page = connPage;
        btns.forEach(b => { b.disabled = true; });
        connMsg(card, 'Testing…', true);
        try {
            let res = await post(url.connectionsSaveUrl + '/' + path, Object.assign({page_name: page}, body));
            // Nakakabit sa ibang site (o hindi masuri): itanong bago ilipat.
            if (res.status === 409 && confirm(res.data.message)) res = await post(url.connectionsSaveUrl + '/' + path, Object.assign({page_name: page, confirm: true}, body));
            const d = res.data;
            if (connPage !== page) { btns.forEach(b => { b.disabled = false; }); load(); return; } // ibang page na ang bukas
            if (d.connections) paintConnections(d.connections);
            if (card.dataset.provider === 'botcake') kwLine(d);
            if (res.status === 409) connMsg(card, 'Not changed.', false);
            else if (!res.ok) connMsg(card, (d.name ? 'Provider page: ' + d.name + '. ' : '') + (d.error || d.message || 'Not saved (' + res.status + ').'), false);
            else if (path.endsWith('-test')) connMsg(card, 'Test: ' + d.status + (d.chat_block ? ' · chat block ' + d.chat_block : '') + (d.error ? ' · ' + d.error : ''), d.status === 'ok' && (!d.chat_block || d.chat_block === 'on'));
            else if (path === 'botcake-clear') connMsg(card, 'Botcake page ID ' + body.page_id + ' removed from ' + page + '.', true);
            else if (path.endsWith('-clear')) connMsg(card, (GATEWAY_LABEL[card.dataset.provider] || 'Forbeast') + ' token removed.', true);
            else if (d.chat_block && !d.chat_block.ok) connMsg(card, 'Token saved. Chat block: ' + d.chat_block.error, false);
            else connMsg(card, path === 'chat-block' ? 'Chat block applied.' : 'Saved and connected' + (d.name ? ' (' + d.name + ')' : '') + '.', true);
        } catch (e) { connMsg(card, 'No connection: not changed.', false); }
        btns.forEach(b => { b.disabled = false; });
        load(); // ang Connected, Bot at Check ng row mula sa server
    }
    document.querySelectorAll('#conn .conn-card').forEach(card => card.addEventListener('submit', e => {
        e.preventDefault();
        connSave(card, card.dataset.provider, {page_id: card.elements.page_id.value.trim(), token: card.elements.token.value.trim()});
    }));
    document.querySelector('#conn .conn-fix').addEventListener('click', () => connSave(connCard('botcake'), 'chat-block', {}));
    // Test: ang naka-save na token (at ang chat block ng Botcake), kahit kailan; walang isinusulat sa provider.
    document.querySelectorAll('#conn .conn-test').forEach(b => b.addEventListener('click', () => { const card = b.closest('.conn-card'); connSave(card, card.dataset.provider + '-test', {}); }));
    // botcake-clear:start
    // Ang Clear ng Botcake ay sa Likha lang: walang binabago sa Botcake (ang Default Reply ay maaaring sa ibang row na may
    // parehong Botcake page), kaya ang bot na On ay nananatiling On.
    const botcakeClearShown = c => !!(c && c.page_id);
    // gateways: ang Forbeast / Incepxionbot na may token; hinahanap nila ang chat ayon sa Botcake page ID, kaya titigil din.
    const botcakeClearQuestion = (page, id, on, gateways = []) => 'Remove Botcake page ID ' + id + ' from ' + page + '? This page stops receiving Botcake chats here. Botcake itself is not changed.'
        + (on ? '\nThe bot of this page is On: it stays On, with no Botcake chats coming in here.' : '')
        + (gateways.length ? '\n' + gateways.join(' and ') + (gateways.length > 1 ? ' also stop for this page: they find' : ' also stops for this page: it finds') + ' its chats by this Botcake page ID.' : '');
    // botcake-clear:end
    document.querySelectorAll('#conn .conn-clear').forEach(b => b.addEventListener('click', () => {
        const card = b.closest('.conn-card'), l = GATEWAY_LABEL[card.dataset.provider] || 'Forbeast';
        if (card.dataset.provider === 'botcake') {
            const id = card.dataset.pageId || '', r = rows.find(x => x.page === connPage);
            if (id && confirm(botcakeClearQuestion(connPage, id, !!(r && r.active), JSON.parse(card.dataset.gateways || '[]')))) connSave(card, 'botcake-clear', {page_id: id});
            return;
        }
        if (confirm('Remove the ' + l + ' token of ' + connPage + ' and disconnect the page from ' + l + '?')) connSave(card, card.dataset.provider + '-clear', {});
    }));

    function pickBox(r) {
        const c = el('input', {type: 'checkbox', class: 'cb-pick mr-1 align-middle', 'aria-label': 'Select ' + r.page});
        c.checked = picked.has(r.page);
        c.addEventListener('change', () => { if (c.checked) picked.add(r.page); else picked.delete(r.page); paintPicked(); });
        return c;
    }
    function paintPicked() {
        const list = visible();
        const all = $('sel-all'), n = list.filter(r => picked.has(r.page)).length;
        if (all) { all.checked = list.length > 0 && n === list.length; all.indeterminate = n > 0 && n < list.length; }
        const sel = [...picked].filter(p => rows.some(r => r.page === p));
        $('bulk-apply').disabled = !sel.length || !url.providerUrl;
        $('bulk-apply').textContent = sel.length ? 'Apply to ' + sel.length + ' selected' : 'Apply to selected';
    }
    // Ang mga pili ng bulk "Replies via": gaya ng sa row (Botcake, saka Bot N kada address, o Forbeast kapag isa lang).
    function fillBulkVia() {
        const s = $('bulk-provider'), was = s.value;
        const routes = viaRoutes().map(([v, t]) => el('option', {value: v, text: t}));
        s.replaceChildren(el('option', {value: 'botcake', text: 'Botcake'}), ...(routes.length ? routes : [el('option', {value: 'gateway', text: 'Forbeast'})]));
        s.value = [...s.options].some(o => o.value === was) ? was : 'botcake';
    }
    $('bulk-apply').addEventListener('click', async () => {
        const names = [...picked].filter(p => rows.some(r => r.page === p)), choice = $('bulk-provider').value;
        const [want, wantApp] = choice.split(':');
        if (!names.length) return;
        // Gateway: ang bawat page ay may gumaganang token sa sarili nitong gateway (Forbeast o Incepxionbot).
        const notReady = want === 'gateway' ? names.filter(p => { const r = rows.find(x => x.page === p); return !(r?.gateways || []).includes(r?.gateway); }) : [];
        if (notReady.length) { notice(NEEDS_TOKEN + ': ' + notReady.join(', '), 'text-red-600'); return; }
        const label = wantApp ? $('bulk-provider').selectedOptions[0].textContent
            : want === 'gateway' ? [...new Set(names.map(p => provLabel(rows.find(x => x.page === p) || {}, 'gateway')))].join(' / ') : 'Botcake';
        if (!confirm('Send and receive the chats of these ' + names.length + ' page' + (names.length === 1 ? '' : 's') + ' through ' + label + '?\n\n' + names.join('\n'))) return;
        $('bulk-apply').disabled = true;
        try {
            const {ok, data} = await post(url.providerUrl, {page_names: names, provider: want, ...(wantApp ? {gateway_app: wantApp} : {})});
            if (ok) { rows.forEach(r => { if (names.includes(r.page)) { r.provider = data.provider; if (data.gateway_app) r.gateway_app = data.gateway_app; r.auto_switched = null; } }); picked.clear(); notice(names.length + ' page' + (names.length === 1 ? '' : 's') + ' now reply via ' + label + '.', 'text-green-700'); }
            else notice(data.error || data.message || 'Not changed.', 'text-red-600');
        } catch (e) { notice('No connection: not changed.', 'text-red-600'); }
        render();
    });

    // Kusang lumipat sa Botcake dahil pumalya ang Forbeast (o held, o ibinalik sa Forbeast): ang label at oras, at ang dahilan sa loob (keyboard din). Nawawala kapag
    // may nagpalit nang mano-mano.
    function autoSwitchNote(a) {
        return el('details', {class: 'mt-1 max-w-[12rem] text-[11px] text-amber-700'}, [
            el('summary', {class: 'cursor-pointer', text: (a.label || 'Auto-switched') + ' ' + a.at, title: a.reason}),
            el('div', {class: 'break-words text-slate-600', text: a.reason}),
        ]);
    }

    // Ang mga address ng Forbeast gateway bilang pili: [value, label] = ['gateway:<key>', 'Bot N (<pangalan>)'], ayon sa
    // GATEWAY_URL saka GATEWAY_URLS. Isa lang ang address: walang listahan (ang pili ay "Forbeast" gaya ng dati).
    function viaRoutes() {
        const keys = Object.keys(gatewayApps || {});
        return keys.length < 2 ? [] : keys.map((k, i) => ['gateway:' + k, 'Bot ' + (i + 1) + ' (' + gatewayApps[k] + ')']);
    }

    // Ang "Replies via" ng page (kung saan dumadaan ang chat at sagot), iisang pili: Botcake, o isang address ng gateway
    // (Bot N), na isinusulat nang sabay (chatbot.provider at chatbot.gateway_app). Ang mga kumpletong koneksyon lang
    // (Connected), kasama ang kasalukuyan; isa lang: text, walang select. Ang gateway (Forbeast o Incepxionbot, ang aktibo
    // ng page) ay kailangan ding naka-set up (GATEWAY_URL, INCEPXIONBOT_URL). Ang mga address ay sa Forbeast gateway lang.
    function repliesViaSelect(r) {
        const provider = r.provider || 'botcake';
        const routes = (r.gateway || 'forbeast') === 'forbeast' && url.providerUrl ? viaRoutes() : [];
        const route = routes.some(([v]) => v === 'gateway:' + r.gateway_app) ? r.gateway_app : 'forbeast';
        const current = provider === 'gateway' && routes.length ? 'gateway:' + route : provider;
        const gatewayOk = (r.connected || []).includes(provLabel(r, 'gateway')) && gatewayReady;
        const all = [['botcake', 'Botcake'], ...(routes.length ? routes : [['gateway', provLabel(r, 'gateway')]])];
        // Bot N: kapag alam na ng Forbeast ang token ng page kada Meta app (route_apps), ang may gumaganang token lang ("ok");
        // hindi pa alam (lumang Forbeast, hindi pa nasusuri): gaya ng dati.
        const routeOk = v => !r.route_apps || r.route_apps[v.split(':')[1]] === 'ok';
        const options = all.filter(([v]) => v === current || (v === 'botcake' ? (r.connected || []).includes(provLabel(r, 'botcake')) : gatewayOk && (!v.startsWith('gateway:') || routeOk(v))));
        const labelOf = v => (all.find(o => o[0] === v) || [v, v])[1];
        if (options.length < 2) return el('div', {class: 'mt-1 text-xs text-slate-600', title: 'Replies via', text: 'Bot: ' + labelOf(current)});
        const sel = el('select', {class: 'mt-1 block w-full max-w-[9rem] rounded border border-slate-300 px-1 py-0.5 text-xs', 'aria-label': 'Replies via of ' + r.page, title: 'Replies via'},
            options.map(([v, t]) => el('option', {value: v, text: t})));
        sel.value = current;
        sel.addEventListener('change', async () => {
            const want = sel.value;
            if (!confirm('Switch ' + r.page + ' from ' + labelOf(current) + ' to ' + labelOf(want) + '?')) { sel.value = current; return; }
            const [wantProvider, wantApp] = want.split(':');
            sel.disabled = true;
            try {
                const {ok, data} = await post(url.providerUrl, {page_name: r.page, provider: wantProvider, ...(wantApp ? {gateway_app: wantApp} : {})});
                if (ok) { r.provider = data.provider; if (data.gateway_app) r.gateway_app = data.gateway_app; r.auto_switched = null; errors.delete(r.page); } else errors.set(r.page, data.error || data.message || 'Not changed.');
            } catch (e) { errors.set(r.page, 'No connection: not changed.'); }
            redraw(r.page);
        });
        return sel;
    }

    // Aling gateway ang sumasagot (kapag gateway ang Bot): ang mga naka-set up na may gumaganang token ng page, kasama ang
    // kasalukuyan. Isa lang: walang select (nasa "Bot:" na ang pangalan). Ang pili ng tao ay hindi ibinabalik ng bantay.
    function gatewaySelect(r) {
        const current = r.gateway || 'forbeast';
        const options = [...new Set([current, ...(r.gateways || [])])];
        if (r.provider !== 'gateway' || options.length < 2 || !url.gatewayUrl) return document.createTextNode('');
        const sel = el('select', {class: 'mt-1 block w-full max-w-[9rem] rounded border border-slate-300 px-1 py-0.5 text-xs', 'aria-label': 'Gateway of ' + r.page, title: 'Gateway'},
            options.map(g => el('option', {value: g, text: 'via ' + (GATEWAY_LABEL[g] || g)})));
        sel.value = current;
        sel.addEventListener('change', async () => {
            const want = sel.value;
            if (!confirm('Send the replies of ' + r.page + ' through ' + (GATEWAY_LABEL[want] || want) + ' instead of ' + (GATEWAY_LABEL[current] || current) + '?')) { sel.value = current; return; }
            sel.disabled = true;
            try {
                const {ok, data} = await post(url.gatewayUrl, {page_name: r.page, gateway: want});
                if (ok) { r.gateway = data.gateway; r.auto_switched = null; errors.delete(r.page); } else errors.set(r.page, data.error || data.message || 'Not changed.');
            } catch (e) { errors.set(r.page, 'No connection: not changed.'); }
            redraw(r.page);
        });
        return sel;
    }

    // ---- AI ng page at ang site default ----
    // Ang button sa row ay nagsasabi kung aling AI ang sumasagot ("(default)" = sumusunod sa site default).
    const aiLabel = p => (aiSite?.providers || []).find(x => x.name === p)?.label || p;
    let aiPage = null; // ang row na binubuksan sa AI dialog; null = ang site default
    // Ang note ng Forbeast kapag low o stopped ang AI load ng account ng page (spec 210): ang text nila, gaya ng ibinigay.
    function aiLoadNote(load) {
        return el('p', {class: 'ai-load-note mt-1 max-w-[12rem] break-words text-xs ' + (load.state === 'stopped' ? 'text-red-700' : 'text-amber-700'), text: load.note || (load.state === 'stopped' ? 'AI stopped: no load.' : 'Low AI load.')});
    }
    function aiButton(r) {
        if (!r.ai) return null;
        const b = el('button', {type: 'button', class: 'mt-1 block max-w-[9rem] truncate rounded border border-slate-300 px-1 py-0.5 text-left text-xs text-slate-700 hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-blue-500',
            title: 'Which AI answers: ' + r.ai.chain, 'aria-label': 'AI of ' + r.page + ': ' + r.ai.label, text: 'AI: ' + r.ai.label});
        b.addEventListener('click', () => openAi(r));
        return b;
    }
    function aiOptions(sel, list, value) {
        sel.replaceChildren(...list.map(([v, t, off]) => { const o = el('option', {value: v, text: t}); o.disabled = !!off && v !== value; return o; }));
        sel.value = value;
    }
    // Ang naka-save na value sa form: pang-umpisa, at pang-balik kapag pumalya ang save (hindi ang tinype ang naiiwan).
    function aiRevert() {
        const f = $('ai-form'), r = aiPage, a = r ? r.ai : aiSite, notReady = !aiSite?.local_ready;
        const provs = (aiSite?.providers || []).map(p => [p.name, p.label, p.name === 'local' && notReady]);
        aiOptions(f.elements.provider, r ? [['default', 'Default (' + aiLabel(aiSite?.provider) + ')'], ...provs] : provs, r ? (a.override ? a.provider : 'default') : a.provider);
        const falls = [...provs, ['none', 'None']];
        // Ang fallback ay listahan (spec 210): ang una at ang ikalawa ("deepseek,openai").
        const fallList = String(a.fallback || '').split(',').filter(Boolean);
        aiOptions(f.elements.fallback, r ? [['default', 'Default'], ...falls] : [['', 'Default (Local → OpenAI, others → OpenAI, OpenAI → none)'], ...falls], fallList[0] || (r ? 'default' : ''));
        aiOptions(f.elements.fallback2, [['', 'Nothing more'], ...provs], fallList[1] || '');
        for (const k of ['anthropic_model', 'deepseek_model']) {
            f.elements[k].value = a[k] || '';
            f.elements[k].placeholder = r && aiSite?.[k] ? 'Default: ' + aiSite[k] : '';
        }
        const load = r ? r.ai_load : null, ln = $('ai-load-dialog-note');
        ln.textContent = load ? (load.note || (load.state === 'stopped' ? 'AI stopped: no load.' : 'Low AI load.')) : '';
        ln.className = 'ai-load-note mb-2 break-words text-xs ' + (!load ? 'hidden' : load.state === 'stopped' ? 'text-red-700' : 'text-amber-700');
        f.elements.local_model.value = a.local_model || '';
        f.elements.local_model.placeholder = r && aiSite?.local_model ? 'Default: ' + aiSite.local_model : 'e.g. qwen3:14b';
        f.elements.local_timeout.value = a.local_timeout ?? '';
        f.elements.local_timeout.placeholder = r ? 'Default: ' + (aiSite?.local_timeout ?? 8) : '';
        for (const k of ['local_concurrency', 'breaker_failures', 'breaker_seconds', 'local_reasoning_effort']) f.elements[k].value = r ? '' : (a[k] ?? '');
        f.querySelectorAll('[data-ai-site-only]').forEach(l => l.classList.toggle('hidden', !!r));
        $('ai-chain').textContent = r ? 'Now: ' + a.chain : '';
        $('ai-local-note').classList.toggle('hidden', !notReady);
    }
    function openAi(r) {
        aiPage = r;
        $('ai-title').textContent = r ? 'AI for ' + r.page : 'AI default for all pages';
        $('ai-error').textContent = '';
        aiRevert();
        dialog('ai-box', true);
    }
    $('ai-site-open').addEventListener('click', () => openAi(null));
    $('ai-box').querySelector('[data-close-dialog-alt]').addEventListener('click', () => dialog('ai-box', false));
    $('ai-form').addEventListener('submit', async e => {
        e.preventDefault();
        const f = e.target, r = aiPage && current(aiPage), body = r ? {page_name: r.page} : {};
        const fb = f.elements.fallback.value, fb2 = f.elements.fallback2.value;
        const fallback = fb && fb !== 'default' && fb !== 'none' && fb2 && fb2 !== fb ? fb + ',' + fb2 : fb;
        for (const k of r ? ['provider', 'fallback', 'local_model', 'local_timeout', 'anthropic_model', 'deepseek_model']
            : ['provider', 'fallback', 'local_model', 'local_timeout', 'local_concurrency', 'breaker_failures', 'breaker_seconds', 'local_reasoning_effort', 'anthropic_model', 'deepseek_model']) {
            let v = k === 'fallback' ? fallback : f.elements[k].value.trim();
            // Ang blangko ay nagiging null sa server (walang pagbabago): sa page, ang blangkong model o timeout ay "default".
            if (r && v === '' && ['local_model', 'local_timeout', 'anthropic_model', 'deepseek_model'].includes(k)) v = 'default';
            // Sa page, ang blangkong model o timeout = bumalik sa default. Sa site, ang binago lang (ang default ng server ay hindi naisusulat).
            if (r || ((v !== '' || k === 'local_reasoning_effort') && v !== String(aiSite?.[k] ?? ''))) body[k] = v; // blangkong reasoning = hindi ipinapadala
        }
        if (!r && !Object.keys(body).length) { dialog('ai-box', false); notice('No change.'); return; }
        const btn = f.querySelector('button.cb-btn-primary');
        btn.disabled = true;
        try {
            const {ok, data} = await post(r ? url.aiUrl : url.aiSiteUrl, body);
            if (!ok) { $('ai-error').textContent = data.error || data.message || 'Not saved.'; aiRevert(); return; }
            dialog('ai-box', false);
            notice(data.status === 'unchanged' ? 'No change.' : 'AI saved.', 'text-green-700');
            if (r) { r.ai = data.ai[r.page]; render(); } else { aiSite = data.ai_site; load(); }
        } catch (err) {
            $('ai-error').textContent = 'No connection: not saved.';
            aiRevert();
        } finally { btn.disabled = false; }
    });

    // Ang paghahambing ng mga AI ng isang page (mga numero lang; "–" = walang tokens).
    const dash = v => v === null || v === undefined ? '–' : String(v);
    async function loadCompare() {
        const f = $('aic-filters'), q = new URLSearchParams({page: f.elements.page.value, from: f.elements.from.value, to: f.elements.to.value});
        $('aic-note').textContent = 'Loading…';
        let res;
        try { res = await getJson(url.aiCompareUrl + '?' + q); } catch (e) { $('aic-note').textContent = 'No connection: could not load.'; return; }
        const {ok, data} = res;
        if (!ok) { $('aic-note').textContent = data.error || 'Could not load.'; $('aic-rows').replaceChildren(); return; }
        $('aic-note').textContent = data.message ? data.message : data.requests + ' requests from ' + data.from + ' to ' + data.to + ' (Manila).';
        $('aic-rows').replaceChildren(...data.providers.map(p => el('tr', {class: 'border-t border-slate-100'}, [
            el('td', {class: 'py-1 pr-3 font-medium', text: p.label}),
            ...['requests', 'answered', 'failed', 'fallbacks', 'avg_ms', 'p95_ms', 'prompt_tokens', 'completion_tokens'].map(k => el('td', {class: 'py-1 pr-3 text-right tabular-nums', text: dash(p[k])})),
        ])));
    }
    $('ai-compare-open').addEventListener('click', () => {
        const f = $('aic-filters'), day = d => new Date(d.getTime() - d.getTimezoneOffset() * 6e4).toISOString().slice(0, 10), keep = f.elements.page.value;
        f.elements.page.replaceChildren(...rows.map(r => el('option', {value: r.page, text: r.page})));
        if (keep) f.elements.page.value = keep;
        if (!f.elements.to.value) { const now = new Date(); f.elements.to.value = day(now); f.elements.from.value = day(new Date(now - 6 * 864e5)); }
        dialog('ai-compare', true);
        if (rows.length) loadCompare();
    });
    $('aic-filters').addEventListener('change', loadCompare);

    function paintSwitch(b, on) {
        const [track, label] = b.children;
        b.setAttribute('aria-checked', on ? 'true' : 'false');
        track.classList.toggle('bg-green-500', on); track.classList.toggle('bg-slate-300', !on);
        track.firstChild.classList.toggle('translate-x-[18px]', on);
        label.textContent = on ? 'On' : 'Off';
        label.classList.toggle('text-green-700', on); label.classList.toggle('text-slate-600', !on);
    }

    async function toggle(r, b) {
        r = current(r);
        const on = !r.active;
        if (!confirm((on ? 'Switch the bot On for ' : 'Switch the bot Off for ') + r.page + '? This changes the live Botcake page.')) return;
        // Lilipat agad ang knob habang naghihintay; ang render() pagkatapos ay sagot ng server (babalik kapag pumalya ang Botcake).
        b.disabled = true; b.setAttribute('aria-busy', 'true'); b.classList.add('opacity-50'); paintSwitch(b, on);
        try {
            let {ok, status, data} = await post(url.activeUrl, {page_name: r.page, on});
            // Ang Default Reply ay nasa ibang site: itanong bago palitan.
            if (status === 409 && confirm(data.message)) ({ok, status, data} = await post(url.activeUrl, {page_name: r.page, on, confirm: true}));
            if (!ok) errors.set(r.page, status === 409 ? 'Not changed.' : (data.error || data.message || 'Not changed.'));
            else { r.active = data.active; if (data.error) errors.set(r.page, data.error); else errors.delete(r.page); }
        } catch (e) { errors.set(r.page, 'No connection: not changed.'); }
        redraw(r.page);
    }

    function cell(r, c, extra = '') {
        const k = id(r.page, c.key), m = marks.get(k);
        // FLOW: ang pangalan bilang text at ✎ (ang popup ay may hanap at confirm), para hindi mapalitan nang hindi sinasadya.
        // Ang lumang FLOW text na hindi nakatali sa flow ay "Not linked" (amber).
        if (c.kind === 'flow') {
            const value = r.cells[c.key] ?? '';
            const td = el('td', {class: extra + ' border-b border-slate-100 px-3 py-2 align-top', 'data-key': c.key});
            const edit = el('button', {type: 'button', class: 'shrink-0 rounded px-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
                'aria-label': 'Change the flow of ' + r.page, title: 'Change the flow', text: '✎'});
            edit.addEventListener('click', () => openFlow(r, c));
            td.append(el('div', {class: 'flex items-start gap-1'}, [
                value ? el('span', {class: 'min-w-0 break-words', text: value})
                    : el('span', {class: 'min-w-0 break-words ' + (r.flow_text ? 'text-amber-800' : 'text-slate-400'), text: r.flow_text ? 'Not linked: ' + r.flow_text : 'No flow'}),
                edit,
            ]));
            // Ang template code ng flow (ang ipinapaste sa Forbeast), kapag mayroon.
            if (value && r.flow_code) td.append(el('span', {class: 'block font-mono text-xs text-slate-500', text: r.flow_code, title: 'Template code of this flow'}));
            if (m) td.append(el('span', {class: 'block text-xs ' + m.cls, text: m.text, title: m.title || null}));
            return td;
        }
        if (c.kind === 'auto') {
            const td = el('td', {class: extra + ' cb-cell border-b border-slate-100 bg-slate-50 px-3 py-2 align-top', tabindex: '0', 'data-key': c.key, title: SHOP_NOTE});
            td.append(el('span', {class: 'cb-txt', text: String(r.cells[c.key] ?? '')}), el('span', {class: 'block text-xs text-slate-500', text: SHOP_NOTE}));
            td.addEventListener('click', ev => {
                if (ev.shiftKey && sel) { sel.b = {page: r.page, key: c.key}; paintSel(); return; }
                sel = {a: {page: r.page, key: c.key}, b: {page: r.page, key: c.key}};
                paintSel();
                toggleRow(r.page);
            });
            td.addEventListener('keydown', ev => { if (ev.target === td && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); toggleRow(r.page); } });
            return td;
        }
        const p = pending.get(k);
        const td = el('td', {class: extra + ' cb-cell border-b border-slate-100 px-3 py-2 align-top' + (p ? ' cb-pending' : ''), tabindex: '0', 'data-key': c.key,
            title: p ? 'Not saved yet. Was: ' + (p.old === '' ? '(empty)' : (p.old.length > 300 ? p.old.slice(0, 300) + '…' : p.old)) : null});
        const value = p ? p.value : (r.cells[c.key] ?? '');
        let show;
        if (c.kind === 'image' && isUrl(value)) {
            show = el('span', {class: 'flex items-center gap-2'}, [el('img', {src: value, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', class: 'h-8 w-8 rounded object-cover'}), el('span', {class: 'max-w-[9rem] truncate text-xs', text: value})]);
        } else if (value) {
            show = el('span', {class: 'cb-txt', text: String(value)});
        } else {
            show = el('span', {class: 'block max-w-[14rem] truncate text-slate-300', text: c.kind === 'image' ? '+ link' : '+ set'});
        }
        const edit = el('button', {type: 'button', class: 'cb-edit shrink-0 rounded px-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500', 'aria-label': 'Edit ' + c.label, title: 'Edit ' + c.label, text: '✎'});
        td.append(el('div', {class: 'flex items-start gap-1'}, [el('div', {class: 'min-w-0 flex-1'}, show), edit]));
        if (m) td.append(el('span', {class: 'block text-xs ' + m.cls, text: m.text, title: m.title || null}));
        // Ang value na huling isinulat mula sa Forbeast (page values API): sino, sa title.
        else if (!p && value && r.from_forbeast && r.from_forbeast[c.key]) td.append(el('span', {class: 'block text-xs text-indigo-700', text: 'via Forbeast', title: 'Last changed from Forbeast: ' + r.from_forbeast[c.key]}));
        // Ang lapis lang ang nagbubukas ng editor (ang text sa textarea ng modal, dahil inaalis ng input ang line break; ang
        // image ay sa URL editor). Ang click (o Enter / Space) sa cell ay nagbubukas o nagsasara ng row.
        const open = () => c.kind === 'image' ? startEdit(td, r, c) : openLong(r, c);
        edit.addEventListener('click', ev => { ev.stopPropagation(); open(); });
        edit.addEventListener('keydown', ev => ev.stopPropagation());
        td.addEventListener('click', ev => {
            if (ev.target.closest('input')) return;
            if (ev.shiftKey && sel) { sel.b = {page: r.page, key: c.key}; paintSel(); return; }
            sel = {a: {page: r.page, key: c.key}, b: {page: r.page, key: c.key}};
            paintSel();
            toggleRow(r.page);
        });
        td.addEventListener('keydown', ev => { if (ev.target === td && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); toggleRow(r.page); } });
        return td;
    }

    function startEdit(td, r, c) {
        if (editing) finish(true);
        const hit = rowCache.get(r.page);
        if (hit) hit.sig = null; // ang cell ay papalitan ng input: ang row ay binubuo ulit sa susunod na render
        const input = el('input', {class: 'w-full min-w-[10rem] rounded border border-blue-500 px-1', autocomplete: 'off', placeholder: c.kind === 'image' ? 'https://…' : ''});
        input.value = r.cells[c.key] ?? '';
        td.replaceChildren(input);
        input.focus();
        editing = {r, c, input, original: input.value, timer: null};
        input.addEventListener('input', () => { clearTimeout(editing.timer); editing.timer = setTimeout(() => save(r, c, input.value), PAUSE_MS); });
        input.addEventListener('keydown', ev => {
            if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
            if (ev.key === 'Escape') { ev.preventDefault(); input.value = editing.original; finish(false); }
        });
        input.addEventListener('blur', () => { if (editing && editing.input === input) finish(true); });
    }

    function finish(doSave) {
        const ed = editing;
        if (!ed) return;
        clearTimeout(ed.timer);
        editing = null;
        if (doSave) save(ed.r, ed.c, ed.input.value); else render();
    }

    async function save(r, c, value) {
        r = current(r);
        value = value.trim();
        if (pending.size && c.kind !== 'flow') {
            // Gaya ng paste: ang hindi link sa column ng larawan ay hindi isinasama (tatanggihan ng server ang buong Save).
            if (value !== '' && c.kind === 'image' && !ChatbotPaste.isImageLink(value)) notice(r.page + ' · ' + c.label + ': use an image link that starts with http:// or https://.', 'text-red-600');
            else addPending(r, c.key, value);
            if (!editing) render();
            return;
        }
        if (value === String(r.cells[c.key] ?? '')) { if (!editing) render(); return; }
        const k = id(r.page, c.key), seq = (seqs.get(k) || 0) + 1;
        seqs.set(k, seq);
        marks.set(k, {text: 'Saving…', cls: 'text-slate-400'});
        if (!editing) render();
        try {
            const {ok, status, data} = await post(url.cellUrl, {page_name: r.page, key: c.key, value});
            if (seqs.get(k) !== seq) return; // mas bago ang ibang save ng parehong cell
            if (!ok) marks.set(k, {text: '!', cls: 'text-red-600', title: data.error || data.message || ('Not saved (' + status + ')')});
            else {
                if (data.value == null) delete r.cells[c.key]; else r.cells[c.key] = data.value;
                // Ang bagong sulat ay mula sa Likha: wala na ang "via Forbeast" ng cell (maliban kung walang nagbago).
                if (data.status !== 'unchanged' && r.from_forbeast) delete r.from_forbeast[c.key];
                marks.set(k, {text: data.status === 'unchanged' ? '✓' : 'Saved', cls: 'text-green-600'});
                setTimeout(() => { if (marks.get(k)?.cls === 'text-green-600') { marks.delete(k); if (!editing) render(); } }, 2500);
            }
            if (!ok && !editing) notice(r.page + ' · ' + c.label + ': ' + (data.error || 'not saved'), 'text-red-600');
        } catch (e) {
            if (seqs.get(k) === seq) marks.set(k, {text: '!', cls: 'text-red-600', title: 'No connection: not saved.'});
        }
        if (!editing) render();
        if ($('long').dataset.key === k) $('long-mark').textContent = marks.get(k)?.text === '!' ? (marks.get(k).title || 'Not saved') : (marks.get(k)?.text || '');
    }

    // ---- mahabang text sa modal ----
    let longTimer = null, longRow = null, longCol = null;
    function openLong(r, c) {
        longRow = r; longCol = c;
        $('long-title').textContent = r.page + ' · ' + c.label;
        $('long-text').value = r.cells[c.key] ?? '';
        $('long').dataset.key = id(r.page, c.key);
        $('long-mark').textContent = '';
        $('long-template').classList.toggle('hidden', !commentTemplates[c.key]);
        longWarn();
        $('long').classList.remove('hidden');
        $('long-text').focus();
    }
    function closeLong() {
        clearTimeout(longTimer);
        if (longRow) save(longRow, longCol, $('long-text').value);
        $('long').classList.add('hidden');
        delete $('long').dataset.key;
        longRow = null;
    }
    // Ang link lang sa text column: babala (amber), pero nase-save pa rin.
    function longWarn() {
        const v = $('long-text').value;
        $('long-warn').textContent = longCol && ChatbotPaste.isLinkOnly(v) ? ChatbotPaste.linkWarning(longCol, botCols()) : '';
    }
    $('long-text').addEventListener('input', () => { longWarn(); clearTimeout(longTimer); longTimer = setTimeout(() => save(longRow, longCol, $('long-text').value), PAUSE_MS); });
    // Ang draft lang ng column na ito (walang auto-save habang hindi nagta-type): nase-save sa pagsara, gaya ng ibang value.
    $('long-template').addEventListener('click', () => {
        $('long-text').value = commentTemplates[longCol.key] ?? $('long-text').value;
        $('long-mark').textContent = 'Not saved yet: edit it, then close to save';
        longWarn();
        $('long-text').focus();
    });
    $('long-close').addEventListener('click', closeLong);
    $('long').addEventListener('click', e => { if (e.target === $('long')) closeLong(); });

    // ---- "+ Column" at "+ Page" ----
    function modal(boxId, show) {
        const m = $(boxId);
        if (!m) return;
        m.classList.toggle('hidden', !show);
        if (show) m.querySelector('input')?.focus();
    }
    document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => { modal('col-box', false); modal('page-box', false); }));
    $('add-column').addEventListener('click', () => { $('col-error').textContent = ''; $('col-form').reset(); modal('col-box', true); });
    $('col-form').addEventListener('submit', async ev => {
        ev.preventDefault();
        const {ok, data} = await post(url.columnUrl, {name: new FormData(ev.target).get('name')});
        if (!ok) { $('col-error').textContent = data.error || data.message || 'Not added.'; return; }
        modal('col-box', false);
        notice('Column "' + data.label + '" is ready for every page.', 'text-green-700');
        await load();
    });
    if ($('add-page')) {
        $('add-page').addEventListener('click', () => { $('page-error').textContent = ''; $('page-form').reset(); modal('page-box', true); });
        $('page-form').addEventListener('submit', async ev => {
            ev.preventDefault();
            const f = new FormData(ev.target);
            const btn = ev.target.querySelector('button.cb-btn-primary');
            btn.disabled = true;
            try {
                const body = {page_name: f.get('page_name'), page_id: f.get('page_id'), token: f.get('token')};
                let {ok, status, data} = await post(url.pagesUrl, body);
                if (status === 409 && confirm(data.message)) ({ok, status, data} = await post(url.pagesUrl, Object.assign({confirm: true}, body)));
                if (!ok) { $('page-error').textContent = data.error || data.message || 'Not added.'; return; }
                modal('page-box', false);
                notice(data.page + ' added · token test: ' + data.token_test + ' · ' + (data.active ? 'On' : 'Off'), data.active ? 'text-green-700' : 'text-amber-700');
                if (data.error) errors.set(data.page, data.error); // sa row ng page, gaya ng error ng switch
                await load();
            } finally { btn.disabled = false; }
        });
    }

    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        // Ang modal sa ibabaw (Preview, History, Changes) muna; ang Edit sa ilalim ay naiiwang bukas.
        const top = ['hist', 'pv', 'chg'].find(d => !$(d).classList.contains('hidden'));
        if (top) { dialog(top, false); return; }
        if (!$('long').classList.contains('hidden')) closeLong();
        if (!$('pe').classList.contains('hidden')) closePe();
        modal('col-box', false); modal('page-box', false);
    });
    // ---- paste: hindi pa naka-save, Save, Cancel, Undo ----
    function addPending(r, key, value) {
        const old = String(r.cells[key] ?? ''), k = id(r.page, key);
        if (value === old) pending.delete(k); else pending.set(k, {page: r.page, key, value, old});
    }
    const pageCount = list => new Set(list.map(x => x.page_name ?? x.page)).size;
    const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's');
    function paintBar() {
        const bar = $('bar'), n = pending.size, show = n > 0 || !!lastUndo;
        bar.classList.toggle('hidden', !show); bar.classList.toggle('flex', show);
        $('bar-save').classList.toggle('hidden', !n); $('bar-cancel').classList.toggle('hidden', !n);
        $('bar-undo').classList.toggle('hidden', !lastUndo);
        $('bar-text').textContent = n ? plural(n, 'cell') + ' changed on ' + plural(pageCount([...pending.values()]), 'page') + ' · not saved yet'
            : lastUndo ? 'Saved ' + plural(lastUndo.length, 'field') + ' on ' + plural(pageCount(lastUndo), 'page') + '.' : '';
    }
    /** Isang Save (o Undo): isang request, isang transaction. Ang sagot ng server, o {error}. */
    async function saveCells(changes) {
        try {
            const {ok, status, data} = await post(url.cellsUrl, {blanks_empty: true, changes});
            if (ok) return data;
            const first = (data.errors || []).slice(0, 3).map(e => e.page_name + ' · ' + (columns.find(c => c.key === e.key)?.label || e.key) + ': ' + e.error).join(' ');
            return {error: (data.error || data.message || ('Not saved (' + status + ').')) + (first ? ' ' + first : '')};
        } catch (e) {
            return {error: 'No connection: nothing was saved.'};
        }
    }
    async function savePending() {
        const btn = $('bar-save');
        btn.disabled = true;
        const res = await saveCells([...pending.values()].map(p => ({page_name: p.page, key: p.key, value: p.value})));
        btn.disabled = false;
        if (res.error) { notice(res.error, 'text-red-600'); return; }
        pending.clear();
        done(res);
    }
    function done(res) {
        lastUndo = res.undo.length ? res.undo : null;
        for (const u of res.undo) firstPages.add(u.page_name ?? u.page);
        notice('Saved ' + plural(res.saved, 'field') + ' on ' + plural(res.pages, 'page') + (res.unchanged ? ' · ' + res.unchanged + ' already had that value' : '') + '.', 'text-green-700');
        load();
    }
    $('bar-save').addEventListener('click', savePending);
    $('bar-cancel').addEventListener('click', () => { pending.clear(); notice('Pasted changes discarded.'); render(); });
    $('bar-undo').addEventListener('click', async () => {
        const btn = $('bar-undo');
        btn.disabled = true;
        const res = await saveCells(lastUndo);
        btn.disabled = false;
        if (res.error) { notice('Undo failed: ' + res.error, 'text-red-600'); return; }
        for (const u of lastUndo) firstPages.add(u.page_name ?? u.page);
        lastUndo = null;
        notice('Undone: ' + plural(res.saved, 'field') + ' back to the previous value.', 'text-green-700');
        load();
    });
    window.addEventListener('beforeunload', e => { if (pending.size || peDirty()) { e.preventDefault(); e.returnValue = ''; } });

    // ---- pagpili, Copy at Paste sa grid ----
    /** Ang napiling rectangle sa ayos ng nakikita (mga index), o null. */
    function selBox() {
        if (!sel) return null;
        const list = visible(), cols = botCols();
        const ri = p => list.findIndex(r => r.page === p), ci = k => cols.findIndex(c => c.key === k);
        const r1 = ri(sel.a.page), r2 = ri(sel.b.page), c1 = ci(sel.a.key), c2 = ci(sel.b.key);
        if (r1 < 0 || r2 < 0 || c1 < 0 || c2 < 0) return null;
        return {list, cols, top: Math.min(r1, r2), bottom: Math.max(r1, r2), left: Math.min(c1, c2), right: Math.max(c1, c2)};
    }
    // Ang mga cell lang ng napili (at ng dating napili) ang ginagalaw, hindi bawat cell ng table.
    let selTds = new Set();
    function paintSel() {
        const b = selBox(), now = new Set();
        if (b) for (let i = b.top; i <= b.bottom; i++) {
            const page = b.list[i].page, entry = rowCache.get(page);
            const trs = entry ? [...(entry.nodes || []), ...(entry.replacing || [])].filter(tr => tr.dataset.page === page) : [];
            for (let j = b.left; j <= b.right; j++) for (const tr of trs) { const td = tr.querySelector('td.cb-cell[data-key="' + CSS.escape(b.cols[j].key) + '"]'); if (td) now.add(td); }
        }
        for (const td of selTds) if (!now.has(td)) td.classList.remove('cb-sel');
        for (const td of now) td.classList.add('cb-sel');
        selTds = now;
    }
    const typing = t => t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT');
    table.addEventListener('keydown', e => {
        if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'a' || typing(e.target)) return;
        const list = visible(), cols = botCols();
        if (!list.length || !cols.length) return;
        e.preventDefault();
        sel = {a: {page: list[0].page, key: cols[0].key}, b: {page: list[list.length - 1].page, key: cols[cols.length - 1].key}};
        paintSel();
    });
    table.addEventListener('copy', e => {
        const b = selBox();
        if (!b || typing(e.target)) return;
        const out = [];
        for (let i = b.top; i <= b.bottom; i++) {
            const r = b.list[i], line = [];
            for (let j = b.left; j <= b.right; j++) { const p = pending.get(id(r.page, b.cols[j].key)); line.push(p ? p.value : String(r.cells[b.cols[j].key] ?? '')); }
            out.push(line);
        }
        e.clipboardData.setData('text/plain', ChatbotPaste.toTsv(out));
        e.preventDefault();
        notice('Copied ' + plural(out.length * out[0].length, 'cell') + '.');
    });
    // Mula sa kaliwang itaas ng napili, pakanan at pababa (ayon sa nakikita). Isang value sa napiling rectangle = puno lahat.
    table.addEventListener('paste', e => {
        const b = selBox();
        if (!b || typing(e.target)) return;
        e.preventDefault();
        let block = ChatbotPaste.parseTsv(e.clipboardData.getData('text/plain'));
        if (!block.length) return;
        if (block.length === 1 && block[0].length === 1) block = Array.from({length: b.bottom - b.top + 1}, () => Array(b.right - b.left + 1).fill(block[0][0]));
        let dropped = 0, flagged = 0, added = 0, linked = 0, auto = 0;
        block.forEach((line, i) => line.forEach((raw, j) => {
            const r = b.list[b.top + i], c = b.cols[b.left + j], value = raw.trim();
            if (!r || !c) { if (value !== '') dropped++; return; }
            if (c.kind === 'auto') { if (value !== '') auto++; return; }
            if (value === '' && !$('f-blanks').checked) return;
            if (value !== '' && c.kind === 'image' && !ChatbotPaste.isImageLink(value)) { flagged++; return; }
            if (value !== '' && c.kind !== 'image' && c.kind !== 'flow' && ChatbotPaste.isLinkOnly(value)) linked++;
            addPending(r, c.key, value); added++;
        }));
        const width = Math.max(...block.map(l => l.length));
        sel = {a: {page: b.list[b.top].page, key: b.cols[b.left].key},
            b: {page: b.list[Math.min(b.top + block.length, b.list.length) - 1].page, key: b.cols[Math.min(b.left + width, b.cols.length) - 1].key}};
        const notes = [plural(added, 'cell') + ' pasted, not saved yet'];
        if (flagged) notes.push(plural(flagged, 'value') + ' in image columns are not links and were left out');
        if (linked) notes.push(plural(linked, 'value') + ' in text columns are only a link (check the image columns)');
        if (dropped) notes.push(plural(dropped, 'value') + ' past the last column or row were dropped');
        if (auto) notes.push(plural(auto, 'value') + ' in SHOP DETAILS were left out (it is automatic)');
        notice(notes.join(' · ') + '.', flagged || dropped || linked || auto ? 'text-amber-700' : 'text-slate-600');
        render();
        table.querySelector('td.cb-sel')?.focus({preventScroll: true});
    });

    // ---- Edit ng isang page (modal) ----
    // pe: ang page na bukas, ang mga value nito nang buksan (orig), at ang value ng mga field bago ang huling paste
    // (beforePaste), para ang bagong paste o ibang "Starting at field" ay pumalit sa dati at hindi magpatong.
    let pe = null;
    const peInput = key => $('pe-fields').querySelector('[data-key="' + CSS.escape(key) + '"] .pe-in');
    const grow = t => { if (t.tagName === 'TEXTAREA') { t.style.height = 'auto'; t.style.height = t.scrollHeight + 2 + 'px'; } };
    // Lahat ng textarea ng Edit nang sabay: lahat ng sulat, saka lahat ng basa, saka lahat ng sulat (isang layout, hindi isa
    // kada field: ang sunod-sunod na sulat at basa ay buong layout ng page bawat isa).
    function growAll() {
        const list = [...$('pe-fields').querySelectorAll('textarea')];
        for (const t of list) t.style.height = 'auto';
        const heights = list.map(t => t.scrollHeight + 2);
        list.forEach((t, i) => { t.style.height = heights[i] + 'px'; });
    }
    /** Bawat field: ang value ngayon, at kung binago (changed) o hindi link sa image field (flagged, hindi sine-save). */
    // Ang mga field na nasa Edit ngayon (ang Edit ng group ay walang field para sa hindi value ng group).
    const peCols = () => editCols().filter(c => peInput(c.key));
    function peState() {
        return peCols().map(c => {
            // Ikinukumpara sa trimmed na dating value: ang lumang value na may espasyo sa dulo ay hindi "binago" pagbukas.
            const value = peInput(c.key).value.trim(), old = pe.orig[c.key], same = value === old.trim();
            const flagged = value !== '' && !same && c.kind === 'image' && !ChatbotPaste.isImageLink(value);
            const linked = value !== '' && c.kind !== 'image' && c.kind !== 'flow' && ChatbotPaste.isLinkOnly(value);
            return {c, value, old, changed: !same && !flagged, flagged, linked};
        });
    }
    const peDirty = () => !!pe && peState().some(x => x.changed || x.flagged);
    function paintPe() {
        const list = peState(), n = list.filter(x => x.changed).length;
        for (const x of list) {
            const row = peInput(x.c.key).closest('.pe-field');
            row.classList.toggle('pe-changed', x.changed); row.classList.toggle('pe-flagged', x.flagged);
            const was = row.querySelector('.pe-was');
            was.textContent = x.flagged ? 'Not a link (http:// or https://): will not be saved.' : x.linked ? ChatbotPaste.linkWarning(x.c, botCols()) : x.changed ? 'Was: ' + (x.old === '' ? '(empty)' : x.old) : '';
            was.className = 'pe-was whitespace-pre-line break-words text-xs ' + (x.flagged ? 'text-red-600' : x.linked ? 'text-amber-700' : 'text-slate-500');
            // Edit ng group: ang blangko ay ang default (ang page value, malabo).
            row.querySelector('.pe-default')?.classList.toggle('hidden', x.value !== '');
            const img = row.querySelector('img');
            if (img) { const ok = ChatbotPaste.isImageLink(x.value); img.classList.toggle('invisible', !ok); if (ok && img.getAttribute('src') !== x.value) img.src = x.value; }
        }
        $('pe-save').disabled = !n;
        $('pe-save').textContent = n ? 'Save ' + plural(n, 'change') : 'Save';
    }
    /**
     * Ang Edit ng isang page, o ng isang ad group nito (g): parehong window, paste box at AI mapping (parehong endpoint);
     * sa group, ang mga field ay ang mga value ng group (blangko = ang page value), at ang Save ay sa endpoint ng value ng
     * group (history at Restore ng group).
     */
    function openPe(r, g = null) {
        pe = {r, g, orig: {}, beforePaste: {}, map: null};
        peSeq++;
        $('pe-map').classList.add('hidden');
        $('pe-name').textContent = g ? r.page + ' · ' + g.name : r.page;
        $('pe-preview').classList.toggle('hidden', !!g); // ang Preview ay ang page values
        $('pe-id').textContent = r.page_id || '';
        $('pe-fb').replaceChildren(fbAnchor(r));
        const start = $('pe-start').value;
        $('pe-start').replaceChildren(...editCols().map(c => el('option', {value: c.key, text: c.label})));
        if (start && editCols().some(c => c.key === start)) $('pe-start').value = start;
        $('pe-blanks').checked = $('f-blanks').checked;
        $('pe-paste').value = '';
        $('pe-notes').replaceChildren();
        $('pe-error').textContent = '';
        $('pe-start-box').classList.remove('hidden');
        $('pe-fields').replaceChildren(...botCols().filter(c => !g || c.kind === 'auto' || !GROUP_EXCLUDED.includes(c.key)).map(peField));
        if (g) {
            $('pe').classList.remove('hidden');
            $('pe-fields').scrollTop = 0;
            growAll();
            paintPe();
            $('pe-paste').focus();
            return;
        }
        // Ad groups: dito ginagawa at inaayos (Ad-based lang); sa table, tinitingnan.
        const agHost = el('div', {class: 'mt-1'});
        $('pe-fields').append(el('section', {id: 'pe-ag', class: 'py-3 text-xs text-slate-600'}, [el('h3', {id: 'pe-ag-title', tabindex: '-1', class: 'text-sm font-semibold text-slate-800 focus:outline-none', text: 'Ad groups'}),
            r.reply === 'ad' ? agHost
                : el('p', {class: 'mt-1', text: 'This page is Page-based: every customer gets the values above. To give ads their own values, set Replies to Ad-based under the page name in the table; its ad groups are then made here.'})]));
        if (r.reply === 'ad') AG.mount(r, agHost);
        $('pe').classList.remove('hidden');
        $('pe-fields').scrollTop = 0;
        growAll();
        paintPe();
        $('pe-paste').focus();
    }
    /** Isang field ng modal (label, input, History at template), na may value ng page nang buksan. */
    function peField(c) {
        const r = pe.r, g = pe.g, v = pe.orig[c.key] = String((g ? (g.values || {})[c.key] : r.cells[c.key]) ?? ''), fid = 'pe-f-' + c.key;
        if (c.kind === 'auto' && g) return el('div', {class: 'pe-field py-2', 'data-key': c.key}, [
            el('div', {class: 'mb-1 text-xs font-medium text-slate-600', text: c.label}), el('div', {class: 'text-xs text-slate-500', text: 'Built from the group\'s item'})]);
        if (c.kind === 'auto') return el('div', {class: 'pe-field py-2', 'data-key': c.key}, [
            el('div', {class: 'mb-1 text-xs font-medium text-slate-600', text: c.label}),
            el('div', {class: 'whitespace-pre-line break-words rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-sm text-slate-700', text: v}),
            el('div', {class: 'mt-1 text-xs text-slate-500', text: SHOP_NOTE})]);
        // Ang image ay link (isang linya, may maliit na preview); ang iba ay textarea na lumalaki, buo ang line break.
        const input = c.kind === 'image'
            ? el('input', {id: fid, type: 'url', class: 'pe-in w-full rounded-md border border-slate-300 px-2 py-1 text-sm', placeholder: 'https://…', autocomplete: 'off'})
            : el('textarea', {id: fid, rows: '1', class: 'pe-in w-full rounded-md border border-slate-300 px-2 py-1 text-sm'});
        input.value = v;
        input.addEventListener('input', () => { grow(input); paintPe(); });
        const box = c.kind === 'image'
            ? el('div', {class: 'flex items-center gap-2'}, [el('img', {alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', class: 'invisible h-10 w-10 shrink-0 rounded border border-slate-200 object-cover'}), input])
            : input;
        const history = el('button', {type: 'button', class: 'text-xs text-blue-700 underline hover:text-blue-900', text: 'History', 'aria-label': g ? 'History of ' + g.name + ' (every value)' : 'History of ' + c.label});
        history.addEventListener('click', () => g ? AG.history(r.page, g) : openHistory(r.page, c));
        const links = [history];
        if (commentTemplates[c.key]) {
            // Ang Comment Reply: ang tatlong variant nang sabay. Pumupuno lang; ang Save pa rin ang nagse-save.
            const tpl = el('button', {type: 'button', class: 'text-xs text-blue-700 underline hover:text-blue-900', text: 'Use template', 'aria-label': 'Use template for ' + c.label});
            tpl.addEventListener('click', () => {
                for (const k of REPLY_KEYS.includes(c.key) ? REPLY_KEYS : [c.key]) {
                    const t = peInput(k);
                    if (t) { t.value = commentTemplates[k]; grow(t); }
                }
                paintPe();
            });
            links.unshift(tpl);
        }
        const def = String(r.cells[c.key] ?? '');
        return el('div', {class: 'pe-field py-2', 'data-key': c.key}, [
            el('div', {class: 'mb-1 flex items-center justify-between gap-2'}, [el('label', {for: fid, class: 'text-xs font-medium text-slate-600', text: c.label}), el('div', {class: 'flex gap-3'}, links)]),
            box, g ? el('div', {class: 'pe-default whitespace-pre-line break-words text-xs text-slate-400'}, [el('span', {class: 'italic', text: 'uses default: '}), def === '' ? '(empty)' : def]) : null,
            el('div', {class: 'pe-was'})]);
    }
    function closePe(force = false) {
        if (!pe) return;
        if (!force && peDirty() && !confirm('Discard the changes to ' + (pe.g ? '"' + pe.g.name + '"' : pe.r.page) + '?')) return;
        const back = pe.back ? pe.r : null;
        $('pe').classList.add('hidden');
        if (!pe.g) AG.unmount(pe.r.page);
        pe = null;
        // Ang mga field at ang Ad groups ng saradong Edit ay hindi naiiwan sa page (libu-libong element sa Ad-based na page).
        $('pe-fields').replaceChildren();
        $('pe-map-list').replaceChildren();
        if (agStale) { agStale = false; render(); }
        // Ang Edit ng group na binuksan mula sa Manage in Edit: pabalik sa Edit ng page, sa Ad groups.
        if (back) manageAdGroups(current(back));
    }
    /** Ang Edit ng isang ad group (mula sa row nito sa table o sa Manage in Edit). */
    function openGroupEdit(r, g) {
        const back = !!pe && !pe.g;
        if (pe && peDirty() && !confirm('Discard the changes to ' + (pe.g ? '"' + pe.g.name + '"' : pe.r.page) + '?')) return;
        if (pe) { pe.back = false; closePe(true); }
        openPe(current(r), AG.group(r.page, g.id) || g);
        pe.back = back;
    }
    /** Ibinabalik ang mga field bago ang huling paste, para ang bagong paste ay pumalit at hindi magpatong. */
    function peRestore() {
        for (const [key, v] of Object.entries(pe.beforePaste)) peInput(key).value = v;
        pe.beforePaste = {};
    }
    /** Ang paste box: isang row (ang una lang), ayon sa header kung mayroon, kung wala ay ayon sa ayos mula sa napiling field. */
    function pePaste() {
        peRestore();
        const text = $('pe-paste').value, notes = [];
        const current = Object.fromEntries(peCols().map(c => [c.key, peInput(c.key).value]));
        const plan = ChatbotPaste.plan({text, columns: botCols(), targets: [{page: pe.r.page, cells: pe.orig}], startKey: $('pe-start').value, blanksEmpty: $('pe-blanks').checked, firstRowOnly: true});
        $('pe-start-box').classList.toggle('hidden', plan.byName);
        if (text.trim() && plan.error) notes.push(plan.error);
        if (plan.byName) notes.push('Mapped by the header names in the first line.');
        if (plan.ignored.length) notes.push('Not a field, ignored: ' + plan.ignored.join(', ') + '.');
        if (plan.reserved) notes.push('ACTIVE and FLOW are not in this window: they were not changed.');
        if (plan.dropped) notes.push(plural(plan.dropped, 'value') + ' past the last field were dropped.');
        if (plan.extraRows) notes.push('Only the first row was used (' + plural(plan.extraRows, 'more row') + ' ignored).');
        let filled = 0;
        for (const x of plan.changes) {
            if (x.status === 'blank' || !peInput(x.key)) continue; // blangko: naiiwan ang value; SHOP DETAILS: automatic
            pe.beforePaste[x.key] = current[x.key];
            peInput(x.key).value = x.value;
            if (x.status !== 'unchanged') filled++;
        }
        if (plan.changes.length) notes.unshift(plural(filled, 'field') + ' filled from the paste, not saved yet.');
        $('pe-notes').replaceChildren(...notes.map(n => el('li', {text: n})));
        growAll();
        paintPe();
    }
    // ---- Ang AI ang pumipili ng field ng bawat value (paste sa Edit) ----
    // pe.map: ang mga cell, ang napiling key at confidence kada cell, at kung na-Apply na. Ang value ay hindi dumadaan sa
    // server pabalik: ang sagot ay mapping lang, at dito inilalagay ang value nang gaya ng na-paste. peSeq: ang huling
    // paste lang ang may bisa (ang lumang sagot ay binabalewala).
    let peSeq = 0, peTimer = null;
    const AI_WAIT_MS = 30000; // ang server ay hanggang 20 s sa AI; lampas dito, ang dating mapping na
    const short = v => { const one = String(v).trim().split(/\r?\n/)[0]; return one.length > 80 ? one.slice(0, 80) + '…' : one + (String(v).trim().includes('\n') ? ' …' : ''); };
    function peAiPaste() {
        clearTimeout(peTimer);
        const seq = ++peSeq;
        pe.map = null;
        $('pe-map').classList.add('hidden');
        const row = ChatbotPaste.aiRow($('pe-paste').value, botCols());
        if (!row) { pePaste(); return; } // walang laman (o header lang): walang tawag sa AI
        peRestore();
        $('pe-notes').replaceChildren();
        // guesses: ang field ayon sa header o sa "Starting at field", para sa AI (ang ayos ng sheet ang batayan).
        const guesses = ChatbotPaste.positionKeys($('pe-paste').value, botCols(), $('pe-start').value);
        pe.map = {cells: row.cells, keys: [], conf: [], note: [], missing: [], applied: false};
        $('pe-map-list').replaceChildren();
        $('pe-map-missing').classList.add('hidden'); $('pe-map-missing').classList.remove('flex');
        $('pe-map-item').textContent = '';
        $('pe-map-error').textContent = '';
        $('pe-map-apply').disabled = true;
        $('pe-map-status').textContent = 'Asking AI which field each value goes to…';
        $('pe-map').classList.remove('hidden');
        paintPe();
        peTimer = setTimeout(async () => {
            if (!pe || seq !== peSeq) return; // sarado na ang modal o may bagong paste
            let res;
            try {
                res = await Promise.race([post(url.pasteMapUrl, {page_name: pe.r.page, cells: row.cells, header: row.header, guesses}),
                    new Promise(ok => setTimeout(() => ok({ok: false, data: {}}), AI_WAIT_MS))]);
            } catch (e) { res = {ok: false, data: {}}; }
            if (!pe || seq !== peSeq) return;
            if (!res.ok || !Array.isArray(res.data.mapping)) { peClassic(res.data.note || res.data.error || 'AI could not map this paste, so the header/position mapping was used.'); return; }
            for (const m of res.data.mapping) { pe.map.keys[m.cell] = m.key && peInput(m.key) ? m.key : null; pe.map.conf[m.cell] = m.confidence; pe.map.note[m.cell] = m.note || ''; }
            pe.map.missing = Array.isArray(res.data.missing) ? res.data.missing.filter(m => /^seq_(img_)?\d+$/.test(m?.key)) : [];
            peMapList();
        }, 400);
    }
    /** Ang preview: bawat value na may laman, may dropdown ng field na naka-set sa pinili ng AI. */
    function peMapList() {
        const items = [];
        pe.map.cells.forEach((v, i) => {
            if (String(v).trim() === '') return;
            const sel = el('select', {class: 'pe-map-field max-w-full rounded border border-slate-300 px-1 py-0.5 text-xs text-gray-900', 'aria-label': 'Field for value ' + (i + 1)},
                [el('option', {value: '', text: 'None (skip)'}), ...peCols().map(c => el('option', {value: c.key, text: c.label}))]);
            sel.value = pe.map.keys[i] || '';
            sel.addEventListener('change', () => { pe.map.keys[i] = sel.value || null; pe.map.conf[i] = 'high'; pe.map.note[i] = ''; peMapPaint(); });
            items.push(el('li', {class: 'pe-map-item flex flex-wrap items-center gap-x-2 gap-y-1 rounded border px-2 py-1', 'data-cell': String(i)}, [
                el('span', {class: 'min-w-0 flex-1 basis-40 truncate font-mono text-xs', text: short(v)}), sel, el('span', {class: 'pe-map-tag text-xs empty:hidden'})]));
        });
        $('pe-map-list').replaceChildren(...items);
        $('pe-map-apply').disabled = false;
        peMapPaint();
    }
    /** Ang mababang confidence (dilaw) at ang dalawang value sa iisang field (pula). */
    function peMapPaint() {
        const conflicts = ChatbotPaste.mapConflicts(pe.map.cells.map((_, i) => pe.map.keys[i] || null));
        let low = 0, clash = 0;
        for (const li of $('pe-map-list').children) {
            const i = +li.dataset.cell, isClash = conflicts[i], isLow = !isClash && pe.map.conf[i] === 'low';
            low += isLow; clash += isClash;
            li.classList.toggle('border-red-300', isClash); li.classList.toggle('bg-red-50', isClash);
            li.classList.toggle('border-amber-300', isLow); li.classList.toggle('bg-amber-50', isLow);
            li.classList.toggle('border-slate-200', !isClash && !isLow); li.classList.toggle('bg-white', !isClash && !isLow);
            const tag = li.querySelector('.pe-map-tag');
            tag.textContent = isClash ? 'Same field as another value' : isLow ? 'Not sure: check this' : pe.map.note[i] || '';
            tag.className = 'pe-map-tag text-xs empty:hidden ' + (isClash ? 'text-red-700' : isLow ? 'text-amber-700' : 'text-slate-500');
        }
        $('pe-map-status').textContent = 'AI picked a field for each value. Check them, then Apply (nothing is saved until Save).'
            + (low ? ' ' + plural(low, 'value') + ' not sure.' : '') + (clash ? ' ' + plural(clash, 'value') + ' on the same field.' : '');
        $('pe-map-error').textContent = '';
        // Mga follow-up na walang Seq column pa, at ang paste na ibang item kaysa sa page (walang AI dito).
        const miss = ChatbotPaste.missingText(pe.map.missing);
        $('pe-map-missing').classList.toggle('hidden', !miss); $('pe-map-missing').classList.toggle('flex', !!miss);
        $('pe-map-missing-text').textContent = miss ? miss.text : '';
        $('pe-map-add').textContent = miss ? miss.button : '';
        const item = ChatbotPaste.itemMismatch(pe.map.cells, pe.map.cells.map((_, i) => pe.map.keys[i] || null), pe.orig.item_name_in_jnt_dashboard || String(pe.r.cells.item_name_in_jnt_dashboard ?? ''));
        $('pe-map-item').textContent = item ? 'The pasted texts mention ' + item.pasted + "; this page's item is " + item.page + '. Check you copied the right row.' : '';
    }
    /** "Add Seq 21–22": ang mga kulang na Seq / Seq Img column sa "+ Column", saka ang mga cell sa kanila. Walang value na sine-save. */
    async function peMapAddSeq() {
        const map = pe.map, miss = ChatbotPaste.missingText(map.missing);
        if (!miss || !confirm('This adds the columns ' + miss.names.join(', ') + ' to every page now, only the empty columns: no value is saved. The pasted values go in with Apply and are saved with Save.')) return;
        $('pe-map-add').disabled = true;
        try {
            for (const name of miss.names) {
                const {ok, data} = await post(url.columnUrl, {name});
                if (!ok) { $('pe-map-error').textContent = data.error || data.message || 'Column not added.'; return; }
            }
            await load();
        } finally { $('pe-map-add').disabled = false; }
        if (!pe) return; // sarado na ang modal
        // Laging idinadagdag sa bukas na modal ang mga bagong column (pinalitan na ng load() ang columns), kahit may bagong paste.
        for (const c of editCols()) {
            if (peInput(c.key) || (pe.g && GROUP_EXCLUDED.includes(c.key))) continue;
            $('pe-fields').insertBefore(peField(c), $('pe-ag')); // sa itaas ng Ad groups (ang Edit ng group: sa dulo)
            $('pe-start').append(el('option', {value: c.key, text: c.label}));
        }
        if (pe.map !== map) { paintPe(); return; } // may bagong paste: ang lumang mapping ay hindi na ginagalaw
        // Ang cell na pinalitan na ng user ang field ay hindi ginagalaw.
        for (const m of map.missing) if (peInput(m.key) && !map.keys[m.cell]) { map.keys[m.cell] = m.key; map.conf[m.cell] = 'medium'; map.note[m.cell] = 'in order (new column)'; }
        map.missing = map.missing.filter(m => !peInput(m.key));
        growAll();
        peMapList();
        paintPe();
    }
    /** Apply: pinupuno ang mga field ayon sa napili, gaya ng na-paste; ang Save pa rin ang nagse-save. */
    function peMapApply() {
        const out = ChatbotPaste.applyMapping(pe.map.cells, pe.map.cells.map((_, i) => pe.map.keys[i] || null), $('pe-blanks').checked);
        if (out.error) { $('pe-map-error').textContent = out.error; return; }
        peRestore();
        for (const x of out.changes) {
            const input = peInput(x.key);
            if (!input) continue;
            pe.beforePaste[x.key] = input.value;
            input.value = x.value;
        }
        pe.map.applied = true;
        $('pe-map').classList.add('hidden');
        $('pe-notes').replaceChildren(el('li', {text: plural(out.changes.length, 'field') + ' filled from the paste (AI mapping), not saved yet.'}));
        growAll();
        paintPe();
    }
    /** Ang dating mapping (header o ayos), may maikling dahilan kung bakit. */
    function peClassic(note) {
        clearTimeout(peTimer);
        peSeq++;
        pe.map = null;
        $('pe-map').classList.add('hidden');
        pePaste();
        if (note) $('pe-notes').prepend(el('li', {class: 'text-amber-700', text: note}));
    }
    $('pe-paste').addEventListener('input', peAiPaste);
    $('pe-start').addEventListener('change', () => peClassic());
    $('pe-blanks').addEventListener('change', () => { if (pe.map?.applied) peMapApply(); else if (!pe.map) pePaste(); });
    $('pe-map-apply').addEventListener('click', peMapApply);
    $('pe-map-add').addEventListener('click', peMapAddSeq);
    $('pe-map-classic').addEventListener('click', () => peClassic());
    $('pe-blanks').addEventListener('change', () => { $('f-blanks').checked = $('pe-blanks').checked; });
    $('pe-close').addEventListener('click', () => closePe());
    $('pe-cancel').addEventListener('click', () => closePe());
    $('pe').addEventListener('click', e => { if (e.target === $('pe')) closePe(); });
    $('pe-save').addEventListener('click', async () => {
        const changes = peState().filter(x => x.changed).map(x => ({page_name: pe.r.page, key: x.c.key, value: x.value}));
        if (!changes.length) return;
        $('pe-save').disabled = true;
        $('pe-error').textContent = '';
        if (pe.g) {
            // Ang group: isang request, lahat o wala (ang blangko = ang page value ulit).
            const {r, g} = pe, res = await AG.saveValues(r.page, g, Object.fromEntries(changes.map(x => [x.key, x.value])));
            if (res.error) { $('pe-error').textContent = res.error; $('pe-save').disabled = false; return; }
            closePe(true);
            notice('Saved ' + plural(changes.length, 'value') + ' of "' + g.name + '" on ' + r.page + '.', 'text-green-700');
            return;
        }
        const res = await saveCells(changes);
        if (res.error) { $('pe-error').textContent = res.error; $('pe-save').disabled = false; return; }
        closePe(true);
        done(res);
    });

    $('f-q').addEventListener('input', render);

    $('f-state').addEventListener('change', render);
    load();
})();
</script>
@endsection
