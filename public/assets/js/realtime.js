/* Sinkronisasi realtime = POLLING AJAX berversi (bukan WebSocket/SSE).
   Server menaikkan penghitung versi di dalam transaksi DB yang sama dengan perubahan data, sehingga
   perubahan baru terlihat hanya setelah COMMIT. Klien menembak /api/changes (respons sangat kecil),
   membandingkan versi, dan memanggil handler halaman hanya bila versi berubah. */
(function () {
  'use strict';
  var H = window.HME;
  if (!H) return;
  var handlers = [];           // {scopes:[], fn}
  var known = null;            // versi terakhir yang dilihat
  var ACTIVE_MS = 6000, HIDDEN_MS = 30000, MAX_MS = 60000;
  var delay = ACTIVE_MS, timer = null, failures = 0, stopped = false;
  var dot = document.getElementById('liveDot');

  function setDot(ok) {
    if (!dot) return;
    dot.classList.toggle('off', !ok);
    dot.title = ok ? 'Sinkronisasi data otomatis aktif' : 'Koneksi terputus — mencoba menyambung kembali';
  }

  H.live = {
    on: function (scopes, fn) { handlers.push({ scopes: [].concat(scopes), fn: fn }); },
    versions: function () { return known; }
  };

  function tick() {
    if (stopped) return;
    fetch(H.url('/api/changes'), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
      .then(function (r) {
        if (r.status === 401) { stopped = true; window.location.href = H.url('/login'); return null; }
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (j) {
        if (!j) return;
        failures = 0; setDot(true);
        var v = j.versions || {};
        if (known === null) { known = v; return; }   // baseline pertama: tidak memicu handler
        var changed = [];
        Object.keys(v).forEach(function (k) { if (known[k] !== v[k]) changed.push(k); });
        known = v;
        if (changed.length) {
          handlers.forEach(function (h) {
            if (h.scopes.some(function (s) { return changed.indexOf(s) !== -1; })) { try { h.fn(changed); } catch (e) { /* abaikan */ } }
          });
        }
      })
      .catch(function () { failures++; setDot(false); })
      .then(schedule);
  }

  function schedule() {
    if (stopped) return;
    var base = document.hidden ? HIDDEN_MS : ACTIVE_MS;
    // backoff eksponensial saat gagal (reconnect otomatis)
    delay = failures ? Math.min(MAX_MS, base * Math.pow(2, Math.min(failures, 4))) : base;
    clearTimeout(timer);
    timer = setTimeout(tick, delay);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { clearTimeout(timer); tick(); } else { schedule(); }
  });
  window.addEventListener('online', function () { failures = 0; clearTimeout(timer); tick(); });
  window.addEventListener('offline', function () { setDot(false); });

  tick();
})();
