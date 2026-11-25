<?php /** @var array $t @var array $errors @var ?int $id @var string $kind @var array $parties @var array $projects @var array $items @var array $products @var string $srcIssued @var bool $derived */
$v = static fn(string $k, $d = '') => $t[$k] ?? $d;
$fe = static fn(string $k) => isset($errors[$k]) ? '<div class="err">' . e($errors[$k]) . '</div>' : '';
$lampu = $kind === 'lampu';
$issue = !$lampu ? (string) $v('issue_status', 'terbit') : 'terbit';   // terbit | belum_terbit (jenis dipilih saat tambah; berikutnya lewat "Terbitkan Invoice")
$ub = $issue !== 'terbit';   // Invoice Belum Terbit (belum_terbit | selesai)
$num = static fn($x): string => $x === '' || $x === null ? '' : (preg_match('/^\d+(\.\d+)?$/', (string) $x) ? money((string) $x, false) : (string) $x);
// Customer dari master Customer (dropdown + "Tambah"); nama lama yang belum di master tetap tampil terpilih
// Nama barang dari master Barang (dropdown + "Tambah"); nama lama yang belum di master tetap tampil terpilih. Satuan & harga tampil sebagai keterangan; harga mengisi kolom harga satuan bila masih kosong.
$productNames = array_map('mb_strtolower', array_column($products ?? [], 'name'));
$itemSelect = static function (string $sel) use ($products, $productNames): string {
    $h = '<select class="input" name="item_name[]" data-combo data-combo-create="/api/products" data-combo-create-value="name" data-combo-placeholder="Cari / tambah barang…" aria-label="Nama barang"><option value="">— pilih barang —</option>';
    if ($sel !== '' && !in_array(mb_strtolower($sel), $productNames, true)) {
        $h .= '<option value="' . e($sel) . '" selected>' . e($sel) . ' (belum di master)</option>';
    }
    foreach ($products as $p) {
        $sub = trim(($p['unit'] ?? '') . ($p['price'] !== null ? ' · Rp ' . money($p['price'], false) : ''), ' ·');
        $h .= '<option value="' . e($p['name']) . '"' . ($sub !== '' ? ' data-sub="' . e($sub) . '"' : '') . ($p['price'] !== null ? ' data-price="' . e(money($p['price'], false)) . '"' : '') . (mb_strtolower($p['name']) === mb_strtolower($sel) ? ' selected' : '') . '>' . e($p['name']) . '</option>';
    }
    return $h . '</select>';
};
$cur = trim((string) $v('customer'));
$names = array_column($parties, 'name');
ob_start(); ?>
      <div class="field full"><label for="customer">Customer <span class="req">*</span></label>
        <div class="field-pick"><select class="input" id="customer" name="customer" data-combo data-combo-create="/api/parties" data-combo-placeholder="Ketik untuk mencari customer…" required>
          <option value="">— pilih customer —</option>
          <?php if ($cur !== '' && !in_array(mb_strtolower($cur), array_map('mb_strtolower', $names), true)): ?><option value="<?= e($cur) ?>" selected><?= e($cur) ?> (belum di master)</option><?php endif; ?>
          <?php foreach ($names as $pn): ?><option value="<?= e($pn) ?>" <?= mb_strtolower($pn) === mb_strtolower($cur) ? 'selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?>
        </select></div><?= $fe('customer') ?>
        <span class="help">Tidak ada di daftar? Ketik namanya lalu pilih “Tambah”. Daftar dikelola di <a href="<?= e(url('/master?tab=parties')) ?>">Master Data → Customer</a>.</span></div>
<?php $customerField = ob_get_clean();
?>
<section class="card form-wide">
  <?php if ($errors): ?><div class="alert alert-danger mb-2" role="alert">Periksa kembali isian yang ditandai.</div><?php endif; ?>
  <form method="post" action="<?= e($id ? url('/invoices/' . $id) : url('/invoices')) ?>" novalidate<?= $lampu ? ' id="lampuForm"' : '' ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= e($kind) ?>">
    <?php if ($lampu): ?>
    <?php $legacy = $id && !$items; ?>
    <div class="form-grid">
      <div class="field"><label for="invoice_date">Tgl. nota <span class="req">*</span></label><input class="input" type="date" id="invoice_date" name="invoice_date" value="<?= e($v('invoice_date')) ?>" required><?= $fe('invoice_date') ?></div>
      <div class="field"><label for="invoice_no">No. surat jalan <span class="req">*</span></label><input class="input" id="invoice_no" name="invoice_no" maxlength="80" value="<?= e($v('invoice_no')) ?>" placeholder="mis. JL00001537" autocomplete="off"<?= $id && $v('invoice_no') === '' ? '' : ' required' ?>>
        <span class="help">Ikuti nomor dari aplikasi stok. <?= $id ? '' : 'Terisi otomatis nomor berikutnya; ubah bila berbeda. ' ?>Nomor yang sudah dipakai tidak dapat disimpan.</span><?= $fe('invoice_no') ?></div>
      <?= $customerField ?>
    </div>

    <h3 class="mt-3 mb-1" style="font-size:15px">Uraian barang</h3>
    <div class="table-wrap">
      <table class="tbl inv-items" id="itemsTbl">
        <thead><tr><th style="width:36px">No</th><th>Nama barang</th><th class="r" style="width:110px">Qty</th><th class="r" style="width:170px">Harga satuan (Rp)</th><th class="r" style="width:170px">Jumlah (Rp)</th><th style="width:56px"></th></tr></thead>
        <tbody>
        <?php $rows = $items ?: ($legacy ? [] : [['name' => '', 'qty' => '', 'price' => '']]); foreach ($rows as $i => $it): ?>
          <tr>
            <td class="ln"><?= $i + 1 ?></td>
            <td><?= $itemSelect((string) $it['name']) ?></td>
            <td><input class="input num r" name="item_qty[]" inputmode="decimal" value="<?= e($num($it['qty'])) ?>" aria-label="Qty"></td>
            <td><input class="input num r" name="item_price[]" inputmode="decimal" value="<?= e($num($it['price'])) ?>" aria-label="Harga satuan"></td>
            <td class="r num sub">-</td>
            <td><button type="button" class="icon-btn" style="background:#eef1f8;color:#3b4566" data-del-item aria-label="Hapus baris"><svg class="i i-sm"><use href="#i-x"/></svg></button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="4" class="r"><b>Total piutang</b></td><td class="r num"><b id="itemsTotal">-</b></td><td></td></tr></tfoot>
      </table>
    </div>
    <div class="mt-1" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><button type="button" class="btn btn-ghost btn-sm" id="addItem"><svg class="i i-sm"><use href="#i-plus"/></svg>Tambah barang</button><span class="help">Barang dipilih dari <a href="<?= e(url('/master?tab=products')) ?>">Master Data → Barang</a>; tidak ada? Ketik namanya lalu pilih “Tambah”. Harga satuan terisi dari master bila ada. Jumlah = Qty × Harga satuan; Total piutang = jumlah semua barang (dihitung ulang oleh server).</span></div>
    <?= $fe('items') ?>
    <?php if ($legacy): ?>
    <div class="field mt-2" id="legacyAmount" style="max-width:320px"><label for="amount">Total piutang (Rp) — data lama tanpa rincian</label><input class="input num" id="amount" name="amount" inputmode="decimal" value="<?= e($num($v('amount'))) ?>"><span class="help">Isi rincian barang di atas untuk menghitung total otomatis.</span><?= $fe('amount') ?></div>
    <?php endif; ?>

    <div class="form-grid mt-3">
      <div class="field"><label for="due_days">Jatuh tempo (hari)</label><input class="input num" id="due_days" name="due_days" inputmode="numeric" maxlength="4" value="<?= e((string) $v('due_days')) ?>" placeholder="mis. 30">
        <span class="help">Jumlah hari sejak tgl. nota<?= $v('due_note') ? ' · catatan lama: ' . e($v('due_note')) : '' ?>.</span><?= $fe('due_days') ?></div>
      <div class="field"><label for="due_date_view">Tgl. jatuh tempo</label><input class="input" id="due_date_view" value="<?= e($v('due_date') ? fmt_date($v('due_date')) : '') ?>" readonly tabindex="-1" data-saved="<?= e((string) $v('due_date')) ?>"><span class="help">Terisi otomatis: tgl. nota + jumlah hari.</span></div>
      <div class="field"><label for="pay_tx_id">Kode bukti (transaksi Mutasi Masuk)</label>
        <?php $curTx = (string) ($t['pay_tx_id'] ?? ''); ?>
        <select class="input" id="pay_tx_id" name="pay_tx_id" data-combo-placeholder="Cari kode bukti, tanggal, nominal…">
          <option value="">— belum ada pembayaran —</option>
          <?php foreach ($payTxs as $x): ?><option value="<?= (int) $x['id'] ?>" data-date="<?= e($x['tx_date']) ?>" data-amount="<?= e(money($x['debit'], false)) ?>" <?= $curTx === (string) $x['id'] ? 'selected' : '' ?>><?= e(($x['voucher_code'] ?: '#' . $x['id']) . ' · ' . fmt_date($x['tx_date']) . ' · ' . money($x['debit']) . ' · ' . ($x['counterparty'] ?: $x['account'])) ?></option><?php endforeach; ?>
        </select>
        <span class="help">Pilih transaksi penerimaannya; tgl. bayar & dibayar terisi dari transaksi itu. <?= $v('pay_ref') !== '' && $curTx === '' ? 'Kode lama dari Excel: <b>' . e($v('pay_ref')) . '</b> (belum cocok dengan transaksi). ' : '' ?>Transaksi yang sudah dipakai piutang/hutang lain tidak muncul.</span><?= $fe('pay_tx_id') ?></div>
      <div class="field"><label for="paid_date">Tgl. bayar</label><input class="input" type="date" id="paid_date" name="paid_date" value="<?= e($v('paid_date')) ?>"><?= $fe('paid_date') ?></div>
      <div class="field"><label for="paid">Dibayar (Rp) <span class="help">— total, termasuk penerimaan lewat Transaksi</span></label><input class="input num" id="paid" name="paid" inputmode="decimal" value="<?= e($num($v('paid'))) ?>"><?= $fe('paid') ?></div>
      <div class="field"><label>Sisa</label><input class="input num" id="remainView" value="" readonly tabindex="-1"></div>
      <div class="field full"><label class="check"><input type="checkbox" name="paid_known" value="1" <?= !empty($t['paid_known']) ? 'checked' : '' ?>>Pembayaran sudah dipastikan</label>
        <span class="help">Hilangkan centang bila nominal pembayaran belum pasti (status “Perlu konfirmasi”: tidak dihitung lunas maupun belum dibayar).</span></div>
      <div class="field full"><label for="note">Keterangan</label><input class="input" id="note" name="note" maxlength="255" value="<?= e($v('note')) ?>"></div>
    </div>
    <template id="itemTpl"><tr>
      <td class="ln"></td>
      <td><?= $itemSelect('') ?></td>
      <td><input class="input num r" name="item_qty[]" inputmode="decimal" aria-label="Qty"></td>
      <td><input class="input num r" name="item_price[]" inputmode="decimal" aria-label="Harga satuan"></td>
      <td class="r num sub">-</td>
      <td><button type="button" class="icon-btn" style="background:#eef1f8;color:#3b4566" data-del-item aria-label="Hapus baris"><svg class="i i-sm"><use href="#i-x"/></svg></button></td>
    </tr></template>
    <?php else: ?>
    <div class="form-grid">
      <?php if (!$id): ?>
      <div class="field full"><label>Jenis <span class="req">*</span></label>
        <div class="issue-type" style="display:flex;gap:22px;flex-wrap:wrap;padding:4px 0">
          <label class="check"><input type="radio" name="issue_status" value="terbit" <?= $ub ? '' : 'checked' ?> data-issue-radio>Invoice</label>
          <label class="check"><input type="radio" name="issue_status" value="belum_terbit" <?= $ub ? 'checked' : '' ?> data-issue-radio>Invoice Belum Terbit</label>
        </div>
        <span class="help" id="issueHelp"><?= $ub ? 'Belum diterbitkan: tanggal jatuh tempo tidak wajib, status otomatis “Belum Terbit”, tidak membuat pengingat atau item Proyeksi, dan tidak dihitung sebagai piutang. Terbitkan nanti dari daftar “Invoice Belum Terbit”.' : 'Invoice resmi: tanggal jatuh tempo wajib; masuk daftar piutang dan Proyeksi.' ?></span></div>
      <?php elseif ($ub): ?>
      <div class="field full"><div class="alert alert-info mb-0">Jenis: <b>Invoice Belum Terbit</b> — tanpa jatuh tempo dan belum dihitung sebagai piutang. Menerbitkannya (bertahap) lewat tombol <b>Terbitkan Invoice</b>.
        <?php if (bccomp($srcIssued, '0', 2) > 0): ?><br>Sudah diterbitkan <b><?= e(money($srcIssued)) ?></b>: nominal tidak boleh di bawah angka ini dan project tidak dapat diubah.<?php endif; ?></div></div>
      <?php elseif ($derived): ?>
      <div class="field full"><div class="alert alert-info mb-0">Invoice ini hasil penerbitan Invoice Belum Terbit, sehingga <b>nominal tagihan dikunci</b>. Untuk mengubahnya, hapus invoice ini (penerbitan dibatalkan, saldo kembali) lalu terbitkan ulang.</div></div>
      <?php endif; ?>
      <?= $customerField ?>
      <?php $curProj = trim((string) $v('project_name')); ?>
      <div class="field"><label for="project_name">Proyek<span class="req" id="projReq"<?= $ub ? '' : ' hidden' ?>> *</span></label>
        <div class="field-pick"><select class="input" id="project_name" name="project_name" data-combo data-combo-create="/api/projects" data-combo-create-value="name" data-combo-placeholder="Ketik untuk mencari proyek…"<?= $ub ? ' required' : '' ?>>
          <option value="">— tanpa proyek —</option>
          <?php if ($curProj !== '' && !in_array(mb_strtolower($curProj), array_map('mb_strtolower', $projects), true)): ?><option value="<?= e($curProj) ?>" selected><?= e($curProj) ?> (belum di master)</option><?php endif; ?>
          <?php foreach ($projects as $pj): ?><option value="<?= e($pj) ?>" <?= mb_strtolower($pj) === mb_strtolower($curProj) ? 'selected' : '' ?>><?= e($pj) ?></option><?php endforeach; ?>
        </select></div><?= $fe('project_name') ?>
        <span class="help">Tidak ada di daftar? Ketik namanya lalu pilih “Tambah”. Dikelola di <a href="<?= e(url('/master?tab=projects')) ?>">Master Data → Proyek</a>.</span></div>
      <div class="field"><label for="invoice_no">No. invoice</label><input class="input" id="invoice_no" name="invoice_no" maxlength="80" value="<?= e($v('invoice_no')) ?>"></div>
      <div class="field full"><label for="description">Keterangan</label><input class="input" id="description" name="description" maxlength="255" value="<?= e($v('description')) ?>"></div>
      <div class="field"><label for="invoice_date">Tanggal invoice</label><input class="input" type="date" id="invoice_date" name="invoice_date" value="<?= e($v('invoice_date')) ?>"><?= $fe('invoice_date') ?></div>
      <div class="field" id="dueField"<?= $ub ? ' hidden' : '' ?>><label for="due_date">Tgl. jatuh tempo<?= $id && !$v('due_date') ? '' : ' <span class="req">*</span>' ?></label><input class="input" type="date" id="due_date" name="due_date" value="<?= e($v('due_date')) ?>"<?= $id && !$v('due_date') ? '' : ' required' ?><?= $ub ? ' disabled' : '' ?>><?= $fe('due_date') ?>
        <span class="help">Piutang yang belum lunas otomatis muncul di Proyeksi (penerimaan) pada tanggal ini.</span></div>
      <div class="field"><label for="amount"><span id="amountLbl"><?= $ub ? 'Nominal Invoice Belum Terbit (Rp)' : 'Nominal tagihan (Rp)' ?></span> <span class="req">*</span></label><input class="input num" id="amount" name="amount" inputmode="decimal" value="<?= e($v('amount')) ?>" required<?= $derived ? ' readonly' : '' ?>><?= $fe('amount') ?></div>
      <div class="field" id="paidField"<?= $ub ? ' hidden' : '' ?>><label for="paid">Sudah dibayar (Rp) <span class="help">— total, termasuk penerimaan lewat Transaksi</span></label><input class="input num" id="paid" name="paid" inputmode="decimal" value="<?= e($v('paid')) ?>"<?= $ub ? ' disabled' : '' ?>><?= $fe('paid') ?></div>
      <div class="field full" id="knownField"<?= $ub ? ' hidden' : '' ?>><label class="check"><input type="checkbox" name="paid_known" value="1" <?= !empty($t['paid_known']) ? 'checked' : '' ?><?= $ub ? ' disabled' : '' ?>>Pembayaran sudah dipastikan</label>
        <span class="help">Hilangkan centang bila nominal pembayaran belum pasti (status “Perlu konfirmasi”: tidak dihitung lunas maupun belum dibayar).</span></div>
      <div class="field full"><label for="note">Catatan</label><input class="input" id="note" name="note" maxlength="255" value="<?= e($v('note')) ?>"></div>
    </div>
    <?php endif; ?>
    <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn" type="submit">Simpan</button><a class="btn btn-ghost" href="<?= e(url($id ? '/invoices/' . $id : '/invoices?kind=' . $kind)) ?>">Batal</a></div>
  </form>
</section>
