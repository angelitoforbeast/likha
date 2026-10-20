// Paste values sa /chatbot/pages: ang pagbasa ng TSV na kinopya sa Google Sheets o Excel (at ang pagsulat nito pabalik
// para sa Copy), at ang plano kung aling value ang mapupunta sa aling page at field. Purong function lang ang narito:
// walang DOM at walang tawag sa server, kaya napapatakbo rin ito ng node para sa mga test.
// Plain na script ito, hindi module: iisang pangalan lang ang inilalagay nito sa page, ang ChatbotPaste.
(function (root) {
  'use strict';

  // TSV gaya ng sa Sheets: tab ang hati ng cell, line break ang hati ng row. Ang cell na may line break, tab o " ay
  // nasa loob ng "…" at doble ang " sa loob. Ang cell na nagsisimula sa " pero hindi ganoon ang ayos (hal. "Hi" po)
  // ay binabasa nang buo, gaya ng nakasulat. CRLF = isang line break; ang huling line break ay walang dagdag na row.
  function parseTsv(text) {
    var s = String(text == null ? '' : text), n = s.length, rows = [], row = [], i = 0;
    if (!n) return rows;
    function endOfCell(j) { return j >= n || s[j] === '\t' || s[j] === '\n' || s[j] === '\r'; }
    while (true) {
      var cell = '', start = i, quoted = false;
      if (s[i] === '"') {
        var j = i + 1, buf = '';
        while (j < n) {
          if (s[j] === '"') {
            if (s[j + 1] === '"') { buf += '"'; j += 2; continue; }
            break;
          }
          buf += s[j++];
        }
        if (j < n && endOfCell(j + 1)) { cell = buf; i = j + 1; quoted = true; }
      }
      if (!quoted) {
        i = start;
        while (!endOfCell(i)) cell += s[i++];
      }
      row.push(cell);
      if (i >= n) { rows.push(row); break; }
      if (s[i] === '\t') { i++; continue; }
      i += s[i] === '\r' && s[i + 1] === '\n' ? 2 : 1;
      rows.push(row); row = [];
      if (i >= n) break; // ang huling line break
    }
    return rows;
  }

  function toTsv(rows) {
    return (rows || []).map(function (r) {
      return r.map(function (v) {
        v = v == null ? '' : String(v);
        return /[\t\n\r"]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
      }).join('\t');
    }).join('\n');
  }

  // Ang pangalan ng field nang walang laki ng titik, espasyo at bantas: "Main Flow", "main_flow" at "MAIN FLOW" ay iisa.
  function norm(v) { return String(v == null ? '' : v).toLowerCase().replace(/[^a-z0-9]+/g, ''); }

  var RESERVED = ['active', 'flow'];

  // Ang link ng larawan: http(s), may host, walang espasyo o letrang hindi ASCII (gaya ng tinatanggap ng server).
  function isImageLink(v) {
    return /^https?:\/\/[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d+)?([\/?#][\x21-\x7e]*)?$/i.test(v);
  }

  /**
   * Ang plano ng isang paste. input: { text, columns: [{key, label, kind}] (ang mga botfield na puwedeng i-paste, sa
   * ayos ng grid), targets: [{page, cells: {key: value}}] (sa ayos ng grid), startKey (para sa ayon sa ayos; default
   * ang unang column), blanksEmpty }.
   * Sagot: { error, byName, ignored (mga header na hindi kilala), reserved (may ACTIVE o FLOW), dropped (mga value na
   * lampas sa huling column), changes: [{page, key, label, value, old, status}] }. Status: changed, unchanged, blank
   * (walang binabago dahil hindi naka-tick ang "Paste blanks as empty") o flagged (hindi link sa column ng larawan).
   */
  function plan(input) {
    var columns = input.columns || [], targets = input.targets || [];
    var out = { error: null, byName: false, ignored: [], reserved: false, dropped: 0, changes: [] };
    var rows = parseTsv(input.text);
    if (!targets.length) { out.error = 'Tick at least one page.'; return out; }
    if (!rows.length) { out.error = 'Paste a block copied from Google Sheets.'; return out; }

    var byNorm = {};
    columns.forEach(function (c) { byNorm[norm(c.key)] = c; byNorm[norm(c.label)] = c; });

    // Header kapag kalahati man lang ng may laman sa unang linya ay pangalan ng field (kasama ang ACTIVE at FLOW).
    var first = rows[0], named = first.filter(function (v) { return norm(v) !== ''; });
    var hits = named.filter(function (v) { return byNorm[norm(v)] || RESERVED.indexOf(norm(v)) !== -1; }).length;
    var map = []; // index ng pasted na column => column (o null = laktaw)
    if (hits > 0 && hits * 2 >= named.length) {
      out.byName = true;
      rows = rows.slice(1);
      first.forEach(function (v, i) {
        var k = norm(v);
        if (RESERVED.indexOf(k) !== -1) { out.reserved = true; map[i] = null; }
        else if (byNorm[k]) map[i] = byNorm[k];
        else { map[i] = null; if (k !== '') out.ignored.push(String(v).trim()); }
      });
      if (!rows.length) { out.error = 'Only a header line was pasted: add the values under it.'; return out; }
    } else {
      var start = 0;
      columns.forEach(function (c, i) { if (c.key === input.startKey) start = i; });
      var width = 0;
      rows.forEach(function (r) { width = Math.max(width, r.length); });
      for (var i = 0; i < width; i++) map[i] = columns[start + i] || null;
      rows.forEach(function (r) { r.forEach(function (v, i) { if (!map[i] && v.trim() !== '') out.dropped++; }); });
    }

    if (rows.length !== 1 && rows.length !== targets.length) {
      out.error = 'The paste has ' + rows.length + ' rows but ' + targets.length + (targets.length === 1 ? ' page is' : ' pages are')
        + ' selected. Paste 1 row (for every page) or ' + targets.length + ' rows (one per page, in the order shown).';
      return out;
    }

    targets.forEach(function (t, ti) {
      var r = rows.length === 1 ? rows[0] : rows[ti], seen = {};
      map.forEach(function (c, i) {
        if (!c || seen[c.key]) return;
        seen[c.key] = true;
        var value = String(r[i] == null ? '' : r[i]).trim(), old = String((t.cells || {})[c.key] == null ? '' : t.cells[c.key]);
        var status;
        if (value === '' && !input.blanksEmpty) status = 'blank';
        else if (value === old) status = 'unchanged';
        else if (value !== '' && c.kind === 'image' && !isImageLink(value)) status = 'flagged';
        else status = 'changed';
        out.changes.push({ page: t.page, key: c.key, label: c.label, value: value, old: old, status: status });
      });
    });
    return out;
  }

  root.ChatbotPaste = { parseTsv: parseTsv, toTsv: toTsv, plan: plan, isImageLink: isImageLink };
})(typeof self !== 'undefined' ? self : globalThis);
