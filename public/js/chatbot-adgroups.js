// Ad groups ng isang Ad-based na page sa /chatbot/pages: ang mga sub-row sa ilalim ng row ng page (Default, isang row
// kada group na may sariling mga cell, ang mga ad ID ng group na may status, Add ad group at "Ads not in a group yet").
// Ang blangkong cell ng group ay ang default (page value). Lahat ng text mula sa server ay textContent.
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
  var LINK = 'py-1 text-xs text-blue-700 underline hover:text-blue-900';
  var INPUT = 'w-full rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900';
  var TD = 'border-b border-slate-100 bg-slate-50 px-3 py-2 align-top text-xs';
  var SHOWN_ADS = 5;
  var plural = function (n, w) { return n + ' ' + w + (n === 1 ? '' : 's'); };

  /** Ang status ng ad bilang maikling text: On, Off since <petsa> (N days), o No report data. */
  function statusText(a) {
    if (a.status === 'on') return 'On';
    if (a.status === 'off') return 'Off since ' + a.off_since + ' (' + plural(a.off_days, 'day') + ')';
    return 'No report data';
  }
  function statusPill(a) {
    var cls = a.status === 'on' ? 'bg-green-100 text-green-800' : a.status === 'off' ? 'bg-slate-200 text-slate-700' : 'bg-amber-50 text-amber-800';
    return el('span', {class: 'inline-block rounded px-1.5 py-0.5 text-[11px] font-medium ' + cls, text: statusText(a)});
  }
  function newBadge(a) { return a.new ? el('span', {class: 'inline-block rounded bg-blue-600 px-1.5 py-0.5 text-[11px] font-semibold text-white', text: 'New'}) : null; }

  /**
   * opts: {csrf, urls: {index, save, add, del, history, restore, mode, value, removeAd}, onChange()}
   * Ang onChange ay tinatawag kapag may bagong data (ang table ay muling ipinipinta).
   */
  function create(opts) {
    var u = opts.urls, pages = new Map(), open = new Set();
    var post = function (url, body) {
      return fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': opts.csrf}, body: JSON.stringify(body)})
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return {ok: r.ok, status: r.status, data: d}; }); })
        .catch(function () { return {ok: false, status: 0, data: {error: 'No connection: nothing was saved.'}}; });
    };
    function state(page) {
      if (!pages.has(page)) pages.set(page, {data: null, error: '', note: '', q: '', ticked: new Set(), showAll: new Set(), seq: 0, adding: false});
      return pages.get(page);
    }
    function load(page, focusSearch) {
      var st = state(page), seq = ++st.seq;
      return fetch(u.index + '?page=' + encodeURIComponent(page) + (st.q ? '&q=' + encodeURIComponent(st.q) : ''), {headers: {'Accept': 'application/json'}})
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (d) {
          if (seq !== st.seq) return;
          st.data = d; st.error = '';
          st.ticked.forEach(function (a) { if (!d.unassigned.some(function (x) { return x.ad_id === a; })) st.ticked.delete(a); });
          opts.onChange(page, d);
          if (focusSearch) { var s = document.querySelector('input[data-ag-search="' + CSS.escape(page) + '"]'); if (s) { s.focus(); s.setSelectionRange(s.value.length, s.value.length); } }
        })
        .catch(function (s) { if (seq === st.seq) { st.error = 'Could not load the ad groups (' + s + ').'; opts.onChange(page); } });
    }
    function say(page, text, bad) { var st = state(page); st.note = text; st.noteBad = !!bad; }
    // Pagkatapos ng sulat: ang mensahe, saka ang bagong data ng page.
    function after(page, ok, text) { return function (r) { say(page, r.ok ? text : (r.data.error || 'Not saved (' + r.status + ').'), !r.ok); return load(page); }; }

    // ---- Maliit na modal (value ng group at history): nasa ibabaw ng table, Escape o ✕ ang nagsasara. ----
    var modal = null;
    function closeModal() { if (modal) { modal.box.remove(); if (modal.back) modal.back.focus(); modal = null; } }
    function openModal(title, sub, body, focus) {
      closeModal();
      var close = el('button', {type: 'button', class: 'px-2 text-slate-500 hover:text-slate-800', 'aria-label': 'Close', text: '✕'});
      var box = el('div', {class: 'fixed inset-0 overflow-y-auto bg-slate-900/30 p-2 sm:p-8', style: 'z-index: 70', role: 'dialog', 'aria-modal': 'true', 'aria-label': title}, [
        el('div', {class: 'mx-auto w-full max-w-lg rounded-xl bg-white p-4 text-sm shadow-lg'}, [
          el('div', {class: 'mb-2 flex items-start justify-between gap-3'}, [el('div', {class: 'min-w-0'}, [el('div', {class: 'break-words font-semibold', text: title}),
            sub ? el('div', {class: 'break-words text-xs text-slate-500', text: sub}) : null]), close]),
          body])]);
      close.addEventListener('click', closeModal);
      box.addEventListener('click', function (e) { if (e.target === box) closeModal(); });
      box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); closeModal(); } });
      modal = {box: box, back: document.activeElement};
      document.body.append(box);
      (focus || close).focus();
    }

    /** Ang editor ng isang value ng group; blangko = ang default. */
    function editValue(page, g, k, pageValue) {
      var input = k.kind === 'image' ? el('input', {type: 'url', class: INPUT, autocomplete: 'off', placeholder: 'https://…'}) : el('textarea', {rows: '6', class: INPUT});
      input.value = (g.values || {})[k.key] || '';
      var err = el('p', {class: 'mt-1 min-h-[1rem] text-xs text-red-600', 'aria-live': 'polite'});
      var save = el('button', {type: 'button', class: 'cb-btn cb-btn-primary cb-btn-sm', text: 'Save'});
      var cancel = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Cancel'});
      cancel.addEventListener('click', closeModal);
      save.addEventListener('click', function () {
        save.disabled = true;
        post(u.value, {page: page, id: g.id, key: k.key, value: input.value.trim()}).then(function (r) {
          save.disabled = false;
          if (!r.ok) { err.textContent = r.data.error || 'Not saved (' + r.status + ').'; return; }
          closeModal();
          say(page, 'Saved ' + k.label + ' of "' + g.name + '".');
          load(page);
        });
      });
      openModal(g.name + ' · ' + k.label, 'Leave it empty to use the default (the page value).', el('div', {}, [input,
        el('p', {class: 'mt-1 whitespace-pre-line break-words text-xs text-slate-500', text: 'Default: ' + (pageValue === '' ? '(empty)' : pageValue)}), err,
        el('div', {class: 'mt-2 flex gap-2'}, [save, cancel])]), input);
    }

    /** Ang history ng isang group (lahat ng value, pangalan at ad list), may Restore. */
    function history(page, g) {
      var list = el('ul', {class: 'max-h-[70vh] space-y-2 overflow-y-auto'}, el('li', {class: 'text-xs text-slate-500', text: 'Loading…'}));
      var note = el('p', {class: 'mb-1 min-h-[1rem] text-xs', 'aria-live': 'polite'});
      var label = function (key) { var k = (state(page).data.keys || []).find(function (x) { return x.key === key; }); return key === 'name' ? 'Name' : key === 'ads' ? 'Ad IDs' : (k ? k.label : key); };
      function fill() {
        fetch(u.history + '?page=' + encodeURIComponent(page) + '&id=' + g.id, {headers: {'Accept': 'application/json'}})
          .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
          .then(function (d) {
            if (!d.rows.length) { list.replaceChildren(el('li', {class: 'text-xs text-slate-500', text: 'No history.'})); return; }
            list.replaceChildren.apply(list, d.rows.map(function (h) {
              var restore = null;
              if (!h.active) {
                restore = el('button', {type: 'button', class: LINK, text: 'Restore', 'aria-label': 'Restore ' + label(h.key) + ' from ' + h.at});
                restore.addEventListener('click', function () {
                  restore.disabled = true;
                  post(u.restore, {page: page, setting: h.id}).then(function (r) {
                    note.className = 'mb-1 min-h-[1rem] text-xs ' + (r.ok ? 'text-green-700' : 'text-red-600');
                    note.textContent = r.ok ? 'Restored ' + label(h.key) + '.' : (r.data.error || 'Not restored (' + r.status + ').');
                    load(page); fill();
                  });
                });
              }
              return el('li', {class: 'border-b border-slate-100 pb-1 text-xs'}, [
                el('div', {class: 'flex flex-wrap items-center gap-x-2'}, [el('span', {class: 'font-medium', text: label(h.key)}),
                  el('span', {class: 'text-slate-500', text: h.at + (h.by ? ' · ' + h.by : '') + (h.active ? ' · current' : h.replaced_at ? ' · replaced ' + h.replaced_at : '')}), restore]),
                el('div', {class: 'whitespace-pre-line break-words text-slate-700', text: h.value})]);
            }));
          })
          .catch(function (s) { list.replaceChildren(el('li', {class: 'text-xs text-red-600', text: 'Could not load the history (' + s + ').'})); });
      }
      openModal('History · ' + g.name, page, el('div', {}, [note, list]));
      fill();
    }

    // ---- Ang mga sub-row ----
    // Ang buong-lapad na row: ang laman ay nakadikit sa kaliwa (sticky), kaya kita kahit naka-scroll pakanan ang table.
    function wide(cols, children, cls) {
      return el('tr', {class: 'ag-sub'}, el('td', {colspan: String(cols), class: 'border-b border-slate-100 bg-slate-50 p-0 align-top'},
        el('div', {class: 'ag-pin px-3 py-2 text-xs ' + (cls || '')}, children)));
    }

    function adItem(page, g, a) {
      var rm = null;
      if (g) {
        rm = el('button', {type: 'button', class: 'py-1 text-xs text-red-700 underline hover:text-red-900', text: 'Remove', 'aria-label': 'Remove ad ' + a.ad_id + ' from ' + g.name});
        rm.addEventListener('click', function () {
          if (!confirm('Remove ad ' + a.ad_id + ' from "' + g.name + '"? Its customers get the page values again.')) return;
          rm.disabled = true;
          post(u.removeAd, {page: page, id: g.id, ad: a.ad_id}).then(after(page, true, 'Removed ad ' + a.ad_id + ' from "' + g.name + '".'));
        });
      }
      return el('li', {class: 'flex items-start gap-2 py-1'}, [
        el('div', {class: 'min-w-0 flex-1'}, [
          el('div', {class: 'flex flex-wrap items-center gap-1'}, [el('span', {class: 'break-all font-mono text-slate-800', text: a.ad_id}), newBadge(a), statusPill(a)]),
          el('div', {class: 'break-words text-slate-600', text: a.name || '(no name in Ads Manager)'}),
          el('div', {class: 'text-slate-500', text: plural(a.customers, 'customer') + ' in 30 days' + (a.last ? ' · last ' + a.last : '')})]),
        rm]);
    }

    function adsBlock(page, g) {
      var st = state(page), all = st.showAll.has(g.id), ads = all ? g.ads : g.ads.slice(0, SHOWN_ADS);
      var more = null;
      if (g.ads.length > SHOWN_ADS) {
        more = el('button', {type: 'button', class: LINK, text: all ? 'Show less' : 'Show all ' + g.ads.length, 'aria-expanded': all ? 'true' : 'false'});
        more.addEventListener('click', function () { if (all) st.showAll.delete(g.id); else st.showAll.add(g.id); opts.onChange(page); });
      }
      var typed = el('textarea', {rows: '1', class: INPUT + ' font-mono sm:max-w-xs', placeholder: 'Ad IDs: comma, space or new line', 'aria-label': 'Ad IDs to add to ' + g.name});
      var add = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Add'});
      add.addEventListener('click', function () {
        if (!typed.value.trim()) return;
        add.disabled = true;
        post(u.add, {page: page, id: g.id, ads: typed.value}).then(after(page, true, 'Added to "' + g.name + '".'));
      });
      return [
        el('div', {class: 'font-medium text-slate-700', text: plural(g.ads.length, 'ad ID') + ' in ' + g.name}),
        ads.length ? el('ul', {class: 'divide-y divide-slate-100'}, ads.map(function (a) { return adItem(page, g, a); })) : el('p', {class: 'text-slate-500', text: 'No ad IDs yet: add some below.'}),
        more,
        el('div', {class: 'mt-1 flex flex-wrap items-start gap-2'}, [typed, add])];
    }

    /** Ang cell ng group: ang sariling value, o ang default (malabo, "uses default"); ang page-only ay "page value". */
    function groupCell(page, r, g, c, keys) {
      var td = el('td', {class: TD});
      if (c.kind === 'auto') { td.append(el('span', {class: 'text-slate-400', text: 'Built from the group\'s item'})); return td; }
      var k = keys[c.key];
      if (!k) { td.append(el('span', {class: 'italic text-slate-400', text: 'page value'})); return td; }
      var own = (g.values || {})[c.key], def = String(r.cells[c.key] == null ? '' : r.cells[c.key]);
      var show = own != null && own !== ''
        ? el('span', {class: 'cb-txt text-slate-900', text: own})
        : el('span', {class: 'block'}, [el('span', {class: 'cb-txt text-slate-400', text: def || '(empty)'}), el('span', {class: 'block text-[11px] italic text-slate-400', text: 'uses default'})]);
      var edit = el('button', {type: 'button', class: 'shrink-0 rounded px-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
        'aria-label': 'Edit ' + c.label + ' of ' + g.name, title: 'Edit ' + c.label, text: '✎'});
      edit.addEventListener('click', function () { editValue(page, g, {key: c.key, label: c.label, kind: c.kind}, def); });
      td.append(el('div', {class: 'flex items-start gap-1'}, [el('div', {class: 'min-w-0 flex-1'}, show), edit]));
      return td;
    }

    function flowSelect(page, g, flows) {
      var sel = el('select', {class: 'w-full max-w-[9rem] rounded border border-slate-300 px-1 py-0.5 text-xs text-gray-900', 'aria-label': 'Flow of ' + g.name},
        [el('option', {value: '', text: '(page flow)'})].concat(flows.map(function (f) { return el('option', {value: String(f.id), text: f.name}); })));
      var current = String((g.values || {}).flow_id || '');
      sel.value = current;
      sel.addEventListener('change', function () {
        var name = sel.value ? sel.options[sel.selectedIndex].text : 'the page flow';
        if (!confirm('Customers of "' + g.name + '" run ' + name + '?')) { sel.value = current; return; }
        sel.disabled = true;
        post(u.value, {page: page, id: g.id, key: 'flow_id', value: sel.value}).then(after(page, true, 'Flow of "' + g.name + '" saved.'));
      });
      return sel;
    }

    function groupRow(page, r, g, ctx, keys, flows) {
      var hist = el('button', {type: 'button', class: LINK, text: 'History', 'aria-label': 'History of ' + g.name});
      hist.addEventListener('click', function () { history(page, g); });
      var rename = el('button', {type: 'button', class: LINK, text: 'Rename', 'aria-label': 'Rename ' + g.name});
      rename.addEventListener('click', function () {
        var name = prompt('New name for "' + g.name + '"', g.name);
        if (name === null || name.trim() === g.name) return;
        post(u.save, {page: page, id: g.id, name: name, ads: g.ads.map(function (a) { return a.ad_id; }).join(','), values: {}}).then(after(page, true, 'Renamed to "' + name.trim() + '".'));
      });
      var del = el('button', {type: 'button', class: 'py-1 text-xs text-red-700 underline hover:text-red-900', text: 'Delete', 'aria-label': 'Delete ' + g.name});
      del.addEventListener('click', function () {
        if (!confirm('Delete the group "' + g.name + '"? Its ' + plural(g.ads.length, 'ad') + ' go back to the page values.')) return;
        post(u.del, {page: page, id: g.id}).then(after(page, true, 'Deleted "' + g.name + '".'));
      });
      var muted = g.all_off ? ' opacity-60' : '';
      return el('tr', {class: 'ag-sub', 'data-ag-group': String(g.id)}, [
        el('td', {class: 'stick-1 ' + TD + ' font-medium break-words' + muted}, [
          el('div', {class: 'flex items-start gap-1'}, [el('span', {'aria-hidden': 'true', class: 'text-slate-400', text: '↳'}), el('span', {class: 'min-w-0 break-words', text: g.name})]),
          el('div', {class: 'font-normal text-slate-500', text: plural(g.ads.length, 'ad') + (g.all_off ? ' · all ads off' : '')}),
          el('div', {class: 'flex flex-wrap gap-x-3 font-normal'}, [rename, hist, del])]),
        el('td', {class: 'stick-2 ' + TD}),
        el('td', {class: 'stick-3 ' + TD + muted}, flowSelect(page, g, flows)),
        el('td', {class: 'stick-4 ' + TD}),
        el('td', {class: TD}), el('td', {class: TD}), el('td', {class: TD}),
      ].concat(ctx.botCols.map(function (c) { var td = groupCell(page, r, g, c, keys); if (muted) td.classList.add('opacity-60'); return td; })));
    }

    // Ang form ng bagong group: ang na-type ay nasa st.adding ({name, ids, focused}), kaya hindi nawawala sa muling pagpinta.
    function newGroupForm(page, form) {
      var st = state(page);
      var name = el('input', {type: 'text', maxlength: '100', class: INPUT + ' sm:max-w-xs', autocomplete: 'off', placeholder: 'Group name, e.g. Serum', 'aria-label': 'New group name'});
      var ids = el('textarea', {rows: '1', class: INPUT + ' font-mono sm:max-w-xs', placeholder: 'Ad IDs: comma, space or new line', 'aria-label': 'Ad IDs of the new group'});
      name.value = form.name; ids.value = form.ids;
      name.addEventListener('input', function () { form.name = name.value; });
      ids.addEventListener('input', function () { form.ids = ids.value; });
      var create = el('button', {type: 'button', class: 'cb-btn cb-btn-primary cb-btn-sm', text: 'Create group'});
      var cancel = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Cancel'});
      cancel.addEventListener('click', function () { st.adding = false; opts.onChange(page); });
      create.addEventListener('click', function () {
        create.disabled = true;
        post(u.save, {page: page, id: null, name: name.value, ads: ids.value, values: {}}).then(function (r) {
          create.disabled = false;
          if (!r.ok) { say(page, r.data.error || 'Not created (' + r.status + ').', true); opts.onChange(page); return; }
          st.adding = false;
          st.ticked.clear();
          say(page, 'Created "' + name.value.trim() + '". Set its values in its row: empty cells use the default.');
          load(page);
        });
      });
      if (!form.focused) { form.focused = true; setTimeout(function () { name.focus(); }); }
      return el('div', {class: 'mt-1 flex flex-col gap-2 rounded-md border border-blue-200 bg-white p-2 sm:flex-row sm:flex-wrap sm:items-start'}, [name, ids, el('div', {class: 'flex gap-2'}, [create, cancel])]);
    }

    function unassignedBlock(page) {
      var st = state(page), d = st.data;
      var search = el('input', {type: 'search', class: INPUT + ' sm:max-w-xs', placeholder: 'Search ad ID or name', 'aria-label': 'Search ads not in a group', 'data-ag-search': page});
      search.value = st.q;
      var timer = null;
      search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { st.q = search.value.trim(); load(page, true); }, 300); });
      var rows = d.unassigned.map(function (a) {
        var cid = 'ag-t-' + page.replace(/\W+/g, '_') + '-' + a.ad_id;
        var cb = el('input', {id: cid, type: 'checkbox', class: 'mt-0.5 h-5 w-5 shrink-0', checked: st.ticked.has(a.ad_id)});
        cb.addEventListener('change', function () { if (cb.checked) st.ticked.add(a.ad_id); else st.ticked.delete(a.ad_id); paint(); });
        var item = adItem(page, null, a);
        item.prepend(cb);
        item.querySelector('div').replaceWith(el('label', {for: cid, class: 'min-w-0 flex-1'}, Array.from(item.querySelector('div').childNodes)));
        return item;
      });
      var target = el('select', {class: 'min-w-0 max-w-full flex-1 rounded border border-slate-300 px-1 py-1 text-xs text-gray-900 sm:max-w-[14rem] sm:flex-none', 'aria-label': 'Add to which group'},
        d.groups.map(function (g) { return el('option', {value: String(g.id), text: g.name}); }).concat([el('option', {value: 'new', text: 'New group…'})]));
      var add = el('button', {type: 'button', class: 'cb-btn cb-btn-sm cb-btn-primary'});
      function paint() { add.disabled = !st.ticked.size; add.textContent = st.ticked.size ? 'Add ' + plural(st.ticked.size, 'ad') + ' to group' : 'Add to group'; }
      paint();
      add.addEventListener('click', function () {
        var ads = Array.from(st.ticked);
        if (target.value === 'new') { st.adding = {name: '', ids: ads.join(', '), focused: false}; opts.onChange(page); return; }
        add.disabled = true;
        var g = d.groups.find(function (x) { return String(x.id) === target.value; });
        post(u.add, {page: page, id: Number(target.value), ads: ads}).then(function (r) { if (r.ok) st.ticked.clear(); return after(page, true, 'Added ' + plural(ads.length, 'ad') + ' to "' + (g ? g.name : '') + '".')(r); });
      });
      return [
        el('h4', {class: 'mt-3 font-semibold text-slate-700', text: 'Ads not in a group yet (last 30 days)'}),
        el('p', {class: 'text-slate-500', text: 'New ads first, then On, then by customers.'}),
        el('div', {class: 'mt-1'}, search),
        rows.length ? el('ul', {class: 'mt-1 max-h-80 divide-y divide-slate-100 overflow-y-auto'}, rows)
          : el('p', {class: 'mt-1 text-slate-500', text: st.q ? 'No ad matches.' : 'Every ad seen in the last 30 days is in a group.'}),
        d.unassigned_total > d.unassigned.length ? el('p', {class: 'mt-1 text-slate-500', text: 'Showing ' + d.unassigned.length + ' of ' + d.unassigned_total + '. Search to find others.'}) : null,
        rows.length ? el('div', {class: 'mt-2 flex flex-wrap items-center gap-2'}, [target, add]) : null];
    }

    /**
     * Ang mga sub-row ng isang bukas na Ad-based na page. ctx: {cols (ilang column ang table), botCols, defaultRow(): <tr>}.
     * Kapag wala pang data, isang "Loading…" na row (at kinukuha ang data).
     */
    function subRows(r, ctx) {
      var page = r.page, st = state(page), d = st.data;
      if (!d) {
        if (!st.error && !st.loading) { st.loading = true; load(page).then(function () { st.loading = false; }); }
        return [wide(ctx.cols, st.error ? el('span', {class: 'text-red-600', text: st.error}) : el('span', {class: 'text-slate-500', text: 'Loading ad groups…'}))];
      }
      var keys = {};
      (d.keys || []).forEach(function (k) { if (k.key !== 'flow_id') keys[k.key] = k; });
      var addBtn = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: '+ Add ad group'});
      addBtn.addEventListener('click', function () { if (!st.adding) st.adding = {name: '', ids: '', focused: false}; opts.onChange(page); });
      var out = [wide(ctx.cols, [
        el('div', {class: 'flex flex-wrap items-center gap-x-3 gap-y-1'}, [
          el('span', {class: 'font-semibold text-slate-700', text: 'Ad-based: customers whose latest ad is in a group get that group\'s values; everyone else gets Default.'}),
          el('span', {class: 'text-slate-500', text: d.as_of ? 'Ad status as of ' + d.as_of + ' (Ads Manager data)' : 'No Ads Manager data for these ads yet'}),
          d.new_unassigned ? el('span', {class: 'rounded bg-blue-50 px-1.5 py-0.5 font-medium text-blue-800', text: plural(d.new_unassigned, 'new ad ID') + ' not in a group yet'}) : null]),
        st.note ? el('p', {class: 'mt-1 ' + (st.noteBad ? 'text-red-600' : 'text-green-700'), 'aria-live': 'polite', text: st.note}) : null])];
      out.push(ctx.defaultRow());
      d.groups.forEach(function (g) {
        out.push(groupRow(page, r, g, ctx, keys, d.flows || []));
        out.push(wide(ctx.cols, adsBlock(page, g), g.all_off ? 'opacity-60' : ''));
      });
      out.push(wide(ctx.cols, [
        el('div', {class: 'flex flex-wrap items-center gap-2'}, [addBtn, el('span', {class: 'text-slate-500', text: d.groups.length ? '' : 'No groups yet: every customer gets Default.'})]),
        st.adding ? newGroupForm(page, st.adding) : null].concat(unassignedBlock(page))));
      return out;
    }

    /** Page-based o Ad-based; ibinabalik ang bagong mode, o null kapag hindi nabago. */
    function setMode(page, ad) {
      return post(u.mode, {page: page, mode: ad ? 'ad' : 'page'}).then(function (r) {
        if (!r.ok) return {error: r.data.error || r.data.message || 'Not changed (' + r.status + ').'};
        if (r.data.mode === 'ad') { open.add(page); pages.delete(page); } else open.delete(page);
        return {mode: r.data.mode};
      });
    }

    return {
      isOpen: function (page) { return open.has(page); },
      toggle: function (page) { if (open.has(page)) open.delete(page); else { open.add(page); pages.delete(page); } },
      groupCount: function (page) { var st = pages.get(page); return st && st.data ? st.data.groups.length : null; },
      subRows: subRows, setMode: setMode, statusText: statusText,
    };
  }

  root.ChatbotAdGroups = {create: create, statusText: statusText};
})(typeof window !== 'undefined' ? window : this);
