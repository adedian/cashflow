/* Cashflow HME — halaman offline: tombol "Coba lagi" + muat ulang otomatis saat koneksi kembali. */
(function () {
  'use strict';
  var btn = document.getElementById('offRetry');
  var status = document.getElementById('offStatus');
  var txt = status ? status.querySelector('.txt') : null;
  var card = document.getElementById('offlineCard');
  if (!btn) return;

  var H = window.HME || {};
  var busy = false;
  var done = false;

  function say(msg, cls) {
    if (txt) txt.textContent = msg;
    if (status) status.className = 'off-status' + (cls ? ' ' + cls : '');
  }

  function goBack() {
    if (done) return;
    done = true;
    say('Tersambung! Memuat ulang…', 'ok');
    if (card) card.classList.add('is-online');
    // bila dibuka langsung di /offline, kembali ke beranda; selain itu muat ulang halaman semula
    var direct = /\/offline\/?$/.test(location.pathname);
    setTimeout(function () {
      if (direct) location.replace(H.url ? H.url('/') : './');
      else location.reload();
    }, 500);
  }

  // Server dianggap terjangkau bila permintaan selesai (status apa pun); gagal jaringan = masih offline.
  function check(manual) {
    if (busy || done) return;
    busy = true;
    if (manual) { btn.classList.add('loading'); say('Memeriksa koneksi…'); }
    var ctl = ('AbortController' in window) ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctl) ctl.abort(); }, 7000);
    fetch(H.url ? H.url('/manifest.webmanifest') : 'manifest.webmanifest', {
      method: 'HEAD', cache: 'no-store', credentials: 'same-origin', signal: ctl ? ctl.signal : undefined
    }).then(function () {
      goBack();
    }).catch(function () {
      say(manual ? 'Masih belum tersambung. Coba lagi sebentar lagi.' : 'Menunggu koneksi…', manual ? 'warn' : '');
    }).then(function () {
      clearTimeout(timer);
      busy = false;
      btn.classList.remove('loading');
    });
  }

  btn.addEventListener('click', function () { check(true); });
  window.addEventListener('online', function () { check(false); });
  setInterval(function () { if (!document.hidden) check(false); }, 6000);
})();
