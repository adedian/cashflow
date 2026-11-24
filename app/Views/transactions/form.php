<?php
/** @var array $t @var array $errors @var ?int $id @var ?string $warn @var array $accounts @var array $categories @var array $projects @var array $counterpartyAccounts @var string $idem @var array $lineRows @var array $refInfos @var array $suppliers */
$kinds = \App\Models\Lookup::KIND_LABEL;
$v = static fn(string $k, $d = '') => $t[$k] ?? $d;
$fe = static fn(string $k) => isset($errors[$k]) ? '<div class="err">' . e($errors[$k]) . '</div>' : '';
$action = $id ? url('/transactions/' . $id) : url('/transactions');
$auto = (!$id && $v('source_ref') && !$errors) ? ' data-auto="1"' : '';   // isian dari tombol Bayar/Terima/Realisasikan: ikut dikosongkan bila referensi diganti
// baris uraian: [{description, category_id, project_id, amount}] — dari kiriman (saat galat), rincian tersimpan (ubah), atau satu baris (baru / tombol Bayar·Terima)
$lineRows = $lineRows ?: [['description' => '', 'category_id' => '', 'project_id' => '', 'amount' => '', 'source_ref' => '', 'note' => '']];
$legacyLink = !empty($t['legacy_link']);   // bukti penerimaan Piutang historis: tidak dapat dihubungkan ke sumber lain
$lineTotal = '0.00';
foreach ($lineRows as $lr) {
    $pa = parse_amount($lr['amount'] ?? '', true);
    $lineTotal = $pa !== null ? bcadd($lineTotal, $pa, 2) : $lineTotal;
}
// pilihan kategori (dikelompokkan menurut jenis) & proyek, dibuat sekali lalu dipakai tiap baris (nilai terpilih ditandai per baris)
$catHtml = '';
$curKind = null;
foreach ($categories as $c) {
    if ($curKind !== $c['kind']) {
        $catHtml .= ($curKind !== null ? '</optgroup>' : '') . '<optgroup label="' . e($kinds[$c['kind']] ?? $c['kind']) . '">';
        $curKind = $c['kind'];
    }
    $catHtml .= '<option value="' . (int) $c['id'] . '">' . e($c['name']) . '</option>';
}
$catHtml .= $curKind !== null ? '</optgroup>' : '';
$projHtml = '';
foreach ($projects as $p) {
    $projHtml .= '<option value="' . (int) $p['id'] . '">' . e($p['name']) . '</option>';
}
$renderLine = static function (array $r, bool $first, string $auto) use ($catHtml, $projHtml, $refInfos, $legacyLink, $id): string {
    $idD = $first ? ' id="description"' : '';
    $idA = $first ? ' id="amount"' : '';
    $cid = (int) ($r['category_id'] ?? 0);
    $pid = (int) ($r['project_id'] ?? 0);
    $cats = $cid > 0 ? str_replace('value="' . $cid . '">', 'value="' . $cid . '" selected>', $catHtml) : $catHtml;
    $projs = $pid > 0 ? str_replace('value="' . $pid . '">', 'value="' . $pid . '" selected>', $projHtml) : $projHtml;
    $ref = trim((string) ($r['source_ref'] ?? ''));
    $info = $ref !== '' ? ($refInfos[$ref] ?? null) : null;
    $refHtml = $legacyLink
        ? '<div class="line-pick line-pick-ref"><input type="hidden" name="line_ref[]" value=""><span class="help">—</span></div>'
        : '<div class="line-pick line-pick-ref"><div class="line-ref" data-combo-remote="/api/refs" data-dir-name="direction" data-combo-create-event data-placeholder="Cari / ketik No. invoice / PO…" data-tx="' . ($id ? (int) $id : '') . '" data-selected="' . e($info ? json_encode($info, JSON_UNESCAPED_UNICODE) : 'null') . '">'
            . '<input type="hidden" class="combo-value" name="line_ref[]" value="' . e($ref) . '"></div></div>';
    return '<div class="line-row">'
        . '<input class="input line-desc" name="line_desc[]"' . $idD . ' maxlength="500" value="' . e((string) ($r['description'] ?? '')) . '" placeholder="Uraian" aria-label="Uraian"' . ($first ? $auto : '') . '>'
        . $refHtml
        . '<div class="line-pick line-pick-cat"><select class="input line-cat" name="line_cat[]" aria-label="Kategori" data-combo-create-event data-combo-placeholder="Ketik untuk mencari kategori…"><option value="">— belum dikategorikan —</option>' . $cats . '</select></div>'
        . '<div class="line-pick line-pick-proj"><select class="input line-proj" name="line_proj[]" aria-label="Proyek" data-combo-create-event data-combo-placeholder="Ketik untuk mencari proyek…"><option value="">— tanpa proyek —</option>' . $projs . '</select></div>'
        . '<input class="input num line-amt" name="line_amount[]"' . $idA . ' type="text" inputmode="decimal" value="' . e((string) ($r['amount'] ?? '')) . '" placeholder="Jumlah (Rp)" aria-label="Jumlah (Rp)" autocomplete="off"' . ($first ? $auto : '') . '>'
        . '<input class="input line-note" name="line_note[]" maxlength="500" value="' . e((string) ($r['note'] ?? '')) . '" placeholder="Catatan" aria-label="Catatan">'
        . '<button type="button" class="btn btn-ghost btn-sm btn-icon line-del" data-line-del title="Hapus baris" aria-label="Hapus baris"><svg class="i i-sm" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>'
        . '<div class="ref-hint line-hint" hidden aria-live="polite"></div>'
        . '</div>';
};
?>
<section class="card form-wide">
  <?php if (!empty($errors['_'])): ?><div class="alert alert-danger mb-2" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
  <?php if (!empty($warn)): ?><div class="alert alert-warn mb-2"><?= e($warn) ?></div><?php endif; ?>
  <?php if ($errors && empty($errors['_'])): ?><div class="alert alert-danger mb-2" role="alert">Periksa kembali isian yang ditandai.</div><?php endif; ?>

  <form method="post" action="<?= e($action) ?>" id="txForm" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="idem_key" value="<?= e($idem) ?>">
    <?php if ($id): ?><input type="hidden" name="row_version" value="<?= e($v('row_version')) ?>"><?php endif; ?>
    <div class="form-grid">
      <div class="field">
        <label for="account_id">Rekening <span class="req">*</span></label>
        <select class="input" id="account_id" name="account_id" data-combo required>
          <option value="">— pilih —</option>
          <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $v('account_id', 0) === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
        </select><?= $fe('account_id') ?>
      </div>
      <div class="field">
        <label for="tx_date">Tanggal <span class="req">*</span></label>
        <input class="input" type="date" id="tx_date" name="tx_date" value="<?= e($v('tx_date')) ?>" required><?= $fe('tx_date') ?>
      </div>
      <div class="field">
        <label>Jenis <span class="req">*</span></label>
        <div style="display:flex;gap:18px;height:44px;align-items:center">
          <label class="check"><input type="radio" name="direction" value="in" <?= $v('direction') === 'in' ? 'checked' : '' ?>>Mutasi Masuk (debit)</label>
          <label class="check"><input type="radio" name="direction" value="out" <?= $v('direction') === 'out' ? 'checked' : '' ?>>Mutasi Keluar (kredit)</label>
        </div><?= $fe('direction') ?>
      </div>
      <div class="field">
        <label for="txTotal">Total nominal (Rp)</label>
        <input class="input num" type="text" id="txTotal" value="<?= e(money($lineTotal, false)) ?>" readonly tabindex="-1" aria-live="polite">
        <span class="help">Dihitung otomatis dari jumlah semua uraian di bawah.</span><?= $fe('amount') ?>
      </div>
      <div class="field">
        <label for="voucher_code">Kode bukti</label>
        <input class="input" id="voucher_code" name="voucher_code" maxlength="30" value="<?= e($v('voucher_code')) ?>" placeholder="mis. BK 10/01"><?= $fe('voucher_code') ?>
      </div>
      <div class="field">
        <label for="counterparty">Pihak (penerima/pembayar)</label>
        <?php $curParty = trim((string) $v('counterparty')); $partyNames = array_column($parties, 'name'); ?>
        <div class="field-pick"><select class="input" id="counterparty" name="counterparty" data-combo data-combo-create="/api/parties" data-combo-placeholder="Ketik untuk mencari pihak…"<?= $auto ?>>
          <option value="">— pilih pihak —</option>
          <?php if ($curParty !== '' && !in_array(mb_strtolower($curParty), array_map('mb_strtolower', $partyNames), true)): ?><option value="<?= e($curParty) ?>" selected><?= e($curParty) ?> (belum di master)</option><?php endif; ?>
          <?php foreach ($partyNames as $pn): ?><option value="<?= e($pn) ?>" <?= mb_strtolower($pn) === mb_strtolower($curParty) ? 'selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?>
        </select></div><?= $fe('counterparty') ?>
        <span class="help">Tidak ada di daftar? Ketik namanya lalu pilih “Tambah”. Daftar dikelola di <a href="<?= e(url('/master?tab=parties')) ?>">Master Data → Customer</a>.</span>
      </div>
      <div class="field full">
        <label for="description">Uraian <span class="req">*</span></label>
        <div class="lines" id="lines" data-max="<?= (int) \App\Services\TransactionService::MAX_LINES ?>">
          <div class="line-head" aria-hidden="true"><span>Uraian</span><span>No. Invoice / PO</span><span>Kategori</span><span>Proyek</span><span class="r">Jumlah (Rp)</span><span>Catatan</span><span></span></div>
          <div id="lineRows">
            <?php foreach ($lineRows as $i => $lr): ?><?= $renderLine($lr, $i === 0, $auto) ?><?php endforeach; ?>
          </div>
          <template id="lineTpl"><?= $renderLine(['description' => '', 'category_id' => '', 'project_id' => '', 'amount' => '', 'source_ref' => '', 'note' => ''], false, '') ?></template>
        </div>
        <div><button type="button" class="btn btn-ghost btn-sm" id="lineAdd"><svg class="i i-sm"><use href="#i-plus"/></svg>Tambah uraian</button></div>
        <?php if ($legacyLink): ?>
          <div class="ref-hint mt-1">Transaksi ini tercatat sebagai bukti penerimaan <a href="<?= e(url('/invoices/' . (int) $t['legacy_link'])) ?>">Piutang #<?= (int) $t['legacy_link'] ?></a> (data historis), sehingga kolom No. Invoice / PO tidak dapat diubah. Lepaskan tautannya di halaman Piutang bila perlu.</div>
        <?php endif; ?>
        <span class="help">Setiap uraian punya No. invoice / PO, kategori, proyek, jumlah, dan catatannya sendiri (mis. tagihan dan potongan PPh). Jumlah boleh bernilai negatif untuk potongan: awali dengan tanda −. Total dijumlahkan otomatis; laporan per kategori dan per proyek dihitung dari tiap uraian.</span>
        <span class="help">No. invoice / PO: pilihan mengikuti jenis transaksi — Mutasi Masuk → Piutang / Proyeksi penerimaan; Mutasi Keluar → Hutang / Proyeksi pengeluaran. Kosongkan bila tanpa referensi. Belum ada di daftar? Ketik nomornya lalu pilih “Tambah”. Beberapa uraian boleh memilih invoice yang sama (jumlahnya dijumlahkan).</span>
        <?= $fe('items') ?><?= $fe('description') ?><?= $fe('category_id') ?><?= $fe('project_id') ?><?= $fe('source_ref') ?>
        <?php if (!empty($errors['_dup'])): ?><label class="check"><input type="checkbox" name="confirm_dup" value="1">Ini pembayaran terpisah — tetap catat</label><?php endif; ?>
        <?php $legacyRef = trim((string) $v('ref_no')); if ($legacyRef !== '' && $id && !array_filter($lineRows, static fn($lr) => trim((string) ($lr['source_ref'] ?? '')) !== '')): ?>
          <label class="check help"><input type="checkbox" name="ref_clear" value="1">Hapus teks No. referensi lama “<?= e($legacyRef) ?>” (bukan referensi terhubung)</label>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="counterparty_detail">Bank / rekening pihak <span class="help">(hanya terlihat Super Admin)</span></label>
        <div class="field-pick"><select class="input" id="counterparty_detail" name="counterparty_detail" data-combo-create-event data-combo-placeholder="Ketik untuk mencari bank / rekening…">
          <option value="">— pilih rekening lawan —</option>
          <?php $curCp = trim((string) $v('counterparty_detail')); $cpLabels = array_column($counterpartyAccounts, 'label'); ?>
          <?php if ($curCp !== '' && !in_array($curCp, $cpLabels, true)): ?><option value="<?= e($curCp) ?>" selected><?= e(cp_label($curCp)) ?> (belum di master)</option><?php endif; ?>
          <?php /* data-party = pemilik rekening; transactions.js menautkan Pihak ↔ Bank/rekening lewat atribut ini */ ?>
          <?php foreach ($counterpartyAccounts as $ca): ?><option value="<?= e($ca['label']) ?>" data-party="<?= e((string) ($ca['party_name'] ?? '')) ?>" <?= $ca['label'] === $curCp ? 'selected' : '' ?>><?= e(cp_label($ca['label'])) ?></option><?php endforeach; ?>
        </select></div><?= $fe('counterparty_detail') ?>
        <span class="help">Terisi otomatis dari Pihak yang dipilih (dan sebaliknya); bagian ini yang tercetak di bukti BK/BM. Rekening baru: ketik bank dan nomornya lalu pilih “Tambah”. Daftar dikelola di <a href="<?= e(url('/master?tab=counterparties')) ?>">Master Data → Rekening Lawan</a>.</span>
      </div>
    </div>
    <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn" type="submit">Simpan</button>
      <a class="btn btn-ghost" href="<?= e($id ? url('/transactions/' . $id) : url('/transactions')) ?>">Batal</a>
    </div>
  </form>
</section>

<?php /* dialog tambah cepat No. Invoice/PO (Hutang bila Mutasi Keluar, Piutang bila Mutasi Masuk) — dibuka dari pilihan “Tambah” di kolom No. Invoice / PO */ ?>
<div class="modal-back" id="quickRef" aria-hidden="true">
  <div class="modal sm" role="dialog" aria-modal="true" aria-labelledby="qrTitle">
    <div class="modal-head"><h3 id="qrTitle">Tambah invoice / PO baru</h3><button type="button" class="icon-btn" style="background:#eef1f8;color:#3b4566" data-close-modal aria-label="Tutup"><svg class="i"><use href="#i-x"/></svg></button></div>
    <div class="modal-body">
      <div class="form-grid" style="grid-template-columns:1fr">
        <div class="alert alert-info" id="qrKind" style="margin:0"></div>
        <div class="field">
          <label for="qrNo">No. invoice / PO <span class="req">*</span></label>
          <input class="input" id="qrNo" maxlength="80" autocomplete="off">
        </div>
        <div class="field">
          <label for="qrParty"><span id="qrPartyLbl">Supplier</span> <span class="req">*</span></label>
          <select class="input" id="qrParty" data-combo-create-event data-combo-placeholder="Ketik untuk mencari…"
            data-suppliers="<?= e(json_encode(array_column($suppliers, 'name'), JSON_UNESCAPED_UNICODE)) ?>" data-parties="<?= e(json_encode(array_values($partyNames), JSON_UNESCAPED_UNICODE)) ?>">
            <option value="">— pilih —</option>
          </select>
          <span class="help">Otomatis mengikuti kolom Pihak di formulir. Tidak ada di daftar? Ketik namanya lalu pilih “Tambah”.</span>
        </div>
        <div class="field">
          <label for="qrAmt">Nominal tagihan (Rp) <span class="req">*</span></label>
          <input class="input num" id="qrAmt" inputmode="decimal" autocomplete="off" placeholder="Total tagihan">
        </div>
        <div class="field">
          <label for="qrDue">Tgl. jatuh tempo <span class="req" id="qrDueReq" hidden>*</span></label>
          <input class="input" type="date" id="qrDue">
        </div>
        <div class="field">
          <label for="qrDesc">Keterangan</label>
          <input class="input" id="qrDesc" maxlength="255" autocomplete="off">
        </div>
        <div class="err" id="qrErr" hidden role="alert"></div>
        <span class="help">Tersimpan sebagai <b id="qrKindName">Hutang</b> baru dan langsung dipilih di baris uraian. Nomor + pihak yang sama tidak dibuat ganda — yang sudah ada langsung dipilih. Lengkapi datanya kemudian di menu <span id="qrMenu">Hutang</span>.</span>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" data-close-modal>Batal</button>
      <button type="button" class="btn" id="qrSave">Simpan &amp; pilih</button>
    </div>
  </div>
</div>

<?php /* dialog tambah Bank/rekening pihak (master: Rekening Lawan) — dibuka dari pilihan “Tambah” di dropdown Bank/rekening */ ?>
<div class="modal-back" id="quickBank" aria-hidden="true">
  <div class="modal sm" role="dialog" aria-modal="true" aria-labelledby="qbTitle">
    <div class="modal-head"><h3 id="qbTitle">Tambah bank / rekening pihak</h3><button type="button" class="icon-btn" style="background:#eef1f8;color:#3b4566" data-close-modal aria-label="Tutup"><svg class="i"><use href="#i-x"/></svg></button></div>
    <div class="modal-body">
      <div class="form-grid" style="grid-template-columns:1fr">
        <div class="field">
          <label for="qbBank">Bank <span class="req">*</span></label>
          <input class="input" id="qbBank" maxlength="80" autocomplete="off" placeholder="mis. BCA">
        </div>
        <div class="field">
          <label for="qbNo">Nomor rekening <span class="req">*</span></label>
          <input class="input num" id="qbNo" maxlength="40" inputmode="numeric" autocomplete="off" placeholder="6–30 digit angka">
        </div>
        <div class="field">
          <label for="qbParty">Pemilik rekening (pihak)</label>
          <select class="input" id="qbParty" data-combo data-combo-create="/api/parties" data-combo-placeholder="Ketik untuk mencari pihak…">
            <option value="">— tanpa pihak —</option>
            <?php foreach ($partyNames as $pn): ?><option value="<?= e($pn) ?>"><?= e($pn) ?></option><?php endforeach; ?>
          </select>
          <span class="help">Otomatis mengikuti kolom Pihak di formulir. Bila dipilih, rekening dihubungkan dengan pihak tersebut (“a.n. …”).</span>
        </div>
        <div class="err" id="qbErr" hidden role="alert"></div>
        <span class="help">Tersimpan ke Master Data → Rekening Lawan dan langsung dipilih. Rekening dengan bank &amp; nomor yang sama tidak dibuat ganda — yang sudah ada langsung dipilih.</span>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" data-close-modal>Batal</button>
      <button type="button" class="btn" id="qbSave">Simpan &amp; pilih</button>
    </div>
  </div>
</div>
