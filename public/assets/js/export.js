/* Cashflow HME — dialog Cetak: pilih pratinjau PDF atau Excel, lalu unduh berkas yang dipilih. Tanpa pustaka luar. */
(function () {
  'use strict';
  var H = window.HME;
  if (!H) return;

  var modal = null, state = null, loaded = {};
  var small = window.matchMedia('(max-width: 767px)');

  function build() {
    if (modal) return modal;
    modal = document.createElement('div');
    modal.className = 'modal-back';
    modal.id = 'exportModal';
    modal.setAttribute('data-static', '');
    modal.innerHTML =
      '<div class="modal xl" role="dialog" aria-modal="true" aria-labelledby="exTitle">' +
      '<div class="modal-head"><div><h3 id="exTitle">Cetak</h3><p class="help mb-0" id="exSub">Pilih format pratinjau, periksa isinya, lalu unduh.</p></div>' +
      '<button type="button" class="btn btn-ghost btn-sm btn-icon" data-close-modal aria-label="Tutup"><svg class="i i-sm" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>' +
      '<div class="ex-bar"><div class="ex-seg" role="tablist" aria-label="Format pratinjau">' +
      '<button type="button" role="tab" data-fmt="pdf" class="on">Pratinjau PDF</button>' +
      '<button type="button" role="tab" data-fmt="xlsx">Pratinjau Excel</button></div>' +
      '<label class="ex-paper" id="exPaperBox" hidden>Kertas <select id="exPaper" class="input">' +
      '<option value="legal">Legal (215,9 × 355,6 mm)</option><option value="folio">Folio (215 × 330 mm)</option><option value="a4">A4 (210 × 297 mm)</option></select></label>' +
      '<span class="ex-hint" id="exHint"></span></div>' +
      '<div class="ex-stage" id="exStage">' +
      '<div class="ex-pane on" data-pane="pdf"></div><div class="ex-pane" data-pane="xlsx"></div></div>' +
      '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-close-modal>Batal</button>' +
      '<a class="btn" id="exDownload" href="#" rel="noopener"><svg class="i i-sm"><use href="#i-down"/></svg><span>Unduh PDF</span></a></div></div>';
    document.body.appendChild(modal);
    modal.querySelectorAll('[data-fmt]').forEach(function (b) {
      b.addEventListener('click', function () { show(b.getAttribute('data-fmt')); });
    });
    // pilihan kertas bukti BK/BM: disimpan di peramban, pratinjau & unduhan mengikuti
    modal.querySelector('#exPaper').addEventListener('change', function () {
      state.paper = this.value;
      try { window.localStorage.setItem('cf_bukti_paper', state.paper); } catch (x) { /* abaikan */ }
      loaded.pdf = false;
      pane('pdf').innerHTML = '';
      show('pdf');
    });
    // tab lembar pada pratinjau Excel
    modal.addEventListener('click', function (ev) {
      var t = ev.target.closest('[data-xl-tab]');
      if (!t) return;
      var i = t.getAttribute('data-xl-tab');
      modal.querySelectorAll('[data-xl-tab]').forEach(function (x) { x.classList.toggle('on', x === t); });
      modal.querySelectorAll('[data-xl-pane]').forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-xl-pane') === i); });
      fit();
    });
    return modal;
  }

  function query() {
    var q = state.live ? window.location.search.replace(/^\?/, '') : state.query;
    var p = new URLSearchParams(q);
    p.delete('format'); p.delete('partial'); p.delete('page');
    return p.toString();
  }

  function url(kind, fmt) {
    var q = query();
    return H.url('/export/' + state.doc + '/' + kind) + '?' + (q ? q + '&' : '') + 'format=' + fmt + (state.doc === 'bukti' ? '&paper=' + encodeURIComponent(state.paper || 'legal') : '');
  }

  function pane(fmt) { return modal.querySelector('[data-pane="' + fmt + '"]'); }

  /* Skala-muat tampilan Excel: tabel dikecilkan secara proporsional (CSS zoom, jadi tata letak ikut mengecil) agar seluruh
     kolom terlihat tanpa menggeser. Batas bawah MIN_FIT menjaga teks tetap terbaca; di bawah itu (layar sempit) baru bergulir. */
  var MIN_FIT = 0.62;
  function fit() {
    if (!modal || !modal.classList.contains('open')) return;
    modal.querySelectorAll('.xl-pane.on table.xl').forEach(function (t) {
      var w = parseFloat(t.getAttribute('data-w'));
      var box = t.parentNode;
      if (!w || !box) return;
      t.style.zoom = '';
      var avail = box.clientWidth - 2;
      if (avail <= 0) return;           // pane sedang tersembunyi
      var s = Math.max(MIN_FIT, Math.min(1, avail / w));
      t.style.zoom = s < 1 ? s.toFixed(3) : '';
    });
  }
  window.addEventListener('resize', H.debounce(fit, 120));

  function loader(el, text) {
    el.innerHTML = '<div class="ex-load"><div class="spin ex-spin"></div><p>' + H.esc(text) + '</p></div>';
  }

  function message(el, text, link) {
    el.innerHTML = '<div class="ex-load"><p>' + H.esc(text) + '</p>' + (link ? '<a class="btn btn-ghost" target="_blank" rel="noopener" href="' + H.esc(link) + '">Buka pratinjau PDF di tab baru</a>' : '') + '</div>';
  }

  function loadPdf() {
    var el = pane('pdf');
    if (small.matches) {
      // peramban ponsel umumnya tidak menampilkan PDF di dalam halaman
      message(el, 'Pratinjau PDF ditampilkan di tab baru pada layar kecil.', url('preview', 'pdf'));
      loaded.pdf = true;
      return;
    }
    loader(el, 'Menyusun PDF…');
    var f = document.createElement('iframe');
    f.className = 'ex-frame';
    f.title = 'Pratinjau PDF';
    f.setAttribute('hidden', '');
    f.addEventListener('load', function () {
      var l = el.querySelector('.ex-load'); if (l) l.remove();
      f.removeAttribute('hidden');
    });
    f.src = url('preview', 'pdf') + '#zoom=page-width&navpanes=0';   // lebar halaman penuh: tanpa geser horizontal
    el.appendChild(f);
    loaded.pdf = true;
  }

  function loadXlsx() {
    var el = pane('xlsx');
    loader(el, 'Menyusun tampilan Excel…');
    loaded.xlsx = true;
    fetch(url('preview', 'xlsx'), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (r.status === 401) { window.location.href = H.url('/login'); return null; }
        return r.json().catch(function () { return { ok: false, error: 'Respons tidak valid dari server.' }; });
      })
      .then(function (j) {
        if (!j) return;
        if (!j.ok) { message(el, j.error || 'Pratinjau gagal dibuat.'); loaded.xlsx = false; return; }
        el.innerHTML = j.html;
        var note = '';
        if (j.truncated) note = 'Pratinjau menampilkan ' + j.maxRows + ' baris pertama; berkas Excel yang diunduh memuat seluruh baris.';
        el.setAttribute('data-note', note);
        if (state.fmt === 'xlsx') hint('xlsx');
        fit();
      })
      .catch(function () { message(el, 'Koneksi terputus. Coba lagi.'); loaded.xlsx = false; });
  }

  function hint(fmt) {
    var h = modal.querySelector('#exHint');
    if (state.doc === 'bukti') {
      h.textContent = 'Potret, satu bukti (BK/BM) per halaman di setengah bagian atas kertas (sisanya kosong untuk dipotong). Pilih kertas, lalu unduh PDF.';
    } else if (fmt === 'pdf') {
      h.textContent = 'Tampilan sama dengan berkas PDF yang diunduh (kertas Legal, otomatis muat satu halaman lebar).';
    } else {
      h.textContent = pane('xlsx').getAttribute('data-note') || 'Tampilan seperti lembar kerja Excel; berkas .xlsx dapat diedit.';
    }
  }

  function show(fmt) {
    state.fmt = fmt;
    modal.querySelectorAll('[data-fmt]').forEach(function (b) {
      var on = b.getAttribute('data-fmt') === fmt;
      b.classList.toggle('on', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    modal.querySelectorAll('.ex-pane').forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-pane') === fmt); });
    if (!loaded[fmt]) { fmt === 'pdf' ? loadPdf() : loadXlsx(); }
    hint(fmt);
    if (fmt === 'xlsx') fit();
    var a = modal.querySelector('#exDownload');
    a.href = url('download', fmt);
    a.querySelector('span').textContent = fmt === 'pdf' ? 'Unduh PDF' : 'Unduh Excel (.xlsx)';
  }

  function open(btn) {
    build();
    state = {
      doc: btn.getAttribute('data-export'),
      title: btn.getAttribute('data-export-title') || 'Dokumen',
      query: btn.getAttribute('data-export-query') || '',
      live: btn.hasAttribute('data-export-live'),
      fmt: 'pdf'
    };
    loaded = {};
    pane('pdf').innerHTML = '';
    pane('xlsx').innerHTML = '';
    pane('xlsx').removeAttribute('data-note');
    modal.querySelector('#exTitle').textContent = state.doc === 'transaksi' ? 'Cetak Mutasi Bank' : state.doc === 'bukti' ? 'Cetak Detail — Bukti Bank' : 'Cetak — ' + state.title;
    // bukti BK/BM hanya tersedia sebagai PDF (satu bukti per halaman)
    modal.querySelector('[data-fmt="xlsx"]').hidden = state.doc === 'bukti';
    var pbox = modal.querySelector('#exPaperBox');
    pbox.hidden = state.doc !== 'bukti';
    if (state.doc === 'bukti') {
      var saved = 'legal';
      try { saved = window.localStorage.getItem('cf_bukti_paper') || 'legal'; } catch (x) { /* abaikan */ }
      state.paper = (saved === 'a4' || saved === 'folio') ? saved : 'legal';
      modal.querySelector('#exPaper').value = state.paper;
    }
    H.openModal('exportModal');
    show('pdf');
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-export]');
    if (!b) return;
    ev.preventDefault();
    open(b);
  });

  // tutup dialog: bersihkan iframe agar PDF tidak tertinggal di memori
  document.addEventListener('click', function (ev) {
    if (!modal || !ev.target.closest) return;
    if (ev.target.closest('#exportModal [data-close-modal]') || ev.target === modal) {
      setTimeout(function () { if (!modal.classList.contains('open')) { pane('pdf').innerHTML = ''; } }, 50);
    }
  });
})();
