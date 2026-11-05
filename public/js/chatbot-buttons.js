// Ang mga button at quick reply ng Send message sa editor ng flow: ang mga patakaran ng Messenger na sinusunod ng form
// (ilan, saan, gaano kahaba) at ang default na payload, gaya ng FlowGraph sa server. Purong function lang ang narito:
// walang DOM at walang tawag sa server, kaya napapatakbo rin ito ng node para sa mga test.
// Plain na script ito, hindi module: iisang pangalan lang ang inilalagay nito sa page, ang ChatbotButtons.
(function (root) {
  'use strict';

  var MAX_BUTTONS = 3, MAX_QUICK = 13, MAX_TITLE = 20, MAX_BUTTON_TEXT = 640;

  // Bilang ng letra gaya ng mb_strlen ng server (ang emoji ay isa, hindi dalawa).
  function chars(v) { return Array.from(String(v == null ? '' : v)).length; }
  function cut(v, n) { return Array.from(String(v == null ? '' : v)).slice(0, n).join(''); }
  function list(v) { return Array.isArray(v) ? v : []; }

  // Ang default na payload: naka-uppercase, bawat pagitan na hindi letra o numero ay _ ("Oo po!" → OO_PO).
  function payloadFor(title) {
    var p = String(title == null ? '' : title).toUpperCase().replace(/[^\p{L}\p{N}]+/gu, '_').replace(/^_+|_+$/g, '');
    return p !== '' ? cut(p, 1000) : 'BUTTON';
  }

  // Ang title habang tina-type: hanggang 20 letra.
  function clampTitle(v) { return cut(v, MAX_TITLE); }

  // Ang text ng item: hanggang 640 kapag may button, kung hindi ay gaya ng dati.
  function clampText(item, v) { return list(item && item.buttons).length ? cut(v, MAX_BUTTON_TEXT) : String(v == null ? '' : v); }

  // Puwede bang magdagdag ng button? {ok, why}. Text lang, hanggang 3, hindi kasabay ng quick reply, text hanggang 640.
  function canAddButton(item) {
    if (!item || item.type === 'image') return {ok: false, why: 'Buttons go on a Text message.'};
    if (list(item.quick_replies).length) return {ok: false, why: 'This message has quick replies: remove them to add buttons.'};
    if (list(item.buttons).length >= MAX_BUTTONS) return {ok: false, why: 'At most 3 buttons on a message.'};
    if (chars(item.value) > MAX_BUTTON_TEXT) return {ok: false, why: 'A message with buttons can have at most 640 characters.'};
    return {ok: true, why: ''};
  }

  // Puwede bang magdagdag ng quick reply sa item i? Huling item lang, hanggang 13, hindi kasabay ng button.
  function canAddQuick(items, i) {
    var item = list(items)[i];
    if (!item) return {ok: false, why: ''};
    if (i !== list(items).length - 1) return {ok: false, why: 'Quick replies only on the last message.'};
    if (list(item.buttons).length) return {ok: false, why: 'This message has buttons: remove them to add quick replies.'};
    if (list(item.quick_replies).length >= MAX_QUICK) return {ok: false, why: 'At most 13 quick replies on a message.'};
    return {ok: true, why: ''};
  }

  // May tap ba na dumarating bilang sagot (postback o quick reply)? Ang link lang ay walang tap, kaya walang hintay.
  function needsWait(items) {
    return list(items).some(function (it) {
      return list(it && it.quick_replies).length > 0 || list(it && it.buttons).some(function (b) { return b && b.type === 'postback'; });
    });
  }

  // Ang Type ng item ay pinalitan: ang Image ay walang button (natatanggal, may sabi); ang quick reply ay naiiwan.
  function setType(item, type) {
    item.type = type === 'image' ? 'image' : 'text';
    if (item.type === 'image' && list(item.buttons).length) { delete item.buttons; return 'Buttons removed: an Image link can have quick replies only.'; }
    return '';
  }

  // Ang mga kulang ng item na hinaharang ng Publish, para makita agad sa form.
  function notes(items, i) {
    var item = list(items)[i], out = [];
    if (!item) return out;
    var taps = list(item.buttons).concat(list(item.quick_replies));
    if (!taps.length) return out;
    if (list(item.quick_replies).length && i !== list(items).length - 1) out.push('Quick replies only on the last message: move them, or Publish is blocked.');
    var titles = {}, payloads = {}, dup = false;
    taps.forEach(function (t) {
      var title = String(t.title || '').trim().toLowerCase();
      if (title === '') { if (out.indexOf('Every button and quick reply needs a title.') < 0) out.push('Every button and quick reply needs a title.'); return; }
      if (titles[title]) dup = true;
      titles[title] = true;
      if (t.type === 'web_url') {
        if (!/^https:\/\/[^\s{}]+\.[^\s{}]+/i.test(String(t.url || ''))) out.push('"' + t.title + '": use a link that starts with https://');
      } else {
        var p = String(t.payload || '').trim();
        if (p === '') out.push('"' + t.title + '" needs a payload.');
        else { if (payloads[p]) dup = true; payloads[p] = true; }
      }
    });
    if (dup) out.push('Two buttons or quick replies have the same title or payload.');
    return out;
  }

  root.ChatbotButtons = {MAX_BUTTONS: MAX_BUTTONS, MAX_QUICK: MAX_QUICK, MAX_TITLE: MAX_TITLE, MAX_BUTTON_TEXT: MAX_BUTTON_TEXT,
    chars: chars, payloadFor: payloadFor, clampTitle: clampTitle, clampText: clampText, canAddButton: canAddButton, canAddQuick: canAddQuick,
    needsWait: needsWait, setType: setType, notes: notes};
})(typeof self !== 'undefined' ? self : this);
