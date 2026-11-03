<?php
/** @var array $f */
/** @var array $accounts */
/** @var array $range */
?>
<section class="card rem" id="remCard" aria-label="Pengingat Pembayaran">
  <div class="rem-head">
    <h2>Pengingat Pembayaran</h2><span class="rem-count" id="remCount" hidden>0</span>
    <span class="muted rem-meta" id="remMeta"></span>
  </div>
  <div class="rem-list" id="remList"><div class="skeleton" style="height:44px"></div></div>
</section>

<section class="card filter-card" id="filterCard">
  <form id="dashFilter" class="filters" method="get" action="<?= e(url('/')) ?>">
    <div class="field">
      <label for="fFrom">Dari tanggal</label>
      <input class="input" type="date" id="fFrom" name="from" value="<?= e($f['from'] ?? '') ?>" <?= $f['period'] === 'all' ? 'disabled' : '' ?>>
    </div>
    <div class="field">
      <label for="fTo">Sampai tanggal</label>
      <input class="input" type="date" id="fTo" name="to" value="<?= e($f['to'] ?? '') ?>" <?= $f['period'] === 'all' ? 'disabled' : '' ?>>
    </div>
    <div class="field">
      <label for="fAcc">Rekening</label>
      <select class="input" id="fAcc" name="account">
        <option value="">Semua rekening</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= (int) $a['id'] ?>" <?= (int) ($f['account'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="flex:0 0 auto">
      <button class="btn" type="submit" data-no-lock>Terapkan</button>
    </div>
  </form>
  <div class="filter-foot">
    <div class="presets" id="presets">
      <button type="button" class="btn btn-ghost btn-sm" data-preset="year">Tahun data terakhir</button>
      <button type="button" class="btn btn-ghost btn-sm" data-preset="m3">3 bulan terakhir</button>
      <button type="button" class="btn btn-ghost btn-sm" data-preset="m1">Bulan terakhir</button>
      <button type="button" class="btn btn-ghost btn-sm" data-preset="all">Semua data</button>
    </div>
    <p class="help mb-0" id="rangeInfo" data-first="<?= e($range['first'] ?? '') ?>" data-last="<?= e($range['last'] ?? '') ?>">
      <?php if ($range['last']): ?>Data tercatat <?= e(fmt_date($range['first'])) ?> – <?= e(fmt_date($range['last'])) ?>.<?php else: ?>Belum ada transaksi. Super Admin dapat mengimpor Excel atau menambah transaksi.<?php endif; ?>
    </p>
  </div>
</section>

<section class="grid g-4" id="accCards" aria-label="Rekening Perusahaan">
  <?php for ($i = 0; $i < 4; $i++): ?><div class="acc-card"><div class="skeleton" style="height:118px;border-radius:0"></div></div><?php endfor; ?>
</section>

<section class="grid g-4" id="kpis" aria-label="Ringkasan periode">
  <?php for ($i = 0; $i < 4; $i++): ?><div class="card"><div class="skeleton" style="height:58px"></div></div><?php endfor; ?>
</section>

<section class="card" id="recon" hidden></section>

<section class="grid g-main">
  <div class="card">
    <div class="card-head"><h2>Mutasi masuk vs mutasi keluar usaha</h2><span class="muted" style="font-size:12.5px">per bulan</span></div>
    <div class="chart" id="chBars"><div class="skeleton" style="height:260px"></div></div>
    <div class="legend"><span><i style="background:#0b5cff"></i>Mutasi masuk</span><span><i style="background:#f07f7f"></i>Mutasi keluar</span></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Limit fasilitas</h2><a href="<?= e(url('/accounts')) ?>" style="font-size:13px">Detail</a></div>
    <div class="limit-row" id="limits"><div class="skeleton" style="height:150px"></div></div>
  </div>
</section>

<section class="grid g-main-r">
  <div class="card">
    <div class="card-head"><h2>Komposisi pengeluaran usaha</h2></div>
    <div class="donut-wrap" id="donutWrap"><div class="skeleton" style="height:190px;width:190px;border-radius:50%"></div></div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Saldo dari waktu ke waktu</h2><span class="muted" style="font-size:12.5px">akhir bulan</span></div>
    <div class="chart" id="chArea"><div class="skeleton" style="height:240px"></div></div>
    <div class="legend"><span><i style="background:#0b5cff"></i>Saldo rekening bank</span><span><i style="background:#f57c00"></i>Posisi bersih (setelah pemakaian fasilitas)</span></div>
  </div>
</section>

<section class="card card-flush">
  <div class="card-head"><h2>Transaksi terbaru</h2><a href="<?= e(url('/transactions')) ?>" style="font-size:13px">Lihat semua</a></div>
  <div class="tx-list" id="recent" style="padding:0 18px 10px"><div class="skeleton" style="height:120px"></div></div>
</section>
