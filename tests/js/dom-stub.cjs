// Isang maliit na DOM para patakbuhin sa Node ang plain na mga script ng page (hal. public/js/chatbot-adgroups.js):
// createElement, append/prepend/replaceChildren/replaceWith/remove, attributes, textContent, classList, events (click,
// change, input), at querySelector(All) para sa "tag", "[attr]", "[attr=\"v\"]" at "tag[attr=\"v\"]". Walang layout.
'use strict';

class Text {
  constructor(t) { this.nodeType = 3; this.data = String(t); this.parentNode = null; }
  get textContent() { return this.data; }
}

class El {
  constructor(tag) {
    this.nodeType = 1; this.tagName = tag.toUpperCase(); this.childNodes = []; this.parentNode = null; this.attrs = {};
    this.listeners = {}; this.value = ''; this.disabled = false; this.checked = false; this.style = {};
    const self = this;
    this.classList = {
      add: (...c) => { const s = new Set(self.className.split(/\s+/).filter(Boolean)); c.forEach(x => s.add(x)); self.className = [...s].join(' '); },
      contains: (c) => self.className.split(/\s+/).includes(c),
    };
  }
  get className() { return this.attrs.class || ''; }
  set className(v) { this.attrs.class = String(v); }
  get id() { return this.attrs.id || ''; }
  get children() { return this.childNodes.filter(n => n.nodeType === 1); }
  get textContent() { return this.childNodes.map(n => n.textContent).join(''); }
  set textContent(v) { this.childNodes = []; if (v !== '' && v != null) this._add(new Text(v)); }
  get options() { return this.children.filter(c => c.tagName === 'OPTION'); }
  get selectedIndex() { return this.options.findIndex(o => o.attrs.value === this.value); }
  get text() { return this.textContent; }
  setAttribute(k, v) { this.attrs[k] = String(v); if (k === 'value') this.value = String(v); if (k === 'checked') this.checked = true; }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  hasAttribute(k) { return k in this.attrs; }
  _wrap(c) { return typeof c === 'string' || typeof c === 'number' ? new Text(c) : c; }
  _add(n, front) { if (n.parentNode) n.parentNode.childNodes = n.parentNode.childNodes.filter(x => x !== n); n.parentNode = this; front ? this.childNodes.unshift(n) : this.childNodes.push(n); }
  append(...cs) { cs.forEach(c => this._add(this._wrap(c))); }
  prepend(...cs) { cs.reverse().forEach(c => this._add(this._wrap(c), true)); }
  replaceChildren(...cs) { this.childNodes.forEach(n => { n.parentNode = null; }); this.childNodes = []; this.append(...cs); }
  replaceWith(n) { const p = this.parentNode; if (!p) return; const i = p.childNodes.indexOf(this); n = this._wrap(n); if (n.parentNode) n.parentNode.childNodes = n.parentNode.childNodes.filter(x => x !== n); n.parentNode = p; p.childNodes[i] = n; this.parentNode = null; }
  remove() { if (this.parentNode) { this.parentNode.childNodes = this.parentNode.childNodes.filter(x => x !== this); this.parentNode = null; } }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  dispatch(t, extra) { const e = Object.assign({type: t, target: this, preventDefault() {}, stopPropagation() {}}, extra || {}); (this.listeners[t] || []).forEach(f => f(e)); return e; }
  click() { if (!this.disabled) this.dispatch('click'); }
  focus() { global.document.activeElement = this; }
  setSelectionRange() {}
  _matches(sel) {
    const m = /^([a-z0-9]*)(?:\[([a-z0-9-]+)(?:="((?:[^"\\]|\\.)*)")?\])?$/i.exec(sel.trim());
    if (!m) throw new Error('selector not supported: ' + sel);
    if (m[1] && this.tagName !== m[1].toUpperCase()) return false;
    if (m[2] && !(m[2] in this.attrs)) return false;
    if (m[2] && m[3] !== undefined && this.attrs[m[2]] !== m[3].replace(/\\(.)/g, '$1')) return false;
    return true;
  }
  querySelectorAll(sel) { const out = []; const walk = (n) => n.children.forEach(c => { if (c._matches(sel)) out.push(c); walk(c); }); walk(this); return out; }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
}

const body = new El('body');
global.document = {
  body, activeElement: null,
  createElement: (t) => new El(t),
  querySelector: (s) => body.querySelector(s),
  querySelectorAll: (s) => body.querySelectorAll(s),
};
global.window = global;
global.CSS = {escape: (s) => String(s).replace(/["\\]/g, '\\$&')};

/** Ang lahat ng button (o ibang tag) na ang text ay $text (eksakto, o RegExp). */
function find(root, tag, text) {
  return root.querySelectorAll(tag).filter(e => text instanceof RegExp ? text.test(e.textContent) : e.textContent === text);
}
/** Hintayin ang mga promise ng fetch at ng muling pagpinta. */
const settle = () => new Promise(r => setTimeout(r, 0)).then(() => new Promise(r => setTimeout(r, 0))).then(() => new Promise(r => setTimeout(r, 0)));

module.exports = {El, Text, find, settle};
