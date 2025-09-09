<!doctype html>
<html lang="en">
  

<head>
  <meta charset="utf-8">
  <title>COGS (Daily, insert-only when present in macro_output)</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    body{font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:16px;}
    .toolbar{display:flex; gap:12px; align-items:center; margin-bottom:12px;}
    table{border-collapse: collapse; width:100%;}
    th,td{border:1px solid #e5e7eb; padding:6px 8px; text-align:right; min-width:74px; white-space:nowrap;}
    th.sticky{position:sticky; top:0; background:#f9fafb; z-index:1;}
    th.day{font-weight:600;}
    td.name,th.name{position:sticky; left:0; background:#fff; text-align:left; min-width:240px; z-index:2;}
    td.editable{background:#ffffff; cursor:text;}
    td.nonpresent{background:#f5f6f7; color:#9aa0a6;}
    td.editing{outline:2px solid #3b82f6; background:#eef2ff;}
    td.saved{background:#ecfeff;}
    .muted{color:#6b7280; font-size:12px;}
    input[type="month"]{padding:4px 6px;}
  </style>
</head>
<body x-data="gridApp('{{ $month }}')">
  <div class="toolbar">
    <h2 style="margin:0;">COGS — Daily Editor</h2>
    <label class="muted">Month:
      <input type="month" x-model="month" @change="load()">
    </label>
    <span class="muted">Rule: Only dates present in <b>macro_output</b> are editable & saved. If missing cogs that day, value shows <i>same as kahapon</i>.</span>
  </div>

  <template x-if="ready">
    <div style="overflow:auto; border:1px solid #e5e7eb; border-radius:6px;">
      <table>
        <thead>
          <tr>
            <th class="sticky name">ITEM NAME</th>
            <template x-for="d in days" :key="'h'+d">
              <th class="sticky day" x-text="d"></th>
            </template>
          </tr>
        </thead>
        <tbody>
          <template x-for="r in rows" :key="r.item_name">
            <tr>
              <td class="name" x-text="r.item_name"></td>
              <template x-for="d in days" :key="r.item_name+'-'+d">
                <td :class="r.editable[d] ? 'editable' : 'nonpresent'"
                    :contenteditable="r.editable[d] ? 'true' : 'false'"
                    @focus="onFocus($event)"
                    @blur="onBlur($event, r.item_name, d)"
                    @keydown.enter.prevent="commit($event, r.item_name, d)"
                    x-text="fmt(r.prices[d])">
                </td>
              </template>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </template>

<script>
function gridApp(initialMonth){
  return {
    month: initialMonth,
    ready: false,
    days: [],
    rows: [],
    fmt(v){ return (v===null||v===undefined) ? '' : Number(v).toFixed(2); },
    parse(v){ if(v===''||v===null) return null; const n = parseFloat(String(v).replace(/,/g,'')); return isNaN(n)? null : n; },
    async load(){
      this.ready=false;
      const res = await fetch(`{{ route('item.cogs.grid') }}?month=${this.month}`);
      const j = await res.json();
      this.days = Array.from({length: j.days}, (_,i)=>i+1);
      this.rows = j.rows;
      this.ready=true;
    },
    onFocus(e){ e.target.classList.add('editing'); },
    async onBlur(e, name, day){ e.target.classList.remove('editing'); await this.commit(e, name, day); },
    async commit(e, name, day){
      const val = this.parse(e.target.innerText.trim());
      if (val === null) { e.target.innerText=''; return; } // ignore blanks
      // if cell non-editable, ignore
      if (!e.target.classList.contains('editable')) { e.target.innerText=''; return; }

      const date = new Date(`${this.month}-01`); date.setDate(day);
      const ymd = date.toISOString().slice(0,10);

      const res = await fetch(`{{ route('item.cogs.update') }}`, {
        method:'POST',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ item_name: name, date: ymd, price: val })
      });
      if (res.ok) {
        // refresh grid quickly (or you can only refresh that row)
        await this.load();
        e.target.classList.add('saved'); setTimeout(()=>e.target.classList.remove('saved'), 600);
      } else {
        const err = await res.json().catch(()=>({error:'Save failed'}));
        alert(err.error || 'Save failed');
      }
    },
    async init(){ await this.load(); }
  }
}
window.addEventListener('DOMContentLoaded',()=>{
  const root = document.querySelector('[x-data]');
  if (root && root.__x) root.__x.$data.init();
});
</script>
</body>
</html>
