<x-layout>
  <x-slot name="heading">💬 Edit Messaging Template (Per Ad)</x-slot>

  <style>
    :root{ --nav-h:64px; --filters-h:84px; --sticky-top:calc(var(--nav-h) + var(--filters-h)); }
    .fixed-filters{ position:fixed; top:var(--nav-h); left:50%; transform:translateX(-50%); width:100vw; background:#fff; z-index:60; border-bottom:1px solid #e5e7eb; }
    .filters-spacer{ height:var(--filters-h); }
    table.table-sticky{ border-collapse:separate; table-layout:fixed; }
    table.table-sticky thead th{ position:sticky; top:var(--sticky-top); background:#fff; z-index:40; }
    table.table-sticky thead th::after{ content:""; position:absolute; left:0; right:0; bottom:-1px; height:1px; background:#e5e7eb; }
    .saving{background:#fff7ed!important}.saved{background:#ecfdf5!important}.save-fail{background:#fef2f2!important}
  </style>

  {{-- FIXED FILTER BAR --}}
  <div id="filters-bar" class="fixed-filters">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <form id="filters-form" method="GET" action="{{ route('ads_manager_creatives.edit') }}" class="py-3 grid gap-3 md:grid-cols-6">
        <div class="md:col-span-2">
          <label class="block text-sm font-semibold mb-1">Search</label>
          <input id="filter-q" type="text" name="q" value="{{ request('q') }}" placeholder="Search campaign/page/body/headline/wm/qr/link" class="w-full border rounded px-2 py-2">
          <p class="text-[11px] text-gray-500 mt-1">Auto-applies as you type.</p>
        </div>
        <div>
          <label class="block text-sm font-semibold mb-1">Page Name</label>
          <select id="filter-page" name="page_name" class="w-full border rounded px-2 py-2">
            <option value="" {{ in_array(request('page_name'),[null,'','all'],true)?'selected':'' }}>All pages</option>
            @foreach($pageOptions as $pn)
              <option value="{{ $pn }}" {{ request('page_name')===$pn ? 'selected' : '' }}>{{ $pn }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold mb-1">Ad Set Delivery</label>
          @php $cur=request('ad_set_delivery'); @endphp
          <select id="filter-delivery" name="ad_set_delivery" class="w-full border rounded px-2 py-2">
            <option value="" {{ $cur===null||$cur===''?'selected':'' }}>All</option>
            <option value="Active"   {{ $cur==='Active'  ?'selected':'' }}>Active</option>
            <option value="Inactive" {{ $cur==='Inactive'?'selected':'' }}>Inactive</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold mb-1">Per Page</label>
          <select id="filter-perpage" name="per_page" class="w-full border rounded px-2 py-2">
            @foreach([50,100,150,200] as $pp)
              <option value="{{ $pp }}" {{ (int)request('per_page',50)===$pp ? 'selected' : '' }}>{{ $pp }}</option>
            @endforeach
          </select>
        </div>
        <div class="flex items-end gap-2">
          <a href="{{ route('ads_manager_creatives.edit') }}" class="px-3 py-2 rounded border bg-white">Reset</a>
        </div>
        <input type="hidden" name="sort_by" value="{{ $sortBy }}">
        <input type="hidden" name="sort_direction" value="{{ $sortDirection }}">
      </form>
    </div>
  </div>

  <div class="filters-spacer"></div>

  @php
    $sortLink = function(string $column, string $label, string $currentSort, string $currentDirection) {
      $newDir = ($currentSort === $column && $currentDirection === 'asc') ? 'desc' : 'asc';
      $arrow  = ($currentSort === $column) ? ($currentDirection === 'asc' ? ' ↑' : ' ↓') : '';
      $pageParam = request('page_name'); if ($pageParam === 'all') $pageParam = '';
      $url = request()->fullUrlWithQuery([
        'sort_by'=>$column,'sort_direction'=>$newDir,'page'=>request('page'),'q'=>request('q'),
        'page_name'=>$pageParam,'ad_set_delivery'=>request('ad_set_delivery'),'per_page'=>request('per_page',50),
      ]);
      return '<a href="'.e($url).'" class="hover:underline font-semibold">'.e($label).$arrow.'</a>';
    };
  @endphp

  {{-- TABLE --}}
  <section class="relative left-1/2 right-1/2 -mx-[50vw] w-screen bg-white shadow-sm border-y rounded-none">
    <div class="px-2 sm:px-3 lg:px-4 py-2">
      <table class="w-full text-[13px] table-sticky">
        <colgroup>
          <col style="width:8%"><col style="width:12%"><col style="width:8%"><col style="width:20%"><col style="width:10%">
          <col style="width:10%"><col style="width:6%"><col style="width:6%"><col style="width:6%">
          <col style="width:10%"><col style="width:6%"><col style="width:8%">
        </colgroup>
        <thead class="border-b bg-white">
          <tr class="text-left text-gray-600">
            <th class="px-2 py-2 border">{!! $sortLink('date_created','Date Created',$sortBy,$sortDirection) !!}</th>
            <th class="px-2 py-2 border">{!! $sortLink('campaign_name','Campaign Name',$sortBy,$sortDirection) !!}</th>
            <th class="px-2 py-2 border">{!! $sortLink('page_name','Page Name',$sortBy,$sortDirection) !!}</th>
            <th class="px-2 py-2 border">{!! $sortLink('body_ad_settings','Body',$sortBy,$sortDirection) !!}</th>
            <th class="px-2 py-2 border">{!! $sortLink('headline','Headline',$sortBy,$sortDirection) !!}</th>
            <th class="px-2 py-2 border">{!! $sortLink('welcome_message','Welcome Message',$sortBy,$sortDirection) !!}</th>
            <th class="px-2 py-2 border">{!! $sortLink('quick_reply_1','Quick Reply 1',$sortBy,$sortDirection) !!}</th>
            <th class="px-2 py-2 border">{!! $sortLink('quick_reply_2','Quick Reply 2',$sortBy,$sortDirection) !!}</th>
            <th class="px-2 py-2 border">{!! $sortLink('quick_reply_3','Quick Reply 3',$sortBy,$sortDirection) !!}</th>
            <th class="px-2 py-2 border">{!! $sortLink('ad_link','Ad Link',$sortBy,$sortDirection) !!}</th>
            <th class="px-2 py-2 border">{!! $sortLink('feedback','Feedback',$sortBy,$sortDirection) !!}</th>
            <th class="px-2 py-2 border">{!! $sortLink('ad_set_delivery','Ad Set Delivery',$sortBy,$sortDirection) !!}</th>
          </tr>
        </thead>

        <tbody>
          @forelse($creatives as $creative)
            <tr class="align-top hover:bg-gray-50 border-t"
                data-row-id="{{ $creative->id }}"
                data-update-url="{{ route('ads_manager_creatives.update', $creative->id) }}">

              <td class="px-2 py-2 border text-center whitespace-pre-wrap break-words">{{ $creative->date_created ?? '—' }}</td>
              <td class="px-2 py-2 border whitespace-pre-wrap break-words" title="{{ $creative->campaign_name }}">{{ $creative->campaign_name }}</td>
              <td class="px-2 py-2 border whitespace-pre-wrap break-words">{{ $creative->page_name }}</td>

              {{-- Body (read-only, clamped) --}}
              <td class="px-2 py-2 border">
                @php $body=$creative->body_ad_settings??''; $isLong=strlen($body)>400; @endphp
                <div class="body-wrap">
                  <div class="body-clamp whitespace-pre-wrap break-words" style="{{ $isLong?'max-height:7.5rem; overflow:hidden;':'' }}">{{ $body }}</div>
                  @if($isLong)
                    <button type="button" class="toggle-body text-xs text-blue-700 hover:underline mt-1">Show more</button>
                  @endif
                </div>
              </td>

              {{-- Editable, AUTO-SAVE --}}
              <td class="px-2 py-2 border">
                <textarea data-autosave name="headline" rows="1" class="w-full border rounded p-1 text-sm leading-snug overflow-hidden resize-none whitespace-pre-wrap break-words">{{ $creative->headline }}</textarea>
              </td>
              <td class="px-2 py-2 border">
                <textarea data-autosave name="welcome_message" rows="1" class="w-full border rounded p-1 text-sm leading-snug overflow-hidden resize-none whitespace-pre-wrap break-words">{{ $creative->welcome_message }}</textarea>
              </td>
              <td class="px-2 py-2 border">
                <textarea data-autosave name="quick_reply_1" rows="1" class="w-full border rounded p-1 text-sm leading-snug overflow-hidden resize-none whitespace-pre-wrap break-words">{{ $creative->quick_reply_1 }}</textarea>
              </td>
              <td class="px-2 py-2 border">
                <textarea data-autosave name="quick_reply_2" rows="1" class="w-full border rounded p-1 text-sm leading-snug overflow-hidden resize-none whitespace-pre-wrap break-words">{{ $creative->quick_reply_2 }}</textarea>
              </td>
              <td class="px-2 py-2 border">
                <textarea data-autosave name="quick_reply_3" rows="1" class="w-full border rounded p-1 text-sm leading-snug overflow-hidden resize-none whitespace-pre-wrap break-words">{{ $creative->quick_reply_3 }}</textarea>
              </td>

              {{-- Ad Link --}}
              <td class="px-2 py-2 border">
                <div class="flex flex-col gap-1">
                  <input data-autosave type="text" name="ad_link" value="{{ $creative->ad_link }}" placeholder="https://..." class="w-full border rounded px-2 py-1 text-sm truncate">
                  @if(!empty($creative->ad_link))
                    <a href="{{ $creative->ad_link }}" target="_blank" class="text-xs text-blue-700 hover:underline">Open</a>
                  @endif
                </div>
              </td>

              {{-- Feedback (0/1) --}}
              <td class="px-2 py-2 border">
                <select data-autosave name="feedback" class="w-full border rounded px-2 py-1 text-sm">
                  <option value="1" {{ (int)$creative->feedback === 1 ? 'selected' : '' }}>Yes</option>
                  <option value="0" {{ (int)$creative->feedback !== 1 ? 'selected' : '' }}>No</option>
                </select>
              </td>

              <td class="px-2 py-2 border text-center whitespace-pre-wrap break-words">{{ $creative->ad_set_delivery }}</td>
            </tr>
          @empty
            <tr><td colspan="12" class="px-2 py-3 border text-center text-gray-500">No results</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>

  <div class="mt-4">{{ $creatives->links() }}</div>

  <script>
    function measureOffsets(){
      const root=document.documentElement;
      const nav=document.querySelector('nav'); const navH=nav?nav.offsetHeight:64;
      root.style.setProperty('--nav-h', navH+'px');
      const bar=document.getElementById('filters-bar'); const h=bar?bar.offsetHeight:84;
      root.style.setProperty('--filters-h', h+'px');
      root.style.setProperty('--sticky-top', `calc(${navH}px + ${h}px)`);
    }
    window.addEventListener('load',measureOffsets);
    window.addEventListener('resize',measureOffsets);
    document.addEventListener('DOMContentLoaded',measureOffsets);

    const form=document.getElementById('filters-form');
    const q=document.getElementById('filter-q');
    const page=document.getElementById('filter-page');
    const deliv=document.getElementById('filter-delivery');
    const perpg=document.getElementById('filter-perpage');
    function debounce(fn,ms){let t;return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),ms);};}
    if(page)  page.addEventListener('change',()=>form.submit());
    if(deliv) deliv.addEventListener('change',()=>form.submit());
    if(perpg) perpg.addEventListener('change',()=>form.submit());
    if(q)     q.addEventListener('input',debounce(()=>form.submit(),400));

    // textarea autoresize
    document.querySelectorAll('textarea[data-autosave]').forEach(el=>{
      const r=()=>{el.style.height='auto';el.style.height=(el.scrollHeight)+'px';};
      r(); el.addEventListener('input',r);
    });

    // show more/less body
    document.querySelectorAll('.toggle-body').forEach(btn=>{
      btn.addEventListener('click',()=>{
        const clamp=btn.closest('.body-wrap').querySelector('.body-clamp');
        const open=btn.getAttribute('data-expanded')==='1';
        if(open){clamp.style.maxHeight='7.5rem';clamp.style.overflow='hidden';btn.textContent='Show more';btn.setAttribute('data-expanded','0');}
        else{clamp.style.maxHeight='';clamp.style.overflow='';btn.textContent='Show less';btn.setAttribute('data-expanded','1');}
      });
    });

    // autosave
    const csrf='{{ csrf_token() }}';
    function flash(td,cls){if(!td)return;td.classList.remove('saving','saved','save-fail');td.classList.add(cls);setTimeout(()=>td.classList.remove(cls),900);}
    async function saveField(el){
      const tr=el.closest('tr[data-row-id]'); if(!tr) return;
      const url=tr.getAttribute('data-update-url');
      const td=el.closest('td'); flash(td,'saving');
      const fd=new FormData(); fd.append('_method','PUT'); fd.append(el.name, el.value);
      try{
        const res=await fetch(url,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},body:fd});
        if(!res.ok) throw new Error(res.status);
        const j=await res.json(); if(!j || j.ok!==true) throw new Error('bad');
        flash(td,'saved');
      }catch(e){ console.error(e); flash(td,'save-fail'); }
    }
    function attachAutosave(el){ const deb=debounce(()=>saveField(el),600); el.addEventListener('input',deb); el.addEventListener('blur',()=>saveField(el)); }
    document.querySelectorAll('[data-autosave]').forEach(attachAutosave);
  </script>
</x-layout>
