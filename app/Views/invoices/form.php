<?php /** @var array $t @var array $errors @var ?int $id @var string $kind @var array $parties @var array $items */
$v = static fn(string $k, $d = '') => $t[$k] ?? $d;
$fe = static fn(string $k) => isset($errors[$k]) ? '<div class="err">' . e($errors[$k]) . '</div>' : '';
$lampu = $kind === 'lampu';
$num = static fn($x): string => $x === '' || $x === null ? '' : (preg_match('/^\d+(\.\d+)?$/', (string) $x) ? money((string) $x, false) : (string) $x);
?>
<section class="card" style="max-width:<?= $lampu ? '980px' : '820px' ?>">
  <?php if ($errors): ?><div class="alert alert-danger mb-2" role="alert">Periksa kembali isian yang ditandai.</div><?php endif; ?>
  <form method="post" action="<?= e($id ? url('/invoices/' . $id) : url('/invoices')) ?>" novalidate<?= $lampu ? ' id="lampuForm"' : '' ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= e($kind) ?>">
    <?php if ($lampu): ?>
    <?php $cur = trim((string) $v('customer')); $names = array_column($parties, 'name'); $legacy = $id && !$items; ?>
    <div class="form-grid">
      <div class="field"><label for="invoice_date">Tgl. nota <span class="req">*</span></label><input class="input" type="date" id="invoice_date" name="invoice_date" value="<?= e($v('invoice_date')) ?>" required><?= $fe('invoice_date') ?></div>
      <div class="field"><label for="invoice_no">No. surat jalan <span class="req">*</span></label><input class="input" id="invoice_no" name="invoice_no" maxlength="80" value="<?= e($v('invoice_no')) ?>" placeholder="mis. JL00001537" autocomplete="off"<?= $id && $v('invoice_no') === '' ? '' : ' required' ?>>
        <span class="help">Ikuti nomor dari aplikasi stok. <?= $id ? '' : 'Terisi otomatis nomor berikutnya; ubah bila berbeda. ' ?>Nomor yang sudah dipakai tidak dapat disimpan.</span><?= $fe('invoice_no') ?></div>
      <div class="field full"><label for="customer">Customer <span class="req">*</span></label>
        <select class="input" id="customer" name="customer" data-combo data-combo-create="/api/parties" data-combo-placeholder="Ketik untuk mencari customer…" required>
          <option value="">— pilih customer —</option>
          <?php if ($cur !== '' && !in_array(mb_strtolower($cur), array_map('mb_strtolower', $names), true)): ?><option value="<?= e($cur) ?>" selected><?= e($cur) ?> (belum di master)</option><?php endif; ?>
          <?php foreach ($names as $pn): ?><option value="<?= e($pn) ?>" <?= mb_strtolower($pn) === mb_strtolower($cur) ? 'selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?>
        </select>
        <span class="help">Tidak ada di daftar? Ketik namanya lalu pilih “Tambah”. Daftar dikelola di <a href="<?= e(url('/master?tab=parties')) ?>">Master Data → Customer</a>.</span><?= $fe('customer') ?></div>
    </div>

    <h3 class="mt-3 mb-1" style="font-size:15px">Uraian barang</h3>
    <div class="table-wrap">
      <table class="tbl inv-items" id="itemsTbl">
        <thead><tr><th style="width:36px">No</th><th>Nama barang</th><th class="r" style="width:110px">Qty</th><th class="r" style="width:170px">Harga satuan (Rp)</th><th class="r" style="width:170px">Jumlah (Rp)</th><th style="width:44px"></th></tr></thead>
        <tbody>
        <?php $rows = $items ?: ($legacy ? [] : [['name' => '', 'qty' => '', 'price' => '']]); foreach ($rows as $i => $it): ?>
          <tr>
            <td class="ln"><?= $i + 1 ?></td>
            <td><input class="input" name="item_name[]" maxlength="160" value="<?= e($it['name']) ?>" aria-label="Nama barang" placeholder="mis. Lampu LED 50W"></td>
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
    <div class="mt-1" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><button type="button" class="btn btn-ghost btn-sm" id="addItem"><svg class="i i-sm"><use href="#i-plus"/></svg>Tambah barang</button><span class="help">Jumlah = Qty × Harga satuan; Total piutang = jumlah semua barang (dihitung ulang oleh server).</span></div>
    <?= $fe('items') ?>
    <?php if ($legacy): ?>
    <div class="field mt-2" id="legacyAmount" style="max-width:320px"><label for="amount">Total piutang (Rp) — data lama tanpa rincian</label><input class="input num" id="amount" name="amount" inputmode="decimal" value="<?= e($num($v('amount'))) ?>"><span class="help">Isi rincian barang di atas untuk menghitung total otomatis.</span><?= $fe('amount') ?></div>
    <?php endif; ?>

    <div class="form-grid mt-3">
      <div class="field"><label for="due_days">Jatuh tempo (hari)</label><input class="input num" id="due_days" name="due_days" inputmode="numeric" maxlength="4" value="<?= e((string) $v('due_days')) ?>" placeholder="mis. 30">
        <span class="help">Jumlah hari sejak tgl. nota<?= $v('due_note') ? ' · catatan lama: ' . e($v('due_note')) : '' ?>.</span><?= $fe('due_days') ?></div>
      <div class="field"><label for="due_date_view">Tgl. jatuh tempo</label><input class="input" id="due_date_view" value="<?= e($v('due_date') ? fmt_date($v('due_date')) : '') ?>" readonly tabindex="-1" data-saved="<?= e((string) $v('due_date')) ?>"><span class="help">Terisi otomatis: tgl. nota + jumlah hari.</span></div>
      <div class="field"><label for="paid_date">Tgl. bayar</label><input class="input" type="date" id="paid_date" name="paid_date" value="<?= e($v('paid_date')) ?>"><?= $fe('paid_date') ?></div>
      <div class="field"><label for="pay_ref">Kode bukti (mis. BM 11/02)</label><input class="input" id="pay_ref" name="pay_ref" maxlength="40" value="<?= e($v('pay_ref')) ?>"></div>
      <div class="field"><label for="paid">Dibayar (Rp) <span class="help">— total, termasuk penerimaan lewat Transaksi</span></label><input class="input num" id="paid" name="paid" inputmode="decimal" value="<?= e($num($v('paid'))) ?>"><?= $fe('paid') ?></div>
      <div class="field"><label>Sisa</label><input class="input num" id="remainView" value="" readonly tabindex="-1"></div>
      <div class="field full"><label class="check"><input type="checkbox" name="paid_known" value="1" <?= !empty($t['paid_known']) ? 'checked' : '' ?>>Pembayaran sudah dipastikan</label>
        <span class="help">Hilangkan centang bila nominal pembayaran belum pasti (status “Perlu konfirmasi”: tidak dihitung lunas maupun belum dibayar).</span></div>
      <div class="field full"><label for="note">Keterangan</label><input class="input" id="note" name="note" maxlength="255" value="<?= e($v('note')) ?>"></div>
    </div>
    <template id="itemTpl"><tr>
      <td class="ln"></td>
      <td><input class="input" name="item_name[]" maxlength="160" aria-label="Nama barang" placeholder="mis. Lampu LED 50W"></td>
      <td><input class="input num r" name="item_qty[]" inputmode="decimal" aria-label="Qty"></td>
      <td><input class="input num r" name="item_price[]" inputmode="decimal" aria-label="Harga satuan"></td>
      <td class="r num sub">-</td>
      <td><button type="button" class="icon-btn" style="background:#eef1f8;color:#3b4566" data-del-item aria-label="Hapus baris"><svg class="i i-sm"><use href="#i-x"/></svg></button></td>
    </tr></template>
    <?php else: ?>
    <div class="form-grid">
      <div class="field full"><label for="customer">Customer <span class="req">*</span></label><input class="input" id="customer" name="customer" maxlength="160" value="<?= e($v('customer')) ?>" required><?= $fe('customer') ?></div>
      <div class="field"><label for="project_name">Proyek</label><input class="input" id="project_name" name="project_name" maxlength="200" value="<?= e($v('project_name')) ?>"></div>
      <div class="field"><label for="invoice_no">No. invoice</label><input class="input" id="invoice_no" name="invoice_no" maxlength="80" value="<?= e($v('invoice_no')) ?>"></div>
      <div class="field full"><label for="description">Keterangan</label><input class="input" id="description" name="description" maxlength="255" value="<?= e($v('description')) ?>"></div>
      <div class="field"><label for="invoice_date">Tanggal invoice</label><input class="input" type="date" id="invoice_date" name="invoice_date" value="<?= e($v('invoice_date')) ?>"><?= $fe('invoice_date') ?></div>
      <div class="field"><label for="amount">Nominal tagihan (Rp) <span class="req">*</span></label><input class="input num" id="amount" name="amount" inputmode="decimal" value="<?= e($v('amount')) ?>" required><?= $fe('amount') ?></div>
      <div class="field"><label for="paid">Sudah dibayar (Rp) <span class="help">— total, termasuk penerimaan lewat Transaksi</span></label><input class="input num" id="paid" name="paid" inputmode="decimal" value="<?= e($v('paid')) ?>"><?= $fe('paid') ?></div>
      <div class="field full"><label class="check"><input type="checkbox" name="paid_known" value="1" <?= !empty($t['paid_known']) ? 'checked' : '' ?>>Pembayaran sudah dipastikan</label>
        <span class="help">Hilangkan centang bila nominal pembayaran belum pasti (status “Perlu konfirmasi”: tidak dihitung lunas maupun belum dibayar).</span></div>
      <div class="field full"><label for="note">Catatan</label><input class="input" id="note" name="note" maxlength="255" value="<?= e($v('note')) ?>"></div>
    </div>
    <?php endif; ?>
    <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn" type="submit">Simpan</button><a class="btn btn-ghost" href="<?= e(url($id ? '/invoices/' . $id : '/invoices?kind=' . $kind)) ?>">Batal</a></div>
  </form>
</section>
