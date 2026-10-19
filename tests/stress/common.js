// Shared parts of the stress scripts: the chat key (./key.txt), the base URL (__ENV.BASE) and one chat to /api/botcake/chat.
import http from 'k6/http';
import { check } from 'k6';

export const KEY = open('./key.txt').trim();
export const BASE = (__ENV.BASE || 'https://likhaaitech.com').replace(/\/$/, '');
export const PAGE_ID = __ENV.PAGE_ID || '990000000000001';
export const PAGE_NAME = __ENV.PAGE_NAME || 'ZZ STRESS TEST';

const MESSAGES = ['Hi po', 'Magkano po?', 'Available pa po ba?', 'Pa-order po ako', 'COD po ba?', 'Saan po kayo located?',
  'Ilang araw po delivery?', 'May size chart po?', 'Thank you po', 'Paano po mag-order?', 'Pwede po ba gcash?', 'Ok po salamat'];

// A fake PSID in the test range (99 + 14 digits). pool = how many different customers.
export function psid(pool) {
  return '99' + String(Math.floor(Math.random() * pool)).padStart(14, '0');
}

export function chat(pool) {
  const body = JSON.stringify({ page_id: PAGE_ID, page_name: PAGE_NAME, psid: psid(pool), name: 'Stress Customer',
    message: MESSAGES[Math.floor(Math.random() * MESSAGES.length)], source: 'default_reply' }); // the same body as the real Botcake block
  const res = http.post(BASE + '/api/botcake/chat', body, { headers: { 'Content-Type': 'application/json', 'X-Chat-Key': KEY }, tags: { name: 'chat' } });
  check(res, { 'status is 200': r => r.status === 200 });
  return res;
}
