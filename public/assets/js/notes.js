(function () {
  'use strict';
  var H = window.HME;
  var list = document.getElementById('notesList');
  var viewing = null;   // catatan yang sedang dibuka di modal baca
  var isAdmin = H.role === 'super_admin';

  /* ---------- daftar (muat ulang tanpa reload halaman) ---------- */
  function refresh(silent) {
    var q = list.getAttribute('data-query') || '';
    return fetch(H.url('/notes') + '?' + q + (q ? '&' : '') + 'partial=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (r.status === 401) { window.location.href = H.url('/login'); return null; } return r.ok ? r.text() : null; })
      .then(function (html) { if (html !== null) { list.innerHTML = html; if (!silent) H.toast('Catatan diperbarui.', 'info', 2200); } })
      .catch(function () { /* koneksi terputus: polling akan mencoba lagi */ });
  }
  if (H.live) {
    H.live.on(['notes'], function () {
      refresh(false);
      // catatan yang sedang dibaca berubah/terhapus di sesi lain
      if (viewing && document.getElementById('noteView').classList.contains('open')) {
        H.api('/api/notes/' + viewing.id).then(function (j) {
          if (!j.ok) { H.closeModal('noteView'); H.toast('Catatan yang Anda buka sudah dihapus.', 'warn'); viewing = null; }
          else if (j.note.updated_at !== viewing.updated_at) { showNote(j.note); H.toast('Catatan ini diperbarui.', 'info', 2500); }
        });
      }
    });
  }

  /* ---------- baca detail ---------- */
  function showNote(n) {
    viewing = n;
    document.getElementById('nvTitle').textContent = n.title;
    document.getElementById('nvBody').textContent = n.body;     // textContent: aman dari XSS
    document.getElementById('nvMeta').textContent = (n.category_name ? n.category_name + ' · ' : '') + (n.author || '-') + ' · dibuat ' + n.created_at.replace(/^(\d{4})-(\d{2})-(\d{2})/, '$3/$2/$1') + (n.is_important == 1 ? ' · PENTING' : '');
    H.openModal('noteView');
  }
  list.addEventListener('click', function (ev) {
    var card = ev.target.closest('[data-note-id]');
    if (!card) return;
    H.api('/api/notes/' + card.getAttribute('data-note-id')).then(function (j) {
      if (j.ok) showNote(j.note); else { H.toast(j.error || 'Catatan tidak ditemukan.', 'error'); refresh(true); }
    });
  });
  list.addEventListener('keydown', function (ev) {
    if ((ev.key === 'Enter' || ev.key === ' ') && ev.target.matches('[data-note-id]')) { ev.preventDefault(); ev.target.click(); }
  });

  /* ---------- filter ---------- */
  var ff = document.getElementById('noteFilter');
  if (ff) ff.elements.category.addEventListener('change', function () { ff.submit(); });

  if (!isAdmin) return;

  /* ---------- tambah / ubah (Super Admin) ---------- */
  var form = document.getElementById('noteFormEl');
  var errBox = document.getElementById('nfError');
  function openForm(n) {
    form.reset();
    errBox.classList.add('hidden');
    H.qsa('[data-err]', form).forEach(function (e) { e.textContent = ''; });
    form.elements.id.value = n ? n.id : '';
    document.getElementById('nfTitle').textContent = n ? 'Ubah Catatan' : 'Tambah Catatan';
    if (n) {
      form.elements.title.value = n.title; form.elements.body.value = n.body;
      form.elements.category_id.value = n.category_id || ''; form.elements.is_important.checked = n.is_important == 1;
    }
    H.openModal('noteForm');
  }
  document.getElementById('addNoteBtn').addEventListener('click', function () { openForm(null); });
  document.getElementById('nvEdit').addEventListener('click', function () { var n = viewing; H.closeModal('noteView'); openForm(n); });
  document.getElementById('nvDelete').addEventListener('click', function () {
    var n = viewing;
    H.confirm('Hapus catatan "' + n.title + '"? Tindakan ini tercatat di audit log dan tidak dapat dibatalkan.', function () {
      H.api('/notes/' + n.id + '/delete', { method: 'POST', data: {} }).then(function (j) {
        if (j.ok) { H.closeModal('noteView'); H.toast('Catatan dihapus.', 'success'); refresh(true); }
        else H.toast(j.error || 'Gagal menghapus catatan.', 'error');
      });
    }, { title: 'Hapus catatan', okText: 'Ya, hapus' });
  });
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var btn = document.getElementById('nfSave');
    var data = {
      title: form.elements.title.value.trim(), body: form.elements.body.value.trim(),
      category_id: form.elements.category_id.value, category_new: form.elements.category_new.value.trim(),
      is_important: form.elements.is_important.checked ? '1' : ''
    };
    H.qsa('[data-err]', form).forEach(function (e) { e.textContent = ''; });
    var bad = false;
    if (!data.title) { form.querySelector('[data-err=title]').textContent = 'Judul catatan wajib diisi.'; bad = true; }
    if (!data.body) { form.querySelector('[data-err=body]').textContent = 'Isi catatan wajib diisi.'; bad = true; }
    if (bad) return;
    var id = form.elements.id.value;
    btn.setAttribute('disabled', 'disabled'); btn.classList.add('loading');
    H.api(id ? '/notes/' + id : '/notes', { method: 'POST', data: data }).then(function (j) {
      if (j.ok) {
        H.closeModal('noteForm');
        H.toast(id ? 'Catatan diperbarui.' : 'Catatan tersimpan.', 'success');
        refresh(true);
      } else if (j.fields) {
        Object.keys(j.fields).forEach(function (k) { var e = form.querySelector('[data-err=' + k + ']'); if (e) e.textContent = j.fields[k]; });
      } else { errBox.textContent = j.error || 'Gagal menyimpan catatan.'; errBox.classList.remove('hidden'); }
    }).catch(function () { errBox.textContent = 'Koneksi terputus. Catatan belum tersimpan.'; errBox.classList.remove('hidden'); })
      .then(function () { btn.removeAttribute('disabled'); btn.classList.remove('loading'); });
  });
})();
