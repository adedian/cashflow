<?php
/** @var string $tab @var array $rows @var array $kinds @var array $categories @var array $projects */
$kindOpts = static function (string $sel) use ($kinds): string {
    $o = '';
    foreach ($kinds as $k => $l) {
        $o .= '<option value="' . e($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $o;
};
// Formulir per-baris tabel memakai atribut form="..." (HTML valid; <form> tidak boleh berada langsung di <tr>)
$rowForm = static fn(string $id, string $action): string => '<form id="' . e($id) . '" method="post" action="' . e($action) . '">' . csrf_field() . '</form>';
?>
<?php if ($tab === 'categories'): ?>
<section class="card">
  <div class="card-head"><h2>Tambah kategori</h2></div>
  <form method="post" action="<?= e(url('/master/categories')) ?>" class="filters">
    <?= csrf_field() ?>
    <div class="field grow"><label for="nc">Nama kategori</label><input class="input" id="nc" name="name" maxlength="120" required></div>
    <div class="field"><label for="nk">Jenis</label><select class="input" id="nk" name="kind"><?= $kindOpts('biaya') ?></select></div>
    <div class="field" style="flex:0 0 auto"><button class="btn" type="submit">Tambah</button></div>
  </form>
  <p class="help mt-1 mb-0">Jenis <b>Pembiayaan</b> dan <b>Transfer</b> dikeluarkan dari mutasi masuk/keluar usaha (agar pinjaman & pindah dana tidak terhitung ganda). Mengubah jenis kategori langsung memengaruhi dashboard & laporan.</p>
</section>
<section class="card card-flush">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Nama</th><th>Jenis</th><th class="r">Transaksi</th><th>Aktif</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $fid = 'mc' . (int) $r['id']; ?>
      <tr>
        <td><?= $rowForm($fid, url('/master/categories/' . (int) $r['id'])) ?><input form="<?= $fid ?>" class="input" name="name" value="<?= e($r['name']) ?>" maxlength="120" required aria-label="Nama"></td>
        <td><select form="<?= $fid ?>" class="input" name="kind" aria-label="Jenis"><?= $kindOpts($r['kind']) ?></select></td>
        <td class="r num"><?= number_format((int) $r['n'], 0, ',', '.') ?></td>
        <td><label class="check"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>></label></td>
        <td><button form="<?= $fid ?>" class="btn btn-ghost btn-sm" type="submit" data-no-lock>Simpan</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<?php elseif ($tab === 'projects'): ?>
<section class="card">
  <div class="card-head"><h2>Tambah proyek</h2></div>
  <form method="post" action="<?= e(url('/master/projects')) ?>" class="filters">
    <?= csrf_field() ?>
    <div class="field grow"><label for="np">Nama proyek</label><input class="input" id="np" name="name" maxlength="160" required></div>
    <div class="field" style="flex:0 0 auto"><button class="btn" type="submit">Tambah</button></div>
  </form>
</section>
<section class="card card-flush">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Nama</th><th class="r">Transaksi</th><th>Aktif</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $fid = 'mp' . (int) $r['id']; ?>
      <tr>
        <td><?= $rowForm($fid, url('/master/projects/' . (int) $r['id'])) ?><input form="<?= $fid ?>" class="input" name="name" value="<?= e($r['name']) ?>" maxlength="160" required aria-label="Nama"></td>
        <td class="r num"><?= number_format((int) $r['n'], 0, ',', '.') ?></td>
        <td><label class="check"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>></label></td>
        <td><button form="<?= $fid ?>" class="btn btn-ghost btn-sm" type="submit" data-no-lock>Simpan</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<?php elseif ($tab === 'accounts'): ?>
<?php
/** @var ?array $edit */
$isEdit = $edit !== null;
$val = static fn(string $k, string $d = ''): string => $isEdit ? (string) ($edit[$k] ?? '') : $d;
$accKind = static fn(array $r): string => $r['balance_mode'] === 'liability' ? 'Pinjaman' : ($r['kind'] === 'facility' ? 'Loan' : 'Bank');
// kolom Limit: hanya untuk jenis Loan (rekening lama berjenis Bank yang sudah punya limit tetap bisa diubah limitnya); Bank HR tidak memakai limit
$showLimit = $isEdit && ($edit['kind'] === 'facility' || $edit['credit_limit'] !== null);
?>
<section class="card" id="acc-form">
  <div class="card-head"><h2><?= $isEdit ? 'Edit rekening ' . e($edit['code']) : 'Tambah rekening' ?></h2></div>
  <form method="post" action="<?= e(url('/master/accounts' . ($isEdit ? '/' . (int) $edit['id'] : ''))) ?>" class="form-grid">
    <?= csrf_field() ?>
    <?php if ($isEdit): ?>
    <div class="field"><label>Kode</label><input class="input" value="<?= e($edit['code']) ?>" disabled></div>
    <?php else: ?>
    <div class="field"><label for="ac">Kode <span class="req">*</span></label><input class="input" id="ac" name="code" maxlength="20" required placeholder="mis. BCA-02"></div>
    <?php endif; ?>
    <div class="field"><label for="an">Nama <span class="req">*</span></label><input class="input" id="an" name="name" maxlength="120" required value="<?= e($val('name')) ?>"></div>
    <?php if ($isEdit): ?>
    <div class="field"><label>Jenis</label><input class="input" value="<?= e($accKind($edit)) ?>" disabled></div>
    <div class="field"><label>Saldo awal</label><input class="input" value="<?= e(money($edit['opening_balance'])) ?> · <?= e(fmt_date($edit['opening_date'])) ?>" disabled></div>
    <?php else: ?>
    <div class="field"><label for="ak">Jenis</label><select class="input" id="ak" name="kind"><option value="bank">Tabungan Giro (tanpa limit)</option><option value="facility">Loan (dengan limit)</option></select></div>
    <div class="field"><label for="am">Perhitungan saldo</label><select class="input" id="am" name="balance_mode"><option value="asset">Aset (debit − kredit)</option><option value="liability">Pinjaman (kredit − debit)</option></select></div>
    <div class="field"><label for="ao">Saldo awal (Rp)</label><input class="input num" id="ao" name="opening_balance" value="0"></div>
    <div class="field"><label for="ad">Tanggal saldo awal <span class="req">*</span></label><input class="input" type="date" id="ad" name="opening_date" required></div>
    <?php endif; ?>
    <div class="field<?= $showLimit ? '' : ' hidden' ?>" id="al-field"><label for="al">Limit</label><input class="input num" id="al" name="credit_limit" value="<?= e($isEdit && $edit['credit_limit'] !== null ? money($edit['credit_limit'], false) : '') ?>"></div>
    <div class="field"><label for="ah">4 digit terakhir (bila nomor lengkap tidak diisi)</label><input class="input" id="ah" name="acct_hint" maxlength="4" inputmode="numeric" value="<?= e($val('acct_hint')) ?>"></div>
    <div class="field"><label for="abn">Nama bank</label><input class="input" id="abn" name="bank_name" maxlength="80" placeholder="mis. BCA" value="<?= e($val('bank_name')) ?>"></div>
    <div class="field"><label for="aho">Nama pemilik rekening</label><input class="input" id="aho" name="account_holder" maxlength="120" value="<?= e($val('account_holder')) ?>"></div>
    <div class="field"><label for="ano">Nomor rekening</label><input class="input num" id="ano" name="account_no" maxlength="40" inputmode="numeric" autocomplete="off" value="<?= e($val('account_no')) ?>"></div>
    <?php if ($isEdit): ?>
    <div class="field"><label for="aso">Urutan</label><input class="input" id="aso" name="sort_order" inputmode="numeric" value="<?= (int) $edit['sort_order'] ?>"></div>
    <?php endif; ?>
    <div class="field" style="justify-content:flex-end"><div style="display:flex;gap:18px;flex-wrap:wrap">
      <label class="check"><input type="checkbox" name="show_on_dashboard" value="1" <?= !$isEdit || $edit['show_on_dashboard'] ? 'checked' : '' ?>>Tampil di dashboard</label>
      <label class="check"><input type="checkbox" name="viewer_can_see_no" value="1" <?= !$isEdit || $edit['viewer_can_see_no'] ? 'checked' : '' ?>>Nomor lengkap terlihat Viewer</label>
      <?php if ($isEdit): ?><label class="check"><input type="checkbox" name="is_active" value="1" <?= $edit['is_active'] ? 'checked' : '' ?>>Aktif</label><?php endif; ?></div></div>
    <div class="field full" style="align-items:flex-start"><div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn" type="submit"><?= $isEdit ? 'Simpan perubahan' : 'Tambah rekening' ?></button>
      <?php if ($isEdit): ?><a class="btn btn-ghost" href="<?= e(url('/master?tab=accounts')) ?>">Batal</a><?php endif; ?></div></div>
  </form>
  <p class="help mt-1 mb-0">Nomor rekening, bank, dan pemilik bersifat opsional dan hanya tampil di dashboard bila diisi. Saldo awal & tanggalnya diubah di tab <a href="<?= e(url('/master?tab=opening')) ?>">Saldo Awal</a>.<?= $isEdit ? ' Kode, jenis, dan perhitungan saldo tidak dapat diubah setelah rekening dibuat.' : '' ?></p>
</section>
<section class="card card-flush">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Rekening</th><th>Jenis</th><th class="r">Transaksi</th><th>Status</th><th class="r">Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $rid = (int) $r['id']; ?>
      <tr class="<?= $r['is_active'] ? '' : 'voided' ?>">
        <td><b><?= e($r['code']) ?></b><div class="sub"><?= e($r['name']) ?></div></td>
        <td class="nowrap"><?= e($accKind($r)) ?></td>
        <td class="r num"><?= number_format((int) $r['n'], 0, ',', '.') ?></td>
        <td><?= $r['is_active'] ? '<span class="pill pill-green">Aktif</span>' : '<span class="pill pill-gray">Nonaktif</span>' ?></td>
        <td class="r nowrap">
          <button type="button" class="btn btn-ghost btn-sm" data-open-modal="accd<?= $rid ?>">Detail</button>
          <a class="btn btn-ghost btn-sm" href="<?= e(url('/master?tab=accounts&edit=' . $rid)) ?>#acc-form">Edit</a>
          <form method="post" action="<?= e(url('/master/accounts/' . $rid . '/delete')) ?>" style="display:inline" data-confirm="Hapus rekening <?= e($r['code']) ?> (<?= e($r['name']) ?>)? Tindakan ini tercatat di audit log dan tidak dapat dibatalkan." data-confirm-ok="Ya, hapus"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit" data-no-lock>Hapus</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Belum ada rekening.</td></tr><?php endif; ?>
    </tbody></table></div>
  <p class="help" style="padding:10px 16px 14px;margin:0">Rekening yang sudah punya transaksi tidak dapat dihapus; nonaktifkan lewat Edit. Nomor lengkap tidak dicatat di audit log.</p>
</section>
<?php foreach ($rows as $r): $rid = (int) $r['id']; ?>
<div class="modal-back" id="accd<?= $rid ?>" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="accdt<?= $rid ?>">
    <div class="modal-head"><h3 id="accdt<?= $rid ?>">Detail rekening <?= e($r['code']) ?></h3><button type="button" class="icon-btn" style="background:#eef1f8;color:#3b4566" data-close-modal aria-label="Tutup"><svg class="i"><use href="#i-x"/></svg></button></div>
    <div class="modal-body">
      <dl class="kv">
        <dt>Kode</dt><dd><?= e($r['code']) ?></dd>
        <dt>Nama</dt><dd><?= e($r['name']) ?></dd>
        <dt>Jenis</dt><dd><?= e($accKind($r)) ?> · <?= $r['balance_mode'] === 'liability' ? 'saldo = kredit − debit' : 'saldo = debit − kredit' ?></dd>
        <dt>Saldo awal</dt><dd class="num"><?= e(money($r['opening_balance'])) ?> <span class="muted">(<?= e(fmt_date($r['opening_date'])) ?>)</span></dd>
        <dt>Limit</dt><dd class="num"><?= $r['credit_limit'] !== null ? e(money($r['credit_limit'])) : '<span class="muted">—</span>' ?></dd>
        <dt>Nama bank</dt><dd><?= $r['bank_name'] ? e($r['bank_name']) : '<span class="muted">—</span>' ?></dd>
        <dt>Pemilik rekening</dt><dd><?= $r['account_holder'] ? e($r['account_holder']) : '<span class="muted">—</span>' ?></dd>
        <dt>Nomor rekening</dt><dd class="num"><?= $r['account_no'] ? e($r['account_no']) : '<span class="muted">—</span>' ?></dd>
        <dt>4 digit terakhir</dt><dd class="num"><?= $r['acct_hint'] ? e($r['acct_hint']) : '<span class="muted">—</span>' ?></dd>
        <dt>Tampil di dashboard</dt><dd><?= $r['show_on_dashboard'] ? 'Ya' : 'Tidak' ?></dd>
        <dt>Nomor terlihat Viewer</dt><dd><?= $r['viewer_can_see_no'] ? 'Ya' : 'Tidak' ?></dd>
        <dt>Urutan</dt><dd><?= (int) $r['sort_order'] ?></dd>
        <dt>Status</dt><dd><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></dd>
        <dt>Jumlah transaksi</dt><dd class="num"><?= number_format((int) $r['n'], 0, ',', '.') ?></dd>
      </dl>
    </div>
    <div class="modal-foot">
      <a class="btn btn-orange" href="<?= e(url('/master?tab=accounts&edit=' . $rid)) ?>#acc-form"><svg class="i i-sm"><use href="#i-edit"/></svg>Edit</a>
      <button type="button" class="btn btn-ghost" data-close-modal>Tutup</button>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php elseif ($tab === 'counterparties'): ?>
<section class="card">
  <div class="card-head"><h2>Tambah rekening lawan</h2></div>
  <form method="post" action="<?= e(url('/master/counterparties')) ?>" class="filters">
    <?= csrf_field() ?>
    <div class="field grow"><label for="ncl">Bank / rekening pihak</label><input class="input" id="ncl" name="label" maxlength="255" required placeholder="mis. BCA 0490393030 a.n. PT Contoh" autocomplete="off"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn" type="submit">Tambah</button></div>
  </form>
  <p class="help mt-1 mb-0">Daftar ini menjadi pilihan dropdown <b>Bank / rekening pihak</b> di formulir transaksi (hanya terlihat Super Admin). Teks yang dipilih disalin ke transaksi; mengubah teks di sini ikut memperbarui transaksi yang memakainya. Rekening yang dinonaktifkan tidak muncul lagi di dropdown, tetapi transaksi lama tetap utuh.</p>
</section>
<section class="card card-flush">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Bank / rekening pihak</th><th class="r">Transaksi</th><th>Aktif</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $fid = 'mq' . (int) $r['id']; ?>
      <tr>
        <td><?= $rowForm($fid, url('/master/counterparties/' . (int) $r['id'])) ?><input form="<?= $fid ?>" class="input" name="label" value="<?= e($r['label']) ?>" maxlength="255" required aria-label="Bank / rekening pihak"></td>
        <td class="r num"><?= number_format((int) $r['n'], 0, ',', '.') ?></td>
        <td><label class="check"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>></label></td>
        <td><button form="<?= $fid ?>" class="btn btn-ghost btn-sm" type="submit" data-no-lock>Simpan</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<?php elseif ($tab === 'suppliers'): ?>
<section class="card">
  <div class="card-head"><h2>Tambah supplier</h2></div>
  <form method="post" action="<?= e(url('/master/suppliers')) ?>" class="filters">
    <?= csrf_field() ?>
    <div class="field grow"><label for="nsp">Nama supplier / vendor</label><input class="input" id="nsp" name="name" maxlength="160" required placeholder="mis. PT. Contoh Jaya" autocomplete="off"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn" type="submit">Tambah</button></div>
  </form>
  <p class="help mt-1 mb-0">Daftar ini menjadi pilihan <b>Nama supplier / vendor / pihak pemberi hutang</b> di formulir Hutang (semua jenis: Usaha, Direksi, Lain-lain — nama direksi atau pihak lain juga ditambahkan di sini). Mengubah nama di sini ikut memperbarui hutang yang memakainya. Supplier yang dinonaktifkan tidak muncul lagi di dropdown, tetapi hutang lama tetap utuh.</p>
</section>
<section class="card card-flush">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Nama supplier</th><th class="r">Hutang</th><th>Aktif</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $fid = 'ms' . (int) $r['id']; ?>
      <tr>
        <td><?= $rowForm($fid, url('/master/suppliers/' . (int) $r['id'])) ?><input form="<?= $fid ?>" class="input" name="name" value="<?= e($r['name']) ?>" maxlength="160" required aria-label="Nama supplier"></td>
        <td class="r num"><?= number_format((int) $r['n'], 0, ',', '.') ?></td>
        <td><label class="check"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>></label></td>
        <td><button form="<?= $fid ?>" class="btn btn-ghost btn-sm" type="submit" data-no-lock>Simpan</button></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">Belum ada supplier.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<?php elseif ($tab === 'parties'): ?>
<section class="card">
  <div class="card-head"><h2>Tambah customer</h2></div>
  <form method="post" action="<?= e(url('/master/parties')) ?>" class="filters">
    <?= csrf_field() ?>
    <div class="field grow"><label for="npt">Nama customer (penerima / pembayar)</label><input class="input" id="npt" name="name" maxlength="255" required placeholder="mis. PT. Contoh Abadi" autocomplete="off"></div>
    <div class="field" style="flex:0 0 auto"><button class="btn" type="submit">Tambah</button></div>
  </form>
  <p class="help mt-1 mb-0">Daftar ini menjadi pilihan <b>Pihak (penerima/pembayar)</b> di formulir Transaksi. Customer juga dapat ditambah langsung dari dropdown di formulir itu. Mengubah nama di sini ikut memperbarui transaksi yang memakainya. Customer yang dinonaktifkan tidak muncul lagi di dropdown, tetapi transaksi lama tetap utuh.</p>
</section>
<section class="card card-flush">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Nama customer</th><th class="r">Transaksi</th><th>Aktif</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $fid = 'mp' . (int) $r['id']; ?>
      <tr>
        <td><?= $rowForm($fid, url('/master/parties/' . (int) $r['id'])) ?><input form="<?= $fid ?>" class="input" name="name" value="<?= e($r['name']) ?>" maxlength="255" required aria-label="Nama customer"></td>
        <td class="r num"><?= number_format((int) $r['n'], 0, ',', '.') ?></td>
        <td><label class="check"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?>></label></td>
        <td><button form="<?= $fid ?>" class="btn btn-ghost btn-sm" type="submit" data-no-lock>Simpan</button></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">Belum ada customer.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<?php elseif ($tab === 'opening'): ?>
<?php
$totBank = '0.00';
$totLoan = '0.00';
foreach ($rows as $r) {
    if ($r['balance_mode'] === 'liability') { $totLoan = bcadd($totLoan, (string) $r['opening_balance'], 2); }
    elseif ($r['kind'] === 'bank') { $totBank = bcadd($totBank, (string) $r['opening_balance'], 2); }
}
?>
<div class="grid g-3 mb-2">
  <div class="card stat"><div class="k">Jumlah rekening</div><div class="v num"><?= count($rows) ?></div></div>
  <div class="card stat"><div class="k">Total saldo awal bank</div><div class="v num"><?= e(money($totBank)) ?></div></div>
  <div class="card stat"><div class="k">Total pemakaian awal pinjaman</div><div class="v num"><?= e(money($totLoan)) ?></div></div>
</div>
<section class="card card-flush">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Kode</th><th>Nama</th><th>Tanggal saldo awal</th><th class="r">Saldo awal (Rp)</th><th class="r">Transaksi</th><th class="r">Saldo sekarang</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $fid = 'mo' . (int) $r['id']; $loan = $r['balance_mode'] === 'liability'; ?>
      <tr class="<?= $r['is_active'] ? '' : 'voided' ?>">
        <td class="nowrap"><form id="<?= $fid ?>" method="post" action="<?= e(url('/master/opening/' . (int) $r['id'])) ?>" data-confirm="Ubah saldo awal <?= e($r['code']) ?>? Saldo, dashboard, laporan, dan proyeksi akan ikut berubah. Tindakan ini tercatat di audit log." data-confirm-ok="Ya, simpan"><?= csrf_field() ?></form>
          <b><?= e($r['code']) ?></b><div class="sub"><?= $loan ? 'Pinjaman' : ($r['kind'] === 'facility' ? 'Fasilitas' : 'Bank') ?><?= $r['is_active'] ? '' : ' · nonaktif' ?></div></td>
        <td><?= e($r['name']) ?></td>
        <td><input form="<?= $fid ?>" class="input" type="date" name="opening_date" value="<?= e($r['opening_date']) ?>" <?= $r['first_tx'] ? 'max="' . e($r['first_tx']) . '"' : '' ?> required aria-label="Tanggal saldo awal"></td>
        <td class="r"><input form="<?= $fid ?>" class="input num" name="opening_balance" value="<?= e(money($r['opening_balance'], false)) ?>" inputmode="decimal" required aria-label="<?= $loan ? 'Pemakaian awal' : 'Saldo awal' ?>" style="text-align:right;min-width:170px"></td>
        <td class="r num"><?= number_format((int) $r['tx_count'], 0, ',', '.') ?></td>
        <td class="r num"><?= e(money($r['balance'])) ?></td>
        <td><button form="<?= $fid ?>" class="btn btn-ghost btn-sm" type="submit" data-no-lock>Simpan</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <p class="help" style="padding:10px 16px 14px;margin:0">Berlaku untuk semua rekening bank dan fasilitas/pinjaman. Untuk rekening pinjaman, angka ini adalah pemakaian awal. Tanggal saldo awal tidak boleh setelah transaksi pertama rekening tersebut. Mengubah saldo awal langsung memengaruhi saldo, dashboard, laporan, dan proyeksi.</p>
</section>

<?php else: ?>
<section class="card">
  <p class="mb-0">Kolom “Kategori Biaya” di Excel berupa teks bebas. Tabel ini memetakan setiap teks mentah ke <b>kategori</b> dan <b>proyek</b>. Mengubah pemetaan langsung memperbarui semua transaksi dengan teks mentah tersebut dan tercatat di audit log.</p>
</section>
<section class="card card-flush">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Teks Excel</th><th class="r">Transaksi</th><th>Kategori</th><th>Proyek</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $fid = 'mm' . (int) $r['id']; ?>
      <tr>
        <td><?= $rowForm($fid, url('/master/mapping/' . (int) $r['id'])) ?><?= $r['raw_text'] === '' ? '<span class="muted">(kosong)</span>' : e($r['raw_text']) ?></td>
        <td class="r num"><?= number_format((int) $r['n'], 0, ',', '.') ?></td>
        <td><select form="<?= $fid ?>" class="input" name="category_id" aria-label="Kategori" style="min-width:230px">
          <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $r['category_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> — <?= e($c['kind']) ?></option><?php endforeach; ?>
        </select></td>
        <td><select form="<?= $fid ?>" class="input" name="project_id" aria-label="Proyek" style="min-width:190px"><option value="">—</option>
          <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $r['project_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></td>
        <td><button form="<?= $fid ?>" class="btn btn-ghost btn-sm" type="submit" data-no-lock>Simpan</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>
