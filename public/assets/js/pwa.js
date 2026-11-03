/* Cashflow HME — PWA: daftar service worker, tombol "Pasang aplikasi", dan info status koneksi. */
(function () {
  'use strict';
  var H = window.HME || {};
  var host = location.hostname;
  var secure = location.protocol === 'https:' || host === 'localhost' || host === '127.0.0.1';

  if ('serviceWorker' in navigator && secure) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(H.url('/sw.js'), { scope: H.base + '/' }).catch(function () { /* abaikan */ });
    });
  }

  // tombol pasang (hanya muncul bila peramban menawarkan)
  var deferred = null;
  var btn = document.getElementById('installBtn');
  window.addEventListener('beforeinstallprompt', function (ev) {
    ev.preventDefault();
    deferred = ev;
    if (btn) btn.hidden = false;
  });
  if (btn) {
    btn.addEventListener('click', function () {
      if (!deferred) return;
      deferred.prompt();
      deferred.userChoice.then(function () { deferred = null; btn.hidden = true; });
    });
  }
  window.addEventListener('appinstalled', function () {
    deferred = null;
    if (btn) btn.hidden = true;
    if (H.toast) H.toast('Aplikasi berhasil dipasang.', 'success');
  });

  // status koneksi
  window.addEventListener('offline', function () { if (H.toast) H.toast('Anda sedang offline. Perubahan tidak dapat disimpan sampai terhubung kembali.', 'error'); });
  window.addEventListener('online', function () { if (H.toast) H.toast('Koneksi kembali tersambung.', 'success'); });
})();
