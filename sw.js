/* Litecoin Explorer service worker: offline app shell + static-asset caching.
   Chain data (/api, pages) stays network-first so it is never stale.
   SPDX-License-Identifier: AGPL-3.0-or-later */
const VERSION = 'lx-v53';
const SHELL = [
  '/assets/app.css?v=46',
  '/assets/app.js?v=25',
  '/assets/qrcode.js?v=1',
  '/assets/theme-init.js?v=2',
  '/assets/favicon.svg',
  '/assets/coins/ltc.svg',
  '/offline.html',
];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(VERSION).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== VERSION; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') { return; }
  var url = new URL(req.url);
  if (url.origin !== location.origin) { return; }

  // Static assets: cache-first (immutable, versioned via ?v=).
  if (url.pathname.indexOf('/assets/') === 0) {
    e.respondWith(caches.match(req).then(function (r) {
      return r || fetch(req).then(function (res) {
        var copy = res.clone();
        caches.open(VERSION).then(function (c) { c.put(req, copy); });
        return res;
      });
    }));
    return;
  }

  // Page navigations: network-first, fall back to cache then offline shell.
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(function () {
      return caches.match(req).then(function (r) { return r || caches.match('/offline.html'); });
    }));
    return;
  }

  // Everything else (including /api chain data): straight to network.
});
