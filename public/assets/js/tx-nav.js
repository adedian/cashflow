/* Cashflow HME — detail transaksi: tombol panah ◀ ▶ dan tombol keyboard ← → untuk berpindah ke transaksi
   sebelumnya (kiri, lebih lama) / berikutnya (kanan, lebih baru). Tautan dibuat server (a[data-tx-nav]);
   keyboard hanya meneruskan klik ke tautan itu supaya transisi halaman (vt.js) ikut berjalan. */
(function () {
  'use strict';
  document.addEventListener('keydown', function (e) {
    if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    var t = e.target;
    if (t && (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || t.isContentEditable)) return;   // jangan ganggu pengetikan
    if (document.querySelector('.modal.open, dialog[open]')) return;                          // ada dialog terbuka (mis. konfirmasi void)
    var a = document.querySelector('a[data-tx-nav="' + (e.key === 'ArrowRight' ? 'next' : 'prev') + '"]');
    if (a) { e.preventDefault(); a.click(); }
  });
})();
