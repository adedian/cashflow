<?php /** @var array $b @var array $st @var array $fields */
$c = $st['counts'];
$kind = $st['kind'];
$rows = $st['rows'] ?? [];
$notIn = array_values(array_filter($rows, static fn($r) => !str_starts_with((string) $r['status'], 'diimpor') && $r['status'] !== 'diperbarui'));
$pill = ['gagal' => 'pill-red', 'duplikat' => 'pill-yellow', 'dilewati' => 'pill-gray', 'perlu pemeriksaan (dilewati)' => 'pill-yellow', 'diperbarui' => 'pill-blue'];
?>
<section class="card">
  <div class="card-head"><h2>Hasil impor <?= e(\App\Services\Import\ImportFields::KINDS[$kind] ?? $kind) ?></h2>
    <span class="pill <?= $b['status'] === 'imported' ? 'pill-green' : 'pill-gray' ?>"><?= $b['status'] === 'imported' ? 'Tersimpan' : 'Batch dibatalkan' ?></span></div>
  <div class="grid g-4">
    <div class="card stat"><div class="k">Berhasil diimpor</div><div class="v num pos"><?= number_format($c['inserted'], 0, ',', '.') ?></div><div class="d"><?= (int) $c['updated'] ?> diperbarui<?= (int) ($c['review_imported'] ?? 0) ? ' · ' . (int) $c['review_imported'] . ' hasil pemeriksaan' : '' ?></div></div>
    <div class="card stat"><div class="k">Dilewati</div><div class="v num"><?= number_format($c['blank'] + $c['dup_skipped'] + $c['review_skipped'], 0, ',', '.') ?></div><div class="d"><?= (int) $c['blank'] ?> judul/kosong · <?= (int) $c['review_skipped'] ?> pemeriksaan</div></div>
    <div class="card stat"><div class="k">Duplikasi ditemukan</div><div class="v num"><?= number_format($c['dup_found'] + $c['review_found'], 0, ',', '.') ?></div><div class="d"><?= (int) $c['dup_found'] ?> pasti · <?= (int) $c['review_found'] ?> perlu diperiksa</div></div>
    <div class="card stat"><div class="k">Gagal (tidak disimpan)</div><div class="v num <?= $c['failed'] ? 'neg' : '' ?>"><?= number_format($c['failed'], 0, ',', '.') ?></div></div>
  </div>
  <dl class="kv mt-2">
    <dt>Berkas</dt><dd><?= e($b['filename']) ?> <span class="muted">· SHA-256 <?= e(substr($b['sha256'], 0, 16)) ?>…</span></dd>
    <dt>Sheet / header</dt><dd><?= e(trim((string) ($st['sheet'] ?? ''))) ?> · baris header <?= (int) ($st['header_row'] ?? 0) ?></dd>
    <dt>Diimpor oleh</dt><dd><?= e($b['full_name'] ?? '-') ?> · <?= e(fmt_date($b['created_at'], 'd/m/Y H:i')) ?></dd>
    <dt>Kebijakan</dt><dd><?= e(['skip' => 'Tambah data baru saja', 'update' => 'Perbarui data yang cocok', 'review' => 'Periksa terlebih dahulu'][$st['policy'] ?? 'skip'] ?? '-') ?></dd>
    <?php if ($kind === 'debts'): ?><dt>Terkait</dt><dd><?= (int) $c['linked'] ?> hutang ditautkan ke item Proyeksi yang sama · <?= (int) $c['payments'] ?> pembayaran awal dicatat</dd><?php endif; ?>
    <?php if (!empty($c['categories_new'])): ?><dt>Kategori baru</dt><dd><?= (int) $c['categories_new'] ?> kategori dibuat dari teks Excel</dd><?php endif; ?>
  </dl>
  <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap">
    <?php if ($notIn): ?><a class="btn" href="<?= e(url('/import/' . (int) $b['id'] . '/errors')) ?>"><svg class="i i-sm"><use href="#i-down"/></svg>Unduh laporan kesalahan (CSV)</a><?php endif; ?>
    <a class="btn btn-ghost" href="<?= e(url($kind === 'debts' ? '/debts?category=' . ($st['debt_category'] ?? 'usaha') : '/transactions')) ?>">Lihat <?= $kind === 'debts' ? 'Hutang' : 'Transaksi' ?></a>
    <a class="btn btn-ghost" href="<?= e(url('/import')) ?>">Impor lagi</a>
  </div>
</section>

<?php if ($notIn): ?>
<section class="card card-flush">
  <div class="card-head"><h2>Baris yang tidak masuk (<?= number_format(count($notIn), 0, ',', '.') ?>)</h2></div>
  <div class="table-wrap" style="max-height:480px"><table class="tbl stack"><thead><tr><th>Baris</th><th>Status</th><th>Alasan</th></tr></thead><tbody>
    <?php foreach (array_slice($notIn, 0, 300) as $r): ?>
      <tr><td data-label="Baris" class="num"><?= (int) $r['r'] ?></td><td data-label="Status"><span class="pill <?= $pill[$r['status']] ?? 'pill-gray' ?>"><?= e($r['status']) ?></span></td><td data-label="Alasan"><?= e($r['reason']) ?><?php if (!empty($r['raw'])): ?><div class="sub"><?= e($r['raw']) ?></div><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php if (count($notIn) > 300): ?><p class="help" style="padding:0 22px 12px;margin:0">300 pertama ditampilkan; unduh CSV untuk daftar lengkap<?= count($rows) >= 5000 ? ' (laporan dibatasi 5.000 baris)' : '' ?>.</p><?php endif; ?>
</section>
<?php endif; ?>
