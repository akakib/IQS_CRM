// T5 CRM as a phone app. Nothing is kept from the server: every page and every
// request goes to the network, so the app always shows today's data. Only the
// "No internet" page and its icon are stored, shown when a page cannot load.
const CACHE = 't5-offline-v1';
const OFFLINE = ['/offline.html', '/icons/icon-192.png'];

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.addAll(OFFLINE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
    if (e.request.mode !== 'navigate') return; // everything else: the browser as usual
    e.respondWith(fetch(e.request).catch(() => caches.match('/offline.html')));
});
