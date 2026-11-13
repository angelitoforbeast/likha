// Spec 275, ang tunog ng /chatbot/live kapag may bagong mensahe ng customer. Purong function lang (walang DOM, walang orasan,
// walang global na binabasa), kaya napapatakbo rin ng node para sa mga test. Plain na script, hindi module: iisang pangalan ang
// inilalagay, ang LiveSound. Ang event na "message" ng feed ay row ng botcake_chats: mensaheng PUMASOK mula sa customer (ang
// echo ng page at ang sagot ng bot o ng tao ay wala roon), at ang feed ay mula lang sa cursor ng pagbukas ng page.
(function (root) {
  'use strict';

  var KEY = 'chatbot-live-sound';
  // Hanggang isang tunog kada 3 segundo, kahit ilang mensahe ang sabay na dumating.
  var GAP = 3000;

  // Ilan ang bagong mensahe ng customer sa mga event ng isang poll.
  function inbound(events) {
    var n = 0;
    if (!events || typeof events.length !== 'number') return 0;
    for (var i = 0; i < events.length; i++) if (events[i] && events[i].type === 'message') n++;
    return n;
  }

  // Tutunog ba: naka-on, may bagong mensahe, at 3 segundo na mula sa huling tunog (o wala pang tunog).
  function ring(n, on, last, now) {
    return !!on && n > 0 && (last === null || last === undefined || now - last >= GAP);
  }

  // Ang isang poll: ang dating estado ({last, unread}), ang mga event, ang oras (ms), kung naka-on ang tunog at kung nakatago
  // ang tab. Ibinabalik kung tutunog, ang bagong estado, at ilan ang bago. Ang hindi pa nakikita (para sa title) ay nadadagdagan
  // lang kapag naka-on at nakatago; kapag nakikita o naka-off, wala.
  function arrive(state, events, now, on, hidden) {
    var last = state && state.last !== undefined ? state.last : null;
    var unread = state && state.unread ? state.unread : 0;
    var n = inbound(events);
    var r = ring(n, on, last, now);
    return {ring: r, last: r ? now : last, unread: on && hidden ? unread + n : 0, n: n};
  }

  // "(3) Chatbot - Live" habang may hindi pa nakikita; ang orihinal kapag wala.
  function title(base, unread) {
    return unread > 0 ? '(' + unread + ') ' + base : base;
  }

  // Ang napili sa browser na ito; naka-on kapag wala, sira o hindi mabasa ang storage.
  function readOn(store) {
    try {
      var v = typeof store.getItem === 'function' ? store.getItem(KEY) : store[KEY];
      return v !== 'off';
    } catch (e) { return true; }
  }
  function saveOn(store, on) {
    try {
      var v = on ? 'on' : 'off';
      if (typeof store.setItem === 'function') store.setItem(KEY, v); else store[KEY] = v;
      return true;
    } catch (e) { return false; }
  }

  root.LiveSound = {GAP: GAP, inbound: inbound, ring: ring, arrive: arrive, title: title, readOn: readOn, saveOn: saveOn};
})(typeof self !== 'undefined' ? self : this);
