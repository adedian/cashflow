/* Formulir Piutang: tombol "+" di samping Customer dan Proyek — tambah ke master lewat dialog ringkas, lalu langsung dipilih.
   (Mengetik nama lalu memilih "Tambah" di dropdown tetap bisa; keduanya memakai API yang sama.) */
(function () {
  'use strict';
  var H = window.HME, dlg = document.getElementById('quickPick');
  if (!H || !dlg) return;
  var $ = function (id) { return document.getElementById(id); };
  var name = $('qkName'), err = $('qkErr'), save = $('qkSave'), busy = false, cur = null;
  var CFG = {
    customer: { title: 'Tambah customer', label: 'Nama customer', url: '/api/parties', max: 255, ph: 'mis. PT. Contoh Abadi', master: 'Master Data → Customer' },
    project_name: { title: 'Tambah proyek', label: 'Nama proyek', url: '/api/projects', max: 160, ph: 'mis. Proyek PJU Kota', master: 'Master Data → Proyek' }
  };
  var show = function (m) { err.hidden = !m; err.textContent = m || ''; };

  function open(key) {
    var c = CFG[key], sel = $(key);
    if (!c || !sel) return;
    cur = { c: c, sel: sel };
    $('qkTitle').textContent = c.title;
    $('qkLabel').innerHTML = c.label + ' <span class="req">*</span>';
    $('qkHelp').textContent = 'Tersimpan ke ' + c.master + ' dan langsung dipilih. Nama yang sudah ada (tanpa membedakan huruf besar/kecil) tidak dibuat ganda — yang ada langsung dipilih.';
    name.maxLength = c.max; name.placeholder = c.ph; name.value = ''; show('');
    H.openModal('quickPick');
    setTimeout(function () { name.focus(); }, 60);
  }

  function doSave() {
    if (busy || !cur) return;
    var n = name.value.replace(/\s+/g, ' ').trim(), c = cur.c, sel = cur.sel;
    if (!n) { show(c.label + ' wajib diisi.'); name.focus(); return; }
    if (n.length > c.max) { show(c.label + ' maksimal ' + c.max + ' karakter.'); return; }
    busy = true; save.disabled = true;
    H.api(c.url, { method: 'POST', form: new URLSearchParams({ name: n }) }).then(function (j) {
      if (!j || !j.ok) { show((j && j.error) || 'Gagal menambahkan.'); return; }
      var v = j.name;
      Array.prototype.forEach.call(sel.options, function (o) { if (o.value.toLowerCase() === v.toLowerCase()) v = o.value; });
      if (!Array.prototype.some.call(sel.options, function (o) { return o.value === v; })) sel.appendChild(new Option(v, v));
      sel.value = v;
      sel.dispatchEvent(new Event('change', { bubbles: true }));   // combo.js menyinkronkan tampilannya
      if (H.toast) H.toast(j.created ? '“' + j.name + '” ditambahkan ke master.' : '“' + j.name + '” sudah ada — dipilih.', 'success', 2500);
      H.closeModal(dlg);
    }).catch(function () { show('Koneksi terputus — gagal menambahkan.'); })
      .then(function () { busy = false; save.disabled = false; });
  }

  save.addEventListener('click', doSave);
  name.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doSave(); } });
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-quick-pick]') : null;
    if (b) open(b.getAttribute('data-quick-pick'));
  });
})();
