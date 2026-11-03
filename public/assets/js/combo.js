/* Dropdown dengan pencarian (tanpa pustaka luar).
 *  - SEMUA <select> (kecuali [multiple] / [data-no-combo]) otomatis menjadi dropdown berpencarian; select asli tetap ada & ikut terkirim,
 *    sehingga formulir tanpa JavaScript tetap berfungsi. Mendukung <optgroup>, atribut form="...", dan event 'change'.
 *  - <select data-combo-create="/api/..."> : bila ketikan tidak ada di daftar, muncul pilihan "Tambah ..." (membuat entri master lewat API).
 *  - <div data-combo-remote="url"> : daftar dimuat dari server (No. Invoice/PO), mengikuti jenis transaksi. Berisi <input type="hidden" class="combo-value">.
 *    Memicu event 'combo:pick' (detail = item | null); API: el.comboApi.clear(), .reload(), .value().
 * Daftar memakai posisi 'fixed' agar tidak terpotong tabel/modal, dan otomatis naik ke atas bila ruang bawah sempit.
 */
(function () {
  'use strict';
  var uid = 0;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function HMEurl(p) { return (window.HME && window.HME.url) ? window.HME.url(p) : p; }

  /* kerangka UI bersama */
  function build(placeholder, ariaLabel) {
    var id = 'combo-list-' + (++uid);
    var wrap = el('div', 'combo');
    var input = el('input', 'input combo-input');
    input.type = 'text';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', id);
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('autocomplete', 'off');
    if (placeholder) input.placeholder = placeholder;
    if (ariaLabel) input.setAttribute('aria-label', ariaLabel);
    var clear = el('button', 'combo-clear', '×');
    clear.type = 'button';
    clear.setAttribute('aria-label', 'Kosongkan pilihan');
    clear.tabIndex = -1;
    clear.hidden = true;
    var list = el('ul', 'combo-list');
    list.id = id;
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    wrap.appendChild(input); wrap.appendChild(clear); wrap.appendChild(list);
    return { wrap: wrap, input: input, list: list, clear: clear };
  }

  /* posisi daftar: fixed, di bawah input (atau di atas bila ruang bawah sempit) */
  function place(ui) {
    var r = ui.input.getBoundingClientRect();
    var vh = window.innerHeight, vw = window.innerWidth;
    var below = vh - r.bottom - 10, above = r.top - 10;
    var up = below < 190 && above > below;
    var s = ui.list.style;
    s.position = 'fixed';
    var w = Math.min(Math.max(r.width, 240), vw - 16);
    var left = Math.min(Math.max(8, r.left), vw - 8 - w);
    s.left = left + 'px'; s.width = w + 'px'; s.right = 'auto';
    s.maxHeight = Math.max(120, Math.min(300, up ? above : below)) + 'px';
    if (up) { s.top = 'auto'; s.bottom = (vh - r.top + 4) + 'px'; } else { s.bottom = 'auto'; s.top = (r.bottom + 4) + 'px'; }
  }

  /* perilaku popup: buka/tutup, navigasi papan ketik */
  function popup(ui, onOpen, onQuery, onPick) {
    var active = -1;
    var onMove = function () { place(ui); };
    function items() { return ui.list.querySelectorAll('li[role=option]'); }
    function setActive(i) {
      var it = items();
      if (!it.length) { active = -1; return; }
      active = (i + it.length) % it.length;
      Array.prototype.forEach.call(it, function (n, k) { n.classList.toggle('on', k === active); n.setAttribute('aria-selected', k === active ? 'true' : 'false'); });
      it[active].scrollIntoView({ block: 'nearest' });
    }
    function open() {
      if (!ui.list.hidden || ui.input.disabled) return;
      ui.list.hidden = false;
      ui.input.setAttribute('aria-expanded', 'true');
      onOpen();
      place(ui);
      window.addEventListener('scroll', onMove, true);
      window.addEventListener('resize', onMove);
    }
    function close() {
      ui.list.hidden = true;
      ui.input.setAttribute('aria-expanded', 'false');
      active = -1;
      window.removeEventListener('scroll', onMove, true);
      window.removeEventListener('resize', onMove);
    }
    ui.input.addEventListener('focus', function () { ui.input.select(); open(); });
    ui.input.addEventListener('click', open);
    ui.input.addEventListener('input', function () { open(); onQuery(ui.input.value); active = -1; place(ui); });
    ui.input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); open(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); open(); setActive(active - 1); }
      else if (e.key === 'Enter') {
        if (!ui.list.hidden) {
          e.preventDefault();       // jangan kirim formulir saat memilih
          var it = items();
          var pick = active >= 0 ? it[active] : (it.length === 1 ? it[0] : null);
          if (pick) pick.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        }
      } else if (e.key === 'Escape') { if (!ui.list.hidden) { e.preventDefault(); close(); onPick(null, true); } }
      else if (e.key === 'Tab') { if (!ui.list.hidden) { close(); onPick(null, true); } }
    });
    document.addEventListener('mousedown', function (e) { if (!ui.wrap.contains(e.target) && !ui.list.hidden) { close(); onPick(null, true); } });
    ui.list.addEventListener('mousedown', function (e) { e.preventDefault(); });   // jaga fokus input
    return { open: open, close: close, setActive: setActive };
  }

  /* ---------- <select> (semua dropdown) ---------- */
  function enhanceSelect(sel) {
    if (sel.__combo || sel.multiple || sel.hasAttribute('data-no-combo') || sel.size > 1) return;
    sel.__combo = true;
    var lbl = sel.getAttribute('aria-label') || (sel.id && document.querySelector('label[for="' + sel.id + '"]') ? document.querySelector('label[for="' + sel.id + '"]').textContent.replace('*', '').trim() : '');
    var ui = build(sel.getAttribute('data-combo-placeholder') || 'Ketik untuk mencari…', lbl);
    var createUrl = sel.getAttribute('data-combo-create');
    // wadah mewarisi gaya sebaris select (lebar/min-width) agar tata letak tidak berubah
    if (sel.style.cssText) ui.wrap.style.cssText = sel.style.cssText;
    ui.input.style.width = '100%';
    sel.parentNode.insertBefore(ui.wrap, sel);
    ui.wrap.appendChild(sel);   // select asli di dalam pembungkus: tidak memengaruhi lebar halaman
    sel.classList.add('combo-native');
    sel.setAttribute('tabindex', '-1'); sel.setAttribute('aria-hidden', 'true');
    if (sel.disabled) ui.input.disabled = true;

    function readOptions() {
      var out = [];
      Array.prototype.forEach.call(sel.children, function (c) {
        if (c.tagName === 'OPTGROUP') {
          out.push({ group: c.label });
          Array.prototype.forEach.call(c.children, function (o) { out.push({ value: o.value, text: o.textContent.trim(), sub: o.getAttribute('data-sub') || '', disabled: o.disabled }); });
        } else if (c.tagName === 'OPTION') {
          out.push({ value: c.value, text: c.textContent.trim(), sub: c.getAttribute('data-sub') || '', disabled: c.disabled });
        }
      });
      return out;
    }
    function label() { var o = sel.options[sel.selectedIndex]; return o && o.value !== '' ? o.textContent.trim() : ''; }
    function sync() { ui.input.value = label(); ui.clear.hidden = !sel.value || sel.disabled; ui.input.disabled = sel.disabled; }
    function choose(value) {
      sel.value = value;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      pop.close(); sync();
    }
    function render(q) {
      q = (q || '').toLowerCase().trim();
      ui.list.textContent = '';
      var n = 0, exact = false, pendingGroup = null;
      readOptions().forEach(function (o) {
        if (o.group !== undefined) { pendingGroup = o.group; return; }
        if (o.value === '') { if (q) return; }
        else if (q && (o.text + ' ' + o.sub).toLowerCase().indexOf(q) < 0) return;
        if (q && o.text.toLowerCase() === q) exact = true;
        if (pendingGroup !== null) { var g = el('li', 'combo-group', pendingGroup); g.setAttribute('role', 'presentation'); ui.list.appendChild(g); pendingGroup = null; }
        var li = el('li', 'combo-opt' + (o.value === sel.value ? ' sel' : '') + (o.value === '' ? ' combo-none' : ''));
        li.setAttribute('role', 'option');
        li.appendChild(el('div', 'combo-t', o.value === '' ? (o.text || '— kosong —') : o.text));
        if (o.sub) li.appendChild(el('div', 'combo-s', o.sub));
        if (!o.disabled) li.addEventListener('mousedown', function () { choose(o.value); });
        else li.classList.add('dis');
        ui.list.appendChild(li);
        if (o.value !== '') n++;
      });
      if (createUrl && q && !exact) {
        var raw = ui.input.value.trim();
        var add = el('li', 'combo-opt combo-add');
        add.setAttribute('role', 'option');
        add.appendChild(el('div', 'combo-t', '+ Tambah “' + raw + '” sebagai baru'));
        add.addEventListener('mousedown', function () { create(raw); });
        ui.list.appendChild(add);
      } else if (!n) {
        ui.list.appendChild(el('li', 'combo-empty', q ? 'Tidak ada yang cocok dengan “' + q + '”.' : 'Daftar kosong.'));
      }
      place(ui);
    }
    function create(name) {
      if (!window.HME || !window.HME.api) return;
      ui.list.textContent = '';
      ui.list.appendChild(el('li', 'combo-empty combo-loading', 'Menambahkan…'));
      window.HME.api(createUrl, { method: 'POST', form: new URLSearchParams({ name: name }) }).then(function (j) {
        if (!j || !j.ok) { ui.list.textContent = ''; ui.list.appendChild(el('li', 'combo-empty combo-error', (j && j.error) || 'Gagal menambahkan.')); return; }
        var exists = false;
        Array.prototype.forEach.call(sel.options, function (o) { if (o.value.toLowerCase() === j.name.toLowerCase()) { exists = true; j.name = o.value; } });
        if (!exists) sel.appendChild(new Option(j.name, j.name));
        if (j.created && window.HME.toast) window.HME.toast('“' + j.name + '” ditambahkan ke master.', 'success', 2500);
        choose(j.name);
      }).catch(function () { ui.list.textContent = ''; ui.list.appendChild(el('li', 'combo-empty combo-error', 'Koneksi terputus — gagal menambahkan.')); });
    }
    var pop = popup(ui, function () { render(''); }, render, function () { sync(); });
    ui.clear.addEventListener('click', function () { sel.value = ''; sel.dispatchEvent(new Event('change', { bubbles: true })); sync(); ui.input.focus(); });
    sel.addEventListener('change', sync);
    // validasi bawaan browser (required) pada select tersembunyi: arahkan fokus ke kolom yang terlihat
    sel.addEventListener('invalid', function () { ui.input.focus(); });
    if (sel.form) sel.form.addEventListener('reset', function () { setTimeout(sync, 0); });
    sel.comboRefresh = sync;
    sync();
  }

  /* ---------- daftar dari server (No. Invoice/PO) ---------- */
  function enhanceRemote(root) {
    var hidden = root.querySelector('.combo-value');
    var url = root.getAttribute('data-combo-remote');
    var dirName = root.getAttribute('data-dir-name') || 'direction';
    var tx = root.getAttribute('data-tx') || '';
    var ui = build(root.getAttribute('data-placeholder') || 'Cari nomor invoice/PO, pihak, atau uraian…', 'No. invoice / PO');
    ui.input.style.width = '100%';
    root.appendChild(ui.wrap);
    var timer = null, seq = 0, current = null;
    try { current = JSON.parse(root.getAttribute('data-selected') || 'null'); } catch (e) { current = null; }

    function dir() { var r = document.querySelector('input[name="' + dirName + '"]:checked'); return r ? r.value : ''; }
    function sync() { ui.input.value = current ? current.title + (current.party ? ' · ' + current.party : '') : ''; ui.clear.hidden = !hidden.value; }
    function pick(item, silent) {
      if (silent === true && item === null) { sync(); return; }       // tutup tanpa memilih: pulihkan teks
      current = item;
      hidden.value = item ? item.value : '';
      sync();
      root.dispatchEvent(new CustomEvent('combo:pick', { bubbles: true, detail: item }));
    }
    function msg(text, cls) { ui.list.textContent = ''; ui.list.appendChild(el('li', cls || 'combo-empty', text)); }
    function render(items, q) {
      ui.list.textContent = '';
      var none = el('li', 'combo-opt combo-none');
      none.setAttribute('role', 'option');
      none.appendChild(el('div', 'combo-t', '— tanpa referensi —'));
      none.addEventListener('mousedown', function () { pick(null); pop.close(); });
      if (!q) ui.list.appendChild(none);
      if (!items.length) { ui.list.appendChild(el('li', 'combo-empty', q ? 'Tidak ada referensi yang cocok dengan “' + q + '”.' : (dir() === 'in' ? 'Belum ada Piutang atau item Proyeksi penerimaan yang masih memiliki sisa.' : 'Belum ada Hutang atau item Proyeksi pengeluaran yang masih memiliki sisa.'))); place(ui); return; }
      var group = null;
      items.forEach(function (it) {
        if (it.group !== group) { group = it.group; var g = el('li', 'combo-group', group); g.setAttribute('role', 'presentation'); ui.list.appendChild(g); }
        var li = el('li', 'combo-opt' + (hidden.value === it.value ? ' sel' : ''));
        li.setAttribute('role', 'option');
        var t = el('div', 'combo-t');
        t.appendChild(el('span', 'combo-badge', it.tag || it.group));
        t.appendChild(document.createTextNode(' ' + it.title));
        li.appendChild(t);
        var bits = [];
        if (it.party) bits.push(it.party);
        bits.push('Nominal ' + it.amount_fmt);
        bits.push('Sisa ' + it.remaining_fmt);
        if (it.due_fmt) bits.push('Tempo ' + it.due_fmt);
        li.appendChild(el('div', 'combo-s', bits.join(' · ')));
        li.addEventListener('mousedown', function () { pick(it); pop.close(); });
        ui.list.appendChild(li);
      });
      place(ui);
    }
    function load(q) {
      var d = dir();
      var my = ++seq;
      msg('Memuat daftar…', 'combo-empty combo-loading');
      var u = HMEurl(url) + '?dir=' + encodeURIComponent(d) + '&q=' + encodeURIComponent(q || '') + (tx ? '&tx=' + encodeURIComponent(tx) : '');
      fetch(u, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function (r) { if (r.status === 401) { window.location.href = HMEurl('/login'); return null; } return r.json().catch(function () { return null; }); })
        .then(function (j) {
          if (my !== seq || j === null) return;
          if (!j || !j.ok) { msg((j && j.error) || 'Daftar referensi gagal dimuat. Coba lagi.', 'combo-empty combo-error'); return; }
          render(j.items || [], q || '');
        })
        .catch(function () { if (my === seq) msg('Koneksi terputus — daftar referensi gagal dimuat. Coba lagi.', 'combo-empty combo-error'); });
    }
    var pop = popup(ui, function () { ui.input.select(); load(''); }, function (q) { clearTimeout(timer); timer = setTimeout(function () { load(q); }, 250); }, pick);
    ui.clear.addEventListener('click', function () { pick(null); ui.input.focus(); });
    root.comboApi = {
      clear: function () { var had = !!hidden.value; current = null; hidden.value = ''; sync(); if (had) root.dispatchEvent(new CustomEvent('combo:pick', { bubbles: true, detail: null })); },
      reload: function () { if (!ui.list.hidden) load(''); },
      value: function () { return hidden.value; }
    };
    sync();
  }

  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.querySelectorAll('select'), enhanceSelect);
    Array.prototype.forEach.call(document.querySelectorAll('[data-combo-remote]'), enhanceRemote);
  });
  // halaman yang menyisipkan <select> belakangan (mis. hasil muat ulang parsial) dapat memanggil HMECombo.scan()
  window.HMECombo = { scan: function (root) { Array.prototype.forEach.call((root || document).querySelectorAll('select'), enhanceSelect); } };
})();
