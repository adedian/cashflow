<?php
/** @var array $t @var array $errors @var ?int $id @var ?string $warn @var array $accounts @var array $categories @var array $projects @var array $counterpartyAccounts @var string $idem */
$kinds = \App\Models\Lookup::KIND_LABEL;
$v = static fn(string $k, $d = '') => $t[$k] ?? $d;
$fe = static fn(string $k) => isset($errors[$k]) ? '<div class="err">' . e($errors[$k]) . '</div>' : '';
$action = $id ? url('/transactions/' . $id) : url('/transactions');
$auto = (!$id && $v('source_ref') && !$errors) ? ' data-auto="1"' : '';   // isian dari tombol Bayar/Terima/Realisasikan: ikut dikosongkan bila referensi diganti
?>
<section class="card" style="max-width:860px">
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
        <label for="amount">Nominal (Rp) <span class="req">*</span></label>
        <input class="input num" type="text" inputmode="decimal" id="amount" name="amount" value="<?= e($v('amount')) ?>" placeholder="1.250.000" autocomplete="off"<?= $auto ?> required>
        <span class="help">Boleh bernilai negatif untuk koreksi/potongan (mis. PPh dipotong): awali dengan tanda −.</span><?= $fe('amount') ?>
      </div>
      <div class="field">
        <label for="voucher_code">Kode bukti</label>
        <input class="input" id="voucher_code" name="voucher_code" maxlength="30" value="<?= e($v('voucher_code')) ?>" placeholder="mis. BK 10/01"><?= $fe('voucher_code') ?>
      </div>
      <div class="field">
        <label for="counterparty">Pihak (penerima/pembayar)</label>
        <?php $curParty = trim((string) $v('counterparty')); $partyNames = array_column($parties, 'name'); ?>
        <select class="input" id="counterparty" name="counterparty" data-combo data-combo-create="/api/parties" data-combo-placeholder="Ketik untuk mencari pihak…"<?= $auto ?>>
          <option value="">— pilih pihak —</option>
          <?php if ($curParty !== '' && !in_array(mb_strtolower($curParty), array_map('mb_strtolower', $partyNames), true)): ?><option value="<?= e($curParty) ?>" selected><?= e($curParty) ?> (belum di master)</option><?php endif; ?>
          <?php foreach ($partyNames as $pn): ?><option value="<?= e($pn) ?>" <?= mb_strtolower($pn) === mb_strtolower($curParty) ? 'selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?>
        </select><?= $fe('counterparty') ?>
        <span class="help">Tidak ada di daftar? Ketik namanya lalu pilih “Tambah”. Daftar dikelola di <a href="<?= e(url('/master?tab=parties')) ?>">Master Data → Pihak</a>.</span>
      </div>
      <div class="field">
        <label for="category_id">Kategori</label>
        <select class="input" id="category_id" name="category_id">
          <option value="">— belum dikategorikan —</option>
          <?php $cur = null; foreach ($categories as $c): if ($cur !== $c['kind']) { if ($cur !== null) echo '</optgroup>'; $cur = $c['kind']; echo '<optgroup label="' . e($kinds[$c['kind']] ?? $c['kind']) . '">'; } ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $v('category_id', 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; if ($cur !== null) echo '</optgroup>'; ?>
        </select><?= $fe('category_id') ?>
      </div>
      <div class="field">
        <label for="project_id">Proyek</label>
        <select class="input" id="project_id" name="project_id">
          <option value="">— tanpa proyek —</option>
          <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $v('project_id', 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select><?= $fe('project_id') ?>
      </div>
      <div class="field full">
        <label for="description">Uraian <span class="req">*</span></label>
        <input class="input" id="description" name="description" maxlength="500" value="<?= e($v('description')) ?>"<?= $auto ?> required><?= $fe('description') ?>
      </div>
      <div class="field">
        <label for="ref_combo">No. invoice / PO</label>
        <?php if (!empty($t['legacy_link'])): ?>
          <input type="hidden" name="ref_no" value="<?= e($v('ref_no')) ?>">
          <div class="ref-hint">Transaksi ini tercatat sebagai bukti penerimaan <a href="<?= e(url('/invoices/' . (int) $t['legacy_link'])) ?>">Piutang #<?= (int) $t['legacy_link'] ?></a> (data historis). Lepaskan tautannya di halaman Piutang bila perlu diubah.</div>
        <?php else: ?>
          <div id="ref_combo" data-combo-remote="/api/refs" data-dir-name="direction" data-tx="<?= $id ? (int) $id : '' ?>" data-selected="<?= e($refInfo ? json_encode($refInfo, JSON_UNESCAPED_UNICODE) : 'null') ?>">
            <input type="hidden" class="combo-value" name="source_ref" value="<?= e($v('source_ref')) ?>">
          </div>
          <?php $legacyRef = trim((string) $v('ref_no')); if ($legacyRef !== '' && !$v('source_ref') && $id): ?>
            <label class="check help"><input type="checkbox" name="ref_clear" value="1">Hapus teks lama “<?= e($legacyRef) ?>” (bukan referensi terhubung)</label>
          <?php endif; ?>
          <div id="refHint" class="ref-hint" hidden aria-live="polite"></div>
          <?= $fe('source_ref') ?>
          <?php if (!empty($errors['_dup'])): ?><label class="check"><input type="checkbox" name="confirm_dup" value="1">Ini pembayaran terpisah — tetap catat</label><?php endif; ?>
          <span class="help">Pilihan mengikuti jenis transaksi: Mutasi Masuk → Piutang / Proyeksi penerimaan; Mutasi Keluar → Hutang / Proyeksi pengeluaran. Kosongkan bila tanpa referensi.</span>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="counterparty_detail">Bank / rekening pihak <span class="help">(hanya terlihat Super Admin)</span></label>
        <select class="input" id="counterparty_detail" name="counterparty_detail">
          <option value="">— pilih rekening lawan —</option>
          <?php $curCp = trim((string) $v('counterparty_detail')); $cpLabels = array_column($counterpartyAccounts, 'label'); ?>
          <?php if ($curCp !== '' && !in_array($curCp, $cpLabels, true)): ?><option value="<?= e($curCp) ?>" selected><?= e($curCp) ?> (belum di master)</option><?php endif; ?>
          <?php foreach ($cpLabels as $lbl): ?><option value="<?= e($lbl) ?>" <?= $lbl === $curCp ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
        </select><?= $fe('counterparty_detail') ?>
        <span class="help">Daftar dikelola di <a href="<?= e(url('/master?tab=counterparties')) ?>">Master Data → Rekening Lawan</a>.</span>
      </div>
      <div class="field full">
        <label for="note">Catatan tambahan</label>
        <textarea class="input" id="note" name="note" maxlength="5000" rows="3"><?= e($v('note')) ?></textarea>
      </div>
    </div>
    <div class="mt-3" style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn" type="submit">Simpan</button>
      <a class="btn btn-ghost" href="<?= e($id ? url('/transactions/' . $id) : url('/transactions')) ?>">Batal</a>
    </div>
  </form>
</section>
