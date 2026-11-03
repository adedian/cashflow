/* Cashflow HME — service worker (dibangkitkan oleh PwaController).
   Yang di-cache: aset statis + halaman offline. Halaman HTML dan /api/* TIDAK pernah di-cache
   (data keuangan & sesi login harus selalu dari server). */
'use strict';
var CACHE = 'cashflow-hme-__VERSION__';
var BASE = __BASE__;
var OFFLINE = __OFFLINE__;
var PRECACHE = __PRECACHE__;
var ASSETS = BASE + '/assets/';

self.addEventListener('install', function (e) {
  e.waitUntil(
    caches.open(CACHE).then(function (c) {
      // satu berkas gagal tidak boleh membatalkan pemasangan
      return Promise.all(PRECACHE.map(function (u) {
        return fetch(u, { credentials: 'same-origin' })
          .then(function (r) { if (r.ok) return c.put(u, r); })
          .catch(function () { /* abaikan */ });
      }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k.indexOf('cashflow-hme-') === 0 && k !== CACHE; })
        .map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

function store(req, res) {
  if (res && res.ok) {
    var copy = res.clone();
    caches.open(CACHE).then(function (c) { c.put(req, copy); });
  }
  return res;
}

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Navigasi: selalu ke jaringan; hanya bila putus tampilkan halaman offline.
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(function () {
      return caches.match(OFFLINE).then(function (r) { return r || Response.error(); });
    }));
    return;
  }

  // Aset statis. Berversi (?v=) -> cache dulu; tanpa versi -> tampilkan cache sambil menyegarkan.
  if (url.pathname.indexOf(ASSETS) === 0) {
    if (url.searchParams.has('v')) {
      e.respondWith(caches.match(req).then(function (hit) {
        return hit || fetch(req).then(function (res) { return store(req, res); });
      }));
    } else {
      e.respondWith(caches.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) { return store(req, res); }).catch(function () { return hit; });
        return hit || net;
      }));
    }
  }
  // selain itu (termasuk /api/*, /export/*): tidak disentuh service worker
});
