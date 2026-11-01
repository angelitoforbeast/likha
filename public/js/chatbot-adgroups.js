// Ad groups ng isang Ad-based na page sa /chatbot/pages. Sa table (pantingin): ang mga sub-row sa ilalim ng row ng page
// (Default, isang row kada group na may sariling mga cell, ang mga ad ID ng group na may status, at ilang ad ang wala pa
// sa group). Sa Edit ng page (pang-ayos): gumawa, palitan ang pangalan, burahin, magdagdag o mag-alis ng ad ID, ang mga
// value, History at "Ads not in a group yet". Ang pindot sa ad ID ay nagpapakita ng video o larawan ng ad.
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
  // Ang uri ng ID sa group: Ad, Ad set o Campaign (ayon sa Ads Manager); ang hindi kilala ay "Not found in Ads Manager".
  var KINDS = {ad: 'Ad', adset: 'Ad set', campaign: 'Campaign'};
  function kindTag(a) {
    if (!a.kind) return null;
    var known = KINDS[a.kind];
    return el('span', {class: 'inline-block rounded px-1.5 py-0.5 text-[11px] font-medium ' + (known ? 'bg-slate-100 text-slate-700' : 'bg-amber-100 text-amber-900'), text: known || 'Not found in Ads Manager'});
  }
  function kindWord(a) { return KINDS[a.kind] ? KINDS[a.kind].toLowerCase() : 'ID'; }

  /**
   * opts: {csrf, urls: {index, save, add, del, history, restore, mode, value, removeAd, ad}, onChange()}
   * Ang onChange ay tinatawag kapag may bagong data (ang table ay muling ipinipinta); ang bukas na panel sa Edit ay
   * muling ipinipinta rito mismo.
   */
  function create(opts) {
    var u = opts.urls, pages = new Map(), open = new Set(), panels = new Map();
    function changed(page) {
      var p = panels.get(page);
      if (p) p.host.replaceChildren.apply(p.host, panel(p.r));
      opts.onChange(page);
    }
    var post = function (url, body) {
      return fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': opts.csrf}, body: JSON.stringify(body)})
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return {ok: r.ok, status: r.status, data: d}; }); })
        .catch(function () { return {ok: false, status: 0, data: {error: 'No connection: nothing was saved.'}}; });
    };
    function state(page) {
      // showHidden: ipinapakita rin ang mga tahimik (group, ID at ad), malabo; nananatili hanggang reload.
      if (!pages.has(page)) pages.set(page, {data: null, error: '', note: '', q: '', ticked: new Set(), showAll: new Set(), seq: 0, adding: false, showHidden: false});
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
          changed(page);
          if (focusSearch) { var s = document.querySelector('input[data-ag-search="' + CSS.escape(page) + '"]'); if (s) { s.focus(); s.setSelectionRange(s.value.length, s.value.length); } }
        })
        .catch(function (s) { if (seq === st.seq) { st.error = 'Could not load the ad groups (' + s + ').'; changed(page); } });
    }
    function say(page, text, bad) { var st = state(page); st.note = text; st.noteBad = !!bad; }
    // Pagkatapos ng sulat: ang mensahe (pati ang note ng server, hal. "Already in this group"), saka ang bagong data ng page.
    // Ang tinanggihang ID na nasa nakatagong group: sinasabi ring nakatago ito.
    function after(page, ok, text) {
      return function (r) {
        var err = r.data.error || 'Not saved (' + r.status + ').', d = state(page).data;
        var g = !r.ok && r.data.group != null && d ? d.groups.find(function (x) { return x.id === r.data.group; }) : null;
        if (g && g.hidden) err += ' That group is hidden (quiet for 7+ days): press "Show hidden" to see it.';
        say(page, r.ok ? text + (r.data.note ? ' ' + r.data.note : '') : err, !r.ok);
        return load(page);
      };
    }

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
      // Ang mapipili (AI provider, fallback): select na ang una ay ang default; ang iba pang AI (model, timeout): isang linya.
      var input = k.options ? el('select', {class: INPUT}, [el('option', {value: '', text: '(use the default)'})].concat(k.options.map(function (o) { return el('option', {value: o[0], text: o[1]}); })))
        : k.kind === 'image' ? el('input', {type: 'url', class: INPUT, autocomplete: 'off', placeholder: 'https://…'})
        : k.kind === 'ai' ? el('input', {type: 'text', class: INPUT, autocomplete: 'off'}) : el('textarea', {rows: '6', class: INPUT});
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

    /**
     * Ang video o larawan ng ad at ang headline at body, mula sa naka-save na creative (gaya ng /owner/private, sa
     * plugin ng Facebook: video para sa reel/video, post para sa iba). Walang naka-save: "No preview".
     */
    function fbEmbedSrc(link) {
      var clean = link.split('#')[0].split('?')[0], href = encodeURIComponent(link);
      return /facebook\.com\/(reel|watch|.*\/videos)|fb\.watch\//i.test(clean)
        ? 'https://www.facebook.com/plugins/video.php?href=' + href + '&show_text=false&width=340'
        : 'https://www.facebook.com/plugins/post.php?href=' + href + '&show_text=true&width=340';
    }
    function preview(page, a) {
      var body = el('div', {class: 'text-xs text-slate-500', text: 'Loading…'});
      openModal('Ad ' + a.ad_id, a.name || page, body);
      fetch(u.ad + '?page=' + encodeURIComponent(page) + '&ad=' + encodeURIComponent(a.ad_id), {headers: {'Accept': 'application/json'}})
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (d) {
          var media = d.link
            ? el('div', {}, [
              el('iframe', {src: fbEmbedSrc(d.link), title: 'Ad ' + a.ad_id + ' on Facebook', class: 'block w-full rounded-md border border-slate-200 bg-slate-50',
                style: 'height: min(560px, 65vh)', loading: 'lazy', allow: 'autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share', allowfullscreen: true}),
              el('a', {href: d.link, target: '_blank', rel: 'noopener noreferrer', class: 'mt-1 inline-block ' + LINK, text: 'Open on Facebook ↗'})])
            : el('div', {class: 'rounded-md border border-dashed border-slate-300 p-6 text-center text-slate-500', text: 'No preview: no video or post link is saved for this ad.'});
          body.className = 'space-y-2 text-xs text-slate-700';
          body.replaceChildren.apply(body, [
            d.from_campaign ? el('p', {class: 'text-amber-700', text: 'This ad has no creative of its own saved: showing its campaign\'s.'}) : null,
            media,
            el('div', {}, [el('div', {class: 'font-semibold uppercase text-slate-500', text: 'Headline'}), el('div', {class: 'break-words', text: d.headline || '(no headline)'})]),
            el('div', {}, [el('div', {class: 'font-semibold uppercase text-slate-500', text: 'Body'}), el('div', {class: 'whitespace-pre-line break-words', text: d.body || '(no body text)'})])].filter(Boolean));
        })
        .catch(function (s) { body.className = 'text-xs text-red-600'; body.textContent = 'Could not load the ad (' + s + ').'; });
    }

    // ---- Ang mga sub-row ----
    // Ang buong-lapad na row: ang laman ay nakadikit sa kaliwa (sticky), kaya kita kahit naka-scroll pakanan ang table.
    function wide(cols, children, cls) {
      return el('tr', {class: 'ag-sub'}, el('td', {colspan: String(cols), class: 'border-b border-slate-100 bg-slate-50 p-0 align-top'},
        el('div', {class: 'ag-pin px-3 py-2 text-xs ' + (cls || '')}, children)));
    }

    // Ang ad ID ay button: ang preview ng ad (video o larawan, headline at body).
    function adButton(page, a) {
      var b = el('button', {type: 'button', class: 'break-all py-1 text-left font-mono text-blue-700 underline hover:text-blue-900', text: a.ad_id, title: 'Watch this ad', 'aria-label': 'Watch ad ' + a.ad_id});
      b.addEventListener('click', function (e) { e.preventDefault(); preview(page, a); });
      return b;
    }

    /**
     * Isang ID: ang ID (preview kapag ad), ang uri (Ad, Ad set, Campaign o Not found in Ads Manager), New, status, ang
     * sariling pangalan, mga customer. Remove lang sa Edit (manage) at kapag nasa group. Ang tahimik na ipinapakita
     * (Show hidden) ay malabo.
     */
    function adItem(page, g, a, manage) {
      var rm = null, word = kindWord(a);
      if (g && manage) {
        rm = el('button', {type: 'button', class: 'min-h-[2rem] px-1 py-1 text-xs text-red-700 underline hover:text-red-900', text: 'Remove', 'aria-label': 'Remove ' + word + ' ' + a.ad_id + ' from ' + g.name});
        rm.addEventListener('click', function () {
          if (!confirm('Remove ' + word + ' ' + a.ad_id + ' from "' + g.name + '"? Its customers get the page values again.')) return;
          rm.disabled = true;
          post(u.removeAd, {page: page, id: g.id, ad: a.ad_id}).then(after(page, true, 'Removed ' + word + ' ' + a.ad_id + ' from "' + g.name + '".'));
        });
      }
      var id = !a.kind || a.kind === 'ad' ? adButton(page, a) : el('span', {class: 'break-all py-1 font-mono text-slate-800', text: a.ad_id});
      var under = a.ads_under != null ? ' · ' + plural(a.ads_under, 'ad') + ' under it' : '';
      return el('li', {class: 'flex items-start gap-2 py-1' + (a.quiet ? ' opacity-60' : ''), 'data-ag-id': a.ad_id}, [
        el('div', {class: 'min-w-0 flex-1'}, [
          el('div', {class: 'flex flex-wrap items-center gap-1'}, [id, kindTag(a), newBadge(a), statusPill(a)]),
          el('div', {class: 'break-words text-slate-600', text: a.name || (a.kind === 'unknown' ? '(not in the Ads Manager data yet)' : '(no name in Ads Manager)')}),
          el('div', {class: 'text-slate-500', text: plural(a.customers, 'customer') + ' in 30 days' + (a.last ? ' · last ' + a.last : '') + under})]),
        rm]);
    }

    /** Ang ipinapakita: lahat kapag Show hidden; kung hindi, ang hindi tahimik lang. */
    function visible(page, list) { return state(page).showHidden ? list : list.filter(function (x) { return !x.quiet && !x.hidden; }); }

    /**
     * Ang linya ng nakatago at ang "Show hidden (N)" / "Hide again" (aria-pressed): ang mga group na tahimik lahat, ang
     * mga tahimik na ID sa ibang group, at ang tahimik na ads na wala sa group. Walang nakatago: walang button.
     */
    function hiddenBar(page) {
      var st = state(page), h = (st.data && st.data.hidden) || {groups: 0, ids: 0, unassigned: 0}, n = h.groups + h.ids + h.unassigned;
      if (!n) return null;
      var parts = [h.groups ? plural(h.groups, 'group') : null, h.ids ? plural(h.ids, 'id') + ' in groups' : null, h.unassigned ? plural(h.unassigned, 'ad') + ' not in a group' : null].filter(Boolean);
      var b = el('button', {type: 'button', class: 'cb-btn cb-btn-sm min-h-[2rem]', 'aria-pressed': st.showHidden ? 'true' : 'false', 'data-ag-hidden-toggle': page,
        text: st.showHidden ? 'Hide again' : 'Show hidden (' + n + ')'});
      b.addEventListener('click', function () { st.showHidden = !st.showHidden; changed(page); var t = document.querySelector('[data-ag-hidden-toggle="' + CSS.escape(page) + '"]'); if (t) t.focus(); });
      return el('div', {class: 'mt-1 flex flex-wrap items-center gap-2 text-slate-500'}, [
        el('span', {'data-ag-hidden-line': '', text: 'Hidden: ' + parts.join(', ') + ' (off or no report data for 7+ days, and no messages in 7 days).'}), b]);
    }

    /** Ang mga ad ID ng group; sa Edit (manage) may Remove at kahon para mag-paste ng iba pa. */
    function adsBlock(page, g, manage) {
      // Ang tahimik na ID ay nakatiklop ("N hidden ads"), maliban kung Show hidden; ang "Show all N" ay sa mga nakikita.
      var st = state(page), all = st.showAll.has(g.id), shown = visible(page, g.ads), folded = g.ads.length - shown.length;
      var ads = all ? shown : shown.slice(0, SHOWN_ADS);
      var more = null;
      if (shown.length > SHOWN_ADS) {
        more = el('button', {type: 'button', class: LINK, text: all ? 'Show less' : 'Show all ' + shown.length, 'aria-expanded': all ? 'true' : 'false'});
        more.addEventListener('click', function () { if (all) st.showAll.delete(g.id); else st.showAll.add(g.id); changed(page); });
      }
      var list = [
        el('div', {class: 'font-medium text-slate-700', text: plural(g.ads.length, 'id') + ' in ' + g.name}),
        ads.length ? el('ul', {class: 'divide-y divide-slate-100'}, ads.map(function (a) { return adItem(page, g, a, manage); }))
          : el('p', {class: 'text-slate-500', text: folded ? '' : manage ? 'No IDs yet: add some below.' : 'No IDs yet.'}),
        folded ? el('p', {class: 'text-slate-500', 'data-ag-folded': '', text: plural(folded, 'hidden ad') + ' (quiet for 7+ days)'}) : null,
        more];
      if (!manage) return list;
      var typed = el('textarea', {rows: '1', class: INPUT + ' font-mono sm:max-w-xs', placeholder: 'IDs: ad, ad set or campaign; comma, space or new line', 'aria-label': 'IDs to add to ' + g.name});
      var add = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Add'});
      add.addEventListener('click', function () {
        if (!typed.value.trim()) return;
        add.disabled = true;
        post(u.add, {page: page, id: g.id, ads: typed.value}).then(after(page, true, 'Added to "' + g.name + '".'));
      });
      return list.concat([el('div', {class: 'mt-1 flex flex-wrap items-start gap-2'}, [typed, add])]);
    }

    /** Ang page value ng isang key (ang default ng group): ang cell ng page, o ang AI ng page (r.ai; blangko = ang site default). */
    function pageValue(r, key, kind) {
      if (kind === 'ai') {
        var a = r.ai || {};
        var v = {ai_provider: a.label, ai_fallback: a.fallback, ai_local_model: a.local_model, ai_local_timeout: a.local_timeout != null ? a.local_timeout + ' s' : null}[key];
        return v == null || v === '' ? 'site default' : String(v);
      }
      return String(r.cells[key] == null ? '' : r.cells[key]);
    }

    /** Ang cell ng group: ang sariling value, o ang default (malabo, "uses default"); ang hindi value ng group ay "page value". */
    function groupCell(page, r, g, c, keys) {
      var td = el('td', {class: TD});
      if (c.kind === 'auto') { td.append(el('span', {class: 'text-slate-400', text: 'Built from the group\'s item'})); return td; }
      var k = keys[c.key];
      if (!k) { td.append(el('span', {class: 'italic text-slate-400', text: 'page value'})); return td; }
      var own = (g.values || {})[c.key], def = pageValue(r, c.key, k.kind);
      var show = own != null && own !== ''
        ? el('span', {class: 'cb-txt text-slate-900', text: own})
        : el('span', {class: 'block'}, [el('span', {class: 'cb-txt text-slate-400', text: def || '(empty)'}), el('span', {class: 'block text-[11px] italic text-slate-400', text: 'uses default'})]);
      var edit = el('button', {type: 'button', class: 'shrink-0 rounded px-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
        'aria-label': 'Edit ' + c.label + ' of ' + g.name, title: 'Edit ' + c.label, text: '✎'});
      edit.addEventListener('click', function () { editValue(page, g, k, def); });
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

    /** Rename, History (may Restore) at Delete ng group: sa Edit lang. */
    function manageLinks(page, g) {
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
        if (!confirm('Delete the group "' + g.name + '"? Its ' + plural(g.ads.length, 'id') + ' go back to the page values.')) return;
        post(u.del, {page: page, id: g.id}).then(after(page, true, 'Deleted "' + g.name + '".'));
      });
      return el('div', {class: 'flex flex-wrap gap-x-3 font-normal'}, [rename, hist, del]);
    }

    /** Ang row ng group sa table: pangalan, ang mga value nito (✎ gaya ng mga cell ng page) at ang flow. */
    function groupRow(page, r, g, ctx, keys, flows) {
      var muted = g.all_off || g.hidden ? ' opacity-60' : '';
      return el('tr', {class: 'ag-sub', 'data-ag-group': String(g.id)}, [
        el('td', {class: 'stick-1 ' + TD + ' font-medium break-words' + muted}, [
          el('div', {class: 'flex items-start gap-1'}, [el('span', {'aria-hidden': 'true', class: 'text-slate-400', text: '↳'}), el('span', {class: 'min-w-0 break-words', text: g.name})]),
          el('div', {class: 'font-normal text-slate-500', text: plural(g.ads.length, 'id') + (g.all_off ? ' · all ads off' : '') + (g.hidden ? ' · hidden' : '')})]),
        el('td', {class: 'stick-2 ' + TD + muted}, aiButton(page, r, g, keys)),
        el('td', {class: 'stick-3 ' + TD + muted}, flowSelect(page, g, flows)),
        el('td', {class: 'stick-4 ' + TD}),
        el('td', {class: TD}), el('td', {class: TD}), el('td', {class: TD}),
      ].concat(ctx.botCols.map(function (c) { var td = groupCell(page, r, g, c, keys); if (muted) td.classList.add('opacity-60'); return td; })));
    }

    // Ang form ng bagong group: ang na-type ay nasa st.adding ({name, ids, focused}), kaya hindi nawawala sa muling pagpinta.
    function newGroupForm(page, form) {
      var st = state(page);
      var name = el('input', {type: 'text', maxlength: '100', class: INPUT + ' sm:max-w-xs', autocomplete: 'off', placeholder: 'Group name, e.g. Serum', 'aria-label': 'New group name'});
      var ids = el('textarea', {rows: '1', class: INPUT + ' font-mono sm:max-w-xs', placeholder: 'IDs: ad, ad set or campaign; comma, space or new line', 'aria-label': 'IDs of the new group'});
      name.value = form.name; ids.value = form.ids;
      name.addEventListener('input', function () { form.name = name.value; });
      ids.addEventListener('input', function () { form.ids = ids.value; });
      var create = el('button', {type: 'button', class: 'cb-btn cb-btn-primary cb-btn-sm', text: 'Create group'});
      var cancel = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Cancel'});
      cancel.addEventListener('click', function () { st.adding = false; changed(page); });
      create.addEventListener('click', function () {
        create.disabled = true;
        post(u.save, {page: page, id: null, name: name.value, ads: ids.value, values: {}}).then(function (r) {
          create.disabled = false;
          if (!r.ok) { say(page, r.data.error || 'Not created (' + r.status + ').', true); changed(page); return; }
          st.adding = false;
          st.ticked.clear();
          say(page, 'Created "' + name.value.trim() + '". Set its values: empty ones use the default (the page value).');
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
      var rows = visible(page, d.unassigned).map(function (a) {
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
        if (target.value === 'new') { st.adding = {name: '', ids: ads.join(', '), focused: false}; changed(page); return; }
        add.disabled = true;
        var g = d.groups.find(function (x) { return String(x.id) === target.value; });
        post(u.add, {page: page, id: Number(target.value), ads: ads}).then(function (r) { if (r.ok) st.ticked.clear(); return after(page, true, 'Added ' + plural(ads.length, 'ad') + ' to "' + (g ? g.name : '') + '".')(r); });
      });
      return [
        el('h4', {class: 'mt-3 font-semibold text-slate-700', text: 'Ads not in a group yet (last 30 days)'}),
        el('p', {class: 'text-slate-500', text: 'New ads first, then On, then by customers.'}),
        el('div', {class: 'mt-1'}, search),
        rows.length ? el('ul', {class: 'mt-1 max-h-80 divide-y divide-slate-100 overflow-y-auto'}, rows)
          : el('p', {class: 'mt-1 text-slate-500', text: st.q ? 'No ad matches.' : d.unassigned.length ? 'Only quiet ads are left: see "Show hidden".' : 'Every ad seen in the last 30 days is in a group.'}),
        d.unassigned_total > d.unassigned.length ? el('p', {class: 'mt-1 text-slate-500', text: 'Showing ' + d.unassigned.length + ' of ' + d.unassigned_total + '. Search to find others.'}) : null,
        rows.length ? el('div', {class: 'mt-2 flex flex-wrap items-center gap-2'}, [target, add]) : null];
    }

    function asOfText(d) { return d.as_of ? 'Ad status as of ' + d.as_of + ' (Ads Manager data)' : 'No Ads Manager data for these ads yet'; }
    function noteLine(st) { return st.note ? el('p', {class: 'mt-1 ' + (st.noteBad ? 'text-red-600' : 'text-green-700'), 'aria-live': 'polite', text: st.note}) : null; }
    function valueKeys(d) { var keys = {}; (d.keys || []).forEach(function (k) { if (k.key !== 'flow_id') keys[k.key] = k; }); return keys; }

    /**
     * Ang mga sub-row ng isang bukas na Ad-based na page sa table: pantingin lang (walang gawa, dagdag o alis; nasa Edit
     * iyon). ctx: {cols (ilang column ang table), botCols, defaultRow(): <tr>, manage(): binubuksan ang Edit sa Ad groups}.
     * Kapag wala pang data, isang "Loading…" na row (at kinukuha ang data).
     */
    function subRows(r, ctx) {
      var page = r.page, st = state(page), d = st.data;
      if (!d) {
        if (!st.error && !st.loading) { st.loading = true; load(page).then(function () { st.loading = false; }); }
        return [wide(ctx.cols, st.error ? el('span', {class: 'text-red-600', text: st.error}) : el('span', {class: 'text-slate-500', text: 'Loading ad groups…'}))];
      }
      var manage = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Manage in Edit', 'aria-label': 'Manage the ad groups of ' + page + ' in its Edit window'});
      manage.addEventListener('click', function () { ctx.manage(); });
      var rest = st.q ? null : d.unassigned_total;
      var out = [wide(ctx.cols, [
        el('div', {class: 'flex flex-wrap items-center gap-x-3 gap-y-1'}, [
          el('span', {class: 'font-semibold text-slate-700', text: 'Ad-based: customers whose latest ad is in a group get that group\'s values; everyone else gets Default.'}),
          el('span', {class: 'text-slate-500', text: asOfText(d)}),
          rest != null ? el('span', {class: 'rounded bg-blue-50 px-1.5 py-0.5 font-medium text-blue-800', text: plural(rest, 'ad') + ' not in a group yet' + (d.new_unassigned ? ' (' + d.new_unassigned + ' new)' : '')}) : null,
          manage]),
        hiddenBar(page),
        noteLine(st)])];
      out.push(ctx.defaultRow());
      visible(page, d.groups).forEach(function (g) {
        out.push(groupRow(page, r, g, ctx, valueKeys(d), d.flows || []));
        out.push(wide(ctx.cols, adsBlock(page, g, false), g.all_off || g.hidden ? 'opacity-60' : ''));
      });
      if (!d.groups.length) out.push(wide(ctx.cols, el('p', {class: 'text-slate-500', text: 'No groups yet: every customer gets Default. Create groups with "Manage in Edit".'})));
      return out;
    }

    /** Ang AI ng group sa row nito (gaya ng "AI:" ng page): ang pindot ay ang listahan ng AI na may ✎. */
    function aiButton(page, r, g, keys) {
      var own = (g.values || {}).ai_provider, opt = own && keys.ai_provider ? (keys.ai_provider.options || []).find(function (o) { return o[0] === own; }) : null;
      var b = el('button', {type: 'button', class: 'block max-w-[9rem] truncate rounded border border-slate-300 bg-white px-1 py-0.5 text-left text-xs text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
        'aria-label': 'AI of ' + g.name, title: 'Edit the AI of ' + g.name, text: '✎ AI: ' + (own ? (opt ? opt[1] : own) : 'default')});
      b.addEventListener('click', function () {
        openModal('AI · ' + g.name, 'Empty values use the page\'s AI.', valueList(page, r, g, keys, function (key) { return keys[key].kind === 'ai'; }));
      });
      return b;
    }

    /** Ang mga value ng group sa Edit: bawat key na maitatakda ng group, ang sariling value o ang default (malabo), at ✎. */
    function valuesBlock(page, r, g, d) {
      var list = valueList(page, r, g, valueKeys(d), function () { return true; });
      list.prepend(el('li', {class: 'flex items-center gap-2 py-1'}, [el('div', {class: 'w-28 shrink-0 font-medium text-slate-600', text: 'FLOW'}), flowSelect(page, g, d.flows || [])]));
      return list;
    }

    function valueList(page, r, g, keys, pick) {
      var items = Object.keys(keys).filter(pick).map(function (key) {
        var k = keys[key], own = (g.values || {})[key], def = pageValue(r, key, k.kind);
        var opt = own && k.options ? k.options.find(function (o) { return o[0] === own; }) : null;
        if (opt) own = opt[1]; // OpenAI / Local / None, gaya ng sa page
        var edit = el('button', {type: 'button', class: 'shrink-0 rounded px-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
          'aria-label': 'Edit ' + k.label + ' of ' + g.name, title: 'Edit ' + k.label, text: '✎'});
        edit.addEventListener('click', function () { editValue(page, g, k, def); });
        var show = own != null && own !== ''
          ? el('div', {class: 'cb-txt line-clamp-3 whitespace-pre-line break-words text-slate-900', text: own})
          : el('div', {class: 'line-clamp-2 whitespace-pre-line break-words text-slate-400'}, [el('span', {class: 'italic', text: 'uses default: '}), def || '(empty)']);
        return el('li', {class: 'flex items-start gap-2 py-1'}, [el('div', {class: 'w-28 shrink-0 break-words font-medium text-slate-600', text: k.label}), el('div', {class: 'min-w-0 flex-1'}, show), edit]);
      });
      return el('ul', {class: 'mt-1 divide-y divide-slate-100'}, items);
    }

    /**
     * Ang Ad groups sa Edit ng page (pang-ayos): bawat group (Rename, History, Delete, mga value, mga ad ID na may Remove
     * at paste), + Add ad group, at "Ads not in a group yet" (tick o hanap). Parehong mga endpoint at state ng table.
     */
    function panel(r) {
      var page = r.page, st = state(page), d = st.data;
      if (!d) return [el('p', {class: st.error ? 'text-red-600' : 'text-slate-500', text: st.error || 'Loading ad groups…'})];
      var addBtn = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: '+ Add ad group'});
      addBtn.addEventListener('click', function () { if (!st.adding) st.adding = {name: '', ids: '', focused: false}; changed(page); });
      var out = [
        el('p', {class: 'text-slate-500', text: 'Customers whose latest ad is in a group get that group\'s values; everyone else gets the page values above. Changes here save right away (not with Save below). ' + asOfText(d) + '.'}),
        hiddenBar(page),
        noteLine(st)];
      visible(page, d.groups).forEach(function (g) {
        out.push(el('div', {class: 'mt-2 rounded-md border border-slate-200 p-2' + (g.all_off ? ' bg-slate-50' : '') + (g.hidden ? ' opacity-60' : ''), 'data-ag-group': String(g.id)}, [
          el('div', {class: 'flex flex-wrap items-baseline gap-x-2'}, [el('h4', {class: 'min-w-0 break-words text-sm font-semibold text-slate-800', text: g.name}),
            el('span', {class: 'text-slate-500', text: plural(g.ads.length, 'id') + (g.all_off ? ' · all ads off' : '') + (g.hidden ? ' · hidden' : '')})]),
          manageLinks(page, g),
          valuesBlock(page, r, g, d),
          el('div', {class: 'mt-2'}, adsBlock(page, g, true))]));
      });
      out.push(el('div', {class: 'mt-2 flex flex-wrap items-center gap-2'}, [addBtn, d.groups.length ? null : el('span', {class: 'text-slate-500', text: 'No groups yet: every customer gets the page values.'})]));
      if (st.adding) out.push(newGroupForm(page, st.adding));
      return out.concat(unassignedBlock(page)).filter(Boolean);
    }

    /** Inilalagay ang panel sa Edit ng page (host); unmount kapag isinara ang Edit. */
    function mount(r, host) {
      panels.set(r.page, {r: r, host: host});
      host.replaceChildren.apply(host, panel(r));
      return load(r.page);
    }
    function unmount(page) {
      if (!panels.delete(page)) return;
      var st = pages.get(page);
      // Ang hanap sa Edit ay hindi naiiwan sa table: ang bilang ng "not in a group yet" doon ay ang lahat.
      if (st && (st.q || st.adding)) { st.q = ''; st.adding = false; st.ticked.clear(); load(page); }
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
      subRows: subRows, mount: mount, unmount: unmount, setMode: setMode, statusText: statusText,
    };
  }

  root.ChatbotAdGroups = {create: create, statusText: statusText};
})(typeof window !== 'undefined' ? window : this);
