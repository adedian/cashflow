/* Grafik SVG buatan sendiri (tanpa pustaka). Input angka hanya untuk GAMBAR; nilai yang ditampilkan di tooltip memakai string server. */
(function () {
  'use strict';
  var NS = 'http://www.w3.org/2000/svg';
  var C = (window.HMECharts = window.HMECharts || {});
  var PALETTE = ['#0b5cff', '#f57c00', '#19c6f2', '#7c5cff', '#12a150', '#f07f7f', '#ffb300', '#8aa0c8'];
  C.palette = PALETTE;

  function el(name, attrs, parent) {
    var n = document.createElementNS(NS, name);
    for (var k in attrs) { if (Object.prototype.hasOwnProperty.call(attrs, k)) n.setAttribute(k, attrs[k]); }
    if (parent) parent.appendChild(n);
    return n;
  }
  function niceMax(v) {
    if (v <= 0) return 1;
    var p = Math.pow(10, Math.floor(Math.log10(v)));
    var n = v / p;
    var m = n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10;
    return m * p;
  }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  C.monthLabel = function (ym) { var p = String(ym).split('-'); return MON[parseInt(p[1], 10) - 1] + (p[0] ? " '" + p[0].slice(2) : ''); };

  function tooltip(host) {
    var t = host.querySelector('.tip');
    if (!t) { t = document.createElement('div'); t.className = 'tip'; host.appendChild(t); }
    return t;
  }
  function placeTip(host, tip, clientX, clientY, html) {
    tip.innerHTML = html;
    var r = host.getBoundingClientRect();
    var x = clientX - r.left + 14, y = clientY - r.top - 10;
    tip.style.opacity = 1;
    var w = tip.offsetWidth;
    if (x + w > r.width) x = clientX - r.left - w - 14;
    if (x < 0) x = 4;
    tip.style.left = x + 'px'; tip.style.top = Math.max(0, y) + 'px';
  }

  /* ---------- batang berkelompok (masuk vs keluar) ---------- */
  C.bars = function (host, data) {
    host.innerHTML = '';
    if (!data.length) { host.innerHTML = '<div class="empty"><p>Belum ada data pada periode ini.</p></div>'; return; }
    var W = 640, H = 270, L = 56, R = 10, T = 12, B = 30, iw = W - L - R, ih = H - T - B;
    var max = 0;
    data.forEach(function (d) { max = Math.max(max, Number(d.ops_in), Number(d.ops_out)); });
    max = niceMax(max / 4) * 4;   // 4 interval dengan langkah bulat (1, 2, 2,5, 5 × 10^n)
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Grafik mutasi masuk dan mutasi keluar per bulan' }, host);
    for (var i = 0; i <= 4; i++) {
      var y = T + ih - (ih * i / 4);
      el('line', { x1: L, x2: W - R, y1: y, y2: y, stroke: '#e6eaf4', 'stroke-width': 1 }, svg);
      var tx = el('text', { x: L - 8, y: y + 4, 'text-anchor': 'end', 'font-size': 11, fill: '#6b7592' }, svg);
      tx.textContent = window.HME.compact(max * i / 4);
    }
    var gw = iw / data.length, bw = Math.min(26, gw * 0.32);
    var tip = tooltip(host);
    data.forEach(function (d, idx) {
      var cx = L + gw * idx + gw / 2;
      var hi = ih * Number(d.ops_in) / max, ho = ih * Number(d.ops_out) / max;
      el('rect', { x: cx - bw - 2, y: T + ih - hi, width: bw, height: Math.max(hi, 0), rx: 4, fill: '#0b5cff' }, svg);
      el('rect', { x: cx + 2, y: T + ih - ho, width: bw, height: Math.max(ho, 0), rx: 4, fill: '#f07f7f' }, svg);
      var lb = el('text', { x: cx, y: H - 10, 'text-anchor': 'middle', 'font-size': 11, fill: '#6b7592' }, svg);
      lb.textContent = C.monthLabel(d.ym);
      var hit = el('rect', { x: L + gw * idx, y: T, width: gw, height: ih + B, fill: 'transparent' }, svg);
      var show = function (ev) {
        var pt = ev.touches ? ev.touches[0] : ev;
        placeTip(host, tip, pt.clientX, pt.clientY,
          '<b>' + C.monthLabel(d.ym) + '</b><br><span style="color:#0b5cff">Mutasi Masuk</span> ' + window.HME.money(d.ops_in) +
          '<br><span style="color:#e0383e">Mutasi Keluar</span> ' + window.HME.money(d.ops_out) +
          '<br>Bersih <b>' + window.HME.money(d.ops_net) + '</b>');
      };
      hit.addEventListener('mousemove', show); hit.addEventListener('touchstart', show, { passive: true });
      hit.addEventListener('mouseleave', function () { tip.style.opacity = 0; });
    });
    host.addEventListener('mouseleave', function () { tip.style.opacity = 0; });
  };

  /* ---------- area halus (saldo) dengan penanda seperti referensi ---------- */
  C.area = function (host, data) {
    host.innerHTML = '';
    if (!data.length) { host.innerHTML = '<div class="empty"><p>Belum ada data pada periode ini.</p></div>'; return; }
    var W = 640, H = 250, L = 56, R = 14, T = 14, B = 30, iw = W - L - R, ih = H - T - B;
    var vals = [];
    data.forEach(function (d) { vals.push(Number(d.closing)); if (d.closing_bank !== undefined) vals.push(Number(d.closing_bank)); });
    var mn = Math.min.apply(null, vals.concat([0])), mx = Math.max.apply(null, vals.concat([0]));
    var step = niceMax(((mx - mn) || 1) / 4);
    var lo = mn < 0 ? -Math.ceil(-mn / step) * step : 0;
    var hi = lo + Math.max(1, Math.ceil((mx - lo) / step)) * step;
    var span = hi - lo;
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Grafik saldo akhir bulan' }, host);
    var defs = el('defs', {}, svg);
    var g = el('linearGradient', { id: 'ag' + Math.random().toString(36).slice(2, 7), x1: 0, x2: 0, y1: 0, y2: 1 }, defs);
    el('stop', { offset: '0%', 'stop-color': '#0b5cff', 'stop-opacity': .28 }, g);
    el('stop', { offset: '100%', 'stop-color': '#0b5cff', 'stop-opacity': 0 }, g);
    var Y = function (v) { return T + ih - ih * (v - lo) / (hi - lo); };
    var nt = Math.max(1, Math.round((hi - lo) / step));
    for (var i = 0; i <= nt; i++) {
      var v = lo + step * i, y = Y(v);
      el('line', { x1: L, x2: W - R, y1: y, y2: y, stroke: '#e6eaf4' }, svg);
      var t = el('text', { x: L - 8, y: y + 4, 'text-anchor': 'end', 'font-size': 11, fill: '#6b7592' }, svg); t.textContent = window.HME.compact(v);
    }
    if (lo < 0) el('line', { x1: L, x2: W - R, y1: Y(0), y2: Y(0), stroke: '#c3cbe0', 'stroke-dasharray': '4 4' }, svg);
    var n = data.length, X = function (i) { return n === 1 ? L + iw / 2 : L + iw * i / (n - 1); };
    var key = data[0].closing_bank !== undefined ? 'closing_bank' : 'closing';
    var pts = data.map(function (d, i) { return [X(i), Y(Number(d[key]))]; });
    if (key === 'closing_bank') {
      var pts2 = data.map(function (d, i) { return [X(i), Y(Number(d.closing))]; });
      el('path', { d: 'M' + pts2.map(function (p) { return p[0] + ' ' + p[1]; }).join(' L'), fill: 'none', stroke: '#f57c00', 'stroke-width': 2, 'stroke-dasharray': '6 5' }, svg);
    }
    // kurva Catmull-Rom -> bezier
    var path = 'M' + pts[0][0] + ' ' + pts[0][1];
    for (var k = 0; k < pts.length - 1; k++) {
      var p0 = pts[k - 1] || pts[k], p1 = pts[k], p2 = pts[k + 1], p3 = pts[k + 2] || p2, tn = 0.18;
      path += ' C' + (p1[0] + (p2[0] - p0[0]) * tn) + ' ' + (p1[1] + (p2[1] - p0[1]) * tn) + ',' + (p2[0] - (p3[0] - p1[0]) * tn) + ' ' + (p2[1] - (p3[1] - p1[1]) * tn) + ',' + p2[0] + ' ' + p2[1];
    }
    var base = Y(Math.max(lo, 0));
    el('path', { d: path + ' L' + pts[n - 1][0] + ' ' + base + ' L' + pts[0][0] + ' ' + base + ' Z', fill: 'url(#' + g.id + ')' }, svg);
    el('path', { d: path, fill: 'none', stroke: '#0b5cff', 'stroke-width': 2.5, 'stroke-linecap': 'round' }, svg);
    var tip = tooltip(host);
    var vline = el('line', { y1: T, y2: T + ih, stroke: '#0b5cff', 'stroke-width': 1.5, opacity: 0 }, svg);
    var dot = el('circle', { r: 6, fill: '#fff', stroke: '#0b5cff', 'stroke-width': 3, opacity: 0 }, svg);
    data.forEach(function (d, i) {
      var lb = el('text', { x: X(i), y: H - 10, 'text-anchor': 'middle', 'font-size': 11, fill: '#6b7592' }, svg); lb.textContent = C.monthLabel(d.ym);
    });
    var move = function (ev) {
      var pt = ev.touches ? ev.touches[0] : ev;
      var r = svg.getBoundingClientRect(), x = (pt.clientX - r.left) * W / r.width, best = 0, bd = 1e9;
      pts.forEach(function (p, i) { var dd = Math.abs(p[0] - x); if (dd < bd) { bd = dd; best = i; } });
      vline.setAttribute('x1', pts[best][0]); vline.setAttribute('x2', pts[best][0]); vline.setAttribute('opacity', .5);
      dot.setAttribute('cx', pts[best][0]); dot.setAttribute('cy', pts[best][1]); dot.setAttribute('opacity', 1);
      var d = data[best];
      placeTip(host, tip, pt.clientX, pt.clientY, '<b>' + C.monthLabel(d.ym) + '</b><br>' + (d.closing_bank !== undefined ? 'Saldo bank <b>' + window.HME.money(d.closing_bank) + '</b><br><span style="color:#f57c00">Posisi bersih</span> ' + window.HME.money(d.closing) : 'Saldo akhir <b>' + window.HME.money(d.closing) + '</b>') + '<br><span class="muted">Arus usaha ' + window.HME.money(d.ops_net) + '</span>');
    };
    var cap = el('rect', { x: L, y: T, width: iw, height: ih + B, fill: 'transparent' }, svg);
    cap.addEventListener('mousemove', move); cap.addEventListener('touchstart', move, { passive: true }); cap.addEventListener('touchmove', move, { passive: true });
    cap.addEventListener('mouseleave', function () { tip.style.opacity = 0; vline.setAttribute('opacity', 0); dot.setAttribute('opacity', 0); });
  };

  /* ---------- donat ---------- */
  C.donut = function (host, items, legendHost) {
    host.innerHTML = '';
    if (legendHost) legendHost.innerHTML = '';
    if (!items.length) { host.innerHTML = '<div class="empty"><p>Belum ada pengeluaran pada periode ini.</p></div>'; return; }
    var total = items.reduce(function (s, i) { return s + Number(i.total); }, 0);
    var S = 190, r = 74, cx = S / 2, cy = S / 2, sw = 30, circ = 2 * Math.PI * r, off = 0;
    var svg = el('svg', { viewBox: '0 0 ' + S + ' ' + S, role: 'img', 'aria-label': 'Komposisi pengeluaran' }, host);
    el('circle', { cx: cx, cy: cy, r: r, fill: 'none', stroke: '#eef1f8', 'stroke-width': sw }, svg);
    var tip = tooltip(host);
    items.forEach(function (it, i) {
      var frac = Number(it.total) / total, len = circ * frac;
      var c = el('circle', { cx: cx, cy: cy, r: r, fill: 'none', stroke: PALETTE[i % PALETTE.length], 'stroke-width': sw,
        'stroke-dasharray': Math.max(len - 1.5, 0) + ' ' + (circ - Math.max(len - 1.5, 0)), 'stroke-dashoffset': -off, transform: 'rotate(-90 ' + cx + ' ' + cy + ')' }, svg);
      off += len;
      var show = function (ev) { var pt = ev.touches ? ev.touches[0] : ev; placeTip(host, tip, pt.clientX, pt.clientY, '<b>' + window.HME.esc(it.label) + '</b><br>' + window.HME.money(it.total) + ' · ' + (frac * 100).toFixed(1).replace('.', ',') + '%'); };
      c.addEventListener('mousemove', show); c.addEventListener('touchstart', show, { passive: true });
      c.addEventListener('mouseleave', function () { tip.style.opacity = 0; });
    });
    var t1 = el('text', { x: cx, y: cy - 2, 'text-anchor': 'middle', 'font-size': 11, fill: '#6b7592' }, svg); t1.textContent = 'Total mutasi keluar';
    var t2 = el('text', { x: cx, y: cy + 16, 'text-anchor': 'middle', 'font-size': 15, 'font-weight': 700, fill: '#121a33' }, svg); t2.textContent = window.HME.compact(total);
    if (legendHost) {
      items.forEach(function (it, i) {
        var li = document.createElement('div');
        li.style.cssText = 'display:flex;justify-content:space-between;gap:14px;font-size:13px;padding:5px 0;border-bottom:1px solid #eef1f8';
        li.innerHTML = '<span><i style="display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:8px;background:' + PALETTE[i % PALETTE.length] + '"></i>' + window.HME.esc(it.label) + '</span><b class="num">' + window.HME.money(it.total) + '</b>';
        legendHost.appendChild(li);
      });
    }
  };
})();
