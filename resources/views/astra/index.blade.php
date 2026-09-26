<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="referrer" content="no-referrer">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Astra — Likha AI Chat</title>
<style>
:root{color-scheme:dark;--bg:#151716;--panel:#1e211f;--line:#343a35;--text:#ebeee9;--muted:#a0aaa1;--accent:#c5edac;--danger:#ffb6a9;--side:270px}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.65 system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
button,input,select,textarea{font:inherit}
button{cursor:pointer;border:1px solid var(--line);border-radius:10px;background:transparent;color:var(--text);padding:8px 13px}
button:hover{background:#30372f}button:disabled{opacity:.45;cursor:default}
button.primary{background:var(--accent);border-color:var(--accent);color:#18301b;font-weight:650}
button:focus-visible,select:focus-visible,textarea:focus-visible,summary:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
a{color:var(--accent);text-underline-offset:3px;overflow-wrap:anywhere}
select{background:#151816;color:var(--text);border:1px solid #424b42;border-radius:9px;padding:6px 9px;font-size:12.5px;min-width:0}
/* ── layout: sidebar + main ── */
.app{display:flex;min-height:100vh}
.side{width:var(--side);flex:0 0 var(--side);background:var(--panel);border-right:1px solid var(--line);display:flex;flex-direction:column;position:sticky;top:0;height:100vh}
.side-top{padding:16px 14px 10px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.brand{font-size:19px;font-weight:650;letter-spacing:-.5px}.star{color:var(--accent);margin-right:8px}
.side .newchat{margin:0 14px 10px;width:calc(100% - 28px)}
.convlist{flex:1;overflow:auto;padding:4px 8px 10px}
.conv{display:flex;align-items:center;gap:6px;padding:9px 10px;border-radius:9px;cursor:pointer;color:#d5dbd2;font-size:13.5px}
.conv:hover{background:#2a302a}.conv.active{background:#2f3a2d;color:var(--text)}
.conv .t{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.conv .x{opacity:0;border:0;padding:2px 6px;font-size:12px;color:var(--muted);border-radius:6px}.conv:hover .x{opacity:1}.conv .x:hover{color:var(--danger);background:transparent}
.side-foot{border-top:1px solid var(--line);padding:12px 14px;font-size:12.5px;color:var(--muted)}
.side-foot b{color:var(--text);display:block;margin-bottom:4px}
.main{flex:1;min-width:0;display:flex;flex-direction:column}
header{position:sticky;top:0;z-index:5;background:#151716ed;backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
.top{padding:10px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.settings{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.settings label{font-size:11px;color:var(--muted);display:flex;flex-direction:column;gap:3px}
.actions{display:flex;gap:8px}
.wrap{max-width:840px;margin:auto;padding:0 24px;width:100%}
#welcome{text-align:center;padding:60px 0 50px}#welcome .orb{width:58px;height:58px;display:grid;place-items:center;margin:0 auto 20px;border:1px solid #576c4a;border-radius:18px;background:#283423;color:var(--accent);font-size:30px}
h1{font-size:30px;letter-spacing:-1px;line-height:1.2;font-weight:550;margin:0 0 14px}#welcome p{color:var(--muted);max-width:470px;margin:0 auto}
.chips{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-top:25px}.chips button{font-size:13px;padding:10px 14px}
#messages{padding:5px 0 20px}.message{margin:22px 0 34px}
.role{font-size:11px;text-transform:uppercase;letter-spacing:1.3px;color:var(--muted);margin-bottom:11px;font-weight:600}
.user{display:flex;flex-direction:column;align-items:flex-end}
.user .bubble{background:#2b322b;border:1px solid #3a4538;border-radius:19px 19px 4px 19px;padding:13px 18px;max-width:90%;white-space:pre-wrap;overflow-wrap:anywhere}
.user .imgs{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;margin-bottom:6px}
.user .imgs img{max-width:220px;max-height:220px;border-radius:12px;border:1px solid var(--line)}
.answer{overflow-wrap:anywhere}.answer p{margin:12px 0;white-space:pre-wrap}.answer h2,.answer h3,.answer h4{font-size:19px;line-height:1.4;margin:22px 0 10px}.answer ul,.answer ol{padding-left:24px}
.answer pre{background:#0d100e;border:1px solid var(--line);border-radius:12px;overflow:auto;padding:16px;font:13px/1.6 ui-monospace,monospace;white-space:pre}
.answer code{font:13px ui-monospace,monospace;background:#2a312a;border-radius:4px;padding:2px 5px}.answer pre code{background:none;padding:0}
.answer blockquote{border-left:3px solid #7c9a68;margin-left:0;padding-left:16px;color:#bbc6b6}
.table-scroll{overflow:auto}.answer table{border-collapse:collapse;font-size:13px;min-width:100%;margin:14px 0}.answer td,.answer th{border:1px solid var(--line);padding:9px 12px;text-align:left}.answer th{background:#252c24}
.activity{border:1px solid var(--line);border-radius:12px;margin-bottom:18px;background:#1b201b}.activity summary{padding:11px 15px;cursor:pointer;color:#bacdb0;font-size:13px}.activity .inside{padding:0 15px 14px}
.trace{font-size:12px;color:var(--muted);padding:3px 0}.reasoning{font-size:13px;color:#bcc6b7;white-space:pre-wrap;margin-top:12px;max-height:340px;overflow:auto}
.sources{display:flex;flex-wrap:wrap;gap:7px;margin-top:15px}.sources a{max-width:100%;border:1px solid var(--line);border-radius:8px;padding:5px 9px;font-size:12px;text-decoration:none}
.meta{font-size:11px;color:var(--muted);margin-top:12px}.error{color:var(--danger);white-space:pre-wrap;margin:12px 0}.retry{font-size:12px}.note{font-size:12px;color:var(--muted);margin:10px 0 0}
.footer-space{height:200px}
.dock{position:sticky;bottom:0;z-index:4;background:linear-gradient(transparent,var(--bg) 18%);padding:25px 24px max(12px,env(safe-area-inset-bottom));margin-top:auto}
.dock-inner{max-width:792px;margin:auto}
.composer{border:1px solid #465040;border-radius:20px;background:#222820;padding:12px 14px 10px;box-shadow:0 8px 40px #0003}
.composer.drag{border-color:var(--accent)}
textarea{width:100%;resize:none;max-height:160px;min-height:52px;border:0;outline:none!important;background:transparent;color:var(--text);padding:5px 3px;font-size:16px;line-height:1.5}textarea::placeholder{color:#929e8b}
.previews{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px}
.previews .pv{position:relative}.previews img{width:64px;height:64px;object-fit:cover;border-radius:10px;border:1px solid var(--line)}
.previews .rm{position:absolute;top:-6px;right:-6px;width:20px;height:20px;padding:0;border-radius:999px;background:#151716;font-size:12px;line-height:1}
.composer-bottom{display:flex;align-items:center;justify-content:space-between;gap:12px}
.badge{font-size:11px;color:#b6c9a8}.bottomnote{text-align:center;font-size:11px;color:var(--muted);margin-top:7px}#status{font-size:12px;color:var(--muted)}
.iconbtn{padding:6px 9px;font-size:15px;line-height:1}
[hidden]{display:none!important}
.menu-btn{display:none}
@media(max-width:820px){.side{position:fixed;left:0;top:0;z-index:20;transform:translateX(-100%);transition:transform .15s}.side.open{transform:none}.menu-btn{display:inline-block}.wrap{padding:0 14px}.dock{padding-left:12px;padding-right:12px}.user .bubble{max-width:95%}h1{font-size:26px}#welcome{padding:30px 0}}
</style>
</head>
<body>
<div class="app">
  {{-- ── Sidebar: conversations ── --}}
  <aside class="side" id="side">
    <div class="side-top"><span class="brand"><span class="star">✳</span>Astra</span><button class="iconbtn" id="closeSide" title="Isara" style="display:none">✕</button></div>
    <button class="newchat primary" id="newChat">+ New chat</button>
    <div class="convlist" id="convlist"></div>
    <div class="side-foot"><b>{{ Auth::user()->name ?? '' }}</b><a href="{{ url('/') }}">← Bumalik sa likha</a></div>
  </aside>

  <div class="main">
    <header><div class="top">
      <div class="settings">
        <button class="menu-btn iconbtn" id="openSide" title="Conversations">☰</button>
        <label>Model<select id="model">@foreach($models as $id => $label)<option value="{{ $id }}" @selected($id === $defaultModel)>{{ $label }}</option>@endforeach</select></label>
        <label>Thinking effort<select id="effort">@foreach($efforts as $e)<option value="{{ $e }}" @selected($e === 'xhigh')>{{ ['low'=>'Low','medium'=>'Medium','high'=>'High','xhigh'=>'Extra High','max'=>'Max'][$e] ?? ucfirst($e) }}</option>@endforeach</select></label>
        <label>Web search<select id="search">@foreach($searchModes as $s)<option value="{{ $s }}" @selected($s === 'auto')>{{ $s === 'required' ? 'Always' : ucfirst($s) }}</option>@endforeach</select></label>
        <label>Budget<select id="budget">@foreach($budgets as $b)<option value="{{ $b }}" @selected($b === 32768)>{{ number_format($b) }}</option>@endforeach</select></label>
      </div>
      <div class="actions"><button id="renameChat" title="Palitan ang pangalan" disabled>Rename</button><button id="exportChat" title="I-download ang usapan" disabled>Export</button></div>
    </div></header>

    <main class="wrap">
      <section id="welcome"><div class="orb">✳</div><h1>Ano ang nasa isip mo?</h1><p>Magtanong. Mag-explore ng idea. Mag-research. Pwede ring magpadala ng larawan (📎 o Ctrl+V).</p>
        <div class="chips"><button data-prompt="Mag-research ng latest OpenAI API updates. Gumamit ng official sources at ilagay ang links at dates.">Research with sources ↗</button><button data-prompt="Tulungan mo akong magplano ng simpleng AI assistant para sa business ko. Tanungin mo muna ako kung ano ang pinakamahalagang task.">Plan something together</button></div>
      </section>
      <div id="messages" aria-label="Conversation"></div><div class="footer-space"></div>
    </main>

    <div class="dock"><div class="dock-inner">
      <form id="chatForm" class="composer">
        <div class="previews" id="previews"></div>
        <label for="prompt" hidden>Your message</label>
        <textarea id="prompt" placeholder="Message Astra…" rows="2"></textarea>
        <div class="composer-bottom">
          <div style="display:flex;align-items:center;gap:8px;min-width:0">
            <input type="file" id="fileInput" accept="image/*" multiple hidden>
            <button type="button" class="iconbtn" id="attach" title="Mag-attach ng larawan">📎</button>
            <span class="badge" id="badge"></span>
          </div>
          <div><span id="status" role="status"></span> <button id="stop" type="button" hidden>Stop</button><button id="send" type="submit" class="primary">Send ↑</button></div>
        </div>
      </form>
      <div class="bottomnote">Naka-server ang API key — hindi ito lumalabas sa browser. Hanggang {{ $maxAttach }} larawan kada mensahe (≤ {{ (int) ($maxAttachKb/1024) }} MB bawat isa).</div>
    </div></div>
  </div>
</div>

<script>
'use strict';
const $=id=>document.getElementById(id);
const CSRF=document.querySelector('meta[name="csrf-token"]').content;
const R={send:@json(route('astra.send')),upload:@json(route('astra.upload')),list:@json(route('astra.conversations')),base:@json(url('/astra/conversations'))};
let conversationId=null,busy=false,controller=null,pending=[],conversations=[];

// ── DOM helpers + markdown (same as original file) ──
function el(tag,cls,text){const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;}
function safeURL(raw){try{const u=new URL(raw);return /^https?:$/.test(u.protocol)?u.href:null;}catch{return null;}}
function inline(parent,text){const re=/(\*\*([^*]+)\*\*|`([^`]+)`|\[([^\]]+)\]\((https?:\/\/[^\s)]+)\))/g;let i=0,m;while((m=re.exec(text))){parent.append(document.createTextNode(text.slice(i,m.index)));if(m[2])parent.append(el('strong','',m[2]));else if(m[3])parent.append(el('code','',m[3]));else{const url=safeURL(m[5]);if(url){const a=el('a','',m[4]);a.href=url;a.target='_blank';a.rel='noopener noreferrer';parent.append(a);}else parent.append(document.createTextNode(m[0]));}i=re.lastIndex;}parent.append(document.createTextNode(text.slice(i)));}
function markdown(target,text){target.replaceChildren();const lines=text.replace(/\r/g,'').split('\n');let i=0;while(i<lines.length){let line=lines[i];if(/^```/.test(line)){const pre=el('pre'),code=el('code');const body=[];i++;while(i<lines.length&&!/^```/.test(lines[i]))body.push(lines[i++]);code.textContent=body.join('\n');pre.append(code);target.append(pre);i++;continue;}if(!line.trim()){i++;continue;}if(i+1<lines.length&&line.includes('|')&&/^\s*\|?\s*:?-{3,}/.test(lines[i+1])){const box=el('div','table-scroll'),table=el('table');const row=(s,head)=>{const tr=el('tr');s.trim().replace(/^\||\|$/g,'').split('|').forEach(t=>{const cell=el(head?'th':'td');inline(cell,t.trim());tr.append(cell);});return tr;};const thead=el('thead');thead.append(row(line,true));table.append(thead);i+=2;const tbody=el('tbody');while(i<lines.length&&lines[i].includes('|')&&lines[i].trim())tbody.append(row(lines[i++],false));table.append(tbody);box.append(table);target.append(box);continue;}let m=line.match(/^(#{1,4})\s+(.+)$/);if(m){const n=el('h'+Math.min(4,m[1].length+1));inline(n,m[2]);target.append(n);i++;continue;}if(/^\s*([-*]|\d+\.)\s+/.test(line)){const ordered=/^\s*\d+\./.test(line),list=el(ordered?'ol':'ul');while(i<lines.length&&(ordered?/^\s*\d+\.\s+/:/^\s*[-*]\s+/).test(lines[i])){const item=el('li');inline(item,lines[i++].replace(/^\s*([-*]|\d+\.)\s+/,''));list.append(item);}target.append(list);continue;}const p=el(line.startsWith('> ')?'blockquote':'p');const body=[line.replace(/^> /,'')];i++;while(i<lines.length&&lines[i].trim()&&!/^(#{1,4}\s|```|> |\s*([-*]|\d+\.)\s)/.test(lines[i])){if(i+1<lines.length&&lines[i].includes('|')&&/^\s*\|?\s*:?-{3,}/.test(lines[i+1]))break;body.push(lines[i++]);}inline(p,body.join('\n'));target.append(p);}}
function scrollIfNear(){if(document.documentElement.scrollHeight-window.innerHeight-window.scrollY<440)window.scrollTo({top:document.body.scrollHeight,behavior:'instant'});}
function badge(){const s=$('search').value;$('badge').textContent=$('model').value+' · '+$('effort').selectedOptions[0].text+' · Web '+(s==='required'?'always':s);}
function setBusy(v){busy=v;['send','newChat','model','effort','search','budget','attach'].forEach(id=>$(id).disabled=v);$('stop').hidden=!v;$('send').hidden=v;$('status').textContent=v?'Working…':'';}
function fmtDate(s){if(!s)return '';const d=new Date(s);return isNaN(d)?'':d.toLocaleDateString('en-PH',{month:'short',day:'numeric'});}

// ── Cards ──
function userCard(text,imgs){const user=el('article','message user');user.append(el('div','role','You'));if(imgs&&imgs.length){const box=el('div','imgs');imgs.forEach(a=>{const im=el('img');im.src=a.url;im.alt=a.name||'';im.loading='lazy';box.append(im);});user.append(box);}if(text)user.append(el('div','bubble',text));$('messages').append(user);}
function assistantCard(model,effort){const root=el('article','message assistant');root.append(el('div','role',model+(effort?' · '+effort:'')));const activity=el('details','activity');activity.open=true;const summary=el('summary','','Connecting…');const inside=el('div','inside'),logs=el('div'),reason=el('div','reasoning','');inside.append(logs,reason);activity.append(summary,inside);const answer=el('div','answer'),sources=el('div','sources'),error=el('div','error'),meta=el('div','meta');root.append(activity,answer,sources,error,meta);$('messages').append(root);return{root,activity,summary,logs,reason,answer,sources,error,meta};}
function renderSaved(m){if(m.role==='user'){userCard(m.text||'',m.attachments||[]);return;}const card=assistantCard(m.usage?'assistant':'assistant','');card.activity.open=false;card.summary.textContent=m.status==='completed'?'Finished':(m.status||'');if(m.reasoning)card.reason.textContent=m.reasoning;else card.activity.hidden=true;markdown(card.answer,m.text||'');(m.sources||[]).forEach((s,i)=>{const a=el('a','',`${i+1}. ${s.title||s.url}`);a.href=safeURL(s.url)||'#';a.target='_blank';a.rel='noopener noreferrer';card.sources.append(a);});const u=m.usage||{};card.meta.textContent=(m.duration_ms?Math.round(m.duration_ms/1000)+'s':'')+(u.input_tokens!=null?' · '+Number(u.input_tokens).toLocaleString()+' input · '+Number(u.output_tokens||0).toLocaleString()+' output tokens':'');if(m.status&&m.status!=='completed')card.error.textContent='Status: '+m.status;}
function collectSources(output){const found=new Map();for(const item of output||[]){for(const c of item.content||[]){for(const a of c.annotations||[])if(a.type==='url_citation'&&safeURL(a.url))found.set(a.url,{url:a.url,title:a.title||a.url});}for(const s of item.action?.sources||[])if(safeURL(s.url))found.set(s.url,{url:s.url,title:s.title||s.url});}return [...found.values()];}
function finalText(output,sources){const parts=[];for(const item of output||[]){if(item.type!=='message')continue;for(const c of item.content||[]){if(c.type==='refusal'){parts.push(c.refusal||'');continue;}if(c.type!=='output_text')continue;let text=c.text||'';const annotations=(c.annotations||[]).filter(a=>a.type==='url_citation'&&safeURL(a.url)&&Number.isInteger(a.start_index)&&Number.isInteger(a.end_index)).sort((a,b)=>b.start_index-a.start_index);for(const a of annotations){const num=sources.findIndex(s=>s.url===a.url)+1;const url=safeURL(a.url).replace(/\(/g,'%28').replace(/\)/g,'%29');text=text.slice(0,a.start_index)+`[${num||'source'}](${url})`+text.slice(a.end_index);}parts.push(text);}}return parts.join('\n\n');}
async function readSSE(body,handle){if(!body)throw new Error('Walang streaming support ang browser na ito. Gumamit ng Chrome/Edge.');const reader=body.getReader(),decoder=new TextDecoder();let buffer='',data=[];function line(s){if(s===''){if(data.length){const payload=data.join('\n');data=[];if(payload!=='[DONE]')handle(JSON.parse(payload));}}else if(s.startsWith('data:'))data.push(s.slice(5).replace(/^ /,''));}try{while(true){const {done,value}=await reader.read();buffer+=done?decoder.decode():decoder.decode(value,{stream:true});let pos;while((pos=buffer.indexOf('\n'))>=0){line(buffer.slice(0,pos).replace(/\r$/,''));buffer=buffer.slice(pos+1);}if(done)break;}if(buffer)line(buffer.replace(/\r$/,''));line('');}finally{reader.releaseLock();}}

// ── Conversations (sidebar) ──
async function loadConversations(){try{const r=await fetch(R.list,{headers:{Accept:'application/json'}});const j=await r.json();conversations=j.conversations||[];renderList();}catch(e){console.error(e);}}
function renderList(){const box=$('convlist');box.replaceChildren();if(!conversations.length){box.append(el('div','note','Wala pang usapan.'));return;}conversations.forEach(c=>{const row=el('div','conv'+(c.id===conversationId?' active':''));row.dataset.id=c.id;const t=el('span','t',c.title||'(walang title)');t.title=c.title||'';const d=el('span','',fmtDate(c.last_message_at||c.updated_at));d.style.cssText='font-size:10.5px;color:var(--muted)';const x=el('button','x','✕');x.title='Burahin';x.onclick=async ev=>{ev.stopPropagation();if(busy)return;if(!confirm('Burahin ang usapang ito?'))return;await fetch(R.base+'/'+c.id,{method:'DELETE',headers:{'X-CSRF-TOKEN':CSRF,Accept:'application/json'}});if(c.id===conversationId)newChat(true);await loadConversations();};row.append(t,d,x);row.onclick=()=>{if(busy)return;openConversation(c.id);};box.append(row);});}
async function openConversation(id){setBusy(true);try{const r=await fetch(R.base+'/'+id,{headers:{Accept:'application/json'}});const j=await r.json();if(!j.ok)throw new Error('Hindi mabuksan.');conversationId=id;$('messages').replaceChildren();$('welcome').hidden=true;const c=j.conversation||{};if(c.model&&[...$('model').options].some(o=>o.value===c.model))$('model').value=c.model;if(c.effort)$('effort').value=c.effort;if(c.search_mode)$('search').value=c.search_mode;if(c.max_output_tokens)$('budget').value=String(c.max_output_tokens);badge();(j.messages||[]).forEach(renderSaved);$('renameChat').disabled=false;$('exportChat').disabled=false;renderList();closeSide();window.scrollTo({top:document.body.scrollHeight,behavior:'instant'});}catch(e){alert(e.message);}finally{setBusy(false);$('prompt').focus();}}
function newChat(silent){if(busy)return;conversationId=null;$('messages').replaceChildren();$('welcome').hidden=false;$('status').textContent='';$('prompt').value='';pending=[];renderPreviews();$('renameChat').disabled=true;$('exportChat').disabled=true;renderList();closeSide();if(!silent)$('prompt').focus();}
function openSide(){$('side').classList.add('open');$('closeSide').style.display='';}
function closeSide(){$('side').classList.remove('open');$('closeSide').style.display='none';}

// ── Attachments ──
function renderPreviews(){const box=$('previews');box.replaceChildren();pending.forEach((a,i)=>{const pv=el('div','pv');const im=el('img');im.src=a.url;im.alt=a.name;const rm=el('button','rm','✕');rm.type='button';rm.title='Alisin';rm.onclick=()=>{pending.splice(i,1);renderPreviews();};pv.append(im,rm);box.append(pv);});}
async function uploadFiles(files){const max={{ (int) $maxAttach }};for(const f of files){if(!f.type.startsWith('image/'))continue;if(pending.length>=max){$('status').textContent='Hanggang '+max+' larawan lang.';break;}const fd=new FormData();fd.append('image',f,f.name||'pasted.png');$('status').textContent='Ina-upload ang larawan…';try{const r=await fetch(R.upload,{method:'POST',headers:{'X-CSRF-TOKEN':CSRF,Accept:'application/json'},body:fd});const j=await r.json();if(!r.ok||!j.ok){const msg=j.message||(j.errors&&Object.values(j.errors).flat().join(' '))||'Upload failed';throw new Error(msg);}pending.push(j.attachment);renderPreviews();$('status').textContent='';}catch(e){$('status').textContent=e.message;}}}
$('attach').onclick=()=>$('fileInput').click();$('fileInput').onchange=e=>{uploadFiles([...e.target.files]);e.target.value='';};
document.addEventListener('paste',e=>{const files=[...(e.clipboardData?.files||[])].filter(f=>f.type.startsWith('image/'));if(files.length){e.preventDefault();uploadFiles(files);}});
const comp=$('chatForm');comp.addEventListener('dragover',e=>{e.preventDefault();comp.classList.add('drag');});comp.addEventListener('dragleave',()=>comp.classList.remove('drag'));comp.addEventListener('drop',e=>{e.preventDefault();comp.classList.remove('drag');uploadFiles([...e.dataTransfer.files]);});

// ── Send ──
async function send(event){event?.preventDefault();if(busy)return;const prompt=$('prompt').value.trim(),model=$('model').value;if(!prompt&&!pending.length)return;$('welcome').hidden=true;$('prompt').value='';$('prompt').style.height='auto';const imgs=pending.slice();pending=[];renderPreviews();userCard(prompt,imgs);const effort=$('effort').value,card=assistantCard(model,effort),started=Date.now();let text='',summaries=new Map(),terminal=null,outputItems=new Map(),searchCount=new Set();controller=new AbortController();setBusy(true);window.scrollTo({top:document.body.scrollHeight,behavior:'instant'});
const timer=setInterval(()=>{card.meta.textContent='Elapsed '+Math.floor((Date.now()-started)/1000)+'s'+((effort==='xhigh'||effort==='max')?' · Extra High/Max may take several minutes.':'');},1000);
function log(s){if(card.logs.lastChild?.textContent!==s)card.logs.append(el('div','trace',s));}
function summaryText(){return [...summaries.values()].join('\n\n');}
function eventHandler(e){if(e.type==='likha.start'){if(!conversationId){conversationId=e.conversation_id;conversations.unshift({id:e.conversation_id,title:e.title,last_message_at:new Date().toISOString()});renderList();$('renameChat').disabled=false;$('exportChat').disabled=false;}}
else if(e.type==='likha.notice'){log(e.message);}
else if(e.type==='likha.saved'){const c=conversations.find(x=>x.id===e.conversation_id);if(c){c.last_message_at=new Date().toISOString();}renderList();}
else if(e.type==='response.created'||e.type==='response.in_progress'){card.summary.textContent='Thinking / processing…';}
else if(e.type==='response.reasoning_summary_text.delta'){const k=(e.item_id||e.output_index)+':'+e.summary_index;summaries.set(k,(summaries.get(k)||'')+e.delta);card.reason.textContent=summaryText();card.summary.textContent='Reasoning summary arriving…';}
else if(e.type==='response.reasoning_summary_text.done'){const k=(e.item_id||e.output_index)+':'+e.summary_index;summaries.set(k,e.text||summaries.get(k)||'');card.reason.textContent=summaryText();}
else if(e.type==='response.output_text.delta'){text+=e.delta;card.answer.textContent=text;card.answer.style.whiteSpace='pre-wrap';card.summary.textContent='Writing answer…';}
else if(e.type==='response.refusal.delta'){text+=e.delta;card.answer.textContent=text;}
else if(e.type?.startsWith('response.web_search_call.')){searchCount.add(e.item_id||e.output_index);const done=e.type.endsWith('.completed');log(done?'Web search completed.':'Searching the web…');card.summary.textContent=done?'Reviewing sources…':'Searching the web…';}
else if(e.type==='response.output_item.done'){outputItems.set(e.output_index,e.item);if(e.item?.type==='web_search_call'){const a=e.item.action||{};for(const q of a.queries||(a.query?[a.query]:[]))log('Search: '+q);if(a.url)log('Opened: '+a.url);}}
else if(['response.completed','response.incomplete','response.failed'].includes(e.type)){terminal=e.response;}
else if(e.type==='error'){throw new Error(e.message||e.error?.message||'API stream error.');}scrollIfNear();}
try{const body={conversation_id:conversationId,prompt,model,effort,search:$('search').value,budget:Number($('budget').value),attachments:imgs.map(a=>a.id)};const response=await fetch(R.send,{method:'POST',headers:{'Content-Type':'application/json','Accept':'text/event-stream','X-CSRF-TOKEN':CSRF},body:JSON.stringify(body),signal:controller.signal,credentials:'same-origin'});if(!response.ok){let data;try{data=await response.json();}catch{data={};}throw new Error(data.message||(data.errors&&Object.values(data.errors).flat().join(' '))||response.statusText);}await readSSE(response.body,eventHandler);if(!terminal)throw new Error('Naputol ang koneksyon bago matapos. Naka-save ang partial na sagot; subukan ulit kung kailangan.');if(terminal.status==='failed')throw new Error(terminal.error?.message||'Nag-fail ang API request.');const output=terminal.output||[...outputItems.entries()].sort((a,b)=>a[0]-b[0]).map(p=>p[1]);const sources=collectSources(output);text=finalText(output,sources)||text;card.answer.style.whiteSpace='normal';markdown(card.answer,text);const reasoning=output.filter(o=>o.type==='reasoning').flatMap(o=>(o.summary||[]).map(s=>s.text||'')).join('\n\n')||summaryText();if(reasoning)card.reason.textContent=reasoning;else card.activity.hidden=!searchCount.size;sources.forEach((s,i)=>{const a=el('a','',`${i+1}. ${s.title}`);a.href=safeURL(s.url);a.target='_blank';a.rel='noopener noreferrer';card.sources.append(a);});card.summary.textContent=terminal.status==='incomplete'?'Response incomplete':'Finished'+(searchCount.size?' · '+searchCount.size+' web search call(s)':'');card.activity.open=!text;if(terminal.status==='incomplete')card.error.textContent='Naputol bago matapos: '+(terminal.incomplete_details?.reason||'API limit')+'. Sabihin ang “Continue” o taasan ang Budget.';const usage=terminal.usage;clearInterval(timer);card.meta.textContent=Math.round((Date.now()-started)/1000)+'s'+(usage?' · '+(usage.input_tokens||0).toLocaleString()+' input · '+(usage.output_tokens||0).toLocaleString()+' output tokens':'');}
catch(err){clearInterval(timer);const stopped=err.name==='AbortError';card.summary.textContent=stopped?'Stopped':'Request failed';card.error.textContent=stopped?'Itinigil ang pagtanggap. Maaaring tapusin pa rin ng API ang pagproseso.':(err instanceof TypeError?'Hindi makakonekta sa server. I-check ang internet.':err.message);card.meta.textContent=Math.round((Date.now()-started)/1000)+'s';if(text){card.answer.style.whiteSpace='normal';markdown(card.answer,text);}const retry=el('button','retry','Ibalik ang mensahe para i-retry');retry.type='button';retry.onclick=()=>{if(busy)return;$('prompt').value=prompt;$('prompt').focus();};card.root.append(retry);}
finally{clearInterval(timer);setBusy(false);controller=null;$('prompt').focus();scrollIfNear();}}

// ── Wiring ──
$('chatForm').addEventListener('submit',send);
$('prompt').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing&&!window.matchMedia('(pointer: coarse)').matches){e.preventDefault();send();}});
$('prompt').addEventListener('input',()=>{$('prompt').style.height='auto';$('prompt').style.height=Math.min(160,$('prompt').scrollHeight)+'px';});
$('stop').onclick=()=>controller?.abort();
$('newChat').onclick=()=>newChat(false);
$('openSide').onclick=openSide;$('closeSide').onclick=closeSide;
$('renameChat').onclick=async()=>{if(!conversationId||busy)return;const c=conversations.find(x=>x.id===conversationId);const title=prompt('Bagong pangalan:',c?.title||'');if(title===null||!title.trim())return;const r=await fetch(R.base+'/'+conversationId,{method:'PATCH',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,Accept:'application/json'},body:JSON.stringify({title:title.trim()})});const j=await r.json();if(j.ok){if(c)c.title=j.title;renderList();}};
$('exportChat').onclick=()=>{if(!conversationId)return;window.location.href=R.base+'/'+conversationId+'/export';};
document.querySelectorAll('[data-prompt]').forEach(b=>b.onclick=()=>{$('prompt').value=b.dataset.prompt;$('prompt').focus();});
['model','effort','search'].forEach(id=>$(id).addEventListener('input',badge));badge();
loadConversations();
</script>
</body>
</html>
