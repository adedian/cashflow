(function () {
  'use strict';
  var H = window.HME;
  var res = document.getElementById('debtResults');
  var f = document.getElementById('debtFilter');
  if (f && f.elements.status) f.elements.status.addEventListener('change', function () { f.submit(); });

  /* form hutang: label Hutang Direksi (Tanggal setoran, Rekening penerima) mengikuti pilihan jenis */
  var cat = document.querySelector('form select[name="category"]');
  if (cat) {
    cat.addEventListener('change', function () {
      var loan = cat.value === 'direksi';
      document.querySelectorAll('[data-loan]').forEach(function (el) { el.textContent = el.getAttribute(loan ? 'data-loan' : 'data-std'); });
      document.querySelectorAll('[data-loan-only]').forEach(function (el) { el.hidden = !loan; });
      document.querySelectorAll('[data-std-only]').forEach(function (el) { el.hidden = loan; });
    });
  }

  /* tombol "+" di samping Supplier: tambah ke master lewat dialog ringkas, lalu langsung dipilih */
  (function () {
    var sel = document.getElementById('supplier_id'), dlg = document.getElementById('quickSupplier');
    if (!sel || !dlg) return;
    var name = document.getElementById('qsName'), err = document.getElementById('qsErr'), save = document.getElementById('qsSave'), busy = false;
    var show = function (m) { err.hidden = !m; err.textContent = m || ''; };
    var open = function () { name.value = ''; show(''); H.openModal('quickSupplier'); setTimeout(function () { name.focus(); }, 60); };
    var doSave = function () {
      if (busy) return;
      var n = name.value.replace(/\s+/g, ' ').trim();
      if (!n) { show('Nama supplier wajib diisi.'); name.focus(); return; }
      busy = true; save.disabled = true;
      H.api('/api/suppliers', { method: 'POST', form: new URLSearchParams({ name: n }) }).then(function (j) {
        if (!j || !j.ok) { show((j && j.error) || 'Gagal menambahkan.'); return; }
        var v = String(j.id);
        if (!Array.prototype.some.call(sel.options, function (o) { return o.value === v; })) sel.appendChild(new Option(j.name, v));
        sel.value = v;
        sel.dispatchEvent(new Event('change', { bubbles: true }));   // combo.js menyinkronkan tampilannya
        if (H.toast) H.toast(j.created ? '“' + j.name + '” ditambahkan ke master.' : '“' + j.name + '” sudah ada — dipilih.', 'success', 2500);
        H.closeModal(dlg);
      }).catch(function () { show('Koneksi terputus — gagal menambahkan.'); })
        .then(function () { busy = false; save.disabled = false; });
    };
    save.addEventListener('click', doSave);
    name.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doSave(); } });
    document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-quick-supplier]')) open(); });
  })();

  /* klik baris membuka detail (tautan & tombol di dalam baris tetap bekerja sendiri) */
  document.addEventListener('click', function (ev) {
    var tr = ev.target.closest && ev.target.closest('tr[data-href]');
    if (!tr || ev.target.closest('a,button,form,input,select')) return;
    window.location.href = tr.getAttribute('data-href');
  });

  if (H && H.live) {
    H.live.on(['debts'], function () {
      if (res) {
        var q = res.getAttribute('data-query') || '';
        fetch(H.url('/debts') + '?' + q + (q ? '&' : '') + 'partial=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.ok ? r.text() : null; })
          .then(function (html) { if (html !== null) { res.innerHTML = html; H.toast('Daftar hutang diperbarui.', 'info', 2500); } })
          .catch(function () { /* coba lagi pada perubahan berikutnya */ });
      } else if (document.getElementById('debtDetail')) {
        H.toast('Data hutang ini baru saja diubah. Muat ulang halaman untuk melihat versi terbaru.', 'info', 6000);
      }
    });
  }
})();
