<?php
/** @var string $token @var array $meta @var array $p @var array $c @var array $cat @var array $sum @var ?array $acc @var array $fields @var ?int $dupBatch */
$kind = $p['kind'];
$isTx = $kind === 'transactions';
$errRows = array_values(array_filter($p['rows'], static fn($r) => $r['st'] === 'error'));
$skipRows = array_values(array_filter($p['rows'], static fn($r) => $r['st'] === 'skip'));
$dupRows = array_values(array_filter($p['rows'], static fn($r) => $r['st'] === 'ok' && $r['cl'] === 'dup'));
$revRows = array_values(array_filter($p['rows'], static fn($r) => $r['st'] === 'ok' && $r['cl'] === 'review'));
$newRows = array_values(array_filter($p['rows'], static fn($r) => $r['st'] === 'ok' && $r['cl'] === 'new'));
$canGo = $c['ok'] > 0;
$kindName = \App\Services\Import\ImportFields::KINDS[$kind];
$desc = static function (array $r) use ($isTx): string {
    $d = $r['d'];
    return $isTx ? ($d['date'] . ' · ' . ($d['voucher'] !== '' ? $d['voucher'] . ' · ' : '') . mb_substr($d['desc'], 0, 80)) : ($d['supplier'] . ' · ' . mb_substr($d['description'], 0, 80));
};
$amt = static function (array $r) use ($isTx): string {
    $d = $r['d'];
    if (!$isTx) {
        return money($d['amount'], false);
    }
    return (bccomp($d['debit'], '0', 2) !== 0 ? '+' . money($d['debit'], false) : '') . (bccomp($d['credit'], '0', 2) !== 0 ? ' −' . money($d['credit'], false) : '');
};
?>
<section class="card">
  <div class="card-head"><h2>Ringkasan pratinjau — <?= e($kindName) ?></h2>
    <?php if ($c['error'] === 0 && $c['review'] === 0): ?><span class="pill pill-green">Siap diimpor</span>
    <?php else: ?><span class="pill pill-yellow">Perlu perhatian Anda</span><?php endif; ?></div>
  <div class="grid g-4">
    <div class="card stat"><div class="k">Total baris diproses</div><div class="v num"><?= number_format($c['total'], 0, ',', '.') ?></div><div class="d">sheet “<?= e(trim($p['sheet'])) ?>”<?= $acc ? ' → ' . e($acc['code']) : '' ?></div></div>
    <div class="card stat"><div class="k">Data valid</div><div class="v num pos"><?= number_format($c['ok'], 0, ',', '.') ?></div><div class="d"><?= number_format($c['new'], 0, ',', '.') ?> baru</div></div>
    <div class="card stat"><div class="k">Data bermasalah</div><div class="v num <?= $c['error'] ? 'neg' : '' ?>"><?= number_format($c['error'], 0, ',', '.') ?></div><div class="d">tidak akan diimpor</div></div>
    <div class="card stat"><div class="k">Kemungkinan duplikasi</div><div class="v num"><?= number_format($c['dup'] + $c['review'], 0, ',', '.') ?></div><div class="d"><?= (int) $c['dup'] ?> pasti sama · <?= (int) $c['review'] ?> perlu diperiksa</div></div>
  </div>
  <p class="help mt-1 mb-0"><?= (int) $c['skip'] ?> baris dilewati (judul/kosong/tanpa nominal).
    <?php if ($isTx): ?>Bila semua baris baru diimpor: Mutasi Masuk <b class="pos"><?= e(money($sum['in'])) ?></b>, Mutasi Keluar <b class="neg"><?= e(money($sum['out'])) ?></b>, perubahan saldo rekening <b class="num"><?= e(money(bcsub($sum['in'], $sum['out'], 2))) ?></b> (rekening bertipe pinjaman dihitung terbalik oleh sistem).
    <?php else: ?>Total hutang baru <b class="num"><?= e(money($sum['amount'])) ?></b>. Hutang tidak membuat transaksi/mutasi, jadi saldo & laporan tidak berubah.<?php endif; ?></p>
  <?php if ($dupBatch): ?><div class="alert alert-warn mt-2">Berkas dengan isi yang sama pernah diimpor (batch #<?= (int) $dupBatch ?>). Baris yang sudah ada akan terdeteksi sebagai duplikat dan tidak dimasukkan dua kali.</div><?php endif; ?>
</section>

<section class="card card-flush">
  <div class="card-head"><h2>Kolom yang berhasil dikenali</h2></div>
  <div class="table-wrap"><table class="tbl stack"><thead><tr><th>Kolom Excel</th><th>Header</th><th>Field sistem</th></tr></thead><tbody>
    <?php foreach ($p['mapping'] as $col => $field): ?>
      <tr><td data-label="Kolom"><b><?= e($col) ?></b></td><td data-label="Header"><?= e($p['headers'][$col] ?? '') ?: '<span class="muted">(tanpa header)</span>' ?></td><td data-label="Field"><?= e($fields[$field]['label'] ?? $field) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php if (!empty($p['ignored_cols'])): ?>
    <div class="alert alert-warn" style="margin:12px 22px 16px">Kolom diabaikan atas konfirmasi Anda:
      <?php $o = []; foreach ($p['ignored_cols'] as $col => $i) { $o[] = e(($i['header'] !== '' ? $i['header'] : 'Kolom ' . $col) . ' (' . $i['filled'] . ' sel)'); } echo implode(', ', $o); ?>.</div>
  <?php endif; ?>
</section>

<?php if ($p['warnings'] || $cat): ?>
<section class="card">
  <div class="card-head"><h2>Peringatan</h2></div>
  <?php foreach ($p['warnings'] as $w): ?><div class="alert alert-info mb-1"><?= e($w) ?></div><?php endforeach; ?>
  <?php if ($cat): ?><div class="alert alert-warn mb-1">Teks kategori yang belum dikenali akan masuk ke “Belum Dikategorikan” (dapat dipetakan di Master Data):
    <?php $o = []; foreach (array_slice($cat, 0, 12, true) as $raw => $n) { $o[] = e(($raw === '' ? '(kosong)' : $raw) . ' ×' . $n); } echo implode(' · ', $o) . (count($cat) > 12 ? ' · …' : ''); ?></div><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($newRows): ?>
<section class="card card-flush">
  <div class="card-head"><h2>Contoh hasil pemetaan (<?= min(8, count($newRows)) ?> dari <?= number_format(count($newRows), 0, ',', '.') ?> baris baru)</h2></div>
  <div class="table-wrap"><table class="tbl stack"><thead><tr><th>Baris Excel</th><?php if ($isTx): ?><th>Tanggal</th><th>Kode</th><th>Pihak</th><th>Kategori</th><th>Keterangan</th><th class="r">Mutasi Masuk</th><th class="r">Mutasi Keluar</th><?php else: ?><th>Jenis</th><th>Supplier</th><th>Keterangan</th><th>Jatuh tempo</th><th>Rekening sumber</th><th class="r">Nominal</th><th class="r">Dibayar</th><?php endif; ?></tr></thead><tbody>
    <?php foreach (array_slice($newRows, 0, 8) as $r): $d = $r['d']; ?>
      <tr><td data-label="Baris"><?= (int) $r['r'] ?></td>
      <?php if ($isTx): ?>
        <td data-label="Tanggal" class="nowrap"><?= e(fmt_date($d['date'])) ?></td><td data-label="Kode"><?= e($d['voucher']) ?></td><td data-label="Pihak"><?= e(mb_substr($d['party'], 0, 40)) ?></td><td data-label="Kategori"><?= e($d['cat_raw']) ?></td><td data-label="Keterangan" class="desc"><?= e(mb_substr($d['desc'], 0, 70)) ?></td>
        <td data-label="Mutasi Masuk" class="r num pos"><?= bccomp($d['debit'], '0', 2) !== 0 ? e(money($d['debit'], false)) : '' ?></td><td data-label="Mutasi Keluar" class="r num neg"><?= bccomp($d['credit'], '0', 2) !== 0 ? e(money($d['credit'], false)) : '' ?></td>
      <?php else: ?>
        <td data-label="Jenis"><?= e(\App\Services\DebtService::CATEGORIES[$d['category'] ?? 'usaha'] ?? 'Hutang Usaha') ?></td><td data-label="Supplier"><b><?= e($d['supplier']) ?></b></td><td data-label="Keterangan" class="desc"><?= e(mb_substr($d['description'], 0, 80)) ?></td><td data-label="Jatuh tempo" class="nowrap"><?= $d['due_date'] ? e(fmt_date($d['due_date'])) : '<span class="muted">-</span>' ?></td><td data-label="Rekening"><?= e($d['source_account']) ?></td><td data-label="Nominal" class="r num"><?= e(money($d['amount'], false)) ?></td><td data-label="Dibayar" class="r num"><?= e(money($d['paid'], false)) ?></td>
      <?php endif; ?></tr>
    <?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>

<?php if ($errRows): ?>
<section class="card card-flush">
  <div class="card-head"><h2>Baris bermasalah (<?= number_format(count($errRows), 0, ',', '.') ?>) — tidak akan diimpor</h2></div>
  <div class="table-wrap" style="max-height:340px"><table class="tbl stack"><thead><tr><th>Baris</th><th>Masalah</th><th>Isi</th></tr></thead><tbody>
    <?php foreach (array_slice($errRows, 0, 100) as $r): ?>
      <tr><td data-label="Baris" class="num"><?= (int) $r['r'] ?></td><td data-label="Masalah"><?= e(implode('; ', $r['err'])) ?></td><td data-label="Isi" class="desc"><span class="sub"><?= e($r['raw'] ?? '') ?></span></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php if (count($errRows) > 100): ?><p class="help" style="padding:0 22px 12px;margin:0">100 pertama ditampilkan; daftar lengkap tersedia di laporan setelah impor (dapat diunduh).</p><?php endif; ?>
</section>
<?php endif; ?>

<form method="post" action="<?= e(url('/import/run')) ?>" id="runForm" data-confirm="Simpan data ke database sekarang? Seluruh impor dijalankan dalam satu transaksi; bila ada kegagalan, tidak ada yang tersimpan." data-confirm-title="Konfirmasi impor" data-confirm-ok="Ya, impor sekarang">
  <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">

  <?php if ($dupRows): ?>
  <section class="card card-flush">
    <div class="card-head"><h2>Duplikat pasti (<?= number_format(count($dupRows), 0, ',', '.') ?>) — sudah ada di sistem</h2></div>
    <div class="table-wrap" style="max-height:260px"><table class="tbl stack"><thead><tr><th>Baris</th><th>Data</th><th class="r">Nominal</th><th>Alasan</th></tr></thead><tbody>
      <?php foreach (array_slice($dupRows, 0, 40) as $r): ?>
        <tr><td data-label="Baris" class="num"><?= (int) $r['r'] ?></td><td data-label="Data" class="desc"><?= e($desc($r)) ?></td><td data-label="Nominal" class="r num"><?= e($amt($r)) ?></td><td data-label="Alasan"><span class="sub"><?= e($r['why']) ?></span></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php if (count($dupRows) > 40): ?><p class="help" style="padding:0 22px 12px;margin:0">40 pertama ditampilkan.</p><?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($revRows): ?>
  <section class="card card-flush">
    <div class="card-head"><h2>Perlu Pemeriksaan (<?= number_format(count($revRows), 0, ',', '.') ?>)</h2><span class="muted">identitas tidak cukup kuat untuk memastikan duplikat</span></div>
    <div style="padding:0 22px 12px">
      <p class="help">Kesamaan nominal saja tidak dianggap duplikat. Putuskan setiap baris, atau gunakan keputusan massal:</p>
      <div style="display:flex;gap:18px;flex-wrap:wrap" role="radiogroup" aria-label="Keputusan massal">
        <label class="check"><input type="radio" name="bulk" value="skip" checked>Lewati semua</label>
        <label class="check"><input type="radio" name="bulk" value="import">Impor semua sebagai data baru</label>
        <label class="check"><input type="radio" name="bulk" value="each">Putuskan satu per satu (wajib)</label>
      </div>
    </div>
    <div class="table-wrap" style="max-height:420px"><table class="tbl stack"><thead><tr><th>Baris</th><th>Data</th><th class="r">Nominal</th><th>Alasan</th><th>Keputusan</th></tr></thead><tbody>
      <?php foreach (array_slice($revRows, 0, 200) as $r): ?>
        <tr><td data-label="Baris" class="num"><?= (int) $r['r'] ?></td><td data-label="Data" class="desc"><?= e($desc($r)) ?></td><td data-label="Nominal" class="r num"><?= e($amt($r)) ?></td><td data-label="Alasan"><span class="sub"><?= e($r['why']) ?></span></td>
          <td data-label="Keputusan"><select class="input" name="dec[<?= (int) $r['r'] ?>]" aria-label="Keputusan baris <?= (int) $r['r'] ?>"><option value="">(ikuti keputusan massal)</option><option value="skip">Lewati</option><option value="import">Impor sebagai baru</option></select></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php if (count($revRows) > 200): ?><p class="help" style="padding:0 22px 12px;margin:0">200 pertama dapat diputuskan per baris; sisanya mengikuti keputusan massal (mode “satu per satu” tidak tersedia bila lebih dari 200 baris — pilih lewati/impor semua).</p><?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="card-head"><h2>Konfirmasi impor</h2></div>
    <p class="help mb-1"><b>Kebijakan data yang sudah ada</b></p>
    <div style="display:grid;gap:8px" role="radiogroup" aria-label="Kebijakan impor">
      <label class="check"><input type="radio" name="policy" value="skip" checked><span><b>Tambah data baru saja</b> — baris yang sudah ada dilewati.</span></label>
      <label class="check"><input type="radio" name="policy" value="update"><span><b>Perbarui data yang cocok</b> — hanya untuk kecocokan pasti (<?= $isTx ? 'ada kode bukti' : 'ada nomor dokumen' ?>); hanya kolom deskriptif (<?= $isTx ? 'pihak, bank, catatan, referensi, kategori' : 'keterangan, rekening, catatan, tanggal' ?>) yang berubah — tanggal, nominal, dan rekening tidak pernah diubah.</span></label>
      <label class="check"><input type="radio" name="policy" value="review"><span><b>Periksa terlebih dahulu</b> — semua baris “Perlu Pemeriksaan” wajib Anda putuskan sebelum bisa disimpan.</span></label>
    </div>
    <?php if ($c['error'] > 0): ?>
      <label class="check mt-2"><input type="checkbox" name="ack_errors" value="1" required>Saya mengerti <?= number_format($c['error'], 0, ',', '.') ?> baris bermasalah tidak akan diimpor.</label>
    <?php endif; ?>
    <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn" type="submit" data-no-lock <?= $canGo ? '' : 'disabled' ?>>Konfirmasi & impor</button>
      <a class="btn btn-ghost" href="<?= e(url('/import/map?t=' . $token . '&sheet=' . rawurlencode($p['sheet']) . '&kind=' . $kind . '&hdr=' . (int) $p['header_row'])) ?>">Ubah pemetaan</a>
      <button class="btn btn-ghost" type="submit" formaction="<?= e(url('/import/abort')) ?>" formnovalidate data-no-lock data-confirm="Batalkan impor ini dan hapus berkas sementara?">Batalkan</button>
    </div>
    <?php if (!$canGo): ?><p class="help mt-1 mb-0">Tidak ada baris valid untuk diimpor.</p><?php endif; ?>
  </section>
</form>
