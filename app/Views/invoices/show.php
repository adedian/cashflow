<?php /** @var array $r @var ?array $link @var ?array $fc @var array $cand @var array $pays @var ?array $proof @var bool $isAdmin @var string $today */
$id = (int) $r['id'];
$lampu = $r['kind'] === 'lampu';
$known = (int) $r['paid_known'] === 1;
$rem = bcsub((string) $r['amount'], (string) $r['paid'], 2);
$open = bccomp($rem, '0.004', 3) > 0;
if (!$known) {
    $stPill = '<span class="pill pill-yellow">Perlu konfirmasi</span>';
} elseif (!$open) {
    $stPill = '<span class="pill pill-green">Lunas</span>';
} else {
    $stPill = bccomp((string) $r['paid'], '0', 2) > 0 ? '<span class="pill pill-yellow">Dibayar sebagian</span>' : '<span class="pill pill-red">Belum dibayar</span>';
    if ($r['due_date'] && $r['due_date'] < $today) { $stPill .= ' <span class="pill pill-red">Lewat jatuh tempo ' . -\App\Services\ReminderService::daysLeft($r['due_date'], $today) . ' hari</span>'; }
}
?>
<section class="card" style="max-width:900px" id="invDetail" data-id="<?= $id ?>">
  <div class="card-head"><h2><?= e($r['customer']) ?></h2><div><?= $stPill ?></div></div>
  <?php if (!$known): ?>
    <div class="alert alert-warn mb-2">Pembayaran untuk baris ini <b>tidak terbaca</b> di Excel sumber (kolom berisi galat <code>#REF!</code>). Sistem tidak menebak: baris ini tidak dihitung lunas maupun belum dibayar. <?= $isAdmin ? 'Isi pembayaran yang benar lewat “Ubah” dan centang “Pembayaran sudah dipastikan”.' : 'Super Admin dapat mengonfirmasinya.' ?></div>
  <?php endif; ?>
  <dl class="kv">
    <dt>Jenis</dt><dd><?= $lampu ? 'Piutang Lampu' : 'Piutang Proyek' ?></dd>
    <dt><?= $lampu ? 'No. surat jalan' : 'No. invoice' ?></dt><dd><?= $r['invoice_no'] ? e($r['invoice_no']) : '<span class="muted">Tidak tersedia</span>' ?></dd>
    <dt>Tanggal</dt><dd><?= $r['invoice_date'] ? e(fmt_date($r['invoice_date'], 'd/m/Y')) : '<span class="muted">Tidak tersedia</span>' ?></dd>
    <?php if ($lampu): ?><dt>Jatuh tempo</dt><dd><?= $r['due_date'] ? e(fmt_date($r['due_date'], 'd/m/Y')) : ($r['due_note'] ? e($r['due_note']) : '<span class="muted">-</span>') ?><?= $r['due_days'] !== null ? ' <span class="muted">(' . (int) $r['due_days'] . ' hari dari tgl. nota)</span>' : '' ?></dd>
    <?php if ($r['salesperson']): ?><dt>Sales</dt><dd><?= e($r['salesperson']) ?></dd><?php endif; ?><?php endif; ?>
    <?php if ($r['project_name']): ?><dt>Proyek</dt><dd><?= e($r['project_name']) ?></dd><?php endif; ?>
    <?php if ($r['description']): ?><dt>Keterangan</dt><dd><?= e($r['description']) ?></dd><?php endif; ?>
    <dt><?= $lampu ? 'Total piutang' : 'Tagihan' ?></dt><dd class="num"><b><?= e(money($r['amount'])) ?></b></dd>
    <dt>Sudah dibayar</dt><dd class="num pos"><?= $known ? e(money($r['paid'])) : '<span class="muted">tidak diketahui</span>' ?><?= $r['paid_date'] ? ' <span class="muted">· ' . e(fmt_date($r['paid_date'], 'd/m/Y')) . '</span>' : '' ?></dd>
    <dt>Sisa</dt><dd class="num"><?= $known ? '<b class="' . ($open ? 'neg' : 'pos') . '">' . e(money($open ? $rem : '0')) . '</b>' : '<span class="muted">tidak diketahui</span>' ?></dd>
    <?php if ($r['note']): ?><dt><?= $lampu ? 'Keterangan' : 'Catatan' ?></dt><dd><?= e($r['note']) ?></dd><?php endif; ?>
    <?php if ($r['source_row']): ?><dt>Asal data</dt><dd>Excel baris <?= (int) $r['source_row'] ?><?= $r['import_batch_id'] ? ' · impor batch #' . (int) $r['import_batch_id'] : '' ?></dd><?php endif; ?>
  </dl>
  <?php $its = $lampu ? \App\Controllers\InvoiceController::itemsOf($r) : []; if ($its): ?>
  <h3 class="mt-3 mb-1" style="font-size:15px">Uraian barang</h3>
  <div class="table-wrap"><table class="tbl stack">
    <thead><tr><th>No</th><th>Nama barang</th><th class="r">Qty</th><th class="r">Harga satuan</th><th class="r">Jumlah</th></tr></thead>
    <tbody>
    <?php foreach ($its as $i => $it): ?>
      <tr><td data-label="No"><?= $i + 1 ?></td><td data-label="Nama barang"><?= e($it['name']) ?></td><td data-label="Qty" class="r num"><?= e(money($it['qty'], false)) ?></td><td data-label="Harga satuan" class="r num"><?= e(money($it['price'], false)) ?></td><td data-label="Jumlah" class="r num"><?= e(money($it['subtotal'], false)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="4" class="r"><b>Total piutang</b></td><td class="r num"><b><?= e(money($r['amount'], false)) ?></b></td></tr></tfoot>
  </table></div>
  <?php elseif ($lampu): ?><p class="help mt-2 mb-0">Data lama tanpa rincian barang. <?= $isAdmin ? 'Rincian dapat diisi lewat “Ubah”.' : '' ?></p><?php endif; ?>
</section>

<section class="card card-flush mt-2" style="max-width:900px" id="invPays">
  <div class="card-head"><h2>Riwayat penerimaan</h2><?php if ($isAdmin && $known && $open): ?><a class="btn btn-sm" href="<?= e(url('/transactions/new?ref=invoice:' . $id)) ?>"><svg class="i i-sm"><use href="#i-plus"/></svg>Terima Pembayaran melalui Transaksi</a><?php endif; ?></div>
  <?php if (!$pays): ?>
    <div class="empty"><svg class="i"><use href="#i-card"/></svg><p>Belum ada penerimaan yang dicatat lewat menu Transaksi.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="tbl stack">
    <thead><tr><th>Tanggal</th><th class="r">Nominal</th><th>Transaksi (Mutasi Masuk)</th><th>Rekening</th><th>Dicatat oleh</th></tr></thead>
    <tbody>
    <?php foreach ($pays as $p): $pv = $p['tx_void'] !== null; ?>
      <tr<?= $pv ? ' class="voided"' : '' ?>>
        <td data-label="Tanggal" class="nowrap"><?= $p['pay_date'] ? e(fmt_date($p['pay_date'])) : '-' ?></td>
        <td data-label="Nominal" class="r num"><?= e(money($p['amount'], false)) ?></td>
        <td data-label="Transaksi"><a href="<?= e(url('/transactions/' . (int) $p['transaction_id'])) ?>">#<?= (int) $p['transaction_id'] ?><?= $p['tx_voucher'] ? ' · ' . e($p['tx_voucher']) : '' ?></a><?= $pv ? ' <span class="pill pill-gray">dibatalkan — tidak dihitung</span>' : '' ?></td>
        <td data-label="Rekening"><?= e($p['tx_account']) ?></td>
        <td data-label="Oleh"><?= e($p['by_name'] ?? '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
  <p class="help" style="padding:10px 16px 14px;margin:0">Penerimaan dicatat <b>satu kali</b> di menu Transaksi (Mutasi Masuk) lalu dihubungkan ke piutang ini; mendukung penerimaan bertahap. “Sudah dibayar” di atas = angka historis dari Excel + penerimaan lewat Transaksi. Untuk mengubah/membatalkan penerimaan, ubah atau void transaksinya.</p>
</section>

<?php if ($r['kind'] === 'proyek'): ?>
<section class="card card-flush mt-2" style="max-width:900px" id="invBank">
  <div class="card-head"><h2>Bukti bank (Mutasi Masuk)</h2></div>
  <?php if (!$proof): ?>
    <div class="empty"><svg class="i"><use href="#i-card"/></svg><p>Belum ada transaksi Mutasi Masuk dengan No. invoice/PO “<?= e($r['invoice_no'] ?? '-') ?>”.</p></div>
  <?php else: $diff = bcsub((string) $r['paid'], $proof['sum'], 2); ?>
  <div class="table-wrap"><table class="tbl stack">
    <thead><tr><th>Tanggal</th><th>Kode bukti</th><th>Rekening</th><th class="r">Mutasi Masuk</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($proof['list'] as $x): ?>
      <tr>
        <td data-label="Tanggal" class="nowrap"><?= e(fmt_date($x['tx_date'])) ?></td>
        <td data-label="Kode bukti"><?= e($x['voucher_code'] ?: '-') ?></td>
        <td data-label="Rekening"><?= e($x['account']) ?></td>
        <td data-label="Mutasi Masuk" class="r num"><?= e(money($x['debit'], false)) ?></td>
        <td class="nowrap"><a class="btn btn-ghost btn-sm" href="<?= e(url('/transactions/' . (int) $x['id'])) ?>">Transaksi #<?= (int) $x['id'] ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th scope="row" colspan="3">Total diterima di bank (<?= count($proof['list']) ?> transaksi)</th><td class="r num"><b><?= e(money($proof['sum'], false)) ?></b></td><td></td></tr>
      <tr><th scope="row" colspan="3">Dibayar menurut piutang</th><td class="r num"><?= e(money($r['paid'], false)) ?></td><td></td></tr>
      <tr><th scope="row" colspan="3">Selisih</th><td class="r num <?= abs((float) $diff) <= 1 ? 'pos' : 'neg' ?>"><b><?= e(money($diff, false)) ?></b></td><td><?= abs((float) $diff) <= 1 ? '<span class="pill pill-green">sesuai</span>' : '<span class="pill pill-yellow">tinjau</span>' ?></td></tr>
    </tfoot></table></div>
  <?php endif; ?>
  <p class="help" style="padding:10px 16px 14px;margin:0">Daftar ini dibaca otomatis dari transaksi Mutasi Masuk yang <b>No. invoice/PO</b>-nya sama dengan nomor invoice ini (hanya informasi; tidak mengubah “dibayar” maupun saldo). Selisih umumnya potongan pajak (mis. PPh) atau penerimaan yang belum dicatat dengan nomor invoice ini.</p>
</section>
<?php endif; ?>

<section class="card mt-2" style="max-width:900px" id="invLink">
  <div class="card-head"><h2>Keterhubungan</h2></div>
  <h3 style="margin:0 0 6px;font-size:14px">Proyeksi (penerimaan)</h3>
  <?php if ($fc): ?>
    <p class="mb-0">Tertaut ke item Proyeksi <a href="<?= e(url('/forecast/' . (int) $fc['id'])) ?>"><b>#<?= (int) $fc['id'] ?></b></a> — <?= e($fc['description']) ?>. Proyeksi mencatat <b><?= e(money($fc['amount'])) ?></b><?= $fc['expected_date'] ? ' per ' . e(fmt_date($fc['expected_date'], 'd/m/Y')) : '' ?>; status: <?= $fc['paid_at'] ? '<span class="pill pill-green">sudah diterima (otomatis dari pelunasan piutang)</span>' : '<span class="pill pill-blue">belum diterima</span>' ?>.</p>
    <?php if ($link && $link['differs']): ?><div class="alert alert-warn mt-2">Nominal Proyeksi berbeda dari sisa piutang saat ini. Samakan lewat halaman item Proyeksi bila perlu.</div><?php endif; ?>
    <?php if ($isAdmin): ?><form method="post" action="<?= e(url('/invoices/' . $id . '/unlink')) ?>" class="mt-2" data-confirm="Lepas tautan ke item Proyeksi?" data-confirm-ok="Ya, lepas"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit" data-no-lock>Lepas tautan</button></form><?php endif; ?>
  <?php elseif ($isAdmin): ?>
    <form method="post" action="<?= e(url('/invoices/' . $id . '/link')) ?>" class="filters">
      <?= csrf_field() ?>
      <div class="field grow"><label for="forecast_id">Tautkan ke item Proyeksi penerimaan</label>
        <select class="input" id="forecast_id" name="forecast_id" required><option value="">— pilih item Proyeksi —</option>
          <?php foreach ($cand as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e(($c['expected_date'] ? fmt_date($c['expected_date']) : 'tanpa tanggal') . ' · ' . money($c['amount']) . ' · ' . mb_substr($c['description'], 0, 70)) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field" style="flex:0 0 auto"><button class="btn btn-ghost" type="submit" data-no-lock <?= $cand ? '' : 'disabled' ?>>Tautkan</button></div>
    </form>
  <?php else: ?><p class="muted mb-0">Belum tertaut ke item Proyeksi.</p><?php endif; ?>

  <h3 style="margin:18px 0 6px;font-size:14px">Bukti penerimaan historis (kode bukti Excel)</h3>
  <?php if ($r['pay_tx_id']): ?>
    <p class="mb-0">Pembayaran tercatat pada transaksi <a href="<?= e(url('/transactions/' . (int) $r['pay_tx_id'])) ?>"><b>#<?= (int) $r['pay_tx_id'] ?></b> <?= e($r['tx_voucher'] ?? '') ?></a> (<?= e(fmt_date($r['tx_date'])) ?>, <?= e(money($r['tx_debit'])) ?>)<?= $r['tx_void'] ? ' <span class="pill pill-gray">transaksi dibatalkan</span>' : '' ?>.</p>
  <?php elseif ($r['pay_ref']): ?>
    <p class="mb-0">Kode bukti di Excel: <b><?= e($r['pay_ref']) ?></b> — belum cocok dengan transaksi di sistem (tanggal/nominal berbeda atau transaksinya sebelum saldo awal 2026).</p>
  <?php else: ?><p class="muted mb-0">Belum ada transaksi yang ditautkan.</p><?php endif; ?>
  <?php if ($isAdmin): ?>
    <form method="post" action="<?= e(url('/invoices/' . $id . '/tx')) ?>" class="filters mt-1">
      <?= csrf_field() ?>
      <div class="field" style="max-width:260px"><label for="tx_id">ID transaksi Mutasi Masuk (kosongkan untuk melepas)</label><input class="input num" id="tx_id" name="tx_id" inputmode="numeric" maxlength="15" value="<?= e($r['pay_tx_id'] ?? '') ?>"></div>
      <div class="field" style="flex:0 0 auto"><button class="btn btn-ghost btn-sm" type="submit" data-no-lock>Simpan tautan</button></div>
    </form>
  <?php endif; ?>
  <p class="help mt-2 mb-0">Tautan tidak membuat atau mengubah transaksi/saldo. Pelunasan piutang menandai item Proyeksi yang tertaut sebagai “diterima”.</p>
</section>

<div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;max-width:900px">
  <a class="btn btn-ghost" href="<?= e(url('/invoices?kind=' . $r['kind'])) ?>">Ke daftar Piutang</a>
  <?php if ($isAdmin): ?>
    <a class="btn btn-ghost" href="<?= e(url('/invoices/' . $id . '/edit')) ?>"><svg class="i i-sm"><use href="#i-edit"/></svg>Ubah</a>
    <form method="post" action="<?= e(url('/invoices/' . $id . '/delete')) ?>" data-confirm="Hapus piutang <?= e($r['invoice_no'] ?? '') ?> (<?= e($r['customer']) ?>)? Tindakan ini tercatat di audit log." data-confirm-ok="Ya, hapus"><?= csrf_field() ?><button class="btn btn-ghost" type="submit" data-no-lock>Hapus</button></form>
  <?php endif; ?>
</div>
