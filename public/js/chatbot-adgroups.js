// Ad groups ng isang page sa /chatbot/pages: ang section sa ilalim ng mga value sa Edit ng page. Ang mga ad ng isang
// group ay gumagamit ng mga value ng group; ang blangko ay ang page value. Lahat ng text mula sa server ay textContent.
// Plain na script, hindi module: iisang pangalan lang ang inilalagay nito sa page, ang ChatbotAdGroups.
(function (root) {
  'use strict';

  function el(tag, attrs, children) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v == null || v === false) return;
      if (k === 'class') e.className = v; else if (k === 'text') e.textContent = v; else e.setAttribute(k, v === true ? '' : v);
    });
    [].concat(children || []).forEach(function (c) { if (c != null) e.append(c); });
    return e;
  }
  var BTN = 'cb-btn cb-btn-sm', LINK = 'py-1 text-xs text-blue-700 underline hover:text-blue-900';
  var INPUT = 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900';
  var short = function (v, n) { v = String(v == null ? '' : v); return v.length > n ? v.slice(0, n - 1) + '…' : v; };
  var plural = function (n, w) { return n + ' ' + w + (n === 1 ? '' : 's'); };

  /**
   * opts: {page, pageValues (key => page value, para sa placeholder), csrf, urls: {index, save, add, del, history, restore}}
   */
  function mount(box, opts) {
    var st = {groups: [], keys: [], flows: [], unassigned: [], total: 0, editing: null, history: null, q: '', ticked: new Set(), seq: 0};
    var u = opts.urls;
    var qs = function (extra) { return '?page=' + encodeURIComponent(opts.page) + (extra || ''); };
    var post = function (url, body) {
      return fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': opts.csrf},
        body: JSON.stringify(Object.assign({page: opts.page}, body))})
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return {ok: r.ok, status: r.status, data: d}; }); });
    };
    var status = el('p', {class: 'mt-1 min-h-[1rem] text-xs text-slate-600', 'aria-live': 'polite'});
    var say = function (text, bad) { status.className = 'mt-1 min-h-[1rem] text-xs ' + (bad ? 'text-red-600' : 'text-slate-600'); status.textContent = text; };
    var body = el('div');

    function load() {
      var seq = ++st.seq;
      return fetch(u.index + qs(st.q ? '&q=' + encodeURIComponent(st.q) : ''), {headers: {'Accept': 'application/json'}})
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (d) {
          if (seq !== st.seq) return;
          st.groups = d.groups; st.keys = d.keys; st.flows = d.flows; st.unassigned = d.unassigned; st.total = d.unassigned_total;
          st.ticked.forEach(function (a) { if (!st.unassigned.some(function (x) { return x.ad_id === a; })) st.ticked.delete(a); });
          render();
        })
        .catch(function (s) { say('Could not load the ad groups (' + s + ').', true); });
    }

    function groupName(id) { var g = st.groups.find(function (x) { return x.id === id; }); return g ? g.name : ''; }
    function flowName(id) { var f = st.flows.find(function (x) { return String(x.id) === String(id); }); return f ? f.name : '#' + id; }
    function label(key) { var k = st.keys.find(function (x) { return x.key === key; }); return k ? k.label : key; }

    function render() {
      var list = st.groups.length
        ? el('ul', {class: 'mt-2 space-y-2'}, st.groups.map(groupCard))
        : el('p', {class: 'mt-2 text-xs text-slate-500', text: 'No groups yet: every customer gets the page values.'});
      body.replaceChildren(...[list, st.editing ? editor() : null, unassigned()].filter(Boolean));
    }

    function groupCard(g) {
      var set = Object.keys(g.values);
      var edit = el('button', {type: 'button', class: LINK, text: 'Edit', 'aria-label': 'Edit ' + g.name});
      edit.addEventListener('click', function () { st.editing = {id: g.id}; st.history = null; render(); focusEditor(); });
      var hist = el('button', {type: 'button', class: LINK, text: 'History', 'aria-label': 'History of ' + g.name, 'aria-expanded': st.history && st.history.id === g.id ? 'true' : 'false'});
      hist.addEventListener('click', function () { toggleHistory(g.id); });
      var del = el('button', {type: 'button', class: 'py-1 text-xs text-red-700 underline hover:text-red-900', text: 'Delete', 'aria-label': 'Delete ' + g.name});
      del.addEventListener('click', function () {
        if (!confirm('Delete the group "' + g.name + '"? Its ' + plural(g.ads.length, 'ad') + ' go back to the page values.')) return;
        post(u.del, {id: g.id}).then(function (r) {
          if (!r.ok) return say(r.data.error || 'Could not delete (' + r.status + ').', true);
          say('Deleted "' + g.name + '".');
          if (st.editing && st.editing.id === g.id) st.editing = null;
          load();
        });
      });
      return el('li', {class: 'rounded-md border border-slate-200 p-2'}, [
        el('div', {class: 'flex flex-wrap items-center justify-between gap-x-3 gap-y-1'}, [
          el('div', {class: 'min-w-0 break-words font-medium', text: g.name}),
          el('div', {class: 'flex gap-3'}, [edit, hist, del])]),
        el('div', {class: 'mt-1 break-all font-mono text-xs text-slate-600', text: plural(g.ads.length, 'ad') + (g.ads.length ? ': ' + short(g.ads.join(', '), 160) : '')}),
        el('div', {class: 'mt-1 break-words text-xs text-slate-600', text: set.length
          ? 'Own values: ' + set.map(function (k) { return label(k) + (k === 'flow_id' ? ' (' + flowName(g.values[k]) + ')' : ''); }).join(', ')
          : 'No own values: uses the page values.'}),
        st.history && st.history.id === g.id ? historyBox() : null]);
    }

    function toggleHistory(id) {
      if (st.history && st.history.id === id) { st.history = null; render(); return; }
      st.history = {id: id, rows: null};
      render();
      fetch(u.history + qs('&id=' + id), {headers: {'Accept': 'application/json'}})
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (d) { if (st.history && st.history.id === id) { st.history.rows = d.rows; render(); } })
        .catch(function (s) { say('Could not load the history (' + s + ').', true); });
    }

    function historyBox() {
      var rows = st.history.rows;
      if (rows === null) return el('p', {class: 'mt-2 text-xs text-slate-500', text: 'Loading…'});
      if (!rows.length) return el('p', {class: 'mt-2 text-xs text-slate-500', text: 'No history.'});
      return el('ul', {class: 'mt-2 max-h-64 space-y-1 overflow-y-auto border-t border-slate-100 pt-2'}, rows.map(function (h) {
        var key = h.key === 'name' ? 'Name' : h.key === 'ads' ? 'Ad IDs' : label(h.key);
        var restore = null;
        if (!h.active) {
          restore = el('button', {type: 'button', class: LINK, text: 'Restore', 'aria-label': 'Restore ' + key + ' from ' + h.at});
          restore.addEventListener('click', function () {
            post(u.restore, {setting: h.id}).then(function (r) {
              if (!r.ok) return say(r.data.error || 'Could not restore (' + r.status + ').', true);
              say('Restored ' + key + '.');
              st.history = null;
              load().then(function () { toggleHistory(r.data.id); });
            });
          });
        }
        return el('li', {class: 'text-xs'}, [
          el('div', {class: 'flex flex-wrap items-center gap-x-2'}, [el('span', {class: 'font-medium', text: key}),
            el('span', {class: 'text-slate-500', text: h.at + (h.by ? ' · ' + h.by : '') + (h.active ? ' · current' : h.replaced_at ? ' · replaced ' + h.replaced_at : '')}), restore]),
          el('div', {class: 'whitespace-pre-line break-words text-slate-700', text: short(h.key === 'flow_id' ? flowName(h.value) : h.value, 300)})]);
      }));
    }

    function focusEditor() { var n = box.querySelector('#ag-name'); if (n) n.focus(); }

    function editor() {
      var g = st.editing.id ? st.groups.find(function (x) { return x.id === st.editing.id; }) : null;
      var vals = g ? g.values : {};
      var name = el('input', {id: 'ag-name', type: 'text', maxlength: '100', class: INPUT, autocomplete: 'off'});
      name.value = g ? g.name : (st.editing.name || '');
      var ads = el('textarea', {id: 'ag-ads', rows: '2', class: INPUT + ' font-mono', placeholder: 'Ad IDs: comma, space or new line'});
      ads.value = g ? g.ads.join(', ') : (st.editing.ads || []).join(', ');
      var inputs = {};
      var fields = st.keys.map(function (k) {
        var fid = 'ag-v-' + k.key, page = String((opts.pageValues || {})[k.key] || ''), input;
        if (k.kind === 'flow') {
          input = el('select', {id: fid, class: INPUT}, [el('option', {value: '', text: '(page flow)'})].concat(st.flows.map(function (f) { return el('option', {value: String(f.id), text: f.name}); })));
        } else if (k.kind === 'long' || /^main_flow/.test(k.key)) {
          input = el('textarea', {id: fid, rows: '2', class: INPUT});
        } else {
          input = el('input', {id: fid, type: k.kind === 'image' ? 'url' : 'text', class: INPUT, autocomplete: 'off'});
        }
        if (k.kind !== 'flow') input.setAttribute('placeholder', page ? 'Page: ' + short(page.replace(/\s+/g, ' '), 80) : 'Page value (empty)');
        input.value = vals[k.key] || '';
        inputs[k.key] = input;
        return el('div', {class: 'py-1'}, [el('label', {for: fid, class: 'mb-0.5 block text-xs font-medium text-slate-600', text: k.label}), input]);
      });
      var err = el('p', {class: 'text-xs text-red-600', 'aria-live': 'polite'});
      var save = el('button', {type: 'button', class: 'cb-btn cb-btn-sm cb-btn-primary', text: g ? 'Save group' : 'Create group'});
      var cancel = el('button', {type: 'button', class: BTN, text: 'Cancel'});
      cancel.addEventListener('click', function () { st.editing = null; render(); });
      save.addEventListener('click', function () {
        var values = {};
        Object.keys(inputs).forEach(function (k) { values[k] = inputs[k].value.trim(); });
        save.disabled = true;
        post(u.save, {id: g ? g.id : null, name: name.value, ads: ads.value, values: values}).then(function (r) {
          save.disabled = false;
          if (!r.ok) { err.textContent = r.data.error || 'Could not save (' + r.status + ').'; return; }
          say((g ? 'Saved' : 'Created') + ' "' + name.value.trim() + '".');
          st.editing = null;
          load();
        });
      });
      return el('div', {class: 'mt-3 rounded-md border border-blue-200 bg-blue-50/40 p-2', role: 'group', 'aria-label': g ? 'Edit ' + g.name : 'New group'}, [
        el('div', {class: 'py-1'}, [el('label', {for: 'ag-name', class: 'mb-0.5 block text-xs font-medium text-slate-600', text: 'Group name'}), name]),
        el('div', {class: 'py-1'}, [el('label', {for: 'ag-ads', class: 'mb-0.5 block text-xs font-medium text-slate-600', text: 'Ad IDs'}), ads]),
        el('p', {class: 'mt-1 text-xs text-slate-500', text: 'Leave a value empty to use the page value.'})].concat(fields, [err,
        el('div', {class: 'mt-2 flex flex-wrap gap-2'}, [save, cancel])]));
    }

    function unassigned() {
      var search = el('input', {type: 'search', class: INPUT + ' sm:max-w-xs', placeholder: 'Search ad ID or name', 'aria-label': 'Search ads not in a group', value: st.q});
      var timer = null;
      search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { st.q = search.value.trim(); load().then(function () { var s = box.querySelector('input[type=search]'); if (s) { s.focus(); s.setSelectionRange(s.value.length, s.value.length); } }); }, 300);
      });
      var rows = st.unassigned.map(function (a) {
        var cid = 'ag-t-' + a.ad_id;
        var cb = el('input', {id: cid, type: 'checkbox', class: 'mt-0.5 h-5 w-5 shrink-0', checked: st.ticked.has(a.ad_id)});
        cb.addEventListener('change', function () { if (cb.checked) st.ticked.add(a.ad_id); else st.ticked.delete(a.ad_id); paintAdd(); });
        return el('li', {class: 'flex items-start gap-2 py-1'}, [cb, el('label', {for: cid, class: 'min-w-0 flex-1 text-xs'}, [
          el('span', {class: 'block break-all font-mono text-slate-800', text: a.ad_id}),
          el('span', {class: 'block break-words text-slate-600', text: a.name || '(no name in Ads Manager)'}),
          el('span', {class: 'block text-slate-500', text: plural(a.customers, 'customer') + (a.last ? ' · last ' + a.last : '')})])]);
      });
      var target = el('select', {class: 'min-w-0 max-w-full flex-1 rounded border border-slate-300 px-1 py-1 text-xs text-gray-900 sm:flex-none', 'aria-label': 'Add to which group'},
        st.groups.map(function (g) { return el('option', {value: String(g.id), text: g.name}); }).concat([el('option', {value: 'new', text: 'New group…'})]));
      var add = el('button', {type: 'button', class: 'cb-btn cb-btn-sm cb-btn-primary'});
      function paintAdd() { add.disabled = !st.ticked.size; add.textContent = st.ticked.size ? 'Add ' + plural(st.ticked.size, 'ad') + ' to group' : 'Add to group'; }
      paintAdd();
      add.addEventListener('click', function () {
        var ads = Array.from(st.ticked);
        if (target.value === 'new') {
          // Ang bagong group: bubuksan ang editor na may mga tsinek na ad, para mapangalanan at malagyan ng value.
          st.editing = {id: null, ads: ads};
          st.ticked.clear();
          render();
          focusEditor();
          return;
        }
        add.disabled = true;
        post(u.add, {id: Number(target.value), ads: ads}).then(function (r) {
          if (!r.ok) { paintAdd(); return say(r.data.error || 'Could not add (' + r.status + ').', true); }
          say('Added ' + plural(ads.length, 'ad') + ' to "' + groupName(Number(target.value)) + '".');
          st.ticked.clear();
          load();
        });
      });
      return el('div', {class: 'mt-4 border-t border-slate-100 pt-3'}, [
        el('h4', {class: 'text-xs font-semibold text-slate-700', text: 'Ads not in a group yet (last 30 days)'}),
        el('div', {class: 'mt-1'}, search),
        rows.length ? el('ul', {class: 'mt-1 max-h-72 divide-y divide-slate-100 overflow-y-auto'}, rows)
          : el('p', {class: 'mt-1 text-xs text-slate-500', text: st.q ? 'No ad matches.' : 'Every ad seen in the last 30 days is in a group.'}),
        st.total > st.unassigned.length ? el('p', {class: 'mt-1 text-xs text-slate-500', text: 'Showing the top ' + st.unassigned.length + ' of ' + st.total + ' by customers. Search to find others.'}) : null,
        rows.length ? el('div', {class: 'mt-2 flex flex-wrap items-center gap-2'}, [target, add]) : null]);
    }

    var create = el('button', {type: 'button', class: BTN, text: '+ New group'});
    create.addEventListener('click', function () { st.editing = {id: null}; st.history = null; render(); focusEditor(); });
    box.replaceChildren(
      el('div', {class: 'flex flex-wrap items-center justify-between gap-2'}, [el('h3', {id: 'ag-title', class: 'text-sm font-semibold', text: 'Ad groups'}), create]),
      el('p', {class: 'mt-1 text-xs text-slate-500', text: 'Optional. Customers whose latest ad is in a group get that group\'s values; empty values, and customers with no ad or an ad in no group, use the page values above.'}),
      status, body);
    box.setAttribute('aria-labelledby', 'ag-title');
    load();
  }

  root.ChatbotAdGroups = {mount: mount};
})(typeof window !== 'undefined' ? window : this);
