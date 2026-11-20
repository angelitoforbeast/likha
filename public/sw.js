/*
 * Likha Chat alerts when Likha is closed (spec 308): the service worker for web push.
 * Scope "/". It only shows notifications: it has no fetch handler and caches nothing, so every page still loads from
 * the server exactly as before. It holds no keys; the server signs and encrypts each push.
 */

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

const ICON = '/icons/likha-192.png';
const BADGE = '/icons/likha-badge-96.png';

function setBadge(n) {
  // WP-4: the installed app's icon shows the unread count where the browser supports it.
  try {
    if (!('setAppBadge' in self.navigator)) return Promise.resolve();
    return n > 0 ? self.navigator.setAppBadge(n) : self.navigator.clearAppBadge();
  } catch (e) {
    return Promise.resolve();
  }
}

async function onPush(data) {
  if (!data || !data.tag) return;
  await setBadge(Number(data.unread) || 0).catch(() => {});

  // A Likha tab open and visible on this device already alerts in the page (spec 300): show nothing here.
  const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  if (windows.some((w) => w.visibilityState === 'visible')) return;

  // One notification per conversation: the newest message, with the count when it replaces earlier ones.
  const earlier = await self.registration.getNotifications({ tag: data.tag });
  const count = earlier.reduce((n, x) => Math.max(n, (x.data && x.data.count) || 1), 0) + 1;
  const body = count > 1 ? `${count} new messages · ${data.body}` : data.body;

  await self.registration.showNotification(data.title || 'Likha', {
    body,
    tag: data.tag,
    renotify: true,
    icon: ICON,
    badge: BADGE,
    timestamp: Date.now(),
    data: { url: data.url, c: data.c, count },
  });
}

self.addEventListener('push', (event) => {
  let data = null;
  try { data = event.data ? event.data.json() : null; } catch (e) { data = null; }
  event.waitUntil(onPush(data));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = new URL((event.notification.data && event.notification.data.url) || '/chat', self.location.origin);
  // Only our own pages.
  const target = url.origin === self.location.origin ? url.href : new URL('/chat', self.location.origin).href;
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const open = windows.find((w) => new URL(w.url).origin === self.location.origin);
    if (open) {
      await open.focus();
      // The chat page opens the conversation in place; other pages go to it.
      open.postMessage({ type: 'likha-chat-open', c: event.notification.data && event.notification.data.c, url: target });
      return;
    }
    await self.clients.openWindow(target);
  })());
});

// The push service rotated this browser's subscription: the next visit to /chat saves the new one
// (the page re-subscribes when this device had alerts on).
self.addEventListener('pushsubscriptionchange', () => {});
