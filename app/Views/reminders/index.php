<?php /** @var string $cat @var int $days */ ?>
<section class="card rem" id="remPage" data-cat="<?= e($cat) ?>" aria-live="polite">
  <div class="rem-head">
    <h2 id="remPageTitle"><?= $cat === '' ? 'Pengingat per kategori' : 'Detail kategori' ?></h2><span class="rem-count" id="remCount" hidden>0</span>
    <span class="muted rem-meta" id="remMeta"></span>
  </div>
  <div id="remBody"><div class="skeleton" style="height:140px"></div></div>
  <p class="help mb-0 mt-1">Menampilkan tagihan yang jatuh tempo dalam <?= (int) $days ?> hari ke depan ditambah semua yang sudah terlambat. Sumber: Hutang (nominal = sisa) dan item Proyeksi pengeluaran yang belum tertaut ke hutang. Data diperbarui otomatis saat Hutang atau Proyeksi berubah.</p>
</section>
