<?php /** @var array $p @var string $token @var string $name @var ?int $dupBatch @var int $activeImported */
$errors = array_filter($p['issues'], static fn($i) => $i['level'] === 'error');
$warns = array_filter($p['issues'], static fn($i) => $i['level'] === 'warn');
$canCommit = $p['errors'] === 0 && !$dupBatch && $activeImported === 0;
?>
<section class="card">
  <div class="card-head"><h2>Ringkasan per rekening</h2>
    <?php if ($p['errors'] === 0): ?><span class="pill pill-green">Saldo cocok dengan Excel</span><?php else: ?><span class="pill pill-red"><?= (int) $p['errors'] ?> kesalahan</span><?php endif; ?></div>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Rekening</th><th>Sheet</th><th class="r">Baris</th><th class="r">Total mutasi masuk</th><th class="r">Total mutasi keluar</th><th class="r">Saldo awal</th><th class="r">Saldo akhir (hitung)</th><th class="r">Saldo akhir (Excel)</th><th>Cocok</th></tr></thead>
    <tbody>
    <?php foreach ($p['stats'] as $code => $s): ?>
      <tr><td><b><?= e($code) ?></b></td><td><?= e($s['sheet']) ?></td><td class="r num"><?= number_format($s['rows'], 0, ',', '.') ?></td>
        <td class="r num"><?= e(money($s['sum_debit'], false)) ?></td><td class="r num"><?= e(money($s['sum_credit'], false)) ?></td><td class="r num"><?= e(money($s['opening'], false)) ?></td>
        <td class="r num"><?= e(money($s['calc_final'], false)) ?></td><td class="r num"><?= $s['excel_final'] === null ? 'n/a' : e(money($s['excel_final'], false)) ?></td>
        <td><?= $s['match'] ? '<span class="pill pill-green">Ya</span>' : '<span class="pill pill-red">Tidak</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <p class="help mt-1 mb-0">Juga: <?= count($p['forecast']) ?> item proyeksi, <?= count($p['invoices']) ?> invoice. Tidak diimpor: <?= e(implode(' · ', $p['skipped'])) ?>.</p>
</section>

<?php if ($errors): ?>
<section class="card"><div class="card-head"><h2>Kesalahan (harus diperbaiki)</h2></div>
  <?php foreach ($errors as $i): ?><div class="alert alert-danger mb-1"><?= e($i['sheet']) ?> baris <?= (int) $i['row'] ?>: <?= e($i['msg']) ?></div><?php endforeach; ?></section>
<?php endif; ?>

<?php if ($warns): ?>
<section class="card"><div class="card-head"><h2>Peringatan (<?= count($warns) ?>) — data tetap diimpor & ditandai “Perlu ditinjau”</h2></div>
  <div class="table-wrap"><table class="tbl"><thead><tr><th>Sheet</th><th>Baris</th><th>Keterangan</th></tr></thead><tbody>
    <?php foreach ($warns as $i): ?><tr><td><?= e($i['sheet']) ?></td><td class="num"><?= (int) $i['row'] ?: '-' ?></td><td><?= e($i['msg']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section>
<?php endif; ?>

<section class="card card-flush">
  <div class="card-head"><h2>Pemetaan kategori (<?= count($p['category_map']) ?> teks Excel)</h2></div>
  <div class="table-wrap" style="max-height:420px"><table class="tbl"><thead><tr><th>Teks Excel</th><th class="r">Baris</th><th>Kategori</th><th>Jenis</th><th>Proyek</th></tr></thead><tbody>
    <?php foreach ($p['category_map'] as $raw => $m): ?>
      <tr><td><?= $raw === '' ? '<span class="muted">(kosong)</span>' : e($raw) ?></td><td class="r num"><?= (int) $m['count'] ?></td><td><?= e($m['category']) ?></td><td><?= e($m['kind']) ?></td><td><?= e($m['project'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
</section>

<section class="card">
  <?php if ($dupBatch): ?><div class="alert alert-warn mb-2">Berkas yang sama sudah pernah diimpor (batch #<?= (int) $dupBatch ?>). Batalkan batch tersebut jika ingin mengimpor ulang.</div><?php endif; ?>
  <?php if ($activeImported > 0 && !$dupBatch): ?><div class="alert alert-warn mb-2">Masih ada data hasil impor sebelumnya yang aktif. Batalkan (void) batch lama terlebih dahulu agar data tidak ganda.</div><?php endif; ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <form method="post" action="<?= e(url('/import/commit')) ?>" data-confirm="Simpan <?= count($p['transactions']) ?> transaksi ke database? Seluruh impor dijalankan dalam satu transaksi; bila gagal tidak ada yang tersimpan." data-confirm-title="Konfirmasi impor" data-confirm-ok="Ya, impor sekarang">
      <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <button class="btn" type="submit" data-no-lock <?= $canCommit ? '' : 'disabled' ?>>Konfirmasi impor</button>
    </form>
    <form method="post" action="<?= e(url('/import/cancel')) ?>"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>"><button class="btn btn-ghost" type="submit">Batal</button></form>
  </div>
</section>
